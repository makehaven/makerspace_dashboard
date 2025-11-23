<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

/**
 * Provides location aggregations for new member joins by quarter.
 */
class MemberJoinLocationDataService {

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend for expensive queries.
   */
  protected CacheBackendInterface $cache;

  /**
   * Geocoding service for city/state fallbacks.
   */
  protected GeocodingService $geocoding;

  /**
   * Cache lifetime in seconds.
   */
  protected int $ttl;

  /**
   * Decimal precision for anonymized coordinates.
   */
  protected int $precision = 3;

  /**
   * Maximum jitter offset (in degrees) applied to anonymized coordinates.
   */
  protected float $jitterRange = 0.01;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, GeocodingService $geocoding, int $ttl = 1800) {
    $this->database = $database;
    $this->cache = $cache;
    $this->geocoding = $geocoding;
    $this->ttl = $ttl;
  }

  /**
   * Returns the most recent quarters that contain join data.
   *
   * @param int $limit
   *   Maximum number of quarters to return.
   *
   * @return array
   *   Quarter option arrays with year, quarter, and label.
   */
  public function getAvailableQuarters(int $limit = 12): array {
    $cid = sprintf('makerspace_dashboard:join_locations:quarters:%d', $limit);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('profile', 'p');
    $query->addExpression('YEAR(FROM_UNIXTIME(p.created))', 'year_value');
    $query->addExpression('QUARTER(FROM_UNIXTIME(p.created))', 'quarter_value');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->isNotNull('p.created');
    $query->groupBy('year_value');
    $query->groupBy('quarter_value');
    $query->orderBy('year_value', 'DESC');
    $query->orderBy('quarter_value', 'DESC');
    $query->range(0, $limit);

    $options = [];
    foreach ($query->execute() as $record) {
      $year = (int) $record->year_value;
      $quarter = (int) $record->quarter_value;
      if ($year === 0 || $quarter === 0) {
        continue;
      }
      $options[] = [
        'year' => $year,
        'quarter' => $quarter,
        'label' => $this->formatQuarterLabel($year, $quarter),
        'value' => sprintf('%d-Q%d', $year, $quarter),
      ];
    }

    if (!empty($options)) {
      $expire = time() + ($this->ttl * 2);
      $this->cache->set($cid, $options, $expire, ['profile_list']);
    }

    return $options;
  }

  /**
   * Returns aggregated join locations for the requested quarter.
   */
  public function getJoinLocationsForQuarter(int $year, int $quarter): array {
    $quarter = max(1, min(4, $quarter));
    $cid = sprintf('makerspace_dashboard:join_locations:%d:%d', $year, $quarter);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $range = $this->getQuarterRange($year, $quarter);
    $mappable = [];
    $coordinateKeys = [];
    $pendingGeocode = [];
    $mappableCount = 0;

    $query = $this->database->select('profile', 'p');
    $query->addField('addr', 'city', 'locality');
    $query->addField('addr', 'geo_code_1', 'latitude');
    $query->addField('addr', 'geo_code_2', 'longitude');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->isNotNull('p.created');

    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('civicrm_uf_match', 'uf', 'uf.uf_id = u.uid');
    $query->condition('uf.contact_id', 0, '>');
    $query->innerJoin('civicrm_contact', 'c', 'c.id = uf.contact_id');
    $query->condition('c.is_deleted', 0);
    $query->innerJoin('civicrm_address', 'addr', 'addr.contact_id = c.id AND addr.is_primary = 1');
    $query->leftJoin('civicrm_state_province', 'state', 'state.id = addr.state_province_id');
    $query->addExpression('COALESCE(state.abbreviation, state.name)', 'state_code');

    $query->condition('p.created', [$range['start_timestamp'], $range['end_timestamp']], 'BETWEEN');

    $results = $query->execute();
    foreach ($results as $record) {
      $has_latitude = isset($record->latitude) && is_numeric($record->latitude);
      $has_longitude = isset($record->longitude) && is_numeric($record->longitude);
      if ($has_latitude && $has_longitude) {
        $pair = $this->normalizeCoordinates((float) $record->latitude, (float) $record->longitude);
        if ($pair && $this->isWithinFocusBounds($pair[0], $pair[1])) {
          [$lat, $lon] = $pair;
          $key = $this->buildCoordinateKey($lat, $lon);
          if (!isset($coordinateKeys[$key])) {
            $mappable[$key] = [
              'lat' => $lat,
              'lon' => $lon,
              'count' => 0,
            ];
            $coordinateKeys[$key] = TRUE;
          }
          $mappable[$key]['count']++;
          $mappableCount++;
          continue;
        }
      }

      $city = trim((string) $record->locality);
      $state = trim((string) ($record->state_code ?? ''));
      if ($city === '' || $state === '') {
        continue;
      }
      $key = mb_strtolower($city . '|' . $state);
      if (!isset($pendingGeocode[$key])) {
        $pendingGeocode[$key] = [
          'query' => $city . ', ' . $state,
          'count' => 0,
        ];
      }
      $pendingGeocode[$key]['count']++;
    }

    if (!empty($pendingGeocode)) {
      foreach ($pendingGeocode as $group) {
        $result = $this->geocoding->geocode($group['query']);
        if (!$result) {
          continue;
        }
        $pair = $this->normalizeCoordinates((float) $result['lat'], (float) $result['lon']);
        if (!$pair || !$this->isWithinFocusBounds($pair[0], $pair[1])) {
          continue;
        }
        [$lat, $lon] = $pair;
        $key = $this->buildCoordinateKey($lat, $lon);
        if (!isset($coordinateKeys[$key])) {
          $mappable[$key] = [
            'lat' => $lat,
            'lon' => $lon,
            'count' => 0,
          ];
          $coordinateKeys[$key] = TRUE;
        }
        $mappable[$key]['count'] += $group['count'];
        $mappableCount += $group['count'];
      }
    }

    $total = $this->countQuarterJoins($range);

    $payload = [
      'filters' => [
        'year' => $year,
        'quarter' => $quarter,
        'label' => $this->formatQuarterLabel($year, $quarter),
      ],
      'locations' => array_values($mappable),
      'mappable_count' => $mappableCount,
      'total_count' => $total,
    ];

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $payload, $expire, [
      'profile_list',
      'civicrm_contact_list',
      'civicrm_address_list',
    ]);

    return $payload;
  }

  /**
   * Counts the total join records for the quarter (addresses optional).
   */
  protected function countQuarterJoins(array $range): int {
    $query = $this->database->select('profile', 'p');
    $query->addExpression('COUNT(DISTINCT p.profile_id)', 'total_members');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->isNotNull('p.created');
    $query->condition('p.created', [$range['start_timestamp'], $range['end_timestamp']], 'BETWEEN');

    $result = $query->execute()->fetchField();
    return (int) $result;
  }

  /**
   * Returns a formatted label for the requested quarter.
   */
  protected function formatQuarterLabel(int $year, int $quarter): string {
    return sprintf('Q%d %d', $quarter, $year);
  }

  /**
   * Determines the inclusive/exclusive bounds for the quarter.
   */
  protected function getQuarterRange(int $year, int $quarter): array {
    $quarter = max(1, min(4, $quarter));
    $startMonth = (($quarter - 1) * 3) + 1;
    $start = new \DateTime(sprintf('%04d-%02d-01', $year, $startMonth), new \DateTimeZone('UTC'));
    $end = (clone $start)->modify('+3 months');
    return [
      'start_timestamp' => (int) $start->format('U'),
      'end_timestamp' => (int) $end->format('U'),
    ];
  }

  /**
   * Normalize coordinates to the configured precision and jitter.
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
   * Applies deterministic jitter so markers do not reveal exact homes.
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
    $normalized = (($seed % 2001) / 1000) - 1;
    return round($normalized * $this->jitterRange, 4);
  }

  /**
   * Limits output to the Connecticut region to avoid distracting outliers.
   */
  protected function isWithinFocusBounds(float $lat, float $lon): bool {
    return $lat >= 40.8 && $lat <= 42.4 && $lon >= -74.5 && $lon <= -71.0;
  }

}
