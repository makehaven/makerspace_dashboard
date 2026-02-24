<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

/**
 * Builds location datasets filtered by demographic attributes.
 */
class MemberDemographicLocationDataService {

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Geocoding fallback.
   */
  protected GeocodingService $geocoding;

  /**
   * Cache lifetime.
   */
  protected int $ttl;

  /**
   * Coordinate precision.
   */
  protected int $precision = 3;

  /**
   * Maximum jitter offset (in degrees).
   */
  protected float $jitterRange = 0.01;

  /**
   * Age group buckets.
   */
  protected array $ageBuckets = [
    'under_18' => ['label' => 'Under 18', 'min' => NULL, 'max' => 17],
    '18_24' => ['label' => '18 – 24', 'min' => 18, 'max' => 24],
    '25_34' => ['label' => '25 – 34', 'min' => 25, 'max' => 34],
    '35_44' => ['label' => '35 – 44', 'min' => 35, 'max' => 44],
    '45_54' => ['label' => '45 – 54', 'min' => 45, 'max' => 54],
    '55_64' => ['label' => '55 – 64', 'min' => 55, 'max' => 64],
    '65_plus' => ['label' => '65+', 'min' => 65, 'max' => NULL],
  ];

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
   * Returns available ethnicity options.
   */
  public function getEthnicityOptions(int $limit = 8): array {
    $query = $this->database->select('profile', 'p');
    $query->innerJoin('profile__field_member_ethnicity', 'ethnicity', 'ethnicity.entity_id = p.profile_id AND ethnicity.deleted = 0');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->addExpression('LOWER(TRIM(ethnicity.field_member_ethnicity_value))', 'ethnicity_key');
    $query->addField('ethnicity', 'field_member_ethnicity_value', 'ethnicity_value');
    $query->addExpression('COUNT(DISTINCT p.profile_id)', 'count_members');
    $query->groupBy('ethnicity_value');
    $query->orderBy('count_members', 'DESC');
    $query->orderBy('ethnicity_value', 'ASC');
    $query->range(0, $limit);

    $options = [];
    foreach ($query->execute() as $record) {
      $value = trim((string) $record->ethnicity_value);
      if ($value === '') {
        continue;
      }
      $options[] = [
        'value' => $value,
        'label' => $value,
      ];
    }
    return $options;
  }

  /**
   * Returns predefined age buckets.
   */
  public function getAgeBucketOptions(): array {
    $options = [];
    foreach ($this->ageBuckets as $id => $definition) {
      $options[] = [
        'value' => $id,
        'label' => $definition['label'],
      ];
    }
    return $options;
  }

