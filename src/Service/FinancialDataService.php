<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use DateTimeImmutable;

/**
 * Service to query financial data for the dashboard.
 */
class FinancialDataService {

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Date formatter service.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Membership metrics service.
   */
  protected MembershipMetricsService $membershipMetricsService;

  /**
   * Google Sheet client service.
   */
  protected GoogleSheetClientService $googleSheetClient;

  /**
   * Snapshot data service.
   */
  protected SnapshotDataService $snapshotData;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, DateFormatterInterface $dateFormatter, MembershipMetricsService $membershipMetricsService, GoogleSheetClientService $googleSheetClient, SnapshotDataService $snapshotData) {
    $this->database = $database;
    $this->cache = $cache;
    $this->dateFormatter = $dateFormatter;
    $this->membershipMetricsService = $membershipMetricsService;
    $this->googleSheetClient = $googleSheetClient;
    $this->snapshotData = $snapshotData;
  }

  /**
   * Gets monthly recurring revenue (MRR) trend.
   *
   * @param \DateTimeImmutable $start_date
   *   The start date for the query range.
   * @param \DateTimeImmutable $end_date
   *   The end date for the query range.
   *
   * @return array
   *   An array of MRR data.
   */
  public function getMrrTrend(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array {
    $cid = 'makerspace_dashboard:mrr_trend:' . $start_date->getTimestamp() . ':' . $end_date->getTimestamp();
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('profile__field_membership_type', 'pmt');
    $query->join('profile__field_member_join_date', 'pmjd', 'pmt.entity_id = pmjd.entity_id');
    $query->join('taxonomy_term_field_data', 'tfd', 'pmt.field_membership_type_target_id = tfd.tid');
    $query->fields('pmjd', ['field_member_join_date_value']);
    $query->fields('tfd', ['name']);
    $query->condition('pmjd.field_member_join_date_value', [$start_date->format('Y-m-d'), $end_date->format('Y-m-d')], 'BETWEEN');
    $results = $query->execute()->fetchAll();

    $mrr = [];
    foreach ($results as $result) {
      $month = $this->dateFormatter->format(strtotime($result->field_member_join_date_value), 'custom', 'Y-m');
      if (!isset($mrr[$month])) {
        $mrr[$month] = 0;
      }
      // This is a simplified calculation. A real implementation would be more complex.
      if (strpos(strtolower($result->name), 'individual') !== false) {
        $mrr[$month] += 50;
      }
      elseif (strpos(strtolower($result->name), 'family') !== false) {
        $mrr[$month] += 75;
      }
    }
    ksort($mrr);

    $labels = array_keys($mrr);
    $data = array_values($mrr);

    $trend = [
      'labels' => $labels,
      'data' => $data,
    ];

    $this->cache->set($cid, $trend, CacheBackendInterface::CACHE_PERMANENT, ['profile_list']);

    return $trend;
  }

  /**
   * Gets payment mix data.
   *
   * @return array
   *   An array of payment mix data.
   */
  public function getPaymentMix(): array {
    $cid = 'makerspace_dashboard:payment_mix';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('profile__field_membership_type', 'pmt');
    $query->join('taxonomy_term_field_data', 'tfd', 'pmt.field_membership_type_target_id = tfd.tid');
    $query->addExpression('COUNT(pmt.entity_id)', 'count');
    $query->fields('tfd', ['name']);
    $query->groupBy('tfd.name');
    $results = $query->execute()->fetchAll();

    $mix = [];
    foreach ($results as $result) {
      $mix[$result->name] = (int) $result->count;
    }

    $this->cache->set($cid, $mix, CacheBackendInterface::CACHE_PERMANENT, ['profile_list']);

    return $mix;
  }

  /**
   * Retrieves quarterly payment mix snapshots built from plan snapshots.
   *
   * @param int $quarterLimit
   *   Maximum number of quarters to return.
   *
   * @return array
   *   Structured snapshot data with plan labels and per-quarter counts.
   */
  public function getPaymentMixSnapshots(int $quarterLimit = 6): array {
    $quarterLimit = max(1, $quarterLimit);
    $cid = sprintf('makerspace_dashboard:payment_mix_snapshots:%d', $quarterLimit);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $schema = $this->database->schema();
    if (!$schema->tableExists('ms_snapshot') || !$schema->tableExists('ms_fact_plan_snapshot')) {
      return [];
    }

    $rangeMonths = max(12, ($quarterLimit * 3) + 9);
    $rangeStart = (new DateTimeImmutable('first day of this month'))->modify(sprintf('-%d months', $rangeMonths));

    $query = $this->database->select('ms_snapshot', 's');
    $query->innerJoin('ms_fact_plan_snapshot', 'p', 'p.snapshot_id = s.id');
    $query->fields('s', ['snapshot_date']);
    $query->fields('p', ['plan_code', 'plan_label', 'count_members']);
    $query->condition('s.definition', 'plan');
    $query->condition('s.snapshot_date', $rangeStart->format('Y-m-01'), '>=');
    $query->orderBy('s.snapshot_date', 'ASC');

    $records = $query->execute();
    if (!$records) {
      return [];
    }

    $planLabels = [];
    $quarters = [];

    foreach ($records as $record) {
      $dateString = $record->snapshot_date ?? '';
      $date = DateTimeImmutable::createFromFormat('Y-m-d', $dateString);
      if (!$date) {
        continue;
      }
      $year = (int) $date->format('Y');
      $month = (int) $date->format('n');
      $quarterNumber = (int) ceil($month / 3);
      $quarterKey = sprintf('%04d-Q%d', $year, $quarterNumber);
      $quarterLabel = sprintf('Q%d %d', $quarterNumber, $year);

      $code = $this->normalizePlanCode($record->plan_code ?? '');
      $label = trim((string) ($record->plan_label ?? ''));
      if ($label === '') {
        $label = $code;
      }
      $planLabels[$code] = $label;

      if (!isset($quarters[$quarterKey]) || $date > $quarters[$quarterKey]['date']) {
        $quarters[$quarterKey] = [
          'date' => $date,
          'label' => $quarterLabel,
          'counts' => [],
        ];
      }
      if ($date < $quarters[$quarterKey]['date']) {
        continue;
      }
      if ($date > $quarters[$quarterKey]['date']) {
        $quarters[$quarterKey]['date'] = $date;
        $quarters[$quarterKey]['counts'] = [];
      }
      $quarters[$quarterKey]['counts'][$code] = (int) ($record->count_members ?? 0);
    }

    if (!$quarters) {
      return [];
    }

    uasort($quarters, static function (array $a, array $b): int {
      return $a['date'] <=> $b['date'];
    });
    $quarters = array_slice($quarters, -1 * $quarterLimit, NULL, TRUE);

    $series = [];
    foreach ($quarters as $key => $info) {
      $counts = $info['counts'];
      $series[] = [
        'key' => $key,
        'label' => $info['label'],
        'date' => $info['date']->format('Y-m-d'),
        'counts' => $counts,
        'total' => array_sum($counts),
      ];
    }

    $data = [
      'plans' => $planLabels,
      'quarters' => $series,
    ];
    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, ['makerspace_snapshot:plan']);

    return $data;
  }

  /**
   * Normalizes plan codes pulled from snapshots.
   */
  private function normalizePlanCode($code): string {
    $value = strtoupper(trim((string) $code));
    if ($value === '' || strpos($value, '@') !== FALSE) {
      return 'UNASSIGNED';
    }
    return $value;
  }

  /**
   * Computes average recorded monthly payment amount by membership type.
   */
  public function getAverageMonthlyPaymentByType(): array {
    $cid = 'makerspace_dashboard:avg_payment_by_type:v2';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('profile__field_member_payment_monthly')) {
      return ['types' => [], 'overall' => 0.0];
    }

    $query = $this->database->select('profile__field_member_payment_monthly', 'payment');
    $query->innerJoin('profile', 'p', 'p.profile_id = payment.entity_id');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'member_role', "member_role.entity_id = u.uid AND member_role.roles_target_id = 'member'");
    $schema = $this->database->schema();
    $hasChargebeePause = $schema->tableExists('user__field_chargebee_payment_pause');
    $hasManualPause = $schema->tableExists('user__field_manual_pause');
    if ($hasChargebeePause) {
      $query->leftJoin('user__field_chargebee_payment_pause', 'chargebee_pause', 'chargebee_pause.entity_id = u.uid AND chargebee_pause.deleted = 0');
    }
    if ($hasManualPause) {
      $query->leftJoin('user__field_manual_pause', 'manual_pause', 'manual_pause.entity_id = u.uid AND manual_pause.deleted = 0');
    }
    $query->leftJoin('profile__field_membership_type', 'membership_type', 'membership_type.entity_id = p.profile_id AND membership_type.deleted = 0');
    $query->leftJoin('taxonomy_term_field_data', 'term', 'term.tid = membership_type.field_membership_type_target_id');
    $query->condition('payment.deleted', 0);
    $query->isNotNull('payment.field_member_payment_monthly_value');
    $query->where("payment.field_member_payment_monthly_value <> ''");
    $query->condition('payment.field_member_payment_monthly_value', 0, '>');

    if ($hasChargebeePause || $hasManualPause) {
      $activeGroup = $query->andConditionGroup();
      if ($hasChargebeePause) {
        $chargebeeNotPaused = $query->orConditionGroup()
          ->isNull('chargebee_pause.field_chargebee_payment_pause_value')
          ->condition('chargebee_pause.field_chargebee_payment_pause_value', 0);
        $activeGroup->condition($chargebeeNotPaused);
      }
      if ($hasManualPause) {
        $manualNotPaused = $query->orConditionGroup()
          ->isNull('manual_pause.field_manual_pause_value')
          ->condition('manual_pause.field_manual_pause_value', 0);
        $activeGroup->condition($manualNotPaused);
      }
      $query->condition($activeGroup);
    }

    $query->addExpression("COALESCE(term.name, 'Unknown')", 'membership_type');
    $query->addExpression('AVG(payment.field_member_payment_monthly_value)', 'avg_payment');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');

    $query->groupBy('membership_type');
    $query->orderBy('avg_payment', 'DESC');

    $results = $query->execute();

    $typeStats = [];
    $totalAmount = 0;
    $totalMembers = 0;

    foreach ($results as $record) {
      $avg = (float) $record->avg_payment;
      $count = (int) $record->member_count;
      $total = $avg * $count;
      $typeStats[$record->membership_type] = [
        'average' => round($avg, 2),
        'members' => $count,
        'total' => round($total, 2),
      ];
      $totalAmount += $total;
      $totalMembers += $count;
    }

    ksort($typeStats);

    $overallAverage = $totalMembers > 0 ? round($totalAmount / $totalMembers, 2) : 0.0;

    $data = [
      'types' => $typeStats,
      'overall_average' => $overallAverage,
      'total_revenue' => round($totalAmount, 2),
      'total_members' => $totalMembers,
    ];

    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, ['profile_list']);

    return $data;
  }

  /**
   * Returns Chargebee plan distribution for active users.
   */
  public function getChargebeePlanDistribution(): array {
    $cid = 'makerspace_dashboard:chargebee_plan_distribution';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('user__field_user_chargebee_plan')) {
      return [];
    }

    $query = $this->database->select('user__field_user_chargebee_plan', 'plan');
    $query->addExpression("COALESCE(NULLIF(plan.field_user_chargebee_plan_value, ''), 'Unassigned')", 'plan_label');
    $query->addExpression('COUNT(DISTINCT plan.entity_id)', 'member_count');
    $query->innerJoin('users_field_data', 'u', 'u.uid = plan.entity_id');
    $query->innerJoin('user__roles', 'member_role', "member_role.entity_id = u.uid AND member_role.roles_target_id = 'member'");
    $query->condition('u.status', 1);
    $query->condition('plan.deleted', 0);

    $has_chargebee_pause = $this->database->schema()->tableExists('user__field_chargebee_payment_pause');
    $has_manual_pause = $this->database->schema()->tableExists('user__field_manual_pause');

    if ($has_chargebee_pause) {
      $query->leftJoin('user__field_chargebee_payment_pause', 'chargebee_pause', 'chargebee_pause.entity_id = u.uid AND chargebee_pause.deleted = 0');
    }
    if ($has_manual_pause) {
      $query->leftJoin('user__field_manual_pause', 'manual_pause', 'manual_pause.entity_id = u.uid AND manual_pause.deleted = 0');
    }

    $active_group = $query->andConditionGroup();

    if ($has_chargebee_pause) {
      $chargebee_not_paused = $query->orConditionGroup()
        ->isNull('chargebee_pause.field_chargebee_payment_pause_value')
        ->condition('chargebee_pause.field_chargebee_payment_pause_value', 0);
      $active_group->condition($chargebee_not_paused);
    }

    if ($has_manual_pause) {
      $manual_not_paused = $query->orConditionGroup()
        ->isNull('manual_pause.field_manual_pause_value')
        ->condition('manual_pause.field_manual_pause_value', 0);
      $active_group->condition($manual_not_paused);
    }

    if ($has_chargebee_pause || $has_manual_pause) {
      $query->condition($active_group);
    }

    $query->groupBy('plan_label');
    $query->orderBy('member_count', 'DESC');

    $data = [];
    foreach ($query->execute() as $record) {
      $data[$record->plan_label] = (int) $record->member_count;
    }

    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, ['user_list']);

    return $data;
  }

  /**
   * Calculates estimated Lifetime Value (LTV) grouped by membership type.
   *
   * Estimation: (Months since Join Date) * (Current Monthly Payment).
   *
   * @return array
   *   Array of LTV data keyed by membership type.
   */
  public function getLifetimeValueByMembershipType(): array {
    $cid = 'makerspace_dashboard:ltv_by_type';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('profile__field_member_payment_monthly')) {
      return [];
    }

    $query = $this->database->select('profile__field_member_payment_monthly', 'payment');
    $query->innerJoin('profile', 'p', 'p.profile_id = payment.entity_id');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->leftJoin('profile__field_membership_type', 'membership_type', 'membership_type.entity_id = p.profile_id');
    $query->leftJoin('taxonomy_term_field_data', 'term', 'term.tid = membership_type.field_membership_type_target_id');

    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('u.status', 1);
    $query->condition('payment.field_member_payment_monthly_value', 0, '>');

    // Calculate months since join.
    $query->addExpression('TIMESTAMPDIFF(MONTH, FROM_UNIXTIME(p.created), NOW())', 'months_active');
    $query->addField('payment', 'field_member_payment_monthly_value', 'monthly_payment');
    $query->addExpression("COALESCE(term.name, 'Unknown')", 'type_name');

    $results = $query->execute();

    $sums = [];
    $counts = [];

    foreach ($results as $row) {
      $months = max(1, (int) $row->months_active);
      $ltv = $months * (float) $row->monthly_payment;
      $type = $row->type_name;

      if (!isset($sums[$type])) {
        $sums[$type] = 0;
        $counts[$type] = 0;
      }
      $sums[$type] += $ltv;
      $counts[$type]++;
    }

    $data = [];
    foreach ($sums as $type => $total_ltv) {
      $data[$type] = round($total_ltv / $counts[$type], 2);
    }
    arsort($data);

    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, ['profile_list']);
    return $data;
  }

  /**
   * Calculates estimated Lifetime Value (LTV) grouped by tenure (years).
   *
   * Includes both realized (historical) value and projected future value based
   * on current retention rates.
   *
   * @return array
   *   Array of LTV data keyed by years of membership.
   */
  public function getLifetimeValueByTenure(): array {
    $cid = 'makerspace_dashboard:ltv_by_tenure:v4';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    // 1. Get Retention Curve (Future Life Expectancy by Tenure Year).
    $curve = $this->membershipMetricsService->getRetentionCurve();

    // 2. Query Realized Value.
    $query = $this->database->select('profile__field_member_payment_monthly', 'payment');
    $query->innerJoin('profile', 'p', 'p.profile_id = payment.entity_id');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');

    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('u.status', 1);
    $query->condition('payment.field_member_payment_monthly_value', 0, '>');

    $query->addExpression('TIMESTAMPDIFF(MONTH, FROM_UNIXTIME(p.created), NOW())', 'months_active');
    $query->addField('payment', 'field_member_payment_monthly_value', 'monthly_payment');

    $results = $query->execute();

    $buckets = [];
    $totalMonthlyPaymentSum = 0;
    $totalCount = 0;

    foreach ($results as $row) {
      $months = max(1, (int) $row->months_active);
      $years = (int) floor($months / 12);
      $monthlyPayment = (float) $row->monthly_payment;
      
      $totalMonthlyPaymentSum += $monthlyPayment;
      $totalCount++;

      $realizedLtv = $months * $monthlyPayment;
      
      // Group 10+ years.
      $key = ($years >= 10) ? '10+ Years' : $years . ' Years';
      $sortKey = ($years >= 10) ? 999 : $years;

      if (!isset($buckets[$key])) {
        $buckets[$key] = [
          'realized_sum' => 0,
          'projected_sum' => 0,
          'count' => 0,
          'sort' => $sortKey,
        ];
      }
      
      // Lookup expected future years for this specific tenure year.
      if ($years >= 10) {
        $expectedFutureYears = $curve['10+']['expected_future_years'] ?? 0.5;
      }
      else {
        $expectedFutureYears = $curve[$years]['expected_future_years'] ?? 0.5;
      }
      
      $projectedLtv = $monthlyPayment * ($expectedFutureYears * 12);

      $buckets[$key]['realized_sum'] += $realizedLtv;
      $buckets[$key]['projected_sum'] += $projectedLtv;
      $buckets[$key]['count']++;
    }

    // Ensure 0 Years bucket exists for "New Member" projection.
    if (!isset($buckets['0 Years']) && $totalCount > 0) {
      $globalAvgPayment = $totalMonthlyPaymentSum / $totalCount;
      $curve0 = $curve[0]['expected_future_years'] ?? 0.5;
      $buckets['0 Years'] = [
        'realized_sum' => $globalAvgPayment, // Assume 1 month paid for new member
        'projected_sum' => $globalAvgPayment * ($curve0 * 12),
        'count' => 1, // Artificial count to make division work
        'sort' => 0,
      ];
    }

    // Sort by tenure year.
    uasort($buckets, function ($a, $b) {
      return $a['sort'] <=> $b['sort'];
    });

    $data = [];
    foreach ($buckets as $key => $bucket) {
      $avgRealized = $bucket['realized_sum'] / $bucket['count'];
      $avgProjected = $bucket['projected_sum'] / $bucket['count'];
      
      // Re-determine years to lookup correct months for metadata.
      if ($key === '10+ Years') {
        $years = 10;
        $expectedFutureYears = $curve['10+']['expected_future_years'] ?? 0.5;
      }
      else {
        $years = (int) $key; // "0 Years" -> 0
        $expectedFutureYears = $curve[$years]['expected_future_years'] ?? 0.5;
      }

      $data[$key] = [
        'realized' => round($avgRealized, 2),
        'projected' => round($avgProjected, 2),
        'projected_months' => round($expectedFutureYears * 12, 1),
        'total' => round($avgRealized + $avgProjected, 2),
      ];
    }

    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, ['profile_list']);
    return $data;
  }

  /**
   * Calculates average tenure (retention) by plan type.
   *
   * @return array
   *   Array of average months of tenure keyed by plan name.
   */
  public function getRetentionByPlanType(): array {
    $cid = 'makerspace_dashboard:retention_by_plan';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('user__field_user_chargebee_plan')) {
      return [];
    }

    $query = $this->database->select('user__field_user_chargebee_plan', 'plan');
    $query->innerJoin('users_field_data', 'u', 'u.uid = plan.entity_id');
    $query->innerJoin('profile', 'p', 'p.uid = u.uid AND p.type = \'main\'');
    
    $query->condition('u.status', 1);
    $query->condition('plan.deleted', 0);
    
    $query->addExpression("COALESCE(NULLIF(plan.field_user_chargebee_plan_value, ''), 'Unassigned')", 'plan_label');
    $query->addExpression('AVG(TIMESTAMPDIFF(MONTH, FROM_UNIXTIME(p.created), NOW()))', 'avg_months');
    $query->groupBy('plan_label');
    $query->orderBy('avg_months', 'DESC');

    $results = $query->execute();
    $data = [];
    foreach ($results as $row) {
      $data[$row->plan_label] = round((float) $row->avg_months, 1);
    }

    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, ['user_list', 'profile_list']);
    return $data;
  }

  /**
   * Gets the average monthly operating expense over the last 12 months.
   *
   * @return float
   *   The average monthly operating expense.
   */
  public function getAverageMonthlyOperatingExpense(): float {
    // Trailing four quarters of expense_total from the Income-Statement
    // Google Sheet tab, averaged per month.
    $ttm = $this->getMetricTtmSum('expense_total', 4);
    return $ttm > 0 ? $ttm / 12 : 0.0;
  }

  /**
   * Gets the earned income sustaining core percentage.
   *
   * @return float
   *   The earned income sustaining core percentage.
   */
  public function getEarnedIncomeSustainingCore(): float {
    return $this->getEarnedIncomeSustainingCoreRate();
  }

  /**
   * Gets the annual member revenue.
   *
   * @return float
   *   The annual member revenue.
   */
  public function getAnnualMemberRevenue(): float {
    $year = (int) date('Y');
    if (date('n') <= 3) {
      $year = 2025;
    }
    return abs($this->getActualsForMetric('income_membership', $year));
  }

  /**
   * Gets the annual net income from program lines.
   *
   * @return float
   *   The annual net income from program lines.
   */
  public function getAnnualNetIncomeProgramLines(): float {
    $year = (int) date('Y');
    if (date('n') <= 3) {
      $year = 2025;
    }

    $net_storage = $this->getActualsForMetric('income_storage', $year) + $this->getActualsForMetric('expense_storage', $year);
    $net_workspaces = $this->getActualsForMetric('income_workspaces', $year) + $this->getActualsForMetric('expense_workspaces', $year);
    $net_media = $this->getActualsForMetric('income_media', $year);

    return (float) ($net_storage + $net_workspaces + $net_media);
  }

  /**
   * Gets the adherence to the shop budget.
   *
   * @return float
   *   The adherence to the shop budget as a variance percentage.
   */
  public function getAdherenceToShopBudget(): float {
    $metricKey = 'expense_shop_operations';
    $year = (int) date('Y');
    
    // If it is early in the year (e.g. Feb 2026), 2026 actuals might be too 
    // sparse. We will check 2025 if we are in Q1.
    if (date('n') <= 3) {
      $year = 2025;
    }

    $budget = $this->getBudgetForMetric($metricKey, $year);
    $actuals = $this->getActualsForMetric($metricKey, $year);

    if ($budget > 0) {
      return abs($actuals) / $budget;
    }

    return 1.0;
  }

  /**
   * Helper to get budget value from Google Sheet.
   */
  protected function getBudgetForMetric(string $metricKey, int $year): float {
    $data = $this->googleSheetClient->getSheetData('Budgets');
    if (empty($data)) {
      return 0.0;
    }

    $headers = array_shift($data);
    $yearCol = -1;
    $targetHeader = $year . '_budget';
    foreach ($headers as $idx => $header) {
      if (trim(strtolower($header)) === $targetHeader) {
        $yearCol = $idx;
        break;
      }
    }

    if ($yearCol === -1) {
      return 0.0;
    }

    foreach ($data as $row) {
      if (isset($row[1]) && $row[1] === $metricKey) {
        $value = $row[$yearCol] ?? '0';
        return $this->parseCurrencyValue($value);
      }
    }

    return 0.0;
  }

  /**
   * Helper to get annual actuals from Income Statement sheet.
   */
  /**
   * Helper to get actuals for a specific year from Google Sheet.
   *
   * @param string $metricKey
   *   The metric ID from the sheet.
   * @param int $year
   *   The year to filter by.
   *
   * @return float
   *   The total actuals for that year.
   */
  public function getActualsForMetric(string $metricKey, int $year): float {
    $data = $this->googleSheetClient->getSheetData('Income-Statement');
    if (empty($data)) {
      return 0.0;
    }

    $headers = array_shift($data);
    $targetCols = [];
    foreach ($headers as $idx => $header) {
      if (str_contains($header, (string) $year)) {
        $targetCols[] = $idx;
      }
    }

    if (empty($targetCols)) {
      return 0.0;
    }

    $total = 0.0;
    foreach ($data as $row) {
      if (isset($row[1]) && $row[1] === $metricKey) {
        foreach ($targetCols as $col) {
          $value = $row[$col] ?? '0';
          $total += $this->parseCurrencyValue($value);
        }
        break;
      }
    }

    return $total;
  }

  /**
   * Gets a flat array of historical actuals for a metric (e.g., for sparklines).
   *
   * Returns data points in chronological order (oldest to newest).
   */
  public function getMetricTrend(string $metricKey, int $limit = 12): array {
    $data = $this->googleSheetClient->getSheetData('Income-Statement');
    if (empty($data)) {
      return [];
    }

    $headers = array_shift($data);
    
    // Find the row for this metric.
    $metricRow = NULL;
    foreach ($data as $row) {
      if (isset($row[1]) && $row[1] === $metricKey) {
        $metricRow = $row;
        break;
      }
    }

    if (!$metricRow) {
      return [];
    }

    // Map the "Mon-Mon YYYY" header format used in the sheet to a sortable key.
    // Headers look like: "Oct-Dec 2025", "Jul-Sep 2025", "Apr-Jun 2025", etc.
    $quarterOrder = ['Jan-Mar' => 1, 'Apr-Jun' => 2, 'Jul-Sep' => 3, 'Jul-Sept' => 3, 'Oct-Dec' => 4];
    $trend = [];
    foreach ($headers as $idx => $header) {
      if (preg_match('/^(Jan-Mar|Apr-Jun|Jul-Sept?|Oct-Dec)\s+(\d{4})$/', trim((string) $header), $m)) {
        $q = $quarterOrder[$m[1]];
        // Sortable key: YYYY0Q so ksort gives oldest-first chronological order.
        $sortKey = $m[2] . '0' . $q;
        $val = $this->parseCurrencyValue($metricRow[$idx] ?? '0');
        $trend[$sortKey] = $val;
      }
    }

    ksort($trend);
    $values = array_values($trend);
    return array_slice($values, -$limit);
  }

  /**
   * Normalizes values like "$32,000" or "(9,584)" into floats.
   */
  protected function parseCurrencyValue(string $value): float {
    $clean = str_replace(['$', ',', ' '], '', $value);
    $isNegative = str_starts_with($clean, '(') && str_ends_with($clean, ')');
    if ($isNegative) {
      $clean = trim($clean, '()');
    }
    $float = (float) $clean;
    return $isNegative ? -1 * $float : $float;
  }

  /**
   * Gets the annual individual giving amount.
   *
   * @return float
   *   The annual individual giving amount.
   */
  public function getAnnualIndividualGiving(): float {
    $year = (int) date('Y');
    if (date('n') <= 3) {
      $year = 2025;
    }

    // Individual giving = Financial Type "Donation" (1) + Contact Type "Individual".
    // We exclude Event Fees (4) and Organization donations.
    $query = $this->database->select('civicrm_contribution', 'c');
    $query->innerJoin('civicrm_contact', 'ct', 'c.contact_id = ct.id');
    $query->addExpression('SUM(c.total_amount)', 'total');
    $query->condition('c.financial_type_id', 1);
    $query->condition('c.contribution_status_id', 1);
    $query->condition('ct.contact_type', 'Individual');
    
    $start = $year . '-01-01 00:00:00';
    $end = $year . '-12-31 23:59:59';
    $query->condition('c.receive_date', [$start, $end], 'BETWEEN');

    $result = $query->execute()->fetchField();
    return (float) ($result ?: 0.0);
  }

  /**
   * Gets the annual corporate sponsorships amount.
   *
   * @return float
   *   The annual corporate sponsorships amount.
   */
  public function getAnnualCorporateSponsorships(): float {
    $year = (int) date('Y');
    if (date('n') <= 3) {
      $year = 2025;
    }
    return abs($this->getActualsForMetric('income_corporate_donations', $year));
  }

  /**
   * Gets the number of non-government grants secured.
   *
   * @return int
   *   The number of non-government grants secured.
   */
  public function getNonGovernmentGrantsSecured(): int {
    // Note: The sheet currently tracks dollar amount, not count.
    // Returning 0 for now as we don't have a count in the sheet.
    return 0;
  }

  /**
   * Gets the net income from the education program.
   *
   * @return float
   *   The net income from the education program.
   */
  public function getNetIncomeEducationProgram(): float {
    $year = (int) date('Y');
    if (date('n') <= 3) {
      $year = 2025;
    }
    $income = $this->getActualsForMetric('income_education', $year);
    $expense = $this->getActualsForMetric('expense_education', $year);
    return (float) ($income + $expense);
  }

  /**
   * Calculates the (Revenue per head) / (Expense per head) index.
   */
  public function getRevenuePerMemberIndex(): float {
    $year = (int) date('Y');
    if (date('n') <= 3) {
      $year = 2025;
    }

    $totalExp = abs($this->getActualsForMetric('expense_total', $year));
    $totalRev = $this->getAnnualMemberRevenue();

    // Get average active members for the year.
    $snapshots = $this->snapshotData->getMembershipCountSeries('month');
    $memberSum = 0;
    $monthCount = 0;
    foreach ($snapshots as $s) {
      if ($s['period_date']->format('Y') == (string) $year) {
        $memberSum += $s['members_active'];
        $monthCount++;
      }
    }
    $avgMembers = $monthCount > 0 ? ($memberSum / $monthCount) : 1;

    if ($avgMembers <= 0) {
      return 0.0;
    }

    $monthlyExpPerMember = ($totalExp / 12) / $avgMembers;
    $monthlyRevPerMember = ($totalRev / 12) / $avgMembers;

    if ($monthlyExpPerMember > 0) {
      return $monthlyRevPerMember / $monthlyExpPerMember;
    }

    return 0.0;
  }

  /**
   * Calculates the total monthly revenue for members with failed payments.
   */
  public function getMonthlyRevenueAtRisk(): float {
    $query = $this->database->select('ms_member_success_snapshot', 's');
    $query->innerJoin('profile', 'p', 'p.uid = s.uid AND p.type = :type', [':type' => 'main']);
    $query->innerJoin('profile__field_member_payment_monthly', 'pm', 'pm.entity_id = p.profile_id');
    $query->addExpression('SUM(pm.field_member_payment_monthly_value)', 'total');
    $query->condition('s.payment_failed', 1);
    $query->condition('s.is_latest', 1);
    $query->condition('s.snapshot_type', 'daily');

    $result = $query->execute()->fetchField();
    return (float) ($result ?: 0.0);
  }

  /**
   * Calculates total recurring revenue from members who joined in a specific year.
   */
  public function getAnnualNewRecurringRevenue(int $year): float {
    // 1. Get all Contacts who joined in this year.
    $query = $this->database->select('civicrm_uf_match', 'ufm');
    $query->innerJoin('users_field_data', 'u', 'u.uid = ufm.uf_id');
    $query->innerJoin('profile', 'p', 'p.uid = u.uid AND p.type = :type', [':type' => 'main']);
    $query->innerJoin('profile__field_member_payment_monthly', 'pm', 'pm.entity_id = p.profile_id');
    
    $query->addExpression('SUM(pm.field_member_payment_monthly_value)', 'total');
    
    $start = strtotime($year . '-01-01 00:00:00');
    $end = strtotime($year . '-12-31 23:59:59');
    
    $query->condition('u.created', [$start, $end], 'BETWEEN');
    $query->condition('u.status', 1);

    $result = $query->execute()->fetchField();
    return (float) ($result ?: 0.0);
  }

  /**
   * Gets the quarterly net income trend for program lines.
   */
  public function getNetIncomeProgramLinesTrend(): array {
    $storageInc = $this->getMetricTrend('income_storage');
    $storageExp = $this->getMetricTrend('expense_storage');
    $workInc = $this->getMetricTrend('income_workspaces');
    $workExp = $this->getMetricTrend('expense_workspaces');
    $mediaInc = $this->getMetricTrend('income_media');

    $trend = [];
    $count = count($storageInc);
    for ($i = 0; $i < $count; $i++) {
      $net = ($storageInc[$i] ?? 0) + ($storageExp[$i] ?? 0)
           + ($workInc[$i] ?? 0) + ($workExp[$i] ?? 0)
           + ($mediaInc[$i] ?? 0);
      $trend[] = $net;
    }
    return $trend;
  }

  /**
   * Gets the quarterly net income trend for education.
   */
  public function getNetIncomeEducationTrend(): array {
    $income = $this->getMetricTrend('income_education');
    $expense = $this->getMetricTrend('expense_education');

    $trend = [];
    $count = count($income);
    for ($i = 0; $i < $count; $i++) {
      $trend[] = ($income[$i] ?? 0) + ($expense[$i] ?? 0);
    }
    return $trend;
  }

  /**
   * Gets the quarterly adherence trend for the shop budget.
   */
  public function getShopBudgetAdherenceTrend(): array {
    $actuals = $this->getMetricTrend('expense_shop_operations');
    
    // Budget is annual, we need to divide by 4 for quarterly approximation.
    // Or just use the raw actuals if we want to show spending trend.
    // Given the user asked for trend links, showing the quarterly spending 
    // vs a constant 1/4 budget line is most informative.
    
    // For now, let is return the raw quarterly actuals so the sparkline 
    // shows the spending pattern.
    return $actuals;
  }

  /**
   * Reads monthly balance sheet values for a named account.
   *
   * The Balance-Sheet tab has headers like "Jan 31, 2026", "Dec 31, 2025", etc.
   * Returns an array sorted oldest-first, each element being:
   *   ['label' => 'Jan 2026', 'value' => 157007.0, 'month_key' => '2026-01']
   *
   * @param string $accountName  Exact string in the Account column, e.g.
   *   'Cash and Cash Equivalents'.
   */
  public function getBalanceSheetAccountByMonth(string $accountName): array {
    $data = $this->googleSheetClient->getSheetData('Balance-Sheet');
    if (empty($data)) {
      return [];
    }

    $headers = array_shift($data);

    $accountRow = NULL;
    foreach ($data as $row) {
      if (isset($row[1]) && trim((string) $row[1]) === $accountName) {
        $accountRow = $row;
        break;
      }
    }

    if (!$accountRow) {
      return [];
    }

    $points = [];
    foreach ($headers as $idx => $header) {
      if ($idx < 2 || empty($header)) {
        continue;
      }
      // Parse "Mon DD, YYYY" — handles both "Jan 31, 2026" and "Feb 29, 2024".
      $cleaned = trim((string) $header);
      $dt = \DateTimeImmutable::createFromFormat('M j, Y', $cleaned);
      if (!$dt) {
        continue;
      }
      $val = isset($accountRow[$idx]) ? $this->parseCurrencyValue((string) $accountRow[$idx]) : 0.0;
      $sortKey = $dt->format('Y-m');
      $points[$sortKey] = [
        'label'     => $dt->format('M Y'),
        'value'     => $val,
        'month_key' => $sortKey,
      ];
    }

    ksort($points);
    return array_values($points);
  }

  /**
   * Calculates Reserve Funds (as Months of Operating Expense).
   *
   * Cash = most recent "Cash and Cash Equivalents" from Balance Sheet.
   * Denominator = TTM total expense / 12 (average monthly operating expense).
   */
  public function getReserveFundsMonths(): float {
    $cashData = $this->getBalanceSheetAccountByMonth('Cash and Cash Equivalents');
    if (empty($cashData)) {
      return 0.0;
    }

    $cash = (float) end($cashData)['value'];
    $annualExp = abs($this->getMetricTtmSum('expense_total', 4));

    if ($annualExp > 0) {
      return round($cash / ($annualExp / 12), 2);
    }
    return 0.0;
  }

  /**
   * Returns a monthly Reserve Funds trend (months of operating expense).
   *
   * Uses real Balance Sheet cash balances paired with a per-quarter monthly
   * expense derived from the Income Statement, falling back to TTM average.
   *
   * @param int $months  Number of most-recent months to return (default 18).
   *
   * @return float[]  Oldest-first array of reserve-months values.
   */
  public function getReserveFundsMonthsTrend(int $months = 18): array {
    $cashData = $this->getBalanceSheetAccountByMonth('Cash and Cash Equivalents');
    if (empty($cashData)) {
      return [];
    }

    // Build a map of YYYY-MM => monthly_expense from the quarterly expense row.
    // Each quarter covers 3 months; monthly expense ≈ quarterly total / 3.
    $quarterMap = ['Jan-Mar' => [1, 2, 3], 'Apr-Jun' => [4, 5, 6], 'Jul-Sep' => [7, 8, 9], 'Jul-Sept' => [7, 8, 9], 'Oct-Dec' => [10, 11, 12]];
    $expData    = $this->googleSheetClient->getSheetData('Income-Statement');
    $monthlyExpMap = [];

    if (!empty($expData)) {
      $expHeaders = array_shift($expData);
      $expRow = NULL;
      foreach ($expData as $row) {
        if (isset($row[1]) && $row[1] === 'expense_total') {
          $expRow = $row;
          break;
        }
      }

      if ($expRow) {
        foreach ($expHeaders as $idx => $header) {
          if (preg_match('/^(Jan-Mar|Apr-Jun|Jul-Sept?|Oct-Dec)\s+(\d{4})$/', trim((string) $header), $m)) {
            $qExp = abs($this->parseCurrencyValue($expRow[$idx] ?? '0')) / 3;
            foreach ($quarterMap[$m[1]] as $mo) {
              $monthlyExpMap[sprintf('%s-%02d', $m[2], $mo)] = $qExp;
            }
          }
        }
      }
    }

    // TTM fallback denominator in case a month has no matched quarter.
    $ttmExp = abs($this->getMetricTtmSum('expense_total', 4));
    $fallbackMonthly = $ttmExp > 0 ? $ttmExp / 12 : 0.0;

    $trend = [];
    foreach (array_slice($cashData, -$months) as $point) {
      $cash      = (float) $point['value'];
      $monthlyExp = $monthlyExpMap[$point['month_key']] ?? $fallbackMonthly;
      $trend[]   = $monthlyExp > 0 ? round($cash / $monthlyExp, 2) : 0.0;
    }

    return $trend;
  }

  /**
   * Sums the last N quarters of a metric to produce a trailing total.
   *
   * This avoids the "2025 full year" problem: at any point in time you get a
   * full trailing-twelve-months figure regardless of calendar year boundary.
   *
   * @param string $metricKey
   *   The metric row key in the Income-Statement sheet.
   * @param int $quarters
   *   Number of trailing quarters to include (default 4 = TTM).
   *
   * @return float
   *   The sum (absolute value) of the last $quarters data points.
   */
  public function getMetricTtmSum(string $metricKey, int $quarters = 4): float {
    $trend = $this->getMetricTrend($metricKey, $quarters);
    if (empty($trend)) {
      return 0.0;
    }
    return abs((float) array_sum(array_slice($trend, -$quarters)));
  }

  /**
   * Returns trailing-12-month net income for the education program.
   */
  public function getNetIncomeEducationTtm(): float {
    $income = $this->getMetricTrend('income_education', 4);
    $expense = $this->getMetricTrend('expense_education', 4);
    $net = 0.0;
    $count = max(count($income), count($expense));
    for ($i = 0; $i < $count; $i++) {
      $net += ($income[$i] ?? 0.0) + ($expense[$i] ?? 0.0);
    }
    return (float) $net;
  }

  /**
   * Returns trailing-12-month net income for program lines (storage/workspaces/media).
   */
  public function getNetIncomeProgramLinesTtm(): float {
    $storageInc = $this->getMetricTrend('income_storage', 4);
    $storageExp = $this->getMetricTrend('expense_storage', 4);
    $workInc    = $this->getMetricTrend('income_workspaces', 4);
    $workExp    = $this->getMetricTrend('expense_workspaces', 4);
    $mediaInc   = $this->getMetricTrend('income_media', 4);
    $net = 0.0;
    $count = max(count($storageInc), count($workInc), count($mediaInc));
    for ($i = 0; $i < $count; $i++) {
      $net += ($storageInc[$i] ?? 0) + ($storageExp[$i] ?? 0)
            + ($workInc[$i] ?? 0)    + ($workExp[$i] ?? 0)
            + ($mediaInc[$i] ?? 0);
    }
    return (float) $net;
  }

  /**
   * Returns [year, quarterNumber] for the most recently completed quarter.
   *
   * @return array{int, int}  [$year, $quarter]  e.g. [2025, 4]
   */
  public function getPreviousQuarterLabel(): array {
    $month = (int) date('n');
    $year  = (int) date('Y');
    $currentQ = (int) ceil($month / 3);
    if ($currentQ === 1) {
      return [$year - 1, 4];
    }
    return [$year, $currentQ - 1];
  }

  /**
   * Converts a [year, quarter] pair to the "Mon-Mon YYYY" column label used
   * in the Income-Statement Google Sheet.
   *
   * @param int $year
   * @param int $q  1–4
   *
   * @return string  e.g. "Oct-Dec 2025"
   */
  public function quarterToColumnLabel(int $year, int $q): string {
    $map = [1 => 'Jan-Mar', 2 => 'Apr-Jun', 3 => 'Jul-Sep', 4 => 'Oct-Dec'];
    return ($map[$q] ?? 'Jan-Mar') . ' ' . $year;
  }

  /**
   * Gets membership income for the most recently completed quarter.
   */
  public function getPreviousQuarterMemberRevenue(): ?float {
    [$prevYear, $prevQ] = $this->getPreviousQuarterLabel();
    $targetCol = $this->quarterToColumnLabel($prevYear, $prevQ);

    $data = $this->googleSheetClient->getSheetData('Income-Statement');
    if (empty($data)) {
      return NULL;
    }

    $headers = array_shift($data);
    $colIdx = -1;
    // The sheet has used both "Jul-Sep" and "Jul-Sept" for Q3 headers.
    $candidates = [$targetCol, str_replace('Jul-Sep ', 'Jul-Sept ', $targetCol)];
    foreach ($headers as $idx => $header) {
      if (in_array(trim((string) $header), $candidates, TRUE)) {
        $colIdx = $idx;
        break;
      }
    }

    if ($colIdx === -1) {
      // Quarter column not posted yet: n/a, not $0.
      return NULL;
    }

    foreach ($data as $row) {
      if (isset($row[1]) && $row[1] === 'income_membership') {
        $raw = trim((string) ($row[$colIdx] ?? ''));
        if ($raw === '') {
          return NULL;
        }
        return abs($this->parseCurrencyValue($raw));
      }
    }

    return NULL;
  }

  /**
   * Gets the sum of starting monthly dues for members who joined in the
   * trailing 12 months (rolling window, not calendar-year).
   */
  public function getTrailingNewRecurringRevenue(): float {
    $end = new \DateTimeImmutable('now');
    $start = $end->modify('-12 months');

    $query = $this->database->select('civicrm_uf_match', 'ufm');
    $query->innerJoin('users_field_data', 'u', 'u.uid = ufm.uf_id');
    $query->innerJoin('profile', 'p', 'p.uid = u.uid AND p.type = :type', [':type' => 'main']);
    $query->innerJoin('profile__field_member_payment_monthly', 'pm', 'pm.entity_id = p.profile_id');
    $query->addExpression('SUM(pm.field_member_payment_monthly_value)', 'total');
    $query->condition('u.created', [$start->getTimestamp(), $end->getTimestamp()], 'BETWEEN');
    $query->condition('u.status', 1);

    $result = $query->execute()->fetchField();
    return (float) ($result ?: 0.0);
  }

  /**
   * Returns quarterly earned income sustaining core rate as a trend (oldest-first).
   *
   * Each point = (Total Income - Grants - Individual Donations - Corporate Donations)
   * / Total Expense for a single quarter from the Income-Statement sheet.
   */
  public function getEarnedIncomeSustainingCoreTrend(int $limit = 8): array {
    $totalInc = $this->getMetricTrend('income_total', $limit);
    $grants   = $this->getMetricTrend('income_grants', $limit);
    $indiv    = $this->getMetricTrend('income_individual_donations', $limit);
    $corp     = $this->getMetricTrend('income_corporate_donations', $limit);
    $expense  = $this->getMetricTrend('expense_total', $limit);

    $trend = [];
    $count = max(count($totalInc), count($expense));
    for ($i = 0; $i < $count; $i++) {
      $inc    = $totalInc[$i] ?? 0.0;
      $g      = $grants[$i] ?? 0.0;
      $d      = $indiv[$i] ?? 0.0;
      $c      = $corp[$i] ?? 0.0;
      $exp    = abs($expense[$i] ?? 0.0);
      $earned = $inc - ($g + $d + $c);
      $trend[] = $exp > 0 ? round($earned / $exp, 4) : 0.0;
    }
    return $trend;
  }

  /**
   * Returns quarterly new recurring revenue as a trend (oldest-first).
   *
   * Each point = sum of monthly dues for members whose Drupal account was
   * created in that quarter. Mirrors getTrailingNewRecurringRevenue() but
   * sliced by discrete quarters instead of a rolling window.
   */
  public function getNewRecurringRevenueTrend(int $quarters = 8): array {
    $now   = new \DateTimeImmutable('now');
    $month = (int) $now->format('n');
    $year  = (int) $now->format('Y');

    // Start from the most recently completed quarter.
    $prevQ = (int) ceil($month / 3) - 1;
    if ($prevQ <= 0) {
      $prevQ = 4;
      $year--;
    }

    $trend = [];
    for ($i = $quarters - 1; $i >= 0; $i--) {
      $targetQ    = $prevQ - $i;
      $targetYear = $year;
      while ($targetQ <= 0) {
        $targetQ += 4;
        $targetYear--;
      }

      $startMonth = ($targetQ - 1) * 3 + 1;
      $endMonth   = $targetQ * 3;
      $lastDay    = (int)(new \DateTimeImmutable(sprintf('%d-%02d-01', $targetYear, $endMonth)))->format('t');
      $start = new \DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $targetYear, $startMonth));
      $end   = new \DateTimeImmutable(sprintf('%d-%02d-%02d 23:59:59', $targetYear, $endMonth, $lastDay));

      $query = $this->database->select('civicrm_uf_match', 'ufm');
      $query->innerJoin('users_field_data', 'u', 'u.uid = ufm.uf_id');
      $query->innerJoin('profile', 'p', 'p.uid = u.uid AND p.type = :type', [':type' => 'main']);
      $query->innerJoin('profile__field_member_payment_monthly', 'pm', 'pm.entity_id = p.profile_id');
      $query->addExpression('SUM(pm.field_member_payment_monthly_value)', 'total');
      $query->condition('u.created', [$start->getTimestamp(), $end->getTimestamp()], 'BETWEEN');
      $query->condition('u.status', 1);

      $trend[] = (float) ($query->execute()->fetchField() ?: 0.0);
    }

    return $trend;
  }

  /**
   * Calculates Earned Income Sustaining Core %.
   */
  public function getEarnedIncomeSustainingCoreRate(): float {
    $year = (int) date('Y');
    if (date('n') <= 3) { $year = 2025; }

    $totalInc = $this->getActualsForMetric('income_total', $year);
    $grants = $this->getActualsForMetric('income_grants', $year);
    $indiv = $this->getActualsForMetric('income_individual_donations', $year);
    $corp = $this->getActualsForMetric('income_corporate_donations', $year);
    
    $earnedInc = $totalInc - ($grants + $indiv + $corp);
    $totalExp = abs($this->getActualsForMetric('expense_total', $year));

    if ($totalExp > 0) {
      return $earnedInc / $totalExp;
    }
    return 0.0;
  }

  /**
   * Returns an ordered list of quarterly labels and metric values from the Income-Statement sheet.
   *
   * Internal helper for the multi-stream finance trend charts. Reads the sheet
   * once and returns:
   *   - labels: ordered ['Q1 2024', 'Q2 2024', ...] strings, oldest first
   *   - rows:   metricKey => [float values aligned to labels]
   *
   * @param string[] $metricKeys
   *   Income-Statement metric keys to extract (e.g. 'income_membership').
   * @param int $quarters
   *   How many trailing quarters to return.
   *
   * @return array{labels: string[], rows: array<string, float[]>}
   */
  protected function getIncomeStatementSeries(array $metricKeys, int $quarters): array {
    $data = $this->googleSheetClient->getSheetData('Income-Statement');
    if (empty($data)) {
      return ['labels' => [], 'rows' => []];
    }

    $headers = array_shift($data);
    $quarterOrder = ['Jan-Mar' => 1, 'Apr-Jun' => 2, 'Jul-Sep' => 3, 'Jul-Sept' => 3, 'Oct-Dec' => 4];

    $sortedColumns = [];
    foreach ($headers as $idx => $header) {
      if (preg_match('/^(Jan-Mar|Apr-Jun|Jul-Sept?|Oct-Dec)\s+(\d{4})$/', trim((string) $header), $m)) {
        $year = (int) $m[2];
        $q = $quarterOrder[$m[1]];
        $sortKey = sprintf('%04d0%d', $year, $q);
        $sortedColumns[$sortKey] = [
          'idx' => $idx,
          'label' => sprintf('Q%d %d', $q, $year),
        ];
      }
    }

    if (empty($sortedColumns)) {
      return ['labels' => [], 'rows' => []];
    }

    ksort($sortedColumns);
    $sortedColumns = array_slice($sortedColumns, -$quarters, NULL, TRUE);
    $labels = array_values(array_map(static fn(array $c) => $c['label'], $sortedColumns));

    $needed = array_flip($metricKeys);
    $rows = [];
    foreach ($metricKeys as $key) {
      $rows[$key] = array_fill(0, count($sortedColumns), 0.0);
    }

    foreach ($data as $row) {
      $key = isset($row[1]) ? (string) $row[1] : '';
      if (!isset($needed[$key])) {
        continue;
      }
      $position = 0;
      foreach ($sortedColumns as $column) {
        $rows[$key][$position] = $this->parseCurrencyValue((string) ($row[$column['idx']] ?? '0'));
        $position++;
      }
    }

    return ['labels' => $labels, 'rows' => $rows];
  }

  /**
   * Quarterly revenue broken out by income stream for the revenue-mix chart.
   *
   * Income totals are returned as positive dollars. The "Other earned income"
   * series is derived as income_total minus the sum of the named streams, so
   * any line not yet broken out (e.g. event hosting, future store/lending) is
   * still represented rather than lost.
   *
   * @param int $quarters
   *   Trailing quarters to include (default 8 = two years).
   *
   * @return array{labels: string[], series: array<string, float[]>}
   */
  public function getRevenueMixTrend(int $quarters = 8): array {
    $cid = 'makerspace_dashboard:revenue_mix:' . $quarters;
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $streams = [
      'income_membership' => 'Membership dues',
      'income_education' => 'Education',
      'income_storage' => 'Storage',
      'income_workspaces' => 'Workspaces',
      'income_media' => 'Media',
      'income_grants' => 'Grants',
      'income_individual_donations' => 'Individual donations',
      'income_corporate_donations' => 'Corporate donations',
    ];
    $needed = array_merge(array_keys($streams), ['income_total']);

    $sheet = $this->getIncomeStatementSeries($needed, $quarters);
    if (empty($sheet['labels'])) {
      return ['labels' => [], 'series' => []];
    }

    $series = [];
    foreach ($streams as $key => $label) {
      $series[$label] = array_map(static fn($v) => max(0.0, (float) $v), $sheet['rows'][$key]);
    }

    $totals = array_map(static fn($v) => max(0.0, (float) $v), $sheet['rows']['income_total']);
    $other = [];
    foreach ($totals as $i => $total) {
      $named = 0.0;
      foreach ($streams as $key => $unused) {
        $named += $sheet['rows'][$key][$i] ?? 0.0;
      }
      $other[] = max(0.0, $total - $named);
    }
    if (array_sum($other) > 0) {
      $series['Other earned income'] = $other;
    }

    $result = ['labels' => $sheet['labels'], 'series' => $series];
    $this->cache->set($cid, $result, time() + 3600);
    return $result;
  }

  /**
   * Monthly MRR change waterfall: dollars added by first-time joins,
   * reactivations, and dollars lost to ends.
   *
   * "First join" anchor is COALESCE(field_member_join_date, profile.created):
   * field_member_join_date stopped being populated for new members in Oct 2024
   * (intentional — see project_member_tenure_date_convention memory).
   *
   * Reactivations come from field_member_reactivation_date — a separate field
   * that the join workflow still writes when a lapsed member returns. Without
   * this series the chart would systematically under-count MRR adds (~$4-5k
   * over 12 months at current volumes).
   *
   * Plan upgrades, downgrades, pauses, and price changes still aren't visible
   * here — they require a monthly MRR snapshot diff (deferred until the
   * makerspace_snapshot cron is reliable enough to provide history).
   *
   * @param int $months
   *   Trailing months to include (default 12).
   *
   * @return array{
   *   labels: string[],
   *   joined: float[],
   *   reactivated: float[],
   *   ended: float[],
   *   net: float[],
   *   at_risk_today: float
   * }
   */
  public function getMrrWaterfallTrend(int $months = 12): array {
    $cid = 'makerspace_dashboard:mrr_waterfall:' . $months;
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $end = new \DateTimeImmutable('first day of next month 00:00:00');
    $start = $end->modify('-' . $months . ' months');

    $labels = [];
    $bucket = [];
    $cursor = $start;
    while ($cursor < $end) {
      $key = $cursor->format('Y-m');
      $labels[] = $cursor->format('M Y');
      $bucket[$key] = ['joined' => 0.0, 'reactivated' => 0.0, 'ended' => 0.0];
      $cursor = $cursor->modify('+1 month');
    }

    // Filter to Chargebee-billed members only so the chart reconciles with
    // Chargebee MRR. Comps, founders, and manually-billed members have no
    // entry in user__field_user_chargebee_plan and are excluded — they exist
    // in Drupal MRR but not in Chargebee's reporting.
    //
    // Join dates: COALESCE field_member_join_date with profile.created, since
    // field_member_join_date stopped populating for new members around Oct
    // 2024 (see project_member_join_date_field_stale memory).
    $hasChargebeePlan = $this->database->schema()->tableExists('user__field_user_chargebee_plan');
    if ($this->database->schema()->tableExists('profile__field_member_payment_monthly')) {
      $joinQuery = $this->database->select('profile', 'p');
      $joinQuery->leftJoin('profile__field_member_join_date', 'jd', 'jd.entity_id = p.profile_id AND jd.deleted = 0');
      $joinQuery->innerJoin('profile__field_member_payment_monthly', 'pm', 'pm.entity_id = p.profile_id AND pm.deleted = 0');
      if ($hasChargebeePlan) {
        $joinQuery->innerJoin('user__field_user_chargebee_plan', 'cb', "cb.entity_id = p.uid AND cb.field_user_chargebee_plan_value <> ''");
      }
      $joinQuery->addExpression("DATE_FORMAT(COALESCE(jd.field_member_join_date_value, FROM_UNIXTIME(p.created)), '%Y-%m')", 'period');
      $joinQuery->addExpression('SUM(pm.field_member_payment_monthly_value)', 'total');
      $joinQuery->condition('p.type', 'main');
      $joinQuery->condition('p.is_default', 1);
      $joinQuery->where("COALESCE(jd.field_member_join_date_value, FROM_UNIXTIME(p.created)) BETWEEN :start AND :end", [
        ':start' => $start->format('Y-m-d'),
        ':end' => $end->format('Y-m-d'),
      ]);
      $joinQuery->groupBy('period');
      foreach ($joinQuery->execute()->fetchAll() as $row) {
        if (isset($bucket[$row->period])) {
          $bucket[$row->period]['joined'] = (float) $row->total;
        }
      }
    }

    if ($this->database->schema()->tableExists('profile__field_member_reactivation_date')
      && $this->database->schema()->tableExists('profile__field_member_payment_monthly')) {
      $reactivationQuery = $this->database->select('profile', 'p');
      $reactivationQuery->innerJoin('profile__field_member_reactivation_date', 'rd', 'rd.entity_id = p.profile_id AND rd.deleted = 0');
      $reactivationQuery->innerJoin('profile__field_member_payment_monthly', 'pm', 'pm.entity_id = p.profile_id AND pm.deleted = 0');
      if ($hasChargebeePlan) {
        $reactivationQuery->innerJoin('user__field_user_chargebee_plan', 'cb', "cb.entity_id = p.uid AND cb.field_user_chargebee_plan_value <> ''");
      }
      $reactivationQuery->addExpression("DATE_FORMAT(rd.field_member_reactivation_date_value, '%Y-%m')", 'period');
      $reactivationQuery->addExpression('SUM(pm.field_member_payment_monthly_value)', 'total');
      $reactivationQuery->condition('p.type', 'main');
      $reactivationQuery->condition('p.is_default', 1);
      $reactivationQuery->condition('rd.field_member_reactivation_date_value', [$start->format('Y-m-d'), $end->format('Y-m-d')], 'BETWEEN');
      $reactivationQuery->groupBy('period');
      foreach ($reactivationQuery->execute()->fetchAll() as $row) {
        if (isset($bucket[$row->period])) {
          $bucket[$row->period]['reactivated'] = (float) $row->total;
        }
      }
    }

    if ($this->database->schema()->tableExists('profile__field_member_end_date')
      && $this->database->schema()->tableExists('profile__field_member_payment_monthly')) {
      $endQuery = $this->database->select('profile', 'p');
      $endQuery->innerJoin('profile__field_member_end_date', 'ed', 'ed.entity_id = p.profile_id AND ed.deleted = 0');
      $endQuery->innerJoin('profile__field_member_payment_monthly', 'pm', 'pm.entity_id = p.profile_id AND pm.deleted = 0');
      if ($hasChargebeePlan) {
        $endQuery->innerJoin('user__field_user_chargebee_plan', 'cb', "cb.entity_id = p.uid AND cb.field_user_chargebee_plan_value <> ''");
      }
      $endQuery->addExpression("DATE_FORMAT(ed.field_member_end_date_value, '%Y-%m')", 'period');
      $endQuery->addExpression('SUM(pm.field_member_payment_monthly_value)', 'total');
      $endQuery->condition('p.type', 'main');
      $endQuery->condition('p.is_default', 1);
      $endQuery->condition('ed.field_member_end_date_value', [$start->format('Y-m-d'), $end->format('Y-m-d')], 'BETWEEN');
      $endQuery->groupBy('period');
      foreach ($endQuery->execute()->fetchAll() as $row) {
        if (isset($bucket[$row->period])) {
          $bucket[$row->period]['ended'] = (float) $row->total;
        }
      }
    }

    $joined = [];
    $reactivated = [];
    $ended = [];
    $net = [];
    foreach ($bucket as $row) {
      $joined[] = round((float) $row['joined'], 2);
      $reactivated[] = round((float) $row['reactivated'], 2);
      $ended[] = round((float) $row['ended'], 2);
      $net[] = round((float) $row['joined'] + (float) $row['reactivated'] - (float) $row['ended'], 2);
    }

    $result = [
      'labels' => $labels,
      'joined' => $joined,
      'reactivated' => $reactivated,
      'ended' => $ended,
      'net' => $net,
      'at_risk_today' => $this->getMonthlyRevenueAtRisk(),
    ];

    $this->cache->set($cid, $result, time() + 1800, ['profile_list']);
    return $result;
  }

  /**
   * Cumulative dues collected per joiner, by months-since-join, for recent cohorts.
   *
   * For each quarterly join cohort, we estimate cumulative monthly dues by
   * multiplying each member's stored monthly value by their tenure in months
   * (capped at end_date or today). This is a proxy: we don't have a structured
   * payment ledger in Drupal, but it tracks what the org actually expected to
   * collect from each cohort if everyone paid their stated monthly dues. The
   * chart's purpose is to compare cohort *shapes* — newer cohorts pulling
   * above or below older ones flags retention drift before LTV averages catch
   * up.
   *
   * @param int $cohortQuarters
   *   How many trailing quarterly cohorts to plot (default 6 = 18 months).
   * @param int $monthsHorizon
   *   How many months of cumulative curve to render per cohort (default 24).
   *
   * @return array{
   *   labels: string[],
   *   cohorts: array<string, array{values: float[], size: int}>
   * }
   */
  public function getCohortLtvCurves(int $cohortQuarters = 6, int $monthsHorizon = 24): array {
    $cid = sprintf('makerspace_dashboard:cohort_ltv:%d:%d', $cohortQuarters, $monthsHorizon);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('profile__field_member_payment_monthly')) {
      return ['labels' => [], 'cohorts' => []];
    }

    $today = new \DateTimeImmutable('today');
    $cohortStart = $today->modify('first day of -' . ($cohortQuarters * 3) . ' months');

    // Cohort assignment uses current-tenure-start so a 2014 member who lapsed
    // and rejoined in 2023 lands in the 2023 cohort (where the retention
    // analysis question is "how is this active relationship doing"). See
    // project_member_tenure_date_convention memory for the full rule.
    $query = $this->database->select('profile', 'p');
    $query->leftJoin('profile__field_member_join_date', 'jd', 'jd.entity_id = p.profile_id AND jd.deleted = 0');
    $query->leftJoin('profile__field_member_reactivation_date', 'rd', 'rd.entity_id = p.profile_id AND rd.deleted = 0');
    $query->innerJoin('profile__field_member_payment_monthly', 'pm', 'pm.entity_id = p.profile_id AND pm.deleted = 0');
    $query->leftJoin('profile__field_member_end_date', 'ed', 'ed.entity_id = p.profile_id AND ed.deleted = 0');
    $query->addExpression("COALESCE(rd.field_member_reactivation_date_value, jd.field_member_join_date_value, DATE_FORMAT(FROM_UNIXTIME(p.created), '%Y-%m-%d'))", 'tenure_start');
    $query->addField('ed', 'field_member_end_date_value', 'end_date');
    $query->addField('pm', 'field_member_payment_monthly_value', 'monthly_value');
    $query->condition('p.type', 'main');
    $query->condition('p.is_default', 1);
    $query->where("COALESCE(rd.field_member_reactivation_date_value, jd.field_member_join_date_value, FROM_UNIXTIME(p.created)) BETWEEN :start AND :end", [
      ':start' => $cohortStart->format('Y-m-d'),
      ':end' => $today->format('Y-m-d'),
    ]);
    $rows = $query->execute()->fetchAll();

    $cohortBuckets = [];
    foreach ($rows as $row) {
      try {
        $joinDate = new \DateTimeImmutable($row->tenure_start);
      }
      catch (\Exception $e) {
        continue;
      }
      $monthly = (float) $row->monthly_value;
      if ($monthly <= 0) {
        continue;
      }

      $endDate = NULL;
      if (!empty($row->end_date)) {
        try {
          $endDate = new \DateTimeImmutable($row->end_date);
        }
        catch (\Exception $e) {
          $endDate = NULL;
        }
      }
      $effectiveEnd = $endDate && $endDate < $today ? $endDate : $today;
      $tenureMonths = max(0, $this->monthsBetween($joinDate, $effectiveEnd));

      $quarter = (int) ceil(((int) $joinDate->format('n')) / 3);
      $cohortKey = sprintf('Q%d %s', $quarter, $joinDate->format('Y'));

      if (!isset($cohortBuckets[$cohortKey])) {
        $cohortBuckets[$cohortKey] = [
          'sort' => $joinDate->format('Y') . sprintf('%02d', $quarter),
          'members' => [],
        ];
      }
      $cohortBuckets[$cohortKey]['members'][] = [
        'monthly' => $monthly,
        'tenure' => $tenureMonths,
      ];
    }

    uasort($cohortBuckets, static fn($a, $b) => strcmp($a['sort'], $b['sort']));

    $labels = [];
    for ($i = 0; $i <= $monthsHorizon; $i++) {
      $labels[] = (string) $i;
    }

    $cohorts = [];
    foreach ($cohortBuckets as $label => $info) {
      $size = count($info['members']);
      if ($size === 0) {
        continue;
      }
      $values = [];
      for ($month = 0; $month <= $monthsHorizon; $month++) {
        $sumDollars = 0.0;
        foreach ($info['members'] as $member) {
          $monthsPaid = min($month, (int) $member['tenure']);
          $sumDollars += $monthsPaid * (float) $member['monthly'];
        }
        $values[] = round($sumDollars / $size, 2);
      }
      $cohorts[$label] = [
        'values' => $values,
        'size' => $size,
      ];
    }

    $result = ['labels' => $labels, 'cohorts' => $cohorts];
    $this->cache->set($cid, $result, time() + 3600, ['profile_list']);
    return $result;
  }

  /**
   * Sum of registration fees for upcoming events, bucketed by horizon and event type.
   *
   * Forward-booked revenue: for each registration in the next N days where the
   * participant status is counted (registered/attended), sum fee_amount and
   * group by (horizon bucket, event type). Gives the finance team a leading
   * indicator of next-quarter education income before it hits the books.
   *
   * @param int[] $horizons
   *   Cumulative day horizons (default [30, 60, 90]). Buckets are derived as
   *   "Next 30d", "31-60d", "61-90d" and so on.
   *
   * @return array{
   *   labels: string[],
   *   types: string[],
   *   matrix: array<string, float[]>,
   *   total: float
   * }
   */
  public function getForwardBookedWorkshopRevenue(array $horizons = [30, 60, 90]): array {
    sort($horizons);
    $cid = 'makerspace_dashboard:forward_workshop_revenue:' . implode('-', $horizons);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('civicrm_participant')
      || !$this->database->schema()->tableExists('civicrm_event')) {
      return ['labels' => [], 'types' => [], 'matrix' => [], 'total' => 0.0];
    }

    $now = new \DateTimeImmutable('now');
    $maxHorizon = max($horizons);
    $end = $now->modify('+' . $maxHorizon . ' days');

    $query = $this->database->select('civicrm_participant', 'p');
    $query->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    $query->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $query->leftJoin('civicrm_option_value', 'ov', 'ov.value = e.event_type_id AND ov.option_group_id IN (SELECT id FROM {civicrm_option_group} WHERE name = :grp)', [':grp' => 'event_type']);
    $query->addField('e', 'start_date');
    $query->addField('p', 'fee_amount');
    $query->addExpression("COALESCE(ov.label, 'Other')", 'event_type');
    $query->condition('pst.is_counted', 1);
    $query->isNotNull('e.start_date');
    $query->condition('e.start_date', [$now->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')], 'BETWEEN');
    $query->isNotNull('p.fee_amount');
    $rows = $query->execute()->fetchAll();

    $labels = [];
    $bucketBoundaries = [];
    $previous = 0;
    foreach ($horizons as $h) {
      if ($previous === 0) {
        $labels[] = sprintf('Next %dd', $h);
      }
      else {
        $labels[] = sprintf('%d–%dd', $previous + 1, $h);
      }
      $bucketBoundaries[] = $h;
      $previous = $h;
    }

    $types = [];
    $matrix = [];
    $total = 0.0;
    foreach ($rows as $row) {
      $fee = (float) $row->fee_amount;
      if ($fee <= 0) {
        continue;
      }
      try {
        $startDate = new \DateTimeImmutable($row->start_date);
      }
      catch (\Exception $e) {
        continue;
      }
      $daysOut = max(0, (int) $now->diff($startDate)->days);

      $bucketIdx = NULL;
      foreach ($bucketBoundaries as $idx => $boundary) {
        if ($daysOut <= $boundary) {
          $bucketIdx = $idx;
          break;
        }
      }
      if ($bucketIdx === NULL) {
        continue;
      }

      $type = (string) $row->event_type;
      if (!isset($matrix[$type])) {
        $matrix[$type] = array_fill(0, count($labels), 0.0);
        $types[] = $type;
      }
      $matrix[$type][$bucketIdx] += $fee;
      $total += $fee;
    }

    foreach ($matrix as $type => $values) {
      $matrix[$type] = array_map(static fn($v) => round((float) $v, 2), $values);
    }

    $result = [
      'labels' => $labels,
      'types' => $types,
      'matrix' => $matrix,
      'total' => round($total, 2),
    ];
    $this->cache->set($cid, $result, time() + 1800, ['civicrm_participant_list']);
    return $result;
  }

  /**
   * Monthly dunning recovery: annualized dollars touched, recovered, and lost.
   *
   * Sources `ms_member_outreach_log` joined to per-member monthly dues. For
   * each calendar month we count distinct members who had at least one
   * outreach contact, then split into:
   *   - recovered: had a positive outcome (payment_updated, will_return,
   *     no_action_needed) at least once in that month
   *   - lost: had a confirmed_cancel outcome in that month
   *   - in flight: contacted but neither recovered nor lost
   *
   * Dollar values are annualized (monthly_value × 12) to match the existing
   * `Monthly Revenue at Risk` KPI's denomination — so the chart speaks the
   * same language as the KPI rail.
   *
   * @param int $months
   *   Trailing months to include (default 12).
   *
   * @return array{
   *   labels: string[],
   *   recovered: float[],
   *   lost: float[],
   *   in_flight: float[],
   *   total_touched: float[]
   * }
   */
  public function getDunningRecoveryTrend(int $months = 12): array {
    $cid = 'makerspace_dashboard:dunning_recovery:' . $months;
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('ms_member_outreach_log')
      || !$this->database->schema()->tableExists('profile__field_member_payment_monthly')) {
      return ['labels' => [], 'recovered' => [], 'lost' => [], 'in_flight' => [], 'total_touched' => []];
    }

    $end = new \DateTimeImmutable('first day of next month 00:00:00');
    $start = $end->modify('-' . $months . ' months');

    $labels = [];
    $bucket = [];
    $cursor = $start;
    while ($cursor < $end) {
      $key = $cursor->format('Y-m');
      $labels[] = $cursor->format('M Y');
      $bucket[$key] = ['touched' => 0.0, 'recovered' => 0.0, 'lost' => 0.0];
      $cursor = $cursor->modify('+1 month');
    }

    $resolvedOutcomes = ['payment_updated', 'will_return', 'no_action_needed'];
    $lostOutcomes = ['confirmed_cancel'];

    // One row per (uid, month) with the best outcome that month.
    // Outcome priority: lost > recovered > other (since lost is terminal).
    $sql = "
      SELECT
        DATE_FORMAT(log.contact_date, '%Y-%m') AS period,
        log.uid,
        MAX(CASE WHEN log.outcome IN (:lost_outcomes[]) THEN 1 ELSE 0 END) AS was_lost,
        MAX(CASE WHEN log.outcome IN (:resolved_outcomes[]) THEN 1 ELSE 0 END) AS was_recovered,
        COALESCE(pm.field_member_payment_monthly_value, 0) AS monthly
      FROM {ms_member_outreach_log} log
      LEFT JOIN {profile} p
        ON p.uid = log.uid AND p.type = 'main' AND p.is_default = 1
      LEFT JOIN {profile__field_member_payment_monthly} pm
        ON pm.entity_id = p.profile_id AND pm.deleted = 0
      WHERE log.contact_date >= :start
        AND log.contact_date < :end
      GROUP BY DATE_FORMAT(log.contact_date, '%Y-%m'), log.uid, pm.field_member_payment_monthly_value
    ";

    $rows = $this->database->query($sql, [
      ':lost_outcomes[]' => $lostOutcomes,
      ':resolved_outcomes[]' => $resolvedOutcomes,
      ':start' => $start->format('Y-m-d H:i:s'),
      ':end' => $end->format('Y-m-d H:i:s'),
    ])->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
      $period = $row['period'];
      if (!isset($bucket[$period])) {
        continue;
      }
      $annual = ((float) $row['monthly']) * 12.0;
      $bucket[$period]['touched'] += $annual;
      if ((int) $row['was_lost'] === 1) {
        $bucket[$period]['lost'] += $annual;
      }
      elseif ((int) $row['was_recovered'] === 1) {
        $bucket[$period]['recovered'] += $annual;
      }
    }

    $recovered = [];
    $lost = [];
    $inFlight = [];
    $totals = [];
    foreach ($bucket as $row) {
      $recovered[] = round((float) $row['recovered'], 2);
      $lost[] = round((float) $row['lost'], 2);
      $totals[] = round((float) $row['touched'], 2);
      $inFlight[] = round(max(0.0, (float) $row['touched'] - (float) $row['recovered'] - (float) $row['lost']), 2);
    }

    $result = [
      'labels' => $labels,
      'recovered' => $recovered,
      'lost' => $lost,
      'in_flight' => $inFlight,
      'total_touched' => $totals,
    ];
    $this->cache->set($cid, $result, time() + 1800, ['profile_list']);
    return $result;
  }

  /**
   * Whole-month difference between two dates (a ≤ b assumed; clamps to 0).
   */
  protected function monthsBetween(\DateTimeImmutable $a, \DateTimeImmutable $b): int {
    if ($b < $a) {
      return 0;
    }
    $diff = $a->diff($b);
    return ($diff->y * 12) + $diff->m;
  }

  /**
   * Total revenue and operating expense by quarter for the runway-trend chart.
   *
   * Both lines are returned as positive dollars; in the sheet expense rows are
   * usually parenthesized (negative), so we abs() before returning so callers
   * can plot revenue and expense as comparable magnitudes.
   *
   * @param int $quarters
   *   Trailing quarters to include (default 8).
   *
   * @return array{labels: string[], revenue: float[], expense: float[]}
   */
  public function getRevenueVsExpenseTrend(int $quarters = 8): array {
    $cid = 'makerspace_dashboard:revenue_vs_expense:' . $quarters;
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $sheet = $this->getIncomeStatementSeries(['income_total', 'expense_total'], $quarters);
    if (empty($sheet['labels'])) {
      return ['labels' => [], 'revenue' => [], 'expense' => []];
    }

    $result = [
      'labels' => $sheet['labels'],
      'revenue' => array_map(static fn($v) => max(0.0, (float) $v), $sheet['rows']['income_total']),
      'expense' => array_map(static fn($v) => abs((float) $v), $sheet['rows']['expense_total']),
    ];

    $this->cache->set($cid, $result, time() + 3600);
    return $result;
  }

}
