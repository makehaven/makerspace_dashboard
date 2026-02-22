<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;

/**
 * Provides aggregated demographic metrics for the dashboard.
 */
class DemographicsDataService {

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend for results.
   */
  protected CacheBackendInterface $cache;

  /**
   * Cache lifetime in seconds.
   */
  protected int $ttl;

  /**
   * Role machine names that indicate active membership.
   */
  protected array $memberRoles;

  /**
   * Config factory for resolving field metadata.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Cached allowed-value labels for discovery sources.
   */
  protected ?array $discoveryAllowedValueLabels = NULL;

  /**
   * Cached ethnicity field metadata for contacts.
   *
   * @var array|null
   */
  protected ?array $ethnicityFieldMetadata = NULL;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, ConfigFactoryInterface $configFactory, ?array $member_roles = NULL, int $ttl = 1800) {
    $this->database = $database;
    $this->cache = $cache;
    $this->configFactory = $configFactory;
    $this->ttl = $ttl;
    $this->memberRoles = $member_roles ?: ['current_member', 'member'];
  }

  /**
   * Builds a town/locality distribution for active members.
   */
  public function getLocalityDistribution(int $minimum = 5, int $limit = 8): array {
    $cid = sprintf('makerspace_dashboard:demographics:locality:%d:%d', $minimum, $limit);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $aggregated = $this->getLocalityCounts();

    $rows = array_values($aggregated);
    usort($rows, function (array $a, array $b) {
      return $b['count'] <=> $a['count'];
    });

    $output = [];
    $other = 0;
    foreach ($rows as $row) {
      if ($row['label'] === 'Unknown / not provided') {
        if ($row['count'] < $minimum) {
          $other += $row['count'];
        }
        else {
          $output[] = $row;
        }
        continue;
      }

      if ($row['count'] < $minimum || count($output) >= $limit) {
        $other += $row['count'];
      }
      else {
        $output[] = $row;
      }
    }

    if ($other > 0) {
      $output[] = [
        'label' => 'Other (< ' . $minimum . ')',
        'count' => $other,
      ];
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $output, $expire, ['profile_list', 'user_list']);

    return $output;
  }

  /**
   * Returns counts for all recorded localities.
   */
  public function getLocalityCounts(): array {
    $cid = 'makerspace_dashboard:demographics:locality_counts';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->buildLocalityQuery();
    $query->addField('state', 'abbreviation', 'state_abbreviation');
    $query->addField('state', 'name', 'state_name');
    $results = $query->execute();

    $aggregated = [];
    foreach ($results as $record) {
      $raw = trim((string) $record->locality_raw);
      $state = trim((string) ($record->state_abbreviation ?? $record->state_name ?? ''));
      $key = $raw === '' ? '__unknown' : mb_strtolower($raw);
      $label = $raw === '' ? 'Unknown / not provided' : $this->formatLabel($raw, '');
      if ($raw !== '' && $state !== '') {
        $label = sprintf('%s, %s', $label, $state);
      }
      if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
          'label' => $label,
          'count' => 0,
        ];
      }
      $aggregated[$key]['count'] += (int) $record->member_count;
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $aggregated, $expire, [
      'profile_list',
      'user_list',
      'civicrm_contact_list',
      'civicrm_address_list',
    ]);

    return $aggregated;
  }

  /**
   * Builds the top towns with stacked ethnicity counts.
   */
  public function getTownEthnicityDistribution(int $limit = 8, int $minimum = 5): array {
    $limit = max(1, $limit);
    $minimum = max(1, $minimum);
    $cid = sprintf('makerspace_dashboard:demographics:town_ethnicity:%d:%d', $limit, $minimum);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $metadata = $this->getEthnicityFieldMetadata();
    $ethnicityTable = $metadata['table'] ?? NULL;
    $ethnicityField = $metadata['column'] ?? NULL;

    $query = $this->database->select('profile', 'p');
    $query->addField('c', 'id', 'contact_id');
    $query->addField('addr', 'city', 'city');
    $query->addExpression('COALESCE(state.abbreviation, state.name, \'\')', 'state');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');
    $query->innerJoin('civicrm_uf_match', 'uf', 'uf.uf_id = p.uid');
    $query->condition('uf.contact_id', 0, '>');
    $query->innerJoin('civicrm_contact', 'c', 'c.id = uf.contact_id');
    $query->condition('c.is_deleted', 0);
    $query->leftJoin('civicrm_address', 'addr', 'addr.contact_id = c.id AND addr.is_primary = 1');
    $query->leftJoin('civicrm_state_province', 'state', 'state.id = addr.state_province_id');
    if ($ethnicityTable && $ethnicityField) {
      $query->leftJoin($ethnicityTable, 'demo', 'demo.entity_id = c.id');
      $query->addExpression("COALESCE(demo.$ethnicityField, '')", 'ethnicity');
    }
    else {
      $query->addExpression("''", 'ethnicity');
    }

    $results = $query->execute();

    $ignored = $this->getIgnoredEthnicityValues();
    $towns = [];
    $ethnicityTotals = [];

    foreach ($results as $record) {
      $label = $this->formatTownLabel((string) ($record->city ?? ''), (string) ($record->state ?? ''));
      $key = mb_strtolower($label);

      if (!isset($towns[$key])) {
        $towns[$key] = [
          'label' => $label,
          'total' => 0,
          'distribution' => [],
        ];
      }
      $towns[$key]['total']++;

      $values = $this->normalizeEthnicityValues($record->ethnicity ?? '', $ignored);
      if (!$values) {
        $values = ['Unspecified'];
      }
      foreach ($values as $entry) {
        $towns[$key]['distribution'][$entry] = ($towns[$key]['distribution'][$entry] ?? 0) + 1;
        $ethnicityTotals[$entry] = ($ethnicityTotals[$entry] ?? 0) + 1;
      }
    }

    if (!$towns) {
      return [];
    }

    $filtered = array_filter($towns, static function (array $town) use ($minimum): bool {
      return ($town['total'] ?? 0) >= $minimum;
    });
    if (!$filtered) {
      $filtered = $towns;
    }

    usort($filtered, static function (array $a, array $b): int {
      return ($b['total'] ?? 0) <=> ($a['total'] ?? 0);
    });
    $top = array_slice($filtered, 0, $limit);

    $orderedEthnicities = array_keys($ethnicityTotals);
    usort($orderedEthnicities, static function ($a, $b) use ($ethnicityTotals) {
      return ($ethnicityTotals[$b] ?? 0) <=> ($ethnicityTotals[$a] ?? 0);
    });

    $data = [
      'towns' => array_values($top),
      'ethnicity_labels' => $orderedEthnicities,
    ];

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $data, $expire, ['profile_list', 'user_list', 'civicrm_contact_list', 'civicrm_address_list']);

    return $data;
  }

  /**
   * Builds the common locality aggregation query.
   */
  protected function buildLocalityQuery(): SelectInterface {
    $query = $this->database->select('profile', 'p');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');
    $query->addField('addr', 'city', 'locality_raw');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');
    $query->innerJoin('civicrm_uf_match', 'uf', 'uf.uf_id = p.uid');
    $query->condition('uf.contact_id', 0, '>');
    $query->innerJoin('civicrm_contact', 'c', 'c.id = uf.contact_id');
    $query->condition('c.is_deleted', 0);
    $query->leftJoin('civicrm_address', 'addr', 'addr.contact_id = c.id AND addr.is_primary = 1');
    $query->leftJoin('civicrm_state_province', 'state', 'state.id = addr.state_province_id');

    $query->groupBy('addr.city');
    $query->groupBy('state.abbreviation');
    $query->groupBy('state.name');
    $query->orderBy('member_count', 'DESC');

    return $query;
  }

  /**
   * Builds a gender distribution for active members.
   */
  public function getGenderDistribution(int $minimum = 5): array {
    $cid = sprintf('makerspace_dashboard:demographics:gender:%d', $minimum);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('profile', 'p');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');
    $query->addField('gender', 'field_member_gender_value', 'gender_raw');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->leftJoin('profile__field_member_gender', 'gender', 'gender.entity_id = p.profile_id AND gender.deleted = 0 AND gender.delta = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');

    $query->groupBy('gender.field_member_gender_value');
    $query->orderBy('member_count', 'DESC');

    $results = $query->execute();

    $aggregated = [];
    foreach ($results as $record) {
      $raw = trim((string) $record->gender_raw);
      $key = $raw === '' ? '__unknown' : mb_strtolower($raw);
      $label = $raw === '' ? 'Not provided' : $this->formatLabel($raw, '');
      if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
          'label' => $label,
          'count' => 0,
        ];
      }
      $aggregated[$key]['count'] += (int) $record->member_count;
    }

    $rows = array_values($aggregated);
    usort($rows, function (array $a, array $b) {
      return $b['count'] <=> $a['count'];
    });

    $output = [];
    $other = 0;
    foreach ($rows as $row) {
      if ($row['count'] < $minimum) {
        $other += $row['count'];
        continue;
      }
      $output[] = $row;
    }

    if ($other > 0) {
      $output[] = [
        'label' => 'Other (< ' . $minimum . ')',
        'count' => $other,
      ];
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $output, $expire, ['profile_list', 'user_list']);

    return $output;
  }

  /**
   * Returns membership gender percentages mapped to board categories.
   */
  public function getMembershipGenderPercentages(): array {
    $distribution = $this->getGenderDistribution(0);
    $buckets = [
      'Male' => 0,
      'Female' => 0,
      'Non-Binary' => 0,
      'Other/Unknown' => 0,
    ];
    $total = 0;

    foreach ($distribution as $row) {
      $count = (int) ($row['count'] ?? 0);
      $total += $count;
      $label = isset($row['label']) ? strtolower((string) $row['label']) : '';
      if (str_contains($label, 'male') && !str_contains($label, 'fe')) {
        $buckets['Male'] += $count;
      }
      elseif (str_contains($label, 'female')) {
        $buckets['Female'] += $count;
      }
      elseif (str_contains($label, 'non') || str_contains($label, 'nb') || str_contains($label, 'gender')) {
        $buckets['Non-Binary'] += $count;
      }
      else {
        $buckets['Other/Unknown'] += $count;
      }
    }

    $percentages = [];
    foreach ($buckets as $bucket => $count) {
      $percentages[$bucket] = $total > 0 ? $count / $total : 0;
    }

    return $percentages;
  }

  /**
   * Builds an interest distribution for active members.
   */
  public function getInterestDistribution(int $minimum = 5, int $limit = 10): array {
    $cid = sprintf('makerspace_dashboard:demographics:interest:%d:%d', $minimum, $limit);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    // Some sites may not expose member interests. Return empty results gracefully.
    if (!$this->database->schema()->tableExists('profile__field_member_interest')) {
      return [];
    }

    $query = $this->database->select('profile', 'p');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');
    $query->addField('interest', 'field_member_interest_value', 'interest_raw');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->leftJoin('profile__field_member_interest', 'interest', 'interest.entity_id = p.profile_id AND interest.deleted = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');

    $query->groupBy('interest.field_member_interest_value');
    $query->orderBy('member_count', 'DESC');
    $query->range(0, $limit);

    $results = $query->execute();

    $aggregated = [];
    foreach ($results as $record) {
      $raw = trim((string) $record->interest_raw);
      $key = $raw === '' ? '__unknown' : mb_strtolower($raw);
      $label = $raw === '' ? 'Not provided' : $this->formatLabel($raw, '');
      if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
          'label' => $label,
          'count' => 0,
        ];
      }
      $aggregated[$key]['count'] += (int) $record->member_count;
    }

    $rows = array_values($aggregated);
    usort($rows, function (array $a, array $b) {
      return $b['count'] <=> $a['count'];
    });

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $rows, $expire, ['profile_list', 'user_list']);

    return $rows;
  }

  /**
   * Builds an interest distribution for new members within a date range.
   */
  public function getRecentInterestDistribution(\DateTimeImmutable $start, \DateTimeImmutable $end, int $minimum = 2, int $limit = 10): array {
    $rangeStart = $start->setTime(0, 0, 0)->getTimestamp();
    $rangeEnd = $end->setTime(23, 59, 59)->getTimestamp();
    $cid = sprintf('makerspace_dashboard:demographics:interest_recent:%d:%d:%d:%d', $rangeStart, $rangeEnd, $minimum, $limit);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('profile__field_member_interest')) {
      return [];
    }

    $query = $this->database->select('profile', 'p');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');
    $query->addField('interest', 'field_member_interest_value', 'interest_raw');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->condition('p.created', $rangeStart, '>=');
    $query->condition('p.created', $rangeEnd, '<=');

    $query->leftJoin('profile__field_member_interest', 'interest', 'interest.entity_id = p.profile_id AND interest.deleted = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);

    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');

    $query->groupBy('interest.field_member_interest_value');
    $query->orderBy('member_count', 'DESC');
    $query->range(0, $limit * 2);

    $results = $query->execute();

    $aggregated = [];
    foreach ($results as $record) {
      $raw = trim((string) $record->interest_raw);
      $key = $raw === '' ? '__unknown' : mb_strtolower($raw);
      $label = $raw === '' ? 'Not provided' : $this->formatLabel($raw, '');
      if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
          'label' => $label,
          'count' => 0,
        ];
      }
      $aggregated[$key]['count'] += (int) $record->member_count;
    }

    $rows = array_values($aggregated);
    usort($rows, function (array $a, array $b) {
      return $b['count'] <=> $a['count'];
    });

    $output = [];
    $other = 0;
    foreach ($rows as $row) {
      if ($row['count'] < $minimum) {
        $other += $row['count'];
        continue;
      }
      $output[] = $row;
      if (count($output) >= $limit) {
        break;
      }
    }
    if ($other > 0) {
      $output[] = [
        'label' => 'Other (< ' . $minimum . ')',
        'count' => $other,
      ];
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $output, $expire, ['profile_list', 'user_list']);

    return $output;
  }

  /**
   * Builds a discovery source distribution for active members.
   */
  public function getDiscoveryDistribution(int $minimum = 5): array {
    $cid = sprintf('makerspace_dashboard:demographics:discovery:%d', $minimum);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('profile__field_member_discovery')) {
      return [];
    }

    $query = $this->database->select('profile', 'p');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');
    $query->addField('discovery', 'field_member_discovery_value', 'discovery_raw');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->leftJoin('profile__field_member_discovery', 'discovery', 'discovery.entity_id = p.profile_id AND discovery.deleted = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');

    $query->groupBy('discovery.field_member_discovery_value');
    $query->orderBy('member_count', 'DESC');

    $results = $query->execute();

    $allowedLabels = $this->getDiscoveryAllowedValueLabels();
    $aggregated = [];
    foreach ($results as $record) {
      $raw = trim((string) $record->discovery_raw);
      $key = $raw === '' ? '__unknown' : mb_strtolower($raw);
      $shortLabel = $raw === '' ? 'Not captured' : $this->formatLabel($raw, '');
      $fullLabel = $raw === '' ? $shortLabel : ($allowedLabels[$raw] ?? $shortLabel);
      if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
          'label' => $shortLabel,
          'full_label' => $fullLabel,
          'value' => $raw === '' ? NULL : $raw,
          'count' => 0,
        ];
      }
      $aggregated[$key]['count'] += (int) $record->member_count;
    }

    $rows = array_values($aggregated);
    usort($rows, function (array $a, array $b) {
      return $b['count'] <=> $a['count'];
    });

    $output = [];
    $other = 0;
    foreach ($rows as $row) {
      if ($row['count'] < $minimum) {
        $other += $row['count'];
        continue;
      }
      $output[] = $row;
    }

    if ($other > 0) {
      $output[] = [
        'label' => 'Other (< ' . $minimum . ')',
        'full_label' => 'Other (< ' . $minimum . ')',
        'value' => NULL,
        'count' => $other,
      ];
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $output, $expire, ['profile_list', 'user_list']);

    return $output;
  }

  /**
   * Returns allowed values/labels for the discovery field.
   */
  protected function getDiscoveryAllowedValueLabels(): array {
    if ($this->discoveryAllowedValueLabels !== NULL) {
      return $this->discoveryAllowedValueLabels;
    }

    $labels = [];
    $config = $this->configFactory->get('field.storage.profile.field_member_discovery');
    if ($config) {
      $allowed = $config->get('settings.allowed_values') ?? [];
      foreach ($allowed as $definition) {
        if (!is_array($definition)) {
          continue;
        }
        $value = $definition['value'] ?? NULL;
        $label = $definition['label'] ?? NULL;
        if ($value !== NULL && $label !== NULL) {
          $labels[(string) $value] = (string) $label;
        }
      }
    }

    return $this->discoveryAllowedValueLabels = $labels;
  }

  /**
   * Builds an age distribution for active members.
   */
  public function getAgeDistribution(int $minimumAge = 0, int $maximumAge = 100): array {
    $cid = sprintf('makerspace_dashboard:demographics:ages:%d:%d', $minimumAge, $maximumAge);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('profile__field_member_birthday')) {
      return [];
    }

    $query = $this->database->select('profile', 'p');
    $query->addField('birthday', 'field_member_birthday_value', 'birthday_raw');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->leftJoin('profile__field_member_birthday', 'birthday', 'birthday.entity_id = p.profile_id AND birthday.deleted = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');
    $query->addField('p', 'uid', 'uid');
    $query->distinct();

    $results = $query->execute();

    $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
    $distribution = [];
    $seen = [];
    foreach ($results as $record) {
      $uid = (int) $record->uid;
      if (isset($seen[$uid])) {
        continue;
      }
      $seen[$uid] = TRUE;
      $raw = trim((string) $record->birthday_raw);
      if ($raw === '') {
        continue;
      }
      try {
        $birthDate = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
      }
      catch (\Exception $e) {
        continue;
      }
      $age = (int) $birthDate->diff($now)->y;
      if ($age < $minimumAge || $age > $maximumAge) {
        continue;
      }
      $distribution[$age] = ($distribution[$age] ?? 0) + 1;
    }

    ksort($distribution);
    $output = [];
    foreach ($distribution as $age => $count) {
      $output[] = [
        'label' => (string) $age,
        'count' => $count,
      ];
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $output, $expire, ['profile_list', 'user_list']);

    return $output;
  }

  /**
   * Provides membership age buckets with counts and percentages.
   *
   * @return array
   *   Array with keys:
   *   - counts: Bucket => count map.
   *   - percentages: Bucket => decimal percent map.
   *   - total_members: Total members considered.
   */
  public function getMembershipAgeBucketPercentages(): array {
    $buckets = $this->getAgeBucketDefinitions();
    $counts = array_fill_keys(array_keys($buckets), 0);
    $total = 0;

    $query = $this->buildActiveMemberContactQuery();
    $query->addField('c', 'birth_date', 'birth_date');
    $results = $query->execute();
    $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));

    foreach ($results as $row) {
      $birthRaw = $row->birth_date ?? NULL;
      if (empty($birthRaw)) {
        $counts['Unknown']++;
        $total++;
        continue;
      }
      try {
        $birthDate = new \DateTimeImmutable($birthRaw, new \DateTimeZone('UTC'));
      }
      catch (\Exception $e) {
        $counts['Unknown']++;
        $total++;
        continue;
      }
      $age = (int) $birthDate->diff($now)->y;
      $bucket = $this->resolveAgeBucket($age, $buckets);
      if ($bucket === NULL) {
        $counts['Unknown']++;
      }
      else {
        $counts[$bucket]++;
      }
      $total++;
    }

    $percentages = [];
    foreach ($counts as $bucket => $count) {
      $percentages[$bucket] = $total > 0 ? $count / $total : 0;
    }

    return [
      'counts' => $counts,
      'percentages' => $percentages,
      'total_members' => $total,
    ];
  }

  /**
   * Normalizes stored strings into human-readable labels.
   */
  protected function formatLabel(string $raw, string $fallback): string {
    $prepared = trim($raw);
    if ($prepared === '') {
      return $fallback;
    }

    $prepared = str_replace(['_', '-'], ' ', $prepared);
    $prepared = mb_convert_case($prepared, MB_CASE_TITLE, 'UTF-8');

    $map = [
      'Decline' => 'Prefer Not To Say',
    ];

    return $map[$prepared] ?? $prepared;
  }

  /**
   * Formats a town label with optional state.
   */
  protected function formatTownLabel(string $city, string $state): string {
    $cityLabel = $this->formatLabel($city, 'Unknown');
    $stateLabel = strtoupper(trim($state));
    return $stateLabel ? sprintf('%s, %s', $cityLabel, $stateLabel) : $cityLabel;
  }

  /**
   * Gets the annual member referral rate.
   *
   * @param int|null $year
   *   The year to calculate for. Defaults to current year.
   *
   * @return float
   *   The member referral rate.
   */
  public function getAnnualMemberReferralRate(?int $year = NULL): float {
    // We are now measuring "Member Referrers Rate (%)":
    // Unique referrers in the given year / Total active members.
    $targetYear = $year ?: (int) date('Y');

    // 1. Get unique referrers for the given year.
    // This looks at new members created in that year and identifies who referred them.
    $query = $this->database->select('profile__field_member_referring', 'r');
    $query->innerJoin('profile', 'p', 'r.entity_id = p.profile_id');
    $query->innerJoin('users_field_data', 'u', 'p.uid = u.uid');
    $query->addExpression('COUNT(DISTINCT LOWER(TRIM(r.field_member_referring_value)))', 'referrer_count');
    $query->condition('p.type', 'main');
    $query->condition('u.created', strtotime($targetYear . '-01-01'), '>=');
    $query->condition('u.created', strtotime($targetYear . '-12-31 23:59:59'), '<=');
    $referrerCount = (int) $query->execute()->fetchField();

    // 2. Get total active members.
    // Note: For historical years, this simple count of CURRENTLY active members 
    // is an approximation. Ideally we would use snapshots, but this matches 
    // the user intent for the dashboard fallback.
    $query = $this->database->select('user__roles', 'ur');
    $query->innerJoin('users_field_data', 'u', 'u.uid = ur.entity_id');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');
    $query->condition('u.status', 1);
    $activeCount = (int) $query->countQuery()->execute()->fetchField();

    if ($activeCount > 0) {
      return $referrerCount / $activeCount;
    }

    return 0.0;
  }

  /**
   * Gets the annual membership diversity (% BIPOC).
   *
   * @return float
   *   The membership diversity.
   */
  public function getAnnualMembershipDiversity(): float {
    // @todo: Implement logic to call `getEthnicityDistribution()`, sum the
    // counts for all non-white identities, and divide by the total responses.
    // This will be called by the 'annual' snapshot.
    return 0.18;
  }

  /**
   * Defines the canonical age buckets used across charts.
   */
  protected function getAgeBucketDefinitions(): array {
    return [
      '<30' => [NULL, 29],
      '30-39' => [30, 39],
      '40-49' => [40, 49],
      '50-59' => [50, 59],
      '60+' => [60, NULL],
      'Unknown' => [NULL, NULL],
    ];
  }

  /**
   * Resolves which bucket an age falls into.
   */
  protected function resolveAgeBucket(int $age, array $buckets): ?string {
    foreach ($buckets as $label => $range) {
      [$min, $max] = $range;
      if ($label === 'Unknown') {
        continue;
      }
      if (($min === NULL || $age >= $min) && ($max === NULL || $age <= $max)) {
        return $label;
      }
    }
    if ($age >= 60) {
      return '60+';
    }
    return NULL;
  }

  /**
   * Builds a summary of member-reported ethnicity data.
   *
   * @return array
   *   Summary array containing:
   *   - active_members: Total active members evaluated.
   *   - reported_members: Members who provided an ethnicity response (excluding
   *     "decline"/"prefer not to say").
   *   - bipoc_members: Members who reported at least one BIPOC identity.
   *   - percentage: Decimal percentage of reported members who are BIPOC.
   *   - distribution: Raw counts keyed by ethnicity machine value.
   */
  public function getMembershipEthnicitySummary(): array {
    $cid = 'makerspace_dashboard:demographics:ethnicity_summary';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $schema = $this->database->schema();
    $ethnicityTable = $schema->tableExists('civicrm_value_demographics_15') ? 'civicrm_value_demographics_15' : NULL;
    $ethnicityField = NULL;
    if ($ethnicityTable) {
      foreach (['custom_46', 'ethnicity_46'] as $candidate) {
        if ($schema->fieldExists($ethnicityTable, $candidate)) {
          $ethnicityField = $candidate;
          break;
        }
      }
    }

    $query = $this->buildActiveMemberContactQuery();
    if ($ethnicityTable && $ethnicityField) {
      $query->leftJoin($ethnicityTable, 'demo', 'demo.entity_id = c.id');
      $query->addExpression("LOWER(COALESCE(demo.$ethnicityField, ''))", 'ethnicity_value');
    }
    else {
      $query->addExpression("''", 'ethnicity_value');
    }

    $results = $query->execute();

    $activeMembers = [];
    $reportedMembers = [];
    $bipocMembers = [];
    $distribution = [];
    $ignored = $this->getIgnoredEthnicityValues();
    $bipocValues = $this->getBipocEthnicityValues();

    foreach ($results as $record) {
      $contactId = (int) ($record->id ?? 0);
      $value = trim((string) ($record->ethnicity_value ?? ''));
      $activeMembers[$contactId] = TRUE;

      $values = array_unique(array_filter($this->explodeCustomFieldValues($value)));
      $normalizedValues = [];
      foreach ($values as $entry) {
        $normalized = strtolower($entry);
        if ($normalized === '' || in_array($normalized, $ignored, TRUE)) {
          continue;
        }
        $normalizedValues[] = $normalized;
      }

      if (!$normalizedValues) {
        $distribution['not_specified'] = ($distribution['not_specified'] ?? 0) + 1;
        continue;
      }

      if (count($normalizedValues) > 1) {
        $distribution['multi'] = ($distribution['multi'] ?? 0) + 1;
        $reportedMembers[$contactId] = TRUE;
        $bipocMembers[$contactId] = TRUE;
        continue;
      }

      $single = $normalizedValues[0];
      $distribution[$single] = ($distribution[$single] ?? 0) + 1;
      $reportedMembers[$contactId] = TRUE;
      if (in_array($single, $bipocValues, TRUE)) {
        $bipocMembers[$contactId] = TRUE;
      }
    }

    $reportedCount = count($reportedMembers);
    $bipocCount = count($bipocMembers);
    $summary = [
      'active_members' => count($activeMembers),
      'reported_members' => $reportedCount,
      'bipoc_members' => $bipocCount,
      'percentage' => $reportedCount > 0 ? $bipocCount / $reportedCount : 0.0,
      'distribution' => $distribution,
    ];

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $summary, $expire, ['profile_list', 'user_list', 'civicrm_contact_list']);

    return $summary;
  }

  /**
   * Returns active-member BIPOC snapshot counts using profile ethnicity values.
   *
   * @return array
   *   Array with:
   *   - active_members
   *   - reported_members
   *   - bipoc_members
   *   - percentage
   */
  public function getActiveBipocSnapshot(): array {
    $query = $this->database->select('users_field_data', 'u');
    $query->addField('u', 'uid', 'uid');
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = u.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');
    $query->innerJoin('profile', 'p', "p.uid = u.uid AND p.type = 'main' AND p.status = 1 AND p.is_default = 1");
    $query->leftJoin('profile__field_member_ethnicity', 'eth', 'eth.entity_id = p.profile_id AND eth.deleted = 0');
    $query->addField('eth', 'field_member_ethnicity_value', 'ethnicity_value');
    $query->condition('u.status', 1);

    $valuesByUid = [];
    foreach ($query->execute() as $record) {
      $uid = (int) ($record->uid ?? 0);
      if ($uid <= 0) {
        continue;
      }
      if (!isset($valuesByUid[$uid])) {
        $valuesByUid[$uid] = [];
      }
      $valuesByUid[$uid][] = (string) ($record->ethnicity_value ?? '');
    }

    $activeMembers = count($valuesByUid);
    $reportedMembers = 0;
    $bipocMembers = 0;
    foreach ($valuesByUid as $rawValues) {
      $classification = $this->classifyEthnicityValues($rawValues);
      if (!empty($classification['reported'])) {
        $reportedMembers++;
      }
      if (!empty($classification['bipoc'])) {
        $bipocMembers++;
      }
    }

    return [
      'active_members' => $activeMembers,
      'reported_members' => $reportedMembers,
      'bipoc_members' => $bipocMembers,
      'percentage' => $reportedMembers > 0 ? $bipocMembers / $reportedMembers : 0.0,
    ];
  }

  /**
   * Returns join/cancel BIPOC flow counts for a date range.
   */
  public function getEthnicityFlowSnapshot(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $startTs = $start->setTime(0, 0, 0)->getTimestamp();
    $endTs = $end->setTime(23, 59, 59)->getTimestamp();
    $startDate = $start->format('Y-m-d');
    $endDate = $end->format('Y-m-d');

    $joinQuery = $this->database->select('profile', 'p');
    $joinQuery->addField('p', 'uid', 'uid');
    $joinQuery->leftJoin('profile__field_member_ethnicity', 'eth', 'eth.entity_id = p.profile_id AND eth.deleted = 0');
    $joinQuery->addField('eth', 'field_member_ethnicity_value', 'ethnicity_value');
    $joinQuery->condition('p.type', 'main');
    $joinQuery->condition('p.status', 1);
    $joinQuery->condition('p.is_default', 1);
    $joinQuery->condition('p.created', $startTs, '>=');
    $joinQuery->condition('p.created', $endTs, '<=');

    $cancelQuery = $this->database->select('profile', 'p');
    $cancelQuery->addField('p', 'uid', 'uid');
    $cancelQuery->innerJoin('profile__field_member_end_date', 'end_date', 'end_date.entity_id = p.profile_id AND end_date.deleted = 0');
    $cancelQuery->leftJoin('profile__field_member_ethnicity', 'eth', 'eth.entity_id = p.profile_id AND eth.deleted = 0');
    $cancelQuery->addField('eth', 'field_member_ethnicity_value', 'ethnicity_value');
    $cancelQuery->condition('p.type', 'main');
    $cancelQuery->condition('p.status', 1);
    $cancelQuery->condition('p.is_default', 1);
    $cancelQuery->condition('end_date.field_member_end_date_value', $startDate, '>=');
    $cancelQuery->condition('end_date.field_member_end_date_value', $endDate, '<=');

    $joinValuesByUid = [];
    foreach ($joinQuery->execute() as $record) {
      $uid = (int) ($record->uid ?? 0);
      if ($uid <= 0) {
        continue;
      }
      if (!isset($joinValuesByUid[$uid])) {
        $joinValuesByUid[$uid] = [];
      }
      $joinValuesByUid[$uid][] = (string) ($record->ethnicity_value ?? '');
    }

    $cancelValuesByUid = [];
    foreach ($cancelQuery->execute() as $record) {
      $uid = (int) ($record->uid ?? 0);
      if ($uid <= 0) {
        continue;
      }
      if (!isset($cancelValuesByUid[$uid])) {
        $cancelValuesByUid[$uid] = [];
      }
      $cancelValuesByUid[$uid][] = (string) ($record->ethnicity_value ?? '');
    }

    $joinReported = 0;
    $joinBipoc = 0;
    foreach ($joinValuesByUid as $rawValues) {
      $classification = $this->classifyEthnicityValues($rawValues);
      if (!empty($classification['reported'])) {
        $joinReported++;
      }
      if (!empty($classification['bipoc'])) {
        $joinBipoc++;
      }
    }

    $cancelReported = 0;
    $cancelBipoc = 0;
    foreach ($cancelValuesByUid as $rawValues) {
      $classification = $this->classifyEthnicityValues($rawValues);
      if (!empty($classification['reported'])) {
        $cancelReported++;
      }
      if (!empty($classification['bipoc'])) {
        $cancelBipoc++;
      }
    }

    return [
      'joins_total' => count($joinValuesByUid),
      'joins_reported' => $joinReported,
      'joins_bipoc' => $joinBipoc,
      'joins_bipoc_rate' => $joinReported > 0 ? $joinBipoc / $joinReported : 0.0,
      'cancels_total' => count($cancelValuesByUid),
      'cancels_reported' => $cancelReported,
      'cancels_bipoc' => $cancelBipoc,
      'cancels_bipoc_rate' => $cancelReported > 0 ? $cancelBipoc / $cancelReported : 0.0,
    ];
  }

  /**
   * Returns ethnicity values counted toward the BIPOC percentage.
   */
  protected function getBipocEthnicityValues(): array {
    return [
      'asian',
      'black',
      'middleeast',
      'mena',
      'hispanic',
      'native',
      'aian',
      'islander',
      'nhpi',
      'multi',
      'other',
    ];
  }

  /**
   * Returns ethnicity values ignored for reporting purposes.
   */
  protected function getIgnoredEthnicityValues(): array {
    return [
      '',
      'decline',
      'prefer_not_to_say',
      'prefer_not_to_disclose',
      'not_specified',
    ];
  }

  /**
   * Builds a query selecting active Drupal members linked to CiviCRM contacts.
   */
  protected function buildActiveMemberContactQuery(): SelectInterface {
    $query = $this->database->select('users_field_data', 'u');
    $query->addField('c', 'id');
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = u.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');
    $query->innerJoin('civicrm_uf_match', 'ufm', 'ufm.uf_id = u.uid');
    $query->innerJoin('civicrm_contact', 'c', 'c.id = ufm.contact_id');
    $query->condition('u.status', 1);
    $query->condition('c.is_deleted', 0);
    $query->distinct();
    return $query;
  }

  /**
   * Breaks multi-select custom field values into individual entries.
   */
  protected function explodeCustomFieldValues(?string $value): array {
    if ($value === NULL || $value === '') {
      return [];
    }
    $normalized = str_replace(["\x01", "\x02", '|'], ',', $value);
    $parts = array_map('trim', array_filter(explode(',', $normalized), static fn($part) => $part !== ''));
    if (!$parts) {
      return [$value];
    }
    return $parts;
  }

  /**
   * Determines the ethnicity custom field metadata and caches it.
   */
  protected function getEthnicityFieldMetadata(): array {
    if ($this->ethnicityFieldMetadata !== NULL) {
      return $this->ethnicityFieldMetadata;
    }
    $schema = $this->database->schema();
    $metadata = [];
    if ($schema->tableExists('civicrm_value_demographics_15')) {
      foreach (['custom_46', 'ethnicity_46'] as $candidate) {
        if ($schema->fieldExists('civicrm_value_demographics_15', $candidate)) {
          $metadata = [
            'table' => 'civicrm_value_demographics_15',
            'column' => $candidate,
          ];
          break;
        }
      }
    }
    return $this->ethnicityFieldMetadata = $metadata;
  }

  /**
   * Normalizes raw custom field values into display labels.
   */
  protected function normalizeEthnicityValues(?string $rawValue, array $ignoredValues = []): array {
    $entries = $this->explodeCustomFieldValues($rawValue);
    $labels = [];
    foreach ($entries as $entry) {
      $normalized = strtolower(trim($entry));
      if ($normalized === '' || in_array($normalized, $ignoredValues, TRUE)) {
        continue;
      }
      $labels[] = $this->mapEthnicityCodeToLabel($normalized);
    }
    return $labels;
  }

  /**
   * Maps stored ethnicity codes to readable labels.
   */
  protected function mapEthnicityCodeToLabel(string $code): string {
    $map = [
      'asian' => 'Asian',
      'black' => 'Black / African American',
      'middleeast' => 'Middle Eastern / North African',
      'mena' => 'Middle Eastern / North African',
      'hispanic' => 'Hispanic / Latino',
      'native' => 'American Indian / Alaska Native',
      'aian' => 'American Indian / Alaska Native',
      'islander' => 'Native Hawaiian / Pacific Islander',
      'nhpi' => 'Native Hawaiian / Pacific Islander',
      'white' => 'White',
      'multi' => 'Multiracial',
      'other' => 'Other',
      'prefer_not_to_say' => 'Prefer not to say',
      'prefer_not_to_disclose' => 'Prefer not to disclose',
      'decline' => 'Prefer not to say',
      'not_specified' => 'Unspecified',
    ];
    return $map[$code] ?? $this->formatLabel($code, 'Unspecified');
  }

  /**
   * Classifies raw ethnicity values as reported/BIPOC.
   */
  protected function classifyEthnicityValues(array $rawValues): array {
    $normalizedValues = [];
    foreach ($rawValues as $rawValue) {
      $entries = $this->explodeCustomFieldValues((string) $rawValue);
      foreach ($entries as $entry) {
        $normalized = strtolower(trim($entry));
        if ($normalized === '') {
          continue;
        }
        $normalizedValues[$normalized] = TRUE;
      }
    }

    $ignored = $this->getIgnoredEthnicityValues();
    $filtered = [];
    foreach (array_keys($normalizedValues) as $value) {
      if (in_array($value, $ignored, TRUE)) {
        continue;
      }
      $filtered[] = $value;
    }

    if (empty($filtered)) {
      return [
        'reported' => FALSE,
        'bipoc' => FALSE,
      ];
    }

    foreach ($filtered as $value) {
      if ($this->isBipocEthnicityValue($value)) {
        return [
          'reported' => TRUE,
          'bipoc' => TRUE,
        ];
      }
    }

    return [
      'reported' => TRUE,
      'bipoc' => FALSE,
    ];
  }

  /**
   * Determines whether a normalized ethnicity value should count as BIPOC.
   */
  protected function isBipocEthnicityValue(string $value): bool {
    $value = strtolower(trim($value));
    if ($value === '') {
      return FALSE;
    }

    $bipocValues = $this->getBipocEthnicityValues();
    if (in_array($value, $bipocValues, TRUE)) {
      return TRUE;
    }

    return !str_contains($value, 'white');
  }

}
