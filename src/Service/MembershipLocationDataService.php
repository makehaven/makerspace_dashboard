<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

/**
 * Provides aggregated member location metrics for the dashboard.
 */
class MembershipLocationDataService {

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend for results.
   */
  protected CacheBackendInterface $cache;

  /**
   * Geocoding service.
   *
   * @var \Drupal\makerspace_dashboard\Service\GeocodingService
   */
  protected GeocodingService $geocoding;

  /**
   * Member role machine names treated as active.
   */
  protected array $memberRoles;

  /**
   * Cache lifetime in seconds.
   */
  protected int $ttl;

  /**
   * Decimal precision for anonymized coordinates.
   */
  protected int $precision = 3;

  /**
   * Maximum jitter (in degrees) applied to anonymized coordinates.
   */
  protected float $jitterRange = 0.01;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, GeocodingService $geocoding, int $ttl = 1800) {
    $this->database = $database;
    $this->cache = $cache;
    $this->geocoding = $geocoding;
    $this->memberRoles = ['current_member', 'member'];
    $this->ttl = $ttl;
  }

  /**
   * Builds a list of member locations (city, state).
   *
   * @return array
   *   An array of coordinate arrays, each with 'lat' and 'lon' keys.
   */
  public function getMemberLocations(): array {
    $cid = 'makerspace_dashboard:membership:locations_data';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('users_field_data', 'u');
    $query->addField('addr', 'city', 'locality');
    $query->addField('addr', 'geo_code_1', 'latitude');
    $query->addField('addr', 'geo_code_2', 'longitude');

    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = u.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');

    $query->innerJoin('civicrm_uf_match', 'uf', 'uf.uf_id = u.uid');
    $query->condition('uf.contact_id', 0, '>');

    $query->innerJoin('civicrm_contact', 'c', 'c.id = uf.contact_id');
    $query->condition('c.is_deleted', 0);

    $query->innerJoin('civicrm_address', 'addr', 'addr.contact_id = c.id AND addr.is_primary = 1');
    $query->leftJoin('civicrm_state_province', 'state', 'state.id = addr.state_province_id');
    $query->addExpression('COALESCE(state.abbreviation, state.name)', 'state_code');
    $query->distinct();

    $latLonGroup = $query->andConditionGroup()
      ->isNotNull('addr.geo_code_1')
      ->isNotNull('addr.geo_code_2');
    $cityStateGroup = $query->andConditionGroup()
      ->condition('addr.city', '', '<>')
      ->isNotNull('addr.city')
      ->isNotNull('addr.state_province_id');
    $query->condition($query->orConditionGroup()
      ->condition($latLonGroup)
      ->condition($cityStateGroup));
    $results = $query->execute()->fetchAll();
    $mappable_count = count($results);

    $aggregated = [];
    $coordinates = [];
    $pendingGeocode = [];
    foreach ($results as $record) {
      $has_latitude = isset($record->latitude) && is_numeric($record->latitude);
      $has_longitude = isset($record->longitude) && is_numeric($record->longitude);
      if ($has_latitude && $has_longitude) {
        $pair = $this->normalizeCoordinates((float) $record->latitude, (float) $record->longitude);
        if ($pair) {
          [$lat, $lon] = $pair;
          $coord_key = $this->buildCoordinateKey($lat, $lon);
          if (!isset($aggregated[$coord_key])) {
            $aggregated[$coord_key] = [
              'lat' => $lat,
              'lon' => $lon,
              'count' => 0,
            ];
          }
          $aggregated[$coord_key]['count']++;
        }
        continue;
      }

      $city = trim((string) $record->locality);
      $state = trim((string) ($record->state_code ?? ''));
      if ($city === '' || $state === '') {
        continue;
      }

      $location_key = mb_strtolower($city . '|' . $state);
      if (!isset($pendingGeocode[$location_key])) {
        $pendingGeocode[$location_key] = [
          'query' => $city . ', ' . $state,
          'count' => 0,
        ];
      }
      $pendingGeocode[$location_key]['count']++;
    }

    if (empty($aggregated)) {
      foreach ($pendingGeocode as $location) {
        $geocode_result = $this->geocoding->geocode($location['query']);
        if ($geocode_result) {
          $pair = $this->normalizeCoordinates((float) $geocode_result['lat'], (float) $geocode_result['lon']);
          if ($pair) {
            [$lat, $lon] = $pair;
            $coord_key = $this->buildCoordinateKey($lat, $lon);
            if (!isset($aggregated[$coord_key])) {
              $aggregated[$coord_key] = [
                'lat' => $lat,
                'lon' => $lon,
                'count' => 0,
              ];
            }
            $aggregated[$coord_key]['count'] += $location['count'];
          }
        }
      }
    }

    if (!empty($aggregated)) {
      $coordinates = array_values($aggregated);
    }

    $total_members = $this->getTotalActiveMembers();

    $data = [
      'locations' => $coordinates,
      'mappable_count' => $mappable_count,
      'total_count' => $total_members,
    ];

    if (!empty($coordinates)) {
      $expire = time() + $this->ttl;
      $this->cache->set($cid, $data, $expire, [
        'civicrm_contact_list',
        'civicrm_address_list',
        'user_list',
      ]);
    }
    else {
      // Avoid caching empty payloads so the service can retry later.
      $this->cache->delete($cid);
    }

    return $data;
  }

  /**
   * Gets the total number of active members.
   */
  public function getTotalActiveMembers(): int {
    $query = $this->database->select('users_field_data', 'u');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = u.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');
    $query->innerJoin('civicrm_uf_match', 'uf', 'uf.uf_id = u.uid');
    $query->condition('uf.contact_id', 0, '>');
    $query->innerJoin('civicrm_contact', 'c', 'c.id = uf.contact_id');
    $query->condition('c.is_deleted', 0);
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Normalize coordinates to the configured precision.
   */
  protected function normalizeCoordinates(?float $lat, ?float $lon): ?array {
    if ($lat === NULL || $lon === NULL) {
      return NULL;
    }
    if (!is_finite($lat) || !is_finite($lon)) {
      return NULL;
    }
    $lat = round($lat, $this->precision);
    $lon = round($lon, $this->precision);
    return $this->applyJitter($lat, $lon);
  }

  /**
   * Builds a unique key for an anonymized coordinate pair.
   */
  protected function buildCoordinateKey(float $lat, float $lon): string {
    return sprintf('%.3f|%.3f', $lat, $lon);
  }

  /**
   * Applies a deterministic jitter to avoid pinpoint accuracy.
   */
  protected function applyJitter(float $lat, float $lon): array {
    $seed = crc32(sprintf('%.3f|%.3f', $lat, $lon));
    $latOffset = $this->mapSeedToOffset($seed);
    $lonOffset = $this->mapSeedToOffset($seed >> 1);
    return [
      $lat + $latOffset,
      $lon + $lonOffset,
    ];
  }

  /**
   * Maps a seed to a bounded offset.
   */
  protected function mapSeedToOffset(int $seed): float {
    $normalized = (($seed % 2001) / 1000) - 1; // -1 .. 1
    return round($normalized * $this->jitterRange, 4);
  }

}
