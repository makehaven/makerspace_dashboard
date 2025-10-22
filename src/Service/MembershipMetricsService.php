<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

/**
 * Computes membership recruitment, churn, and retention metrics.
 */
class MembershipMetricsService {

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend for aggregations.
   */
  protected CacheBackendInterface $cache;

  /**
   * Time service.
   */
  protected TimeInterface $time;

  /**
   * Member role machine names treated as currently active.
   */
  protected array $memberRoles;

  /**
   * Cache lifetime in seconds.
   */
  protected int $ttl;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, TimeInterface $time, array $member_roles = NULL, int $ttl = 1800) {
    $this->database = $database;
    $this->cache = $cache;
    $this->time = $time;
    $this->memberRoles = $member_roles ?: ['current_member', 'member'];
    $this->ttl = $ttl;
  }

  /**
   * Returns membership inflow/outflow counts grouped by type and period.
   *
   * @return array
   *   Array with incoming and ending keys.
   */
  public function getFlow(\DateTimeImmutable $start, \DateTimeImmutable $end, string $granularity = 'month'): array {
    $granularity = $this->normalizeGranularity($granularity);
    $startKey = $start->format('Y-m-d');
    $endKey = $end->format('Y-m-d');
    $cacheId = sprintf('makerspace_dashboard:membership:flow:%s:%s:%s', $startKey, $endKey, $granularity);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $incoming = $this->aggregateMembershipEvents('join', $startKey, $endKey, $granularity);
    $ending = $this->aggregateMembershipEvents('end', $startKey, $endKey, $granularity);

    $result = [
      'incoming' => $incoming,
      'ending' => $ending,
    ];

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $result, $expire, ['profile_list', 'user_list']);

    return $result;
  }

  /**
   * Returns annual cohort retention metrics between the given years.
   */
  public function getAnnualCohorts(int $startYear, int $endYear): array {
    if ($startYear > $endYear) {
      [$startYear, $endYear] = [$endYear, $startYear];
    }
    $cacheId = sprintf('makerspace_dashboard:membership:cohorts:%d:%d', $startYear, $endYear);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $query = $this->database->select('profile', 'p');
    $query->innerJoin('profile__field_member_join_date', 'join_date', 'join_date.entity_id = p.profile_id AND join_date.deleted = 0');
    $query->leftJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->fields('p', ['uid']);
    $query->addField('join_date', 'field_member_join_date_value', 'join_date_value');
    $query->condition('join_date.field_member_join_date_value', [$startYear . '-01-01', $endYear . '-12-31'], 'BETWEEN');
    $query->condition('u.status', 1);

    $rows = $query->execute()->fetchAll();

    $activeUids = $this->loadActiveMemberUids();
    $now = (int) $this->time->getRequestTime();
    $currentYear = (int) date('Y', $now);

    $cohorts = [];
    foreach ($rows as $row) {
      $joinDate = $row->join_date_value;
      if (!$joinDate) {
        continue;
      }
      [$year] = explode('-', $joinDate);
      $year = (int) $year;
      if ($year < $startYear || $year > $endYear) {
        continue;
      }
      $uid = (int) $row->uid;
      if (!isset($cohorts[$year])) {
        $cohorts[$year] = [
          'total' => 0,
          'active' => 0,
        ];
      }
      $cohorts[$year]['total']++;
      if (isset($activeUids[$uid])) {
        $cohorts[$year]['active']++;
      }
    }

    ksort($cohorts);
    $output = [];
    foreach ($cohorts as $year => $data) {
      $total = $data['total'];
      $active = $data['active'];
      $inactive = max(0, $total - $active);
      $retention = $total > 0 ? ($active / $total) * 100 : 0.0;
      $yearsElapsed = max(1, $currentYear - $year + 1);
      $annualized = $total > 0 ? pow(max(0.0001, $active / $total), 1 / $yearsElapsed) * 100 : 0.0;
      $output[] = [
        'year' => $year,
        'joined' => $total,
        'active' => $active,
        'inactive' => $inactive,
        'retention_percent' => round($retention, 2),
        'annualized_retention_percent' => round($annualized, 2),
      ];
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $output, $expire, ['profile_list', 'user_list']);

    return $output;
  }

  /**
   * Aggregates joins or ends by membership type and period.
   */
  protected function aggregateMembershipEvents(string $mode, string $startDate, string $endDate, string $granularity): array {
    $tables = [
      'join' => ['table' => 'profile__field_member_join_date', 'alias' => 'join_date', 'column' => 'field_member_join_date_value'],
      'end' => ['table' => 'profile__field_member_end_date', 'alias' => 'end_date', 'column' => 'field_member_end_date_value'],
    ];
    if (!isset($tables[$mode])) {
      throw new \InvalidArgumentException(sprintf('Unsupported membership event type: %s', $mode));
    }

    $tableInfo = $tables[$mode];
    $tableAlias = $tableInfo['alias'];
    $sourceField = $tableInfo['column'];
    $tableName = $tableInfo['table'];

    $periodExpression = $this->buildPeriodExpression($tableAlias . '.' . $sourceField, $granularity);

    $query = $this->database->select('profile', 'p');
    $query->innerJoin($tableName, $tableAlias, "$tableAlias.entity_id = p.profile_id AND $tableAlias.deleted = 0");
    $query->leftJoin('profile__field_membership_type', 'membership_type', 'membership_type.entity_id = p.profile_id AND membership_type.deleted = 0');
    $query->leftJoin('taxonomy_term_field_data', 'term', 'term.tid = membership_type.field_membership_type_target_id');

    $query->addExpression($periodExpression, 'period_key');
    $query->addExpression("COALESCE(term.name, 'Unknown')", 'membership_type');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'total_members');

    $query->where("STR_TO_DATE($tableAlias.$sourceField, '%Y-%m-%d') BETWEEN STR_TO_DATE(:start_date, '%Y-%m-%d') AND STR_TO_DATE(:end_date, '%Y-%m-%d')", [
      ':start_date' => $startDate,
      ':end_date' => $endDate,
    ]);

    $query->groupBy('period_key');
    $query->groupBy('membership_type');

    $query->orderBy('period_key', 'ASC');
    $query->orderBy('membership_type', 'ASC');

    $rows = $query->execute()->fetchAll();

    $results = [];
    foreach ($rows as $row) {
      $results[] = [
        'period' => $row->period_key,
        'membership_type' => $row->membership_type,
        'count' => (int) $row->total_members,
      ];
    }

    return $results;
  }

  /**
   * Builds SQL expression for period grouping.
   */
  protected function buildPeriodExpression(string $column, string $granularity): string {
    $date = "STR_TO_DATE($column, '%Y-%m-%d')";
    switch ($granularity) {
      case 'day':
        return "DATE_FORMAT($date, '%Y-%m-%d')";

      case 'quarter':
        return "CONCAT(DATE_FORMAT($date, '%Y'), '-Q', QUARTER($date))";

      case 'year':
        return "DATE_FORMAT($date, '%Y-01-01')";

      case 'month':
      default:
        return "DATE_FORMAT($date, '%Y-%m-01')";
    }
  }

  /**
   * Returns map of active member user IDs.
   */
  protected function loadActiveMemberUids(): array {
    $query = $this->database->select('user__roles', 'ur');
    $query->fields('ur', ['entity_id']);
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');
    $query->distinct();
    $uids = [];
    foreach ($query->execute()->fetchCol() as $uid) {
      $uids[(int) $uid] = TRUE;
    }
    return $uids;
  }

  /**
   * Normalizes granularity input.
   */
  protected function normalizeGranularity(string $granularity): string {
    $granularity = strtolower($granularity);
    return in_array($granularity, ['day', 'month', 'quarter', 'year'], TRUE) ? $granularity : 'month';
  }

}
