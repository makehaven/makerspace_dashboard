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
    $query = $this->database->select('user__roles', 'ur');
    $query->fields('ur', ['entity_id']);
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');
    $query->distinct();

    $uids = [];
    foreach ($query->execute()->fetchCol() as $uid) {
      $uids[] = (int) $uid;
    }
    return $uids;
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

}
