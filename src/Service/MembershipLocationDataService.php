<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\makerspace_dashboard\Support\PublicLocationGrid;

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
    $cid = 'makerspace_dashboard:membership:locations_grid';
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

    $points = [];
    $pendingGeocode = [];
    foreach ($results as $record) {
      if (is_numeric($record->latitude ?? NULL) && is_numeric($record->longitude ?? NULL)) {
        $points[] = ['lat' => (float) $record->latitude, 'lon' => (float) $record->longitude];
        continue;
      }

      $city = trim((string) $record->locality);
      $state = trim((string) ($record->state_code ?? ''));
      if ($city === '' || $state === '') {
        continue;
      }

      $location_key = mb_strtolower($city . '|' . $state);
      $pendingGeocode[$location_key] ??= ['query' => $city . ', ' . $state, 'count' => 0];
      $pendingGeocode[$location_key]['count']++;
    }

    foreach ($pendingGeocode as $location) {
      $geocode_result = $this->geocoding->geocode($location['query']);
      if ($geocode_result) {
        $points[] = [
          'lat' => (float) $geocode_result['lat'],
          'lon' => (float) $geocode_result['lon'],
          'count' => $location['count'],
        ];
      }
    }

    $grid = PublicLocationGrid::aggregate($points);
    $coordinates = $grid['locations'];

    $total_members = $this->getTotalActiveMembers();

    $data = [
      'locations' => $coordinates,
      'mappable_count' => $grid['shown'],
      'withheld_count' => $grid['withheld'],
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
}
