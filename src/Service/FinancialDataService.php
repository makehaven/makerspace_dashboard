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
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, DateFormatterInterface $dateFormatter) {
    $this->database = $database;
    $this->cache = $cache;
    $this->dateFormatter = $dateFormatter;
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
    // @todo: Implement logic to get the sum of the four quarters for the year
    // from Xero "Membership - Individual Recuring". This will be called by the
    // 'annual' snapshot.
    return 450000.00;
  }

  /**
   * Gets the annual net income from program lines.
   *
   * @return float
   *   The annual net income from program lines.
   */
  public function getAnnualNetIncomeProgramLines(): float {
    // @todo: Implement logic to get this from Xero. Program lines include:
    // desk rental, storage, room rental and equipment usage fees. This will be
    // called by the 'annual' snapshot.
    return 25000.00;
  }

  /**
   * Gets the adherence to the shop budget.
   *
   * @return float
   *   The adherence to the shop budget as a variance percentage.
   */
  public function getAdherenceToShopBudget(): float {
    // @todo: Implement logic to get this from Xero as "Budget vs Shop Expense
    // Line". This will be called by the 'annual' snapshot.
    return 0.98;
  }

  /**
   * Gets the annual individual giving amount.
   *
   * @return float
   *   The annual individual giving amount.
   */
  public function getAnnualIndividualGiving(): float {
    // @todo: Implement logic to get this value from the finance system. This
    // will be called by the 'annual' snapshot.
    return 60000.00;
  }

  /**
   * Gets the annual corporate sponsorships amount.
   *
   * @return float
   *   The annual corporate sponsorships amount.
   */
  public function getAnnualCorporateSponsorships(): float {
    // @todo: Implement logic to get this value from the finance system. This
    // will be called by the 'annual' snapshot.
    return 30000.00;
  }

  /**
   * Gets the number of non-government grants secured.
   *
   * @return int
   *   The number of non-government grants secured.
   */
  public function getNonGovernmentGrantsSecured(): int {
    // @todo: Implement logic to get this value from the finance system. This
    // will be called by the 'annual' snapshot.
    return 3;
  }

  /**
   * Gets the donor retention rate.
   *
   * @return float
   *   The donor retention rate.
   */
  public function getDonorRetentionRate(): float {
    // @todo: Implement logic to get this value from the finance system. This
    // will be called by the 'annual' snapshot.
    return 0.65;
  }

  /**
   * Gets the net income from the education program.
   *
   * @return float
   *   The net income from the education program.
   */
  public function getNetIncomeEducationProgram(): float {
    // @todo: Implement logic to get this value from Xero. The formula is:
    // "Education ... - Education Expense ...". This will be called by the
    // 'annual' snapshot.
    return 15000.00;
  }

}
