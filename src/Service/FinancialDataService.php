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
    // @todo: Implement logic to get this value from Xero. This will be called
    // by the 'annual' snapshot in the makerspace_snapshot module to calculate
    // the "Reserve Funds (as Months of Operating Expense)" KPI.
    return 25000.00;
  }

  /**
   * Gets the earned income sustaining core percentage.
   *
   * @return float
   *   The earned income sustaining core percentage.
   */
  public function getEarnedIncomeSustainingCore(): float {
    // @todo: Implement logic to calculate this from Xero. The formula is:
    // (Income - (grants+donations)) / (expenses- (grant program expense +capital investment)).
    // This will be called by the 'annual' snapshot.
    return 0.85;
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

    // Extract values from columns that look like periods (e.g. "2025 Q1").
    $trend = [];
    foreach ($headers as $idx => $header) {
      if (preg_match('/\d{4}\sQ[1-4]/', $header)) {
        $val = $this->parseCurrencyValue($metricRow[$idx] ?? '0');
        // Store keyed by header so we can sort chronologically.
        $trend[$header] = $val;
      }
    }

    // Sort by year then quarter (e.g. "2023 Q1", "2023 Q2", etc.)
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
   * Gets the donor retention rate.
   *
   * @return float
   *   The donor retention rate.
   */
  public function getDonorRetentionRate(): float {
    // @todo: Implement logic to get this value from the finance system.
    return 0.0;
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
   * Calculates Reserve Funds (as Months of Operating Expense).
   */
  public function getReserveFundsMonths(): float {
    $year = (int) date('Y');
    if (date('n') <= 3) { $year = 2025; }

    // @todo: Identify exact Cash row in spreadsheet. Using a placeholder for now.
    $cash = 150000.00; 
    $annualExp = abs($this->getActualsForMetric('expense_total', $year));
    
    if ($annualExp > 0) {
      $avgMonthlyExp = $annualExp / 12;
      return $cash / $avgMonthlyExp;
    }
    return 0.0;
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

}