  /**
   * Loads locations for a demographic filter.
   */
  public function getLocations(string $filterType, string $filterValue): array {
    $filterType = strtolower($filterType);
    $filterKey = sprintf('%s:%s', $filterType, $filterValue);
    $cid = 'makerspace_dashboard:demographic_locations:' . md5($filterKey);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $baseQuery = $this->buildBaseQuery();
    $totalQuery = $this->buildBaseQuery(TRUE);
    $label = $filterValue;

    switch ($filterType) {
      case 'gender':
        $normalizedGender = $this->normalizeGenderFilterValue($filterValue);
        if ($normalizedGender === NULL) {
          return $this->emptyPayload($filterType, $filterValue);
        }
        $label = ucwords(str_replace('_', ' ', $normalizedGender));
        $this->applyGenderFilter($baseQuery, $normalizedGender);
        $this->applyGenderFilter($totalQuery, $normalizedGender);
        break;

      case 'ethnicity':
        $label = $filterValue;
        $this->applyEthnicityFilter($baseQuery, $filterValue);
        $this->applyEthnicityFilter($totalQuery, $filterValue);
        break;

      case 'age':
      case 'age_range':
        $bucket = $this->ageBuckets[$filterValue] ?? NULL;
        if (!$bucket) {
          return $this->emptyPayload($filterType, $filterValue);
        }
        $label = $bucket['label'];
        $this->applyAgeFilter($baseQuery, $bucket);
        $this->applyAgeFilter($totalQuery, $bucket);
        break;

      default:
        return $this->emptyPayload($filterType, $filterValue);
    }

    $results = $baseQuery->execute();
    $coordinates = [];
    $coordinateKeys = [];
    $pendingGeocode = [];
    $mappableCount = 0;

    foreach ($results as $record) {
      $hasLat = isset($record->latitude) && is_numeric($record->latitude);
      $hasLon = isset($record->longitude) && is_numeric($record->longitude);
      if ($hasLat && $hasLon) {
        $pair = $this->normalizeCoordinates((float) $record->latitude, (float) $record->longitude);
        if ($pair && $this->isWithinFocusBounds($pair[0], $pair[1])) {
          [$lat, $lon] = $pair;
          $key = $this->buildCoordinateKey($lat, $lon);
          if (!isset($coordinateKeys[$key])) {
            $coordinates[$key] = [
              'lat' => $lat,
              'lon' => $lon,
              'count' => 0,
            ];
            $coordinateKeys[$key] = TRUE;
          }
          $coordinates[$key]['count']++;
          $mappableCount++;
          continue;
        }
      }

      $city = trim((string) $record->locality);
      $state = trim((string) ($record->state_code ?? ''));
      if ($city === '' || $state === '') {
        continue;
      }
      $hash = mb_strtolower($city . '|' . $state);
      if (!isset($pendingGeocode[$hash])) {
        $pendingGeocode[$hash] = [
          'query' => $city . ', ' . $state,
          'count' => 0,
        ];
      }
      $pendingGeocode[$hash]['count']++;
    }

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
        $coordinates[$key] = [
          'lat' => $lat,
          'lon' => $lon,
          'count' => 0,
        ];
        $coordinateKeys[$key] = TRUE;
      }
      $coordinates[$key]['count'] += $group['count'];
      $mappableCount += $group['count'];
    }

    $totalQuery->addExpression('COUNT(DISTINCT p.profile_id)', 'member_total');
    $totalCount = (int) $totalQuery->execute()->fetchField();

    $payload = [
      'locations' => array_values($coordinates),
      'mappable_count' => $mappableCount,
      'total_count' => $totalCount,
      'filters' => [
        'type' => $filterType,
        'value' => $filterValue,
        'label' => $label,
      ],
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
   * Builds a base query for member locations.
   */
  protected function buildBaseQuery(bool $countOnly = FALSE) {
    $query = $this->database->select('profile', 'p');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);

    $query->innerJoin('civicrm_uf_match', 'uf', 'uf.uf_id = u.uid');
    $query->condition('uf.contact_id', 0, '>');

    $query->innerJoin('civicrm_contact', 'c', 'c.id = uf.contact_id');
    $query->condition('c.is_deleted', 0);

    $query->innerJoin('civicrm_address', 'addr', 'addr.contact_id = c.id AND addr.is_primary = 1');
    $query->leftJoin('civicrm_state_province', 'state', 'state.id = addr.state_province_id');
    if (!$countOnly) {
      $query->addExpression('COALESCE(state.abbreviation, state.name)', 'state_code');
      $query->addField('addr', 'city', 'locality');
      $query->addField('addr', 'geo_code_1', 'latitude');
      $query->addField('addr', 'geo_code_2', 'longitude');
    }

    return $query;
  }

  /**
   * Applies the ethnicity filter to a query.
   */
  protected function applyEthnicityFilter($query, string $value): void {
    $query->innerJoin('profile__field_member_ethnicity', 'ethnicity', 'ethnicity.entity_id = p.profile_id AND ethnicity.deleted = 0');
    $query->condition('ethnicity.field_member_ethnicity_value', $value);
  }

  /**
   * Applies the age bucket filter to a query.
   */
  protected function applyAgeFilter($query, array $bucket): void {
    $expression = "TIMESTAMPDIFF(YEAR, STR_TO_DATE(birthday.field_member_birthday_value, '%Y-%m-%d'), CURDATE())";
    $query->innerJoin('profile__field_member_birthday', 'birthday', 'birthday.entity_id = p.profile_id AND birthday.deleted = 0 AND birthday.field_member_birthday_value <> \'\'');
    if (isset($bucket['min'])) {
      $query->where($expression . ' >= :age_min', [':age_min' => (int) $bucket['min']]);
    }
    if (isset($bucket['max'])) {
      $query->where($expression . ' <= :age_max', [':age_max' => (int) $bucket['max']]);
    }
  }

  /**
   * Applies a profile gender filter to a query.
   */
  protected function applyGenderFilter($query, string $genderValue): void {
    $query->innerJoin('profile__field_member_gender', 'gender', 'gender.entity_id = p.profile_id AND gender.deleted = 0');
    $query->condition('gender.field_member_gender_value', $genderValue);
  }

  /**
   * Normalizes an incoming gender filter value.
   */
  protected function normalizeGenderFilterValue(string $value): ?string {
    $normalized = mb_strtolower(trim($value));
    if ($normalized === '') {
      return NULL;
    }
    $aliases = [
      'female' => 'female',
      'male' => 'male',
      'non-binary' => 'other',
      'non_binary' => 'other',
      'nonbinary' => 'other',
      'other' => 'other',
      'transgender' => 'transgender',
      'prefer to self-describe' => 'self_describe',
      'prefer_to_self_describe' => 'self_describe',
      'self_describe' => 'self_describe',
      'prefer not to answer' => 'decline',
      'decline' => 'decline',
    ];

    return $aliases[$normalized] ?? $normalized;
  }

  /**
   * Returns an empty payload when filters are invalid.
   */
  protected function emptyPayload(string $type, string $value): array {
    return [
      'locations' => [],
      'mappable_count' => 0,
      'total_count' => 0,
      'filters' => [
        'type' => $type,
        'value' => $value,
        'label' => $value,
      ],
    ];
  }

  /**
   * Normalizes coordinates to fixed precision with jitter.
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
   * Builds a key for deduplicating coordinate pairs.
   */
  protected function buildCoordinateKey(float $lat, float $lon): string {
    return sprintf('%.3f|%.3f', $lat, $lon);
  }

  /**
   * Applies deterministic jitter so points do not reveal exact homes.
   */
  protected function applyJitter(float $lat, float $lon): array {
    $seed = crc32(sprintf('%.3f|%.3f', $lat, $lon));
    $latOffset = $this->mapSeedToOffset($seed);
    $lonOffset = $this->mapSeedToOffset($seed >> 1);
    return [$lat + $latOffset, $lon + $lonOffset];
  }

  /**
   * Maps a seed to a bounded jitter offset.
   */
  protected function mapSeedToOffset(int $seed): float {
    $normalized = (($seed % 2001) / 1000) - 1;
    return round($normalized * $this->jitterRange, 4);
  }

  /**
   * Restricts output to the Connecticut service area.
   */
  protected function isWithinFocusBounds(float $lat, float $lon): bool {
    return $lat >= 40.8 && $lat <= 42.6 && $lon >= -74.5 && $lon <= -70.5;
  }

}
