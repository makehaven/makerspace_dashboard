<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;

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
   * Badge count buckets for tenure correlation.
   */
  protected array $badgeTenureBuckets = [
    ['id' => 'one', 'min' => 1, 'max' => 1],
    ['id' => 'two_three', 'min' => 2, 'max' => 3],
    ['id' => 'four_five', 'min' => 4, 'max' => 5],
    ['id' => 'six_nine', 'min' => 6, 'max' => 9],
    ['id' => 'ten_plus', 'min' => 10, 'max' => NULL],
  ];

  /**
   * Badge histogram buckets used for simple count charts.
   */
  protected array $badgeCountBuckets = [
    ['id' => 'one', 'min' => 1, 'max' => 1],
    ['id' => 'two_three', 'min' => 2, 'max' => 3],
    ['id' => 'four_five', 'min' => 4, 'max' => 5],
    ['id' => 'six_nine', 'min' => 6, 'max' => 9],
    ['id' => 'ten_plus', 'min' => 10, 'max' => NULL],
  ];

  /**
   * Membership type tenure bucket definitions.
   */
  protected array $membershipTypeTenureBuckets = [
    ['id' => '1yr', 'min_years' => 1, 'max_years' => 1],
    ['id' => '2yr', 'min_years' => 2, 'max_years' => 2],
    ['id' => '3yr', 'min_years' => 3, 'max_years' => 3],
    ['id' => '4yr', 'min_years' => 4, 'max_years' => 4],
    ['id' => '5_plus', 'min_years' => 5, 'max_years' => NULL],
  ];

  /**
   * End reason machine names treated as unpreventable attrition.
   */
  protected array $unpreventableEndReasons;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, TimeInterface $time, ?array $member_roles = NULL, int $ttl = 1800, ?array $unpreventable_end_reasons = NULL) {
    $this->database = $database;
    $this->cache = $cache;
    $this->time = $time;
    $this->memberRoles = $member_roles ?: ['current_member', 'member'];
    $this->ttl = $ttl;
    $this->unpreventableEndReasons = array_map('strtolower', $unpreventable_end_reasons ?? [
      'relocation',
      'predefined',
      '3rdparty',
      'na',
    ]);
  }

  /**
   * Returns a monthly cohort retention matrix.
   *
   * @param int $monthsBack
   *   Number of months to look back for cohorts.
   *
   * @return array
   *   Array of cohorts keyed by 'Y-m', containing 'joined', 'label', and 'retention' map.
   */
  public function getMonthlyCohortRetentionMatrix(int $monthsBack = 24): array {
    $cacheId = sprintf('makerspace_dashboard:membership:cohort_matrix:v2:%d', $monthsBack);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $members = $this->loadMemberTenureRecords();
    $now = (int) $this->time->getRequestTime();
    $cutoff = strtotime("-{$monthsBack} months first day of this month"); // Cohort start cutoff

    $cohorts = [];
    $tz = new \DateTimeZone(date_default_timezone_get());

    foreach ($members as $record) {
      if ($record['end'] === NULL && !$record['has_member_role']) {
        continue; // Ignore this record as per user's instruction
      }
      $joinTs = $record['join'];
      if ($joinTs < $cutoff) {
        continue; // Too old
      }
      
      // Determine cohort key (Join Month)
      $joinDate = (new \DateTimeImmutable('@' . $joinTs))->setTimezone($tz);
      $key = $joinDate->format('Y-m');
      $label = $joinDate->format('M Y');

      if (!isset($cohorts[$key])) {
        $cohorts[$key] = [
          'key' => $key,
          'label' => $label,
          'join_ts' => $joinDate->modify('first day of this month')->getTimestamp(),
          'joined' => 0,
          'survived_counts' => array_fill(0, $monthsBack + 1, 0),
        ];
      }

      $cohorts[$key]['joined']++;

      // Calculate months survived
      $endTs = $record['end'] ?? $now;
      // If endTs is in future (e.g. pre-scheduled end), cap at now? 
      // Usually retention is "is member active at month X".
      // If end_date > join_date + X months, they survived month X.
      
      // We check survival at the *end* of month X? Or start?
      // Month 0 is usually "Joined". Retention 100%.
      // Month 1 is "Still here after 1 month".
      
      // Let's iterate months 0..monthsBack
      for ($m = 0; $m <= $monthsBack; $m++) {
        // Milestone timestamp: Join Month Start + m months
        // Or Join Date + m months? Cohort analysis usually normalizes to relative time.
        // Simple logic: Did they survive m months?
        // Tenure = (End - Join).
        // If Tenure >= m months, they survived.
        
        // Exact logic:
        // Join: Jan 15.
        // Month 1 milestone: Feb 15.
        // If End Date is NULL or >= Feb 15, they retained Month 1.
        
        $milestone = strtotime("+{$m} months", $joinTs);
        
        // If milestone is in the future, we cannot count it yet.
        if ($milestone > $now) {
          continue; 
        }

        if ($record['end'] === NULL || $record['end'] >= $milestone) {
          $cohorts[$key]['survived_counts'][$m]++;
        }
      }
    }

    // Sort cohorts by date
    ksort($cohorts);

    // Format output
    $matrix = [];
    foreach ($cohorts as $key => $data) {
      $row = [
        'label' => $data['label'],
        'joined' => $data['joined'],
        'retention' => [],
      ];
      
      // Calculate percentages
      // Verify cohort age to stop future columns
      $cohortStart = $data['join_ts'];
      
      foreach ($data['survived_counts'] as $m => $count) {
        // Check if this month $m is historically observable for this cohort.
        // Cohort Start + m months.
        // If Cohort is Dec 2025. m=1 is Jan 2026. Future.
        $milestoneStart = strtotime("+{$m} months", $cohortStart);
        if ($milestoneStart > $now) {
          $row['retention'][$m] = NULL;
        } else {
          $pct = $data['joined'] > 0 ? round(($count / $data['joined']) * 100, 1) : 0;
          $row['retention'][$m] = $pct;
        }
      }
      $matrix[] = $row;
    }

    $this->cache->set($cacheId, $matrix, $this->time->getRequestTime() + $this->ttl, ['profile_list']);
    return $matrix;
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

    $totals = $this->buildTotals($incoming, $ending, $granularity);

    $result = [
      'incoming' => $incoming,
      'ending' => $ending,
      'totals' => $totals,
    ];

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $result, $expire, ['profile_list', 'user_list']);

    return $result;
  }

  /**
   * Returns monthly recruitment counts grouped by year and month.
   */
  public function getMonthlyRecruitmentHistory(): array {
    $cacheId = 'makerspace_dashboard:membership:recruitment_history';
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $query = $this->database->select('profile', 'p');
    $query->addExpression("FROM_UNIXTIME(p.created, '%Y')", 'year');
    $query->addExpression("FROM_UNIXTIME(p.created, '%c')", 'month');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'count');

    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->groupBy('year');
    $query->groupBy('month');
    $query->orderBy('year', 'ASC');
    $query->orderBy('month', 'ASC');

    $rows = $query->execute()->fetchAll();

    $data = [];
    foreach ($rows as $row) {
      $data[(int) $row->year][(int) $row->month] = (int) $row->count;
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $data, $expire, ['profile_list', 'user_list']);

    return $data;
  }

  /**
   * Returns membership end reasons grouped by period.
   */
  public function getEndReasonsByPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end, string $granularity = 'month'): array {
    $granularity = $this->normalizeGranularity($granularity);
    $startKey = $start->format('Y-m-d');
    $endKey = $end->format('Y-m-d');
    $cacheId = sprintf('makerspace_dashboard:membership:end_reasons:%s:%s:%s', $startKey, $endKey, $granularity);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('profile__field_member_end_reason')) {
      return [];
    }

    $periodExpression = $this->buildPeriodExpression('end_date.field_member_end_date_value', $granularity);

    $query = $this->database->select('profile', 'p');
    $query->innerJoin('profile__field_member_end_date', 'end_date', 'end_date.entity_id = p.profile_id AND end_date.deleted = 0');
    $query->leftJoin('profile__field_member_end_reason', 'end_reason', 'end_reason.entity_id = p.profile_id AND end_reason.deleted = 0');

    $query->addExpression($periodExpression, 'period_key');
    $query->addExpression("COALESCE(NULLIF(end_reason.field_member_end_reason_value, ''), 'Unknown')", 'reason');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'total_members');

    $query->where("STR_TO_DATE(end_date.field_member_end_date_value, '%Y-%m-%d') BETWEEN STR_TO_DATE(:start_date, '%Y-%m-%d') AND STR_TO_DATE(:end_date, '%Y-%m-%d')", [
      ':start_date' => $startKey,
      ':end_date' => $endKey,
    ]);

    $query->groupBy('period_key');
    $query->groupBy('reason');
    $query->orderBy('period_key', 'ASC');
    $query->orderBy('reason', 'ASC');

    $rows = $query->execute()->fetchAll();

    $result = [];
    foreach ($rows as $row) {
      $result[] = [
        'period' => $row->period_key,
        'reason' => $row->reason,
        'count' => (int) $row->total_members,
      ];
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $result, $expire, ['profile_list', 'user_list']);

    return $result;
  }

  /**
   * Returns annual cohort retention metrics between the given years.
   */
  public function getAnnualCohorts(int $startYear, int $endYear, ?array $filter = NULL): array {
    if ($startYear > $endYear) {
      [$startYear, $endYear] = [$endYear, $startYear];
    }
    $cacheId = sprintf(
      'makerspace_dashboard:membership:cohorts:%d:%d:%s',
      $startYear,
      $endYear,
      $this->buildCohortFilterCacheKey($filter)
    );
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $startTs = strtotime($startYear . '-01-01 00:00:00');
    $endTs = strtotime($endYear . '-12-31 23:59:59');

    $query = $this->database->select('profile', 'p');
    $query->leftJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->fields('p', ['uid', 'created']);
    $query->condition('p.created', [$startTs, $endTs], 'BETWEEN');
    $query->condition('u.status', 1);
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    if (!empty($filter) && !empty($filter['type']) && $filter['value'] !== '' && $filter['value'] !== NULL) {
      $this->applyCohortFilter($query, $filter);
    }

    $query->distinct();

    $rows = $query->execute()->fetchAll();

    $activeUids = $this->loadActiveMemberUids();
    $now = (int) $this->time->getRequestTime();
    $currentYear = (int) date('Y', $now);

    $cohorts = [];
    foreach ($rows as $row) {
      $created = (int) $row->created;
      if ($created <= 0) {
        continue;
      }
      $year = (int) date('Y', $created);
      
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
   * Returns monthly first-year retention metrics for matured cohorts.
   *
   * @param int $months
   *   Number of matured cohorts to return (defaults to 36).
   *
   * @return array
   *   Ordered list of period rows with keys:
   *   - period: Month key (Y-m-01).
   *   - label: Human-readable month label.
   *   - total: Members in the cohort (excludes unpreventable attrition).
   *   - retained: Members still active 12 months after joining.
   *   - retention_percent: Rounded retention percentage.
   *   - evaluation_date: Date the metric was evaluated (join month + 12 months).
   */
  public function getMonthlyFirstYearRetentionSeries(int $months = 36): array {
    $months = max(1, $months);
    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
      ->setTime(0, 0)
      ->modify('first day of this month');
    $lastJoinMonth = $now->modify('-12 months');
    if ($lastJoinMonth < new \DateTimeImmutable('1970-01-01')) {
      return [];
    }

    $cacheId = sprintf(
      'makerspace_dashboard:membership:first_year_retention:%d:%s',
      $months,
      $lastJoinMonth->format('Y-m')
    );
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $startJoinMonth = $lastJoinMonth->modify(sprintf('-%d months', $months - 1));
    $monthBuckets = [];
    $periodEnd = $lastJoinMonth->modify('+1 month');
    $period = new \DatePeriod($startJoinMonth, new \DateInterval('P1M'), $periodEnd);
    foreach ($period as $monthDate) {
      $key = $monthDate->format('Y-m-01');
      $monthBuckets[$key] = [
        'label' => $monthDate->format('M Y'),
        'evaluation' => $monthDate->modify('+12 months'),
        'total' => 0,
        'retained' => 0,
      ];
    }

    if (!$monthBuckets) {
      return [];
    }

    $startTs = strtotime($startJoinMonth->format('Y-m-01 00:00:00'));
    $endTs = strtotime($lastJoinMonth->modify('last day of this month')->format('Y-m-t 23:59:59'));

    $query = $this->database->select('profile', 'p');
    $query->fields('p', ['created']);
    $query->leftJoin('profile__field_member_end_date', 'end_date', 'end_date.entity_id = p.profile_id AND end_date.deleted = 0');
    $query->leftJoin('profile__field_member_end_reason', 'end_reason', 'end_reason.entity_id = p.profile_id AND end_reason.deleted = 0');
    $query->leftJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->addField('end_date', 'field_member_end_date_value', 'end_date_value');
    $query->addField('end_reason', 'field_member_end_reason_value', 'end_reason_value');
    $query->condition('p.created', [$startTs, $endTs], 'BETWEEN');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->condition('u.status', 1);

    $rows = $query->execute()->fetchAll();
    $hasData = FALSE;
    $unpreventableReasons = $this->getUnpreventableEndReasons();
    $tz = new \DateTimeZone(date_default_timezone_get());

    foreach ($rows as $row) {
      $created = (int) $row->created;
      if ($created <= 0) {
        continue;
      }
      try {
        $joinDate = (new \DateTimeImmutable('@' . $created))->setTimezone($tz);
      }
      catch (\Exception $exception) {
        continue;
      }
      $monthKey = $joinDate->format('Y-m-01');
      if (!isset($monthBuckets[$monthKey])) {
        continue;
      }
      $evaluationDate = $monthBuckets[$monthKey]['evaluation'];
      $endValue = $row->end_date_value ?? '';
      $endDate = NULL;
      if (!empty($endValue)) {
        try {
          $endDate = new \DateTimeImmutable($endValue);
        }
        catch (\Exception $exception) {
          $endDate = NULL;
        }
      }
      $endReason = strtolower(trim((string) ($row->end_reason_value ?? '')));
      if ($endReason === '') {
        $endReason = NULL;
      }

      if ($endDate !== NULL && $endDate < $evaluationDate && $endReason !== NULL && in_array($endReason, $unpreventableReasons, TRUE)) {
        continue;
      }

      $monthBuckets[$monthKey]['total']++;
      $hasData = TRUE;

      if ($endDate === NULL || $endDate >= $evaluationDate) {
        $monthBuckets[$monthKey]['retained']++;
      }
    }

    if (!$hasData) {
      $expire = $this->time->getRequestTime() + $this->ttl;
      $this->cache->set($cacheId, [], $expire, ['profile_list', 'user_list']);
      return [];
    }

    $results = [];
    foreach ($monthBuckets as $monthKey => $bucket) {
      if ($bucket['total'] <= 0) {
        continue;
      }
      $percent = $bucket['retained'] > 0 ? round(($bucket['retained'] / $bucket['total']) * 100, 2) : 0.0;
      $results[] = [
        'period' => $monthKey,
        'label' => $bucket['label'],
        'total' => $bucket['total'],
        'retained' => $bucket['retained'],
        'retention_percent' => $percent,
        'evaluation_date' => $bucket['evaluation']->format('Y-m-d'),
      ];
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $results, $expire, ['profile_list', 'user_list']);

    return $results;
  }

  /**
   * Returns the top filter options for cohort breakdown charts.
   */
  public function getCohortFilterOptions(string $dimension, int $limit = 5): array {
    $dimension = strtolower($dimension);
    switch ($dimension) {
      case 'ethnicity':
        return $this->getTopProfileFieldOptions('ethnicity', 'profile__field_member_ethnicity', 'field_member_ethnicity_value', $limit);

      case 'gender':
        return $this->getTopProfileFieldOptions('gender', 'profile__field_member_gender', 'field_member_gender_value', $limit);

      case 'membership_type':
        return $this->getTopMembershipTypeOptions($limit);
    }
    return [];
  }

  /**
   * Builds badge count vs tenure buckets.
   */
  public function getBadgeTenureCorrelation(): array {
    $cacheId = 'makerspace_dashboard:membership:badge_tenure_correlation';
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $members = $this->loadMemberTenureRecords();
    if (empty($members)) {
      return $this->initializeBadgeTenureResults();
    }

    $badgeCounts = $this->loadMemberBadgeCounts(array_keys($members));
    $buckets = $this->initializeBadgeTenureBuckets();
    $now = (int) $this->time->getRequestTime();

    foreach ($members as $uid => $record) {
      $joinTs = $record['join'] ?? NULL;
      $endTs = $record['end'] ?? NULL;
      if (!$joinTs || !$endTs || $endTs <= $joinTs) {
        continue;
      }
      $tenureYears = ($endTs - $joinTs) / 31557600;
      if ($tenureYears <= 0) {
        continue;
      }
      $badgeCount = $badgeCounts[$uid] ?? 0;
      if ($badgeCount <= 0) {
        continue;
      }
      $bucketId = $this->resolveBadgeTenureBucket($badgeCount);
      if (!$bucketId || !isset($buckets[$bucketId])) {
        continue;
      }
      $buckets[$bucketId]['durations'][] = $tenureYears;
    }

    $results = [];
    foreach ($buckets as $bucket) {
      $durations = $bucket['durations'];
      $count = count($durations);
      $average = $count ? array_sum($durations) / $count : NULL;
      $median = $count ? $this->calculateMedian($durations) : NULL;
      $results[] = [
        'bucket_id' => $bucket['id'],
        'badge_min' => $bucket['min'],
        'badge_max' => $bucket['max'],
        'member_count' => $count,
        'average_years' => $average !== NULL ? round($average, 2) : NULL,
        'median_years' => $median !== NULL ? round($median, 2) : NULL,
      ];
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $results, $expire, ['profile_list', 'node_list', 'taxonomy_term_list']);

    return $results;
  }

  /**
   * Returns monthly active member counts for the requested window.
   */
  public function getMonthlyActiveMemberCounts(int $months = 36): array {
    $months = max(1, $months);
    $today = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
      ->setTime(0, 0);
    $currentMonth = $today->modify('first day of this month');
    $startMonth = $currentMonth->modify(sprintf('-%d months', $months - 1));
    $bucketEnd = $today;

    $cacheId = sprintf(
      'makerspace_dashboard:membership:active_counts:%d:%s',
      $months,
      $bucketEnd->format('Y-m-d')
    );
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $buckets = [];
    $period = new \DatePeriod($startMonth, new \DateInterval('P1M'), $currentMonth->modify('+1 month'));
    foreach ($period as $monthDate) {
      $key = $monthDate->format('Y-m-01');
      $monthEnd = $monthDate->modify('last day of this month');
      if ($monthEnd > $bucketEnd) {
        $monthEnd = $bucketEnd;
      }
      $buckets[$key] = [
        'label' => $monthDate->format('M Y'),
        'end' => $monthEnd,
        'count' => 0,
      ];
    }

    if (!$buckets) {
      $this->cache->set($cacheId, [], $this->time->getRequestTime() + $this->ttl, ['profile_list', 'user_list']);
      return [];
    }

    $rangeEnd = end($buckets)['end'];
    reset($buckets);
    $endTs = $rangeEnd->modify('23:59:59')->getTimestamp();

    $query = $this->database->select('profile', 'p');
    $query->fields('p', ['created']);
    $query->leftJoin('profile__field_member_end_date', 'end_date', 'end_date.entity_id = p.profile_id AND end_date.deleted = 0');
    $query->leftJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->addField('end_date', 'field_member_end_date_value', 'end_date_value');
    $query->condition('p.type', 'main');
    $query->condition('p.is_default', 1);
    $query->condition('u.status', 1);
    $query->condition('p.created', $endTs, '<=');

    $rows = $query->execute()->fetchAll();
    $tz = new \DateTimeZone(date_default_timezone_get());

    foreach ($rows as $row) {
      $created = (int) $row->created;
      if ($created <= 0) {
        continue;
      }
      try {
        $joinDate = (new \DateTimeImmutable('@' . $created))->setTimezone($tz);
      }
      catch (\Exception $exception) {
        continue;
      }

      $endValue = $row->end_date_value ?? '';
      $endDate = NULL;
      if ($endValue !== '') {
        try {
          $endDate = new \DateTimeImmutable($endValue);
        }
        catch (\Exception $exception) {
          $endDate = NULL;
        }
      }

      foreach ($buckets as $key => &$bucket) {
        if ($joinDate > $bucket['end']) {
          continue;
        }
        if ($endDate !== NULL && $endDate <= $bucket['end']) {
          continue;
        }
        $bucket['count']++;
      }
      unset($bucket);
    }

    $results = [];
    foreach ($buckets as $key => $bucket) {
      $results[] = [
        'period' => $key,
        'label' => $bucket['label'],
        'count' => (int) $bucket['count'],
        'date' => $bucket['end']->format('Y-m-d'),
      ];
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $results, $expire, ['profile_list', 'user_list']);
    return $results;
  }

  /**
   * Calculates tenure buckets grouped by membership type.
   */
  public function getMembershipTypeTenureDistribution(): array {
    $cacheId = 'makerspace_dashboard:membership:type_tenure_distribution';
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $members = $this->loadMemberTenureRecords(TRUE);
    if (empty($members)) {
      return [];
    }

    $now = (int) $this->time->getRequestTime();
    $buckets = $this->initializeMembershipTypeBuckets();

    foreach ($members as $record) {
      $joinTs = $record['join'];
      $endTs = $record['end'] ?? $now;
      if ($endTs <= $joinTs) {
        continue;
      }
      $years = ($endTs - $joinTs) / 31557600;
      if ($years <= 0) {
        continue;
      }
      $typeId = $record['membership']['id'] ?? NULL;
      if (!$typeId || !isset($buckets[$typeId])) {
        continue;
      }
      $bucketId = $this->resolveMembershipTenureBucket($years);
      if (!$bucketId) {
        continue;
      }
      if (!isset($buckets[$typeId]['buckets'][$bucketId])) {
        $buckets[$typeId]['buckets'][$bucketId] = 0;
      }
      $buckets[$typeId]['buckets'][$bucketId]++;
    }

    $results = [];
    foreach ($buckets as $typeId => $info) {
      $total = array_sum($info['buckets']);
      if ($total === 0) {
        continue;
      }
      $results[] = [
        'membership_type_id' => $typeId,
        'membership_type_label' => $info['label'],
        'buckets' => $info['buckets'],
      ];
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $results, $expire, ['profile_list', 'taxonomy_term_list']);

    return $results;
  }

  /**
   * Returns histogram of badge counts per member.
   */
  public function getBadgeCountDistribution(): array {
    $cacheId = 'makerspace_dashboard:membership:badge_count_distribution';
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $members = $this->loadMemberTenureRecords();
    if (empty($members)) {
      return $this->initializeBadgeCountResults();
    }

    $badgeCounts = $this->loadMemberBadgeCounts(array_keys($members));
    $buckets = $this->initializeBadgeCountBuckets();

    foreach ($members as $uid => $record) {
      if (empty($record['end']) || empty($record['join']) || $record['end'] <= $record['join']) {
        continue;
      }
      $count = $badgeCounts[$uid] ?? 0;
      if ($count <= 0) {
        continue;
      }
      $bucketId = $this->resolveBadgeCountBucket($count);
      if (!$bucketId || !isset($buckets[$bucketId])) {
        continue;
      }
      $buckets[$bucketId]['count']++;
    }

    $results = [];
    foreach ($buckets as $bucket) {
      $results[] = [
        'bucket_id' => $bucket['id'],
        'badge_min' => $bucket['min'],
        'badge_max' => $bucket['max'],
        'member_count' => (int) $bucket['count'],
      ];
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $results, $expire, ['profile_list', 'node_list', 'taxonomy_term_list']);

    return $results;
  }

  /**
   * Aggregates joins or ends by membership type and period.
   */
  protected function aggregateMembershipEvents(string $mode, string $startDate, string $endDate, string $granularity): array {
    $tables = [
      'join' => [
        'table' => NULL,
        'alias' => NULL,
        'column' => 'p.created',
        'is_unix' => TRUE,
      ],
      'end' => [
        'table' => 'profile__field_member_end_date',
        'alias' => 'end_date',
        'column' => 'field_member_end_date_value',
        'is_unix' => FALSE,
      ],
    ];
    if (!isset($tables[$mode])) {
      throw new \InvalidArgumentException(sprintf('Unsupported membership event type: %s', $mode));
    }

    $tableInfo = $tables[$mode];
    $dateColumn = $tableInfo['column'];
    $isUnix = (bool) ($tableInfo['is_unix'] ?? FALSE);

    $query = $this->database->select('profile', 'p');

    if (!empty($tableInfo['table']) && !empty($tableInfo['alias'])) {
      $tableAlias = $tableInfo['alias'];
      $tableName = $tableInfo['table'];
      $sourceField = $tableInfo['column'];
      $query->innerJoin($tableName, $tableAlias, "$tableAlias.entity_id = p.profile_id AND $tableAlias.deleted = 0");
      $dateColumn = $tableAlias . '.' . $sourceField;
    }

    $periodExpression = $this->buildPeriodExpression($dateColumn, $granularity, $isUnix);

    $query->leftJoin('profile__field_membership_type', 'membership_type', 'membership_type.entity_id = p.profile_id AND membership_type.deleted = 0');
    $query->leftJoin('taxonomy_term_field_data', 'term', 'term.tid = membership_type.field_membership_type_target_id');

    $query->addExpression($periodExpression, 'period_key');
    $query->addExpression("COALESCE(term.name, 'Unknown')", 'membership_type');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'total_members');

    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    if ($isUnix) {
      $query->where("FROM_UNIXTIME($dateColumn) BETWEEN STR_TO_DATE(:start_date, '%Y-%m-%d') AND STR_TO_DATE(:end_date, '%Y-%m-%d')", [
        ':start_date' => $startDate,
        ':end_date' => $endDate,
      ]);
    }
    else {
      $query->where("STR_TO_DATE($dateColumn, '%Y-%m-%d') BETWEEN STR_TO_DATE(:start_date, '%Y-%m-%d') AND STR_TO_DATE(:end_date, '%Y-%m-%d')", [
        ':start_date' => $startDate,
        ':end_date' => $endDate,
      ]);
    }

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
   * Builds map of member join/end timestamps.
   */
  protected function loadMemberTenureRecords(bool $includeMembershipType = FALSE): array {
    // Basic query for profiles.
    $query = $this->database->select('profile', 'p');
    $query->fields('p', ['uid', 'created']);
    $query->leftJoin('profile__field_member_end_date', 'end_date', 'end_date.entity_id = p.profile_id AND end_date.deleted = 0');
    $query->addField('end_date', 'field_member_end_date_value', 'end_value');
    $query->condition('p.type', 'main');
    $query->condition('p.is_default', 1);

    // Initial records.
    $records = [];
    foreach ($query->execute()->fetchAllAssoc('uid') as $row) {
      $joinTs = (int) $row->created;
      if ($joinTs <= 0) {
        continue;
      }
      $endTs = NULL;
      $endValue = trim((string) ($row->end_value ?? ''));
      if ($endValue !== '') {
        $parsed = strtotime($endValue . ' 23:59:59');
        if ($parsed !== FALSE) {
          $endTs = $parsed;
        }
      }
      $records[(int) $row->uid] = [
        'join' => $joinTs,
        'end' => $endTs,
        'uid' => (int) $row->uid,
      ];
    }

    // Now fetch roles for these UIDs.
    $uids = array_keys($records);
    $memberUids = [];
    if (!empty($uids)) {
        $roleQuery = $this->database->select('user__roles', 'ur');
        $roleQuery->fields('ur', ['entity_id']);
        $roleQuery->condition('ur.entity_id', $uids, 'IN');
        $roleQuery->condition('ur.roles_target_id', $this->memberRoles, 'IN');
        $roleQuery->distinct();
        $memberUids = $roleQuery->execute()->fetchCol();
        $memberUids = array_flip($memberUids); // For quick lookup.
    }

    // Add has_member_role to records.
    foreach ($records as $uid => &$record) {
      $record['has_member_role'] = isset($memberUids[$uid]);
    }

    return $records;
  }

  /**
   * Loads active badge counts grouped by member.
   */
  protected function loadMemberBadgeCounts(array $uids): array {
    if (empty($uids)) {
      return [];
    }
    $query = $this->database->select('node_field_data', 'n');
    $query->addField('mtb', 'field_member_to_badge_target_id', 'uid');
    $query->addExpression('COUNT(DISTINCT n.nid)', 'badge_total');
    $query->innerJoin('node__field_member_to_badge', 'mtb', 'mtb.entity_id = n.nid AND mtb.deleted = 0');
    $query->innerJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    $query->condition('status.field_badge_status_value', 'active');
    $query->condition('mtb.field_member_to_badge_target_id', $uids, 'IN');
    $query->groupBy('mtb.field_member_to_badge_target_id');

    $counts = [];
    foreach ($query->execute() as $row) {
      $counts[(int) $row->uid] = (int) $row->badge_total;
    }
    return $counts;
  }

  /**
   * Builds per-period totals across all membership types.
   */
  protected function buildTotals(array $incoming, array $ending, string $granularity): array {
    $totals = [];
    foreach ($incoming as $row) {
      $key = $row['period'];
      if (!isset($totals[$key])) {
        $totals[$key] = ['period' => $key, 'incoming' => 0, 'ending' => 0];
      }
      $totals[$key]['incoming'] += $row['count'];
    }
    foreach ($ending as $row) {
      $key = $row['period'];
      if (!isset($totals[$key])) {
        $totals[$key] = ['period' => $key, 'incoming' => 0, 'ending' => 0];
      }
      $totals[$key]['ending'] += $row['count'];
    }
    ksort($totals);
    return array_values($totals);
  }

  /**
   * Builds SQL expression for period grouping.
   */
  protected function buildPeriodExpression(string $column, string $granularity, bool $isUnixTimestamp = FALSE): string {
    $date = $isUnixTimestamp ? "FROM_UNIXTIME($column)" : "STR_TO_DATE($column, '%Y-%m-%d')";
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

  /**
   * Adds demographic or membership filters to the cohort query.
   */
  protected function applyCohortFilter(SelectInterface $query, array $filter): void {
    $type = strtolower((string) ($filter['type'] ?? ''));
    $value = $filter['value'] ?? NULL;
    if ($value === NULL || $value === '') {
      return;
    }

    switch ($type) {
      case 'ethnicity':
        $query->innerJoin('profile__field_member_ethnicity', 'cohort_ethnicity', 'cohort_ethnicity.entity_id = p.profile_id AND cohort_ethnicity.deleted = 0');
        $query->condition('cohort_ethnicity.field_member_ethnicity_value', $value);
        break;

      case 'gender':
        $query->innerJoin('profile__field_member_gender', 'cohort_gender', 'cohort_gender.entity_id = p.profile_id AND cohort_gender.deleted = 0');
        $query->condition('cohort_gender.field_member_gender_value', $value);
        break;

      case 'membership_type':
        $query->innerJoin('profile__field_membership_type', 'cohort_membership_type', 'cohort_membership_type.entity_id = p.profile_id AND cohort_membership_type.deleted = 0');
        $query->condition('cohort_membership_type.field_membership_type_target_id', (int) $value);
        break;
    }
  }

  /**
   * Builds a cache suffix for cohort filter combinations.
   */
  protected function buildCohortFilterCacheKey(?array $filter): string {
    if (empty($filter) || empty($filter['type']) || $filter['value'] === '' || $filter['value'] === NULL) {
      return 'all';
    }
    $type = strtolower((string) $filter['type']);
    $value = (string) $filter['value'];
    return $type . ':' . md5($value);
  }

  /**
   * Returns the machine names for unpreventable attrition reasons.
   */
  protected function getUnpreventableEndReasons(): array {
    return $this->unpreventableEndReasons;
  }

  /**
   * Returns the most common values for string-based profile fields.
   */
  protected function getTopProfileFieldOptions(string $dimension, string $table, string $column, int $limit): array {
    $limit = max(1, $limit);
    $cacheId = sprintf('makerspace_dashboard:membership:cohort_filter:%s:%d', $dimension, $limit);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $query = $this->database->select('profile', 'p');
    $query->innerJoin($table, 'filter_field', "filter_field.entity_id = p.profile_id AND filter_field.deleted = 0 AND filter_field.$column <> ''");
    $query->addField('filter_field', $column, 'filter_value');
    $query->addExpression('COUNT(DISTINCT p.profile_id)', 'member_count');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->groupBy('filter_value');
    $query->orderBy('member_count', 'DESC');
    $query->orderBy('filter_value', 'ASC');
    $query->range(0, $limit);

    $options = [];
    foreach ($query->execute() as $record) {
      $value = trim((string) $record->filter_value);
      if ($value === '') {
        continue;
      }
      $options[] = [
        'value' => $value,
        'label' => $value,
        'count' => (int) $record->member_count,
      ];
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $options, $expire, ['profile_list']);
    return $options;
  }

  /**
   * Returns the most common membership types.
   */
  protected function getTopMembershipTypeOptions(int $limit): array {
    $limit = max(1, $limit);
    $cacheId = sprintf('makerspace_dashboard:membership:cohort_filter:membership_type:%d', $limit);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $query = $this->database->select('profile', 'p');
    $query->innerJoin('profile__field_membership_type', 'membership_type', 'membership_type.entity_id = p.profile_id AND membership_type.deleted = 0');
    $query->innerJoin('taxonomy_term_field_data', 'term', 'term.tid = membership_type.field_membership_type_target_id');
    $query->addField('term', 'tid', 'tid');
    $query->addField('term', 'name', 'label');
    $query->addExpression('COUNT(DISTINCT p.profile_id)', 'member_count');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->groupBy('term.tid');
    $query->groupBy('term.name');
    $query->orderBy('member_count', 'DESC');
    $query->orderBy('label', 'ASC');
    $query->range(0, $limit);

    $options = [];
    foreach ($query->execute() as $record) {
      $tid = (int) $record->tid;
      if ($tid <= 0) {
        continue;
      }
      $options[] = [
        'value' => $tid,
        'label' => (string) $record->label,
        'count' => (int) $record->member_count,
      ];
    }

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $options, $expire, ['profile_list', 'taxonomy_term_list']);
    return $options;
  }

  /**
   * Initializes badge buckets with duration placeholders.
   */
  protected function initializeBadgeTenureBuckets(): array {
    $buckets = [];
    foreach ($this->badgeTenureBuckets as $definition) {
      $buckets[$definition['id']] = $definition + ['durations' => []];
    }
    return $buckets;
  }

  /**
   * Returns empty bucket result structures.
   */
  protected function initializeBadgeTenureResults(): array {
    $results = [];
    foreach ($this->badgeTenureBuckets as $definition) {
      $results[] = [
        'bucket_id' => $definition['id'],
        'badge_min' => $definition['min'],
        'badge_max' => $definition['max'],
        'member_count' => 0,
        'average_years' => NULL,
        'median_years' => NULL,
      ];
    }
    return $results;
  }

  /**
   * Determines which badge bucket applies to the count.
   */
  protected function resolveBadgeTenureBucket(int $count): ?string {
    return $this->resolveBucket($this->badgeTenureBuckets, $count);
  }

  /**
   * Determines which badge bucket applies to the count.
   */
  protected function resolveBadgeCountBucket(int $count): ?string {
    return $this->resolveBucket($this->badgeCountBuckets, $count);
  }

  /**
   * Resolves the definition id for a numeric bucket.
   */
  protected function resolveBucket(array $definitions, int $count): ?string {
    foreach ($definitions as $definition) {
      $min = $definition['min'];
      $max = $definition['max'];
      if ($count < $min) {
        continue;
      }
      if ($max !== NULL && $count > $max) {
        continue;
      }
      return $definition['id'];
    }
    return NULL;
  }

  /**
   * Calculates the median from a numeric dataset.
   */
  protected function calculateMedian(array $values): ?float {
    $count = count($values);
    if ($count === 0) {
      return NULL;
    }
    sort($values);
    $middle = (int) floor($count / 2);
    if ($count % 2) {
      return (float) $values[$middle];
    }
    return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
  }

  /**
   * Initializes badge count buckets with counters.
   */
  protected function initializeBadgeCountBuckets(): array {
    $buckets = [];
    foreach ($this->badgeCountBuckets as $definition) {
      $buckets[$definition['id']] = $definition + ['count' => 0];
    }
    return $buckets;
  }

  /**
   * Provides empty dataset for badge histograms.
   */
  protected function initializeBadgeCountResults(): array {
    $results = [];
    foreach ($this->badgeCountBuckets as $definition) {
      $results[] = [
        'bucket_id' => $definition['id'],
        'badge_min' => $definition['min'],
        'badge_max' => $definition['max'],
        'member_count' => 0,
      ];
    }
    return $results;
  }

  /**
   * Initializes membership type buckets keyed by term id.
   */
  protected function initializeMembershipTypeBuckets(): array {
    $types = $this->getTopMembershipTypeOptions(20);
    $buckets = [];
    foreach ($types as $type) {
      $tid = (int) $type['value'];
      $buckets[$tid] = [
        'label' => $type['label'],
        'buckets' => array_fill_keys(array_column($this->membershipTypeTenureBuckets, 'id'), 0),
      ];
    }
    return $buckets;
  }

  /**
   * Resolves the tenure bucket key for a membership type.
   */
  protected function resolveMembershipTenureBucket(float $years): ?string {
    foreach ($this->membershipTypeTenureBuckets as $bucket) {
      $min = $bucket['min_years'];
      $max = $bucket['max_years'];
      if ($years < $min) {
        continue;
      }
      if ($max !== NULL && $years > $max) {
        continue;
      }
      return $bucket['id'];
    }
    return NULL;
  }

  /**
   * Gets the annual Member Net Promoter Score (NPS).
   *
   * @return int
   *   The annual member NPS.
   */
  public function getAnnualMemberNps(): int {
    // @todo: Implement logic to get this value from the annual member survey.
    // This will be called by the 'annual' snapshot in the makerspace_snapshot
    // module.
    return 55;
  }

  /**
   * Gets the annual retention rate for POC members.
   *
   * @return float
   *   The annual POC retention rate.
   */
  public function getAnnualRetentionPoc(): float {
    // @todo: Implement logic to calculate this. This will be called by the
    // 'annual' snapshot.
    return 0.68;
  }

  /**
   * Calculates the retention curve (churn rate and life expectancy by tenure year).
   *
   * @return array
   *   Array keyed by tenure year (int) containing 'churn_rate' and 'expected_future_years'.
   */
  public function getRetentionCurve(): array {
    $cacheId = 'makerspace_dashboard:membership:retention_curve:v4';
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $members = $this->loadMemberTenureRecords();
    if (empty($members)) {
      return [];
    }

    $curve = [];
    $maxYear = 15; // Extend to capture tail for terminal rate calculation.

    // Initialize buckets.
    for ($y = 0; $y <= $maxYear; $y++) {
      $curve[$y] = [
        'entered' => 0,
        'churned' => 0,
      ];
    }

    $now = (int) $this->time->getRequestTime();

    foreach ($members as $record) {
      if ($record['end'] === NULL && !$record['has_member_role']) {
        continue; // Ignore this record as per user's instruction
      }
      $joinTs = $record['join'];
      $endTs = $record['end'] ?? NULL;
      $isChurned = ($endTs !== NULL && $endTs <= $now);
      
      $effectiveEnd = $endTs ?? $now;
      $tenureYears = ($effectiveEnd - $joinTs) / 31557600;

      for ($y = 0; $y <= $maxYear; $y++) {
        if ($tenureYears >= $y) {
          $curve[$y]['entered']++;
          if ($isChurned && $tenureYears < ($y + 1)) {
            $curve[$y]['churned']++;
          }
        }
      }
    }

    $results = [];

    // Calculate smoothed rates for 0-9 years.
    for ($y = 0; $y <= 9; $y++) {
      $poolEntered = 0;
      $poolChurned = 0;

      // Do not smooth Year 0 (new members have distinct high churn).
      // For others, use 3-year window (previous, current, next).
      if ($y === 0) {
        $windowStart = 0;
        $windowEnd = 0;
      }
      else {
        $windowStart = max(0, $y - 1);
        $windowEnd = min($maxYear, $y + 1);
      }

      for ($k = $windowStart; $k <= $windowEnd; $k++) {
        $poolEntered += $curve[$k]['entered'];
        $poolChurned += $curve[$k]['churned'];
      }

      if ($poolEntered > 0) {
        $churnRate = $poolChurned / $poolEntered;
        // Floor churn at 3% for safety in calculations.
        $safeChurn = max($churnRate, 0.03);
        $expectedFutureYears = min(1 / $safeChurn, 15.0);
      } else {
        $churnRate = 1.0;
        $expectedFutureYears = 0.0;
      }

      $results[$y] = [
        'entered' => $curve[$y]['entered'], // Return actual count for reference
        'churn_rate' => $churnRate,
        'expected_future_years' => $expectedFutureYears,
      ];
    }

    // Calculate terminal '10+' rate.
    $termEntered = 0;
    $termChurned = 0;
    for ($y = 10; $y <= $maxYear; $y++) {
      $termEntered += $curve[$y]['entered'];
      $termChurned += $curve[$y]['churned'];
    }

    if ($termEntered > 0) {
      $termChurn = $termChurned / $termEntered;
      $termSafeChurn = max($termChurn, 0.02); // Lower floor for long-tail.
      $termFuture = min(1 / $termSafeChurn, 20.0);
    } else {
      $termFuture = 0.0;
    }

    $results['10+'] = [
      'entered' => $termEntered,
      'expected_future_years' => $termFuture,
    ];

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $results, $expire, ['profile_list']);

    return $results;
  }

}
