<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides aggregation helpers for utilization metrics.
 */
class UtilizationDataService {

  use StringTranslationTrait;

  protected const SECONDS_PER_DAY = 86400;
  protected const SECONDS_PER_MONTH = 2629743; // Average month length in seconds.

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend for expensive aggregates.
   */
  protected CacheBackendInterface $cache;

  /**
   * Current time service.
   */
  protected TimeInterface $time;

  /**
   * Cache lifetime in seconds.
   */
  protected int $ttl;

  /**
   * Role IDs that indicate an active member.
   */
  protected array $memberRoles;

  /**
   * Cached list of active member IDs.
   *
   * @var int[]|null
   */
  protected ?array $activeMemberCache = NULL;

  /**
   * Cached maps of last visit timestamps keyed by as-of timestamp.
   *
   * @var array<int, array<int, int>>
   */
  protected array $lastVisitCache = [];

  /**
   * Constructs the data service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, TimeInterface $time, int $ttl = 900, array $member_roles = NULL) {
    $this->database = $database;
    $this->cache = $cache;
    $this->time = $time;
    $this->ttl = $ttl;
    $this->memberRoles = $member_roles ?: ['current_member', 'member'];
  }

  /**
   * Aggregates daily unique member entries between timestamps.
   *
   * @return array
   *   Array keyed by Y-m-d date string with integer counts.
   */
  public function getDailyUniqueEntries(int $startTimestamp, int $endTimestamp): array {
    $cid = sprintf('makerspace_dashboard:utilization:daily:%d:%d', $startTimestamp, $endTimestamp);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('access_control_log_field_data', 'acl');
    $query->addExpression("DATE(FROM_UNIXTIME(acl.created))", 'day');
    $query->addExpression('COUNT(DISTINCT user_ref.field_access_request_user_target_id)', 'unique_members');
    $query->condition('acl.type', 'access_control_request');
    $query->condition('acl.created', $startTimestamp, '>=');
    $query->condition('acl.created', $endTimestamp, '<=');

    $query->innerJoin('access_control_log__field_access_request_user', 'user_ref', 'user_ref.entity_id = acl.id');
    // Only consider members with active membership role.
    $query->innerJoin('user__roles', 'user_roles', 'user_roles.entity_id = user_ref.field_access_request_user_target_id');
    $query->condition('user_roles.roles_target_id', $this->memberRoles, 'IN');

    $query->groupBy('day');
    $query->orderBy('day', 'ASC');

    $results = $query->execute()->fetchAllKeyed();

    $data = [];
    foreach ($results as $day => $count) {
      $data[$day] = (int) $count;
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cid, $data, $expire, ['access_control_log_list', 'user_list']);

    return $data;
  }

  /**
   * Builds visit frequency buckets based on distinct visit days per member.
   *
   * @return array
   *   Associative array keyed by machine bucket id with counts.
   */
  public function getVisitFrequencyBuckets(int $startTimestamp, int $endTimestamp): array {
    $cid = sprintf('makerspace_dashboard:utilization:frequency:%d:%d', $startTimestamp, $endTimestamp);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('access_control_log_field_data', 'acl');
    $query->addExpression('user_ref.field_access_request_user_target_id', 'uid');
    $query->addExpression('COUNT(DISTINCT DATE(FROM_UNIXTIME(acl.created)))', 'visit_days');
    $query->condition('acl.type', 'access_control_request');
    $query->condition('acl.created', $startTimestamp, '>=');
    $query->condition('acl.created', $endTimestamp, '<=');

    $query->innerJoin('access_control_log__field_access_request_user', 'user_ref', 'user_ref.entity_id = acl.id');
    $query->innerJoin('user__roles', 'user_roles', 'user_roles.entity_id = user_ref.field_access_request_user_target_id');
    $query->condition('user_roles.roles_target_id', $this->memberRoles, 'IN');

    $query->groupBy('uid');

    $results = $query->execute();

    $buckets = [
      'no_visits' => 0,
      'one_per_month' => 0,
      'two_to_three' => 0,
      'weekly' => 0,
      'twice_weekly' => 0,
      'daily_plus' => 0,
    ];

    $visitedUids = [];
    $windowDays = max(1, (int) ceil(($endTimestamp - $startTimestamp + 1) / 86400));

    foreach ($results as $record) {
      $visits = (int) $record->visit_days;
      $uid = (int) $record->uid;
      $visitedUids[$uid] = TRUE;

      $normalized = ($visits / $windowDays) * 30;
      if ($normalized <= 0.5) {
        $buckets['one_per_month']++;
      }
      elseif ($normalized <= 3) {
        $buckets['two_to_three']++;
      }
      elseif ($normalized <= 6) {
        $buckets['weekly']++;
      }
      elseif ($normalized <= 12) {
        $buckets['twice_weekly']++;
      }
      else {
        $buckets['daily_plus']++;
      }
    }

    $activeMembers = $this->loadActiveMemberUids();
    $noVisitCount = max(0, count($activeMembers) - count($visitedUids));
    $buckets['no_visits'] = $noVisitCount;

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cid, $buckets, $expire, ['access_control_log_list', 'user_list']);

