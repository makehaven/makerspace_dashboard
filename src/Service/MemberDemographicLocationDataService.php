<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\makerspace_dashboard\Support\PublicLocationGrid;

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
   * Age group buckets. There is deliberately no under-18 bucket: children's
   * locations are not mapped publicly at any level of aggregation.
   */
  protected array $ageBuckets = [
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
    $filterKey = sprintf('grid:%s:%s', $filterType, $filterValue);
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

    $points = [];
    $pendingGeocode = [];
    // The demographic joins can return one row per stored value, and each person
    // must count once or a square could reach the minimum with fewer people.
    $seen = [];
    foreach ($baseQuery->execute() as $record) {
      if (isset($seen[$record->profile_id])) {
        continue;
      }
      $seen[$record->profile_id] = TRUE;

      if (is_numeric($record->latitude ?? NULL) && is_numeric($record->longitude ?? NULL)) {
        if ($this->isWithinFocusBounds((float) $record->latitude, (float) $record->longitude)) {
          $points[] = ['lat' => (float) $record->latitude, 'lon' => (float) $record->longitude];
          continue;
        }
      }

      $city = trim((string) $record->locality);
      $state = trim((string) ($record->state_code ?? ''));
      if ($city === '' || $state === '') {
        continue;
      }
      $hash = mb_strtolower($city . '|' . $state);
      $pendingGeocode[$hash] ??= ['query' => $city . ', ' . $state, 'count' => 0];
      $pendingGeocode[$hash]['count']++;
    }

    foreach ($pendingGeocode as $group) {
      $result = $this->geocoding->geocode($group['query']);
      if ($result && $this->isWithinFocusBounds((float) $result['lat'], (float) $result['lon'])) {
        $points[] = ['lat' => (float) $result['lat'], 'lon' => (float) $result['lon'], 'count' => $group['count']];
      }
    }

    $grid = PublicLocationGrid::aggregate($points);

    $totalQuery->addExpression('COUNT(DISTINCT p.profile_id)', 'member_total');
    $totalCount = (int) $totalQuery->execute()->fetchField();

    $payload = [
      'locations' => $grid['locations'],
      'mappable_count' => $grid['shown'],
      'withheld_count' => $grid['withheld'],
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
    $query->where(
      'EXISTS (SELECT 1 FROM {user__roles} member_role WHERE member_role.entity_id = u.uid AND member_role.roles_target_id IN (:member_roles[]))',
      [':member_roles[]' => ['current_member', 'member']]
    );

    $query->innerJoin('civicrm_uf_match', 'uf', 'uf.uf_id = u.uid');
    $query->condition('uf.contact_id', 0, '>');

    $query->innerJoin('civicrm_contact', 'c', 'c.id = uf.contact_id');
    $query->condition('c.is_deleted', 0);

    $query->innerJoin('civicrm_address', 'addr', 'addr.contact_id = c.id AND addr.is_primary = 1');
    $query->leftJoin('civicrm_state_province', 'state', 'state.id = addr.state_province_id');
    if (!$countOnly) {
      $query->addField('p', 'profile_id');
      $query->addExpression('COALESCE(state.abbreviation, state.name)', 'state_code');
      $query->addField('addr', 'city', 'locality');
      $query->addField('addr', 'geo_code_1', 'latitude');
      $query->addField('addr', 'geo_code_2', 'longitude');
    }

    return $query;
  }

  /**
   * Applies the ethnicity filter to a query.
   *
   * CiviCRM is the canonical demographics source since December 2025; the
   * Drupal profile field only holds legacy responses, so both are matched.
   */
  protected function applyEthnicityFilter($query, string $value): void {
    $query->leftJoin('profile__field_member_ethnicity', 'ethnicity', 'ethnicity.entity_id = p.profile_id AND ethnicity.deleted = 0');
    $civicrmColumn = $this->getCivicrmEthnicityColumn();
    if ($civicrmColumn) {
      $query->leftJoin('civicrm_value_demographics_15', 'civi_eth', 'civi_eth.entity_id = c.id');
      // CiviCRM CheckBox values are stored padded with CHAR(1) separators.
      $query->where(
        "(ethnicity.field_member_ethnicity_value = :ethnicity_value OR civi_eth.$civicrmColumn LIKE :ethnicity_packed)",
        [
          ':ethnicity_value' => $value,
          ':ethnicity_packed' => '%' . chr(1) . $value . chr(1) . '%',
        ]
      );
    }
    else {
      $query->condition('ethnicity.field_member_ethnicity_value', $value);
    }
  }

  /**
   * Resolves the CiviCRM ethnicity column when the table exists.
   */
  protected function getCivicrmEthnicityColumn(): ?string {
    $schema = $this->database->schema();
    if ($schema->tableExists('civicrm_value_demographics_15')) {
      foreach (['custom_46', 'ethnicity_46'] as $candidate) {
        if ($schema->fieldExists('civicrm_value_demographics_15', $candidate)) {
          return $candidate;
        }
      }
    }
    return NULL;
  }

  /**
   * Applies the age bucket filter to a query.
   *
   * Prefers the CiviCRM birth date (canonical since December 2025) and falls
   * back to the legacy Drupal profile birthday.
   */
  protected function applyAgeFilter($query, array $bucket): void {
    $query->leftJoin('profile__field_member_birthday', 'birthday', 'birthday.entity_id = p.profile_id AND birthday.deleted = 0 AND birthday.field_member_birthday_value <> \'\'');
    $expression = "TIMESTAMPDIFF(YEAR, COALESCE(c.birth_date, STR_TO_DATE(birthday.field_member_birthday_value, '%Y-%m-%d')), CURDATE())";
    $query->where("COALESCE(c.birth_date, birthday.field_member_birthday_value) IS NOT NULL");
    if (isset($bucket['min'])) {
      $query->where($expression . ' >= :age_min', [':age_min' => (int) $bucket['min']]);
    }
    if (isset($bucket['max'])) {
      $query->where($expression . ' <= :age_max', [':age_max' => (int) $bucket['max']]);
    }
  }

  /**
   * Applies a gender filter to a query.
   *
   * Matches the CiviCRM gender (canonical since December 2025) or the legacy
   * Drupal profile value.
   */
  protected function applyGenderFilter($query, string $genderValue): void {
    $query->leftJoin('profile__field_member_gender', 'gender', 'gender.entity_id = p.profile_id AND gender.deleted = 0');
    $civicrmGenderIds = $this->mapGenderValueToCivicrmIds($genderValue);
    if ($civicrmGenderIds) {
      $ids = implode(',', array_map('intval', $civicrmGenderIds));
      $query->where(
        "(gender.field_member_gender_value = :gender_value OR c.gender_id IN ($ids))",
        [':gender_value' => $genderValue]
      );
    }
    else {
      $query->condition('gender.field_member_gender_value', $genderValue);
    }
  }

  /**
   * Maps a normalized Drupal gender value to CiviCRM gender IDs.
   */
  protected function mapGenderValueToCivicrmIds(string $genderValue): array {
    $map = [
      'female' => [1],
      'male' => [2],
      // The Drupal "other" option is labeled Non-binary.
      'other' => [3, 4],
      'self_describe' => [6],
      'genderqueer' => [8],
      'agender' => [9],
      'questioning' => [10],
    ];
    return $map[$genderValue] ?? [];
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
      'woman' => 'female',
      'male' => 'male',
      'man' => 'male',
      'genderqueer or genderfluid' => 'genderqueer',
      'genderqueer' => 'genderqueer',
      'agender' => 'agender',
      'unsure or questioning' => 'questioning',
      'questioning' => 'questioning',
      'i use another term' => 'self_describe',
      'prefer not to say' => 'decline',
      'non-binary' => 'other',
      'non_binary' => 'other',
      'nonbinary' => 'other',
      'other' => 'other',
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
   * Restricts output to the Connecticut service area.
   */
  protected function isWithinFocusBounds(float $lat, float $lon): bool {
    return $lat >= 40.8 && $lat <= 42.6 && $lon >= -74.5 && $lon <= -70.5;
  }
}
