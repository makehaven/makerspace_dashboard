<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

/**
 * Provides aggregation helpers for utilization metrics.
 */
class UtilizationDataService {

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
      'one_per_month' => 0,
      'two_to_three' => 0,
      'weekly' => 0,
      'twice_weekly' => 0,
      'daily_plus' => 0,
    ];

    foreach ($results as $record) {
      $visits = (int) $record->visit_days;
      if ($visits <= 1) {
        $buckets['one_per_month']++;
      }
      elseif ($visits <= 3) {
        $buckets['two_to_three']++;
      }
      elseif ($visits <= 6) {
        $buckets['weekly']++;
      }
      elseif ($visits <= 12) {
        $buckets['twice_weekly']++;
      }
      else {
        $buckets['daily_plus']++;
      }
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cid, $buckets, $expire, ['access_control_log_list', 'user_list']);

    return $buckets;
  }

}