    return $buckets;
  }

  /**
   * Computes average entry counts per hour of day across a time window.
   *
   * @return array
   *   Array with averages keyed by hour, plus metadata.
   */
  public function getAverageEntriesByHour(int $startTimestamp, int $endTimestamp): array {
    $cid = sprintf('makerspace_dashboard:utilization:hourly_average:%d:%d', $startTimestamp, $endTimestamp);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('access_control_log_field_data', 'acl');
    $query->addExpression("DATE(FROM_UNIXTIME(acl.created))", 'day_key');
    $query->addExpression('HOUR(FROM_UNIXTIME(acl.created))', 'hour_key');
    $query->addExpression('COUNT(*)', 'entry_count');
    $query->condition('acl.type', 'access_control_request');
    $query->condition('acl.created', $startTimestamp, '>=');
    $query->condition('acl.created', $endTimestamp, '<=');

    $query->innerJoin('access_control_log__field_access_request_user', 'user_ref', 'user_ref.entity_id = acl.id AND user_ref.deleted = 0');
    $query->innerJoin('user__roles', 'user_roles', 'user_roles.entity_id = user_ref.field_access_request_user_target_id');
    $query->condition('user_roles.roles_target_id', $this->memberRoles, 'IN');
    $query->innerJoin('users_field_data', 'u', 'u.uid = user_ref.field_access_request_user_target_id');
    $query->condition('u.status', 1);

    $query->groupBy('day_key');
    $query->groupBy('hour_key');
    $query->orderBy('day_key', 'ASC');
    $query->orderBy('hour_key', 'ASC');

    $results = $query->execute();

    $hourTotals = array_fill(0, 24, 0.0);
    $daysWithActivity = [];
    foreach ($results as $record) {
      $hour = (int) $record->hour_key;
      if ($hour < 0 || $hour > 23) {
        continue;
      }
      $count = (int) $record->entry_count;
      $hourTotals[$hour] += $count;
      $daysWithActivity[$record->day_key] = TRUE;
    }

    $rangeDays = max(1, (int) floor(max(0, $endTimestamp - $startTimestamp) / 86400) + 1);
    $averages = [];
    foreach ($hourTotals as $hour => $total) {
      $averages[$hour] = round($total / $rangeDays, 2);
    }

    $data = [
      'averages' => $averages,
      'days' => $rangeDays,
      'active_days' => count($daysWithActivity),
      'total_entries' => array_sum($hourTotals),
    ];

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cid, $data, $expire, ['access_control_log_list', 'user_list']);

    return $data;
  }

  /**
   * Builds weekday/time-of-day buckets for first entries.
   */
  public function getFirstEntryBucketsByWeekday(int $startTimestamp, int $endTimestamp): array {
    $cid = sprintf('makerspace_dashboard:utilization:first-entry:%d:%d', $startTimestamp, $endTimestamp);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('access_control_log_field_data', 'acl');
    $query->addField('acl', 'created');
    $query->innerJoin('access_control_log__field_access_request_user', 'user_ref', 'user_ref.entity_id = acl.id');
    $query->addField('user_ref', 'field_access_request_user_target_id', 'uid');
    $query->innerJoin('user__roles', 'user_roles', 'user_roles.entity_id = user_ref.field_access_request_user_target_id');
    $query->condition('user_roles.roles_target_id', $this->memberRoles, 'IN');
    $query->condition('acl.type', 'access_control_request');
    $query->condition('acl.created', $startTimestamp, '>=');
    $query->condition('acl.created', $endTimestamp, '<=');
    $query->orderBy('acl.created', 'ASC');

    $results = $query->execute();

    $buckets = [];
    foreach (range(0, 6) as $weekday) {
      $buckets[$weekday] = array_fill_keys(array_keys($this->timeOfDayBuckets()), 0);
    }

    $seen = [];
    foreach ($results as $record) {
      $timestamp = (int) $record->created;
      $uid = (int) $record->uid;
      $dayKey = date('Y-m-d', $timestamp);
      $weekday = (int) date('w', $timestamp);
      $bucketId = $this->resolveTimeOfDayBucket($timestamp);
      $uniqueKey = $uid . ':' . $dayKey . ':' . $bucketId;
      if (isset($seen[$uniqueKey])) {
        continue;
      }
      $seen[$uniqueKey] = TRUE;
      if (!isset($buckets[$weekday][$bucketId])) {
        $buckets[$weekday][$bucketId] = 0;
      }
      $buckets[$weekday][$bucketId]++;
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cid, $buckets, $expire, ['access_control_log_list', 'user_list']);

    return $buckets;
  }

  /**
   * Builds bucket counts for months since each active member last visited.
   */
  public function getMonthsSinceLastVisitBuckets(int $asOfTimestamp): array {
    $bucketDefinitions = $this->visitRecencyBuckets();
    $buckets = array_fill_keys(array_keys($bucketDefinitions), 0);
    $activeMembers = $this->loadActiveMemberUids();
    if (empty($activeMembers)) {
      return [
        'buckets' => $buckets,
        'active_members' => 0,
        'with_recent_visit' => 0,
        'as_of' => $asOfTimestamp,
      ];
    }

    $cid = sprintf('makerspace_dashboard:utilization:last_visit_recency:%d', $asOfTimestamp);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $lastVisits = $this->loadLastVisitMap($asOfTimestamp);
    $withVisit = 0;
    foreach ($activeMembers as $uid) {
      $bucketId = 'never';
      if (isset($lastVisits[$uid])) {
        $withVisit++;
        $months = $this->diffInMonths($lastVisits[$uid], $asOfTimestamp);
        $bucketId = $this->resolveBucket($bucketDefinitions, $months, 'min_months', 'max_months', ['never']) ?? $bucketId;
      }
      $buckets[$bucketId] = ($buckets[$bucketId] ?? 0) + 1;
    }

    $result = [
      'buckets' => $buckets,
      'active_members' => count($activeMembers),
      'with_recent_visit' => $withVisit,
      'as_of' => $asOfTimestamp,
    ];

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cid, $result, $expire, ['access_control_log_list', 'user_list']);

    return $result;
  }

  /**
   * Builds inactivity buckets showing days between last visit and cancellation.
   */
  public function getCancellationInactivityBuckets(int $asOfTimestamp, int $lookbackMonths = 12): array {
    $bucketDefinitions = $this->cancellationLagBuckets();
    $buckets = array_fill_keys(array_keys($bucketDefinitions), 0);

    $timezone = new \DateTimeZone(date_default_timezone_get());
    $endDate = (new \DateTimeImmutable('@' . $asOfTimestamp))->setTimezone($timezone)->setTime(0, 0, 0);
    $startDate = $endDate->modify(sprintf('-%d months', max(0, $lookbackMonths - 1)))->setTime(0, 0, 0);
    $startKey = $startDate->format('Y-m-d');
    $endKey = $endDate->format('Y-m-d');

    $cid = sprintf('makerspace_dashboard:utilization:cancellation_lag:%s:%s', $startKey, $endKey);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('profile', 'p');
    $query->innerJoin('profile__field_member_end_date', 'end_date', 'end_date.entity_id = p.profile_id AND end_date.deleted = 0');
    $query->leftJoin('access_control_log__field_access_request_user', 'user_ref', 'user_ref.field_access_request_user_target_id = p.uid AND user_ref.deleted = 0');
    $query->leftJoin('access_control_log_field_data', 'acl', "acl.id = user_ref.entity_id AND acl.type = 'access_control_request' AND acl.created <= UNIX_TIMESTAMP(STR_TO_DATE(end_date.field_member_end_date_value, '%Y-%m-%d'))");
    $query->addField('p', 'uid');
    $query->addField('end_date', 'field_member_end_date_value', 'end_value');
    $query->addExpression("UNIX_TIMESTAMP(STR_TO_DATE(end_date.field_member_end_date_value, '%Y-%m-%d'))", 'end_timestamp');
    $query->addExpression('MAX(acl.created)', 'last_visit');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->where("STR_TO_DATE(end_date.field_member_end_date_value, '%Y-%m-%d') BETWEEN STR_TO_DATE(:start_date, '%Y-%m-%d') AND STR_TO_DATE(:end_date, '%Y-%m-%d')", [
      ':start_date' => $startKey,
      ':end_date' => $endKey,
    ]);
    $query->groupBy('p.uid');
    $query->groupBy('end_date.field_member_end_date_value');

    $results = $query->execute();

    $delays = [];
    $total = 0;
    foreach ($results as $row) {
      $endTs = (int) $row->end_timestamp;
      if (!$endTs) {
        continue;
      }
      $total++;
      if ($row->last_visit === NULL) {
        $buckets['never']++;
        continue;
      }
      $lagDays = $this->diffInDays((int) $row->last_visit, $endTs);
      $bucketId = $this->resolveBucket($bucketDefinitions, $lagDays, 'min_days', 'max_days', ['never']) ?? '181_plus';
      $buckets[$bucketId] = ($buckets[$bucketId] ?? 0) + 1;
      $delays[] = $lagDays;
    }

    $median = $this->calculateMedian($delays);
    $average = $this->calculateAverage($delays);

    $result = [
      'buckets' => $buckets,
      'start_date' => $startKey,
      'end_date' => $endKey,
      'lookback_months' => $lookbackMonths,
      'sample_size' => $total,
      'with_visit_sample_size' => count($delays),
      'median_days' => $median,
      'average_days' => $average,
    ];

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cid, $result, $expire, ['profile_list', 'access_control_log_list', 'user_list']);

    return $result;
  }

  /**
   * Buckets inactive members (90+ days) by membership tenure.
   */
  public function getInactiveMembersByTenure(int $asOfTimestamp, int $inactiveDaysThreshold = 90): array {
    $bucketDefinitions = $this->inactivityTenureBuckets();
    $buckets = array_fill_keys(array_keys($bucketDefinitions), 0);
    $activeMembers = $this->loadActiveMemberUids();
    if (empty($activeMembers)) {
      return [
        'buckets' => $buckets,
        'inactive_threshold_days' => $inactiveDaysThreshold,
        'as_of' => $asOfTimestamp,
        'total_active' => 0,
        'total_inactive' => 0,
      ];
    }

    $cid = sprintf('makerspace_dashboard:utilization:inactive_by_tenure:%d:%d', $asOfTimestamp, $inactiveDaysThreshold);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $lastVisits = $this->loadLastVisitMap($asOfTimestamp);
    $joinDates = $this->loadMemberJoinDates($activeMembers);

    $inactiveCount = 0;
    foreach ($activeMembers as $uid) {
      $lastVisit = $lastVisits[$uid] ?? NULL;
      $daysInactive = $lastVisit ? $this->diffInDays($lastVisit, $asOfTimestamp) : PHP_INT_MAX;
      if ($daysInactive < $inactiveDaysThreshold) {
        continue;
      }
      $inactiveCount++;
      if (!isset($joinDates[$uid])) {
        $buckets['unknown']++;
        continue;
      }
      $tenureMonths = $this->diffInMonths($joinDates[$uid], $asOfTimestamp);
      $bucketId = $this->resolveBucket($bucketDefinitions, $tenureMonths, 'min_months', 'max_months', ['unknown']) ?? 'unknown';
      $buckets[$bucketId] = ($buckets[$bucketId] ?? 0) + 1;
    }

    $result = [
      'buckets' => $buckets,
      'inactive_threshold_days' => $inactiveDaysThreshold,
      'as_of' => $asOfTimestamp,
      'total_active' => count($activeMembers),
      'total_inactive' => $inactiveCount,
    ];

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cid, $result, $expire, ['profile_list', 'access_control_log_list', 'user_list']);

    return $result;
  }

  /**
   * Returns translated labels for time-of-day buckets.
   */
  public function getTimeOfDayBucketLabels(): array {
    $labels = [];
    foreach ($this->timeOfDayBuckets() as $id => $info) {
      $labels[$id] = $info['label'];
    }
    return $labels;
  }

  /**
   * Returns all active member IDs.
   */
  protected function loadActiveMemberUids(): array {
    if ($this->activeMemberCache !== NULL) {
      return $this->activeMemberCache;
    }

    $query = $this->database->select('user__roles', 'ur');
    $query->fields('ur', ['entity_id']);
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');
    $query->distinct();

    $uids = [];
    foreach ($query->execute()->fetchCol() as $uid) {
      $uids[] = (int) $uid;
    }
    return $this->activeMemberCache = $uids;
  }

  /**
   * Loads last visit timestamps keyed by user id.
   */
  protected function loadLastVisitMap(int $asOfTimestamp): array {
    if (isset($this->lastVisitCache[$asOfTimestamp])) {
      return $this->lastVisitCache[$asOfTimestamp];
    }

    $cid = sprintf('makerspace_dashboard:utilization:last_visits:%d', $asOfTimestamp);
    if ($cache = $this->cache->get($cid)) {
      $this->lastVisitCache[$asOfTimestamp] = $cache->data;
      return $cache->data;
    }

    $query = $this->database->select('access_control_log_field_data', 'acl');
    $query->addExpression('user_ref.field_access_request_user_target_id', 'uid');
    $query->addExpression('MAX(acl.created)', 'last_visit');
    $query->innerJoin('access_control_log__field_access_request_user', 'user_ref', 'user_ref.entity_id = acl.id AND user_ref.deleted = 0');
    $query->innerJoin('user__roles', 'user_roles', 'user_roles.entity_id = user_ref.field_access_request_user_target_id');
    $query->innerJoin('users_field_data', 'u', 'u.uid = user_ref.field_access_request_user_target_id');
    $query->condition('user_roles.roles_target_id', $this->memberRoles, 'IN');
    $query->condition('u.status', 1);
    $query->condition('acl.type', 'access_control_request');
    $query->condition('acl.created', $asOfTimestamp, '<=');
    $query->groupBy('uid');

    $map = [];
    foreach ($query->execute() as $record) {
      if ($record->last_visit === NULL) {
        continue;
      }
      $map[(int) $record->uid] = (int) $record->last_visit;
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cid, $map, $expire, ['access_control_log_list', 'user_list']);
    $this->lastVisitCache[$asOfTimestamp] = $map;

    return $map;
  }

  /**
   * Loads join-date timestamps for the provided user ids.
   */
  protected function loadMemberJoinDates(array $uids): array {
    if (empty($uids)) {
      return [];
    }

    $map = [];
    foreach (array_chunk($uids, 500) as $chunk) {
      $query = $this->database->select('profile', 'p');
      $query->innerJoin('profile__field_member_join_date', 'join_date', 'join_date.entity_id = p.profile_id AND join_date.deleted = 0');
      $query->fields('p', ['uid']);
      $query->addField('join_date', 'field_member_join_date_value', 'join_value');
      $query->condition('p.type', 'main');
      $query->condition('p.status', 1);
      $query->condition('p.is_default', 1);
      $query->condition('p.uid', $chunk, 'IN');

      foreach ($query->execute() as $row) {
        $timestamp = $row->join_value ? strtotime($row->join_value) : FALSE;
        if ($timestamp) {
          $map[(int) $row->uid] = (int) $timestamp;
        }
      }
    }

    return $map;
  }

  /**
   * Defines visit recency bucket ranges in months.
   */
  protected function visitRecencyBuckets(): array {
    return [
      '0_1' => ['min_months' => 0, 'max_months' => 1],
      '1_2' => ['min_months' => 1, 'max_months' => 2],
      '2_3' => ['min_months' => 2, 'max_months' => 3],
      '3_6' => ['min_months' => 3, 'max_months' => 6],
      '6_12' => ['min_months' => 6, 'max_months' => 12],
      '12_plus' => ['min_months' => 12, 'max_months' => NULL],
      'never' => ['min_months' => NULL, 'max_months' => NULL],
    ];
  }

  /**
   * Defines cancellation inactivity lag buckets in days.
   */
  protected function cancellationLagBuckets(): array {
    return [
      '0_30' => ['min_days' => 0, 'max_days' => 31],
      '31_60' => ['min_days' => 31, 'max_days' => 61],
      '61_90' => ['min_days' => 61, 'max_days' => 91],
      '91_180' => ['min_days' => 91, 'max_days' => 181],
      '181_plus' => ['min_days' => 181, 'max_days' => NULL],
      'never' => ['min_days' => NULL, 'max_days' => NULL],
    ];
  }

  /**
   * Defines tenure buckets in months for inactive member breakdowns.
   */
  protected function inactivityTenureBuckets(): array {
    return [
      'under_three_months' => ['min_months' => 0, 'max_months' => 3],
      'three_to_twelve_months' => ['min_months' => 3, 'max_months' => 12],
      'one_to_three_years' => ['min_months' => 12, 'max_months' => 36],
      'three_plus_years' => ['min_months' => 36, 'max_months' => NULL],
      'unknown' => ['min_months' => NULL, 'max_months' => NULL],
    ];
  }

  /**
   * Generic helper to resolve a bucket id for a numeric range.
   */
  protected function resolveBucket(array $definitions, float $value, string $minKey, string $maxKey, array $skipKeys = []): ?string {
    foreach ($definitions as $id => $definition) {
      if (in_array($id, $skipKeys, TRUE)) {
        continue;
      }
      $min = $definition[$minKey] ?? NULL;
      $max = $definition[$maxKey] ?? NULL;
      if (($min === NULL || $value >= $min) && ($max === NULL || $value < $max)) {
        return $id;
      }
    }
    return NULL;
  }

  /**
   * Calculates the median for an array of numeric values.
   */
  protected function calculateMedian(array $values): ?float {
    if (empty($values)) {
      return NULL;
    }
    sort($values);
    $count = count($values);
    $mid = intdiv($count, 2);
    if ($count % 2) {
      return (float) $values[$mid];
    }
    return ($values[$mid - 1] + $values[$mid]) / 2;
  }

  /**
   * Calculates the average for an array of numeric values.
   */
  protected function calculateAverage(array $values): ?float {
    if (empty($values)) {
      return NULL;
    }
    return array_sum($values) / count($values);
  }

  /**
   * Returns the difference between two timestamps in months.
   */
  protected function diffInMonths(int $earlierTimestamp, int $laterTimestamp): float {
    $diff = max(0, $laterTimestamp - $earlierTimestamp);
    return $diff / self::SECONDS_PER_MONTH;
  }

  /**
   * Returns the difference between two timestamps in whole days.
   */
  protected function diffInDays(int $earlierTimestamp, int $laterTimestamp): int {
    $diff = max(0, $laterTimestamp - $earlierTimestamp);
    return (int) floor($diff / self::SECONDS_PER_DAY);
  }

  /**
   * Provides time-of-day bucket definitions.
   */
  protected function timeOfDayBuckets(): array {
    return [
      'early_morning' => ['label' => $this->t('Early morning (05:00-08:59)'), 'start' => 5 * 3600, 'end' => 8 * 3600 + 59 * 60 + 59],
      'morning' => ['label' => $this->t('Morning (09:00-11:59)'), 'start' => 9 * 3600, 'end' => 11 * 3600 + 59 * 60 + 59],
      'noon' => ['label' => $this->t('Midday (12:00-13:59)'), 'start' => 12 * 3600, 'end' => 13 * 3600 + 59 * 60 + 59],
      'afternoon' => ['label' => $this->t('Afternoon (14:00-17:59)'), 'start' => 14 * 3600, 'end' => 17 * 3600 + 59 * 60 + 59],
      'evening' => ['label' => $this->t('Evening (18:00-21:59)'), 'start' => 18 * 3600, 'end' => 21 * 3600 + 59 * 60 + 59],
      'night' => ['label' => $this->t('Night (22:00-04:59)'), 'start' => 22 * 3600, 'end' => 4 * 3600 + 59 * 60 + 59],
    ];
  }

  /**
   * Resolves a timestamp into a time-of-day bucket.
   */
  protected function resolveTimeOfDayBucket(int $timestamp): string {
    $buckets = $this->timeOfDayBuckets();
    $timeOfDay = (int) date('H', $timestamp) * 3600 + (int) date('i', $timestamp) * 60 + (int) date('s', $timestamp);

    foreach ($buckets as $id => $info) {
      $start = $info['start'];
      $end = $info['end'];

      if ($start <= $end) {
        if ($timeOfDay >= $start && $timeOfDay <= $end) {
          return $id;
        }
      }
      else {
        if ($timeOfDay >= $start || $timeOfDay <= $end) {
          return $id;
        }
      }
    }

    return 'night';
  }

  /**
   * Gets the annual active participation percentage.
   *
   * @return float
   *   The annual active participation percentage.
   */
  public function getAnnualActiveParticipation(): float {
    // @todo: Implement logic to count unique UIDs with door access logs in Q4
    // and divide by `members_active` in the December snapshot. This will be
    // called by the 'annual' snapshot in the makerspace_snapshot module.
    return 0.65;
  }

}
