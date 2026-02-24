<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Provides fundraising-centric aggregates sourced from snapshot tables.
 */
class DevelopmentDataService {

  protected Connection $database;
  protected CacheBackendInterface $cache;
  protected TimeInterface $time;
  protected ConfigFactoryInterface $configFactory;
  protected int $ttl;
  protected ?bool $donationSnapshotHasFirstTime = NULL;

  /**
   * Default gift range fallbacks when config is absent.
   */
  protected const DEFAULT_RANGES = [
    ['id' => 'under_100', 'label' => 'Under $100', 'min' => 0, 'max' => 99.99],
    ['id' => '100_249', 'label' => '$100 - $249', 'min' => 100, 'max' => 249.99],
    ['id' => '250_499', 'label' => '$250 - $499', 'min' => 250, 'max' => 499.99],
    ['id' => '500_999', 'label' => '$500 - $999', 'min' => 500, 'max' => 999.99],
    ['id' => '1000_2499', 'label' => '$1,000 - $2,499', 'min' => 1000, 'max' => 2499.99],
    ['id' => '2500_4999', 'label' => '$2,500 - $4,999', 'min' => 2500, 'max' => 4999.99],
    ['id' => '5000_plus', 'label' => '$5,000+', 'min' => 5000, 'max' => NULL],
  ];

  public function __construct(Connection $database, CacheBackendInterface $cache, TimeInterface $time, ConfigFactoryInterface $configFactory, int $ttl = 900) {
    $this->database = $database;
    $this->cache = $cache;
    $this->time = $time;
    $this->configFactory = $configFactory;
    $this->ttl = $ttl;
  }

  /**
   * Returns annual donation totals for the most recent years.
   */
  public function getAnnualGivingSummary(int $limit = 6): array {
    if (!$this->tablesExist(['ms_snapshot', 'ms_fact_donation_snapshot'])) {
      return $this->buildContributionAnnualSummary($limit);
    }

    $limit = max(1, $limit);
    $cid = $this->cacheId('annual:' . $limit);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('ms_snapshot', 's');
    $query->innerJoin('ms_fact_donation_snapshot', 'd', 'd.snapshot_id = s.id');
    $query->fields('d', ['period_year']);
    $query->addExpression('MAX(d.ytd_unique_donors)', 'unique_donors');
    $query->addExpression('SUM(d.contributions_count)', 'gift_count');
    $query->addExpression('SUM(d.total_amount)', 'total_amount');
    $hasFirstTime = $this->donationSnapshotHasFirstTimeField();
    if ($hasFirstTime) {
      $query->addExpression('SUM(d.first_time_donors_count)', 'first_time_donors');
    }
    $query->condition('s.definition', 'donation_metrics');
    $query->condition('s.snapshot_type', 'monthly');
    $query->groupBy('d.period_year');
    $query->orderBy('d.period_year', 'DESC');
    $query->range(0, $limit);

    $entries = [];
    foreach ($query->execute() as $record) {
      $year = (int) $record->period_year;
      $gifts = (int) $record->gift_count;
      $amount = (float) $record->total_amount;
      $entries[] = [
        'year' => $year,
        'donors' => (int) $record->unique_donors,
        'first_time_donors' => $hasFirstTime ? (int) ($record->first_time_donors ?? 0) : 0,
        'gifts' => $gifts,
        'total_amount' => round($amount, 2),
        'average_gift' => $gifts > 0 ? round($amount / $gifts, 2) : 0.0,
      ];
    }

    $contributionFallback = $this->buildContributionAnnualSummary($limit * 2);
    $existingYears = array_column($entries, 'year');
    foreach ($contributionFallback as $row) {
      if (!in_array($row['year'], $existingYears, TRUE)) {
        $entries[] = $row;
      }
    }
    usort($entries, static function (array $a, array $b) {
      return $b['year'] <=> $a['year'];
    });
    $entries = array_slice($entries, 0, $limit);

    $this->cache->set($cid, $entries, $this->time->getRequestTime() + $this->ttl);
    return $entries;
  }

  /**
   * Returns donor range distribution for the requested year/month (YTD).
   */
  public function getGiftRangeBreakdown(?int $year = NULL, ?int $month = NULL): array {
    $targetYear = $year ?? (int) date('Y');
    $definitions = $this->getDonationRangeDefinitions();
    $baseline = [
      'year' => $targetYear,
      'month' => NULL,
      'ranges' => array_values($this->initializeRangeRows($definitions)),
      'totals' => ['donors' => 0, 'gifts' => 0, 'amount' => 0.0],
    ];

    if (!$this->tablesExist(['ms_snapshot', 'ms_fact_donation_range_snapshot'])) {
      return $baseline;
    }

    $targetMonth = $month ?? $this->getLatestRangeMonth($targetYear);
    if (!$targetMonth) {
      return $baseline;
    }

    $cid = $this->cacheId(sprintf('range:%d:%d', $targetYear, $targetMonth));
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('ms_fact_donation_range_snapshot', 'r');
    $query->innerJoin('ms_snapshot', 's', 's.id = r.snapshot_id');
    $query->fields('r', [
      'range_key',
      'range_label',
      'min_amount',
      'max_amount',
      'donors_count',
      'contributions_count',
      'total_amount',
    ]);
    $query->condition('s.definition', 'donation_range_metrics');
    $query->condition('s.snapshot_type', 'monthly');
    $query->condition('r.period_year', $targetYear);
    $query->condition('r.period_month', $targetMonth);
    $query->condition('r.is_year_to_date', 1);
    $query->orderBy('r.min_amount');
    $rows = $query->execute()->fetchAll();

    $rangeRows = $this->initializeRangeRows($definitions);
    $totals = ['donors' => 0, 'gifts' => 0, 'amount' => 0.0];

    foreach ($rows as $row) {
      $key = (string) $row->range_key;
      if (!isset($rangeRows[$key])) {
        $rangeRows[$key] = [
          'range_key' => $key,
          'label' => (string) $row->range_label,
          'min' => (float) $row->min_amount,
          'max' => $row->max_amount === NULL ? NULL : (float) $row->max_amount,
          'donors' => 0,
          'gifts' => 0,
          'amount' => 0.0,
        ];
      }
      $donors = (int) $row->donors_count;
      $gifts = (int) $row->contributions_count;
      $amount = (float) $row->total_amount;

      $rangeRows[$key]['donors'] = $donors;
      $rangeRows[$key]['gifts'] = $gifts;
      $rangeRows[$key]['amount'] = round($amount, 2);

      $totals['donors'] += $donors;
      $totals['gifts'] += $gifts;
      $totals['amount'] += $amount;
    }

    $totals['amount'] = round($totals['amount'], 2);
    foreach ($rangeRows as &$range) {
      $range['donor_pct'] = $totals['donors'] > 0 ? round(($range['donors'] / $totals['donors']) * 100, 2) : 0.0;
      $range['amount_pct'] = $totals['amount'] > 0 ? round(($range['amount'] / $totals['amount']) * 100, 2) : 0.0;
    }
    unset($range);

    $result = [
      'year' => $targetYear,
      'month' => $targetMonth,
      'ranges' => array_values($rangeRows),
      'totals' => $totals,
    ];
    if ($totals['donors'] === 0 && $totals['amount'] === 0.0) {
      return $this->buildGiftRangeFromContributions($targetYear);
    }
    $this->cache->set($cid, $result, $this->time->getRequestTime() + $this->ttl);

    return $result;
  }

  /**
   * Returns YTD metrics for the current year plus lookback years.
   */
  public function getYearToDateComparison(int $lookback = 2): array {
    if (!$this->tablesExist(['ms_snapshot', 'ms_fact_donation_snapshot'])) {
      return $this->buildContributionYtdComparison($lookback);
    }

    $lookback = max(0, $lookback);
    $cid = $this->cacheId('ytd:' . $lookback);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $currentYear = (int) date('Y');
    $latestMonth = $this->getLatestDonationMonth($currentYear);
    if (!$latestMonth) {
      return [];
    }

    $comparisons = [];
    for ($offset = 0; $offset <= $lookback; $offset++) {
      $year = $currentYear - $offset;
      $month = $offset === 0
        ? $latestMonth
        : $this->getClosestDonationMonth($year, $latestMonth);
      if (!$month) {
        continue;
      }
      $metrics = $this->buildYearToDateMetrics($year, $month);
      if ($metrics) {
        $comparisons[] = $metrics;
      }
    }

    if (!$comparisons) {
      return $this->buildContributionYtdComparison($lookback);
    }

    $this->cache->set($cid, $comparisons, $this->time->getRequestTime() + $this->ttl);
    return $comparisons;
  }

  /**
   * Returns aggregate contribution stats for an arbitrary date range.
   */
  public function getContributionStats(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $metrics = $this->buildContributionMetricsForRange($start, $end);
    if ($metrics) {
      return [
        'total_amount' => $metrics['total_amount'],
        'count' => $metrics['gifts'],
      ];
    }

    return [
      'total_amount' => 0.0,
      'count' => 0,
    ];
  }

  /**
   * Returns a monthly trend of member vs. non-member donors.
   */
  public function getMemberDonorTrend(int $months = 12): array {
    $months = max(1, $months);
    $now = $this->now();
    $monthMap = $this->buildMonthSeries($now, $months);
    $keys = array_keys($monthMap);
    $labels = array_values($monthMap);
    if (!$keys) {
      return [];
    }

    $start = $this->createDateFromKey(reset($keys))->setTime(0, 0, 0);
    $end = $this->createDateFromKey(end($keys))->modify('last day of this month')->setTime(23, 59, 59);

    $data = [
      'member' => [
        'donors' => array_fill(0, count($labels), 0),
        'amounts' => array_fill(0, count($labels), 0.0),
      ],
      'non_member' => [
        'donors' => array_fill(0, count($labels), 0),
        'amounts' => array_fill(0, count($labels), 0.0),
      ],
    ];
    $indexMap = array_flip($keys);

    $query = $this->baseContributionQuery();
    $query->addExpression("DATE_FORMAT(c.receive_date, '%Y-%m')", 'month_key');
    $membershipExpr = $this->getMembershipExpression();
    $query->addExpression($membershipExpr, 'segment');
    $query->addExpression('COUNT(DISTINCT c.contact_id)', 'donors');
    $query->addExpression('SUM(c.total_amount)', 'amount');
    $query->condition('c.receive_date', [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')], 'BETWEEN');
    $query->groupBy('month_key');
    $query->groupBy('segment');

    foreach ($query->execute() as $row) {
      $monthKey = (string) $row->month_key;
      if (!isset($indexMap[$monthKey])) {
        continue;
      }
      $segment = $row->segment === 'member' ? 'member' : 'non_member';
      $index = $indexMap[$monthKey];
      $data[$segment]['donors'][$index] = (int) $row->donors;
      $data[$segment]['amounts'][$index] = round((float) $row->amount, 2);
    }

    return [
      'labels' => $labels,
      'member' => $data['member'],
      'non_member' => $data['non_member'],
    ];
  }

  /**
   * Returns recurring vs. one-time giving over time.
   */
  public function getRecurringVsOnetimeSeries(int $months = 12): array {
    $months = max(1, $months);
    $now = $this->now();
    $monthMap = $this->buildMonthSeries($now, $months);
    $keys = array_keys($monthMap);
    $labels = array_values($monthMap);
    if (!$keys) {
      return [];
    }

    $start = $this->createDateFromKey(reset($keys))->setTime(0, 0, 0);
    $end = $this->createDateFromKey(end($keys))->modify('last day of this month')->setTime(23, 59, 59);

    $data = [
      'recurring' => [
        'donors' => array_fill(0, count($labels), 0),
        'amounts' => array_fill(0, count($labels), 0.0),
      ],
      'one_time' => [
        'donors' => array_fill(0, count($labels), 0),
        'amounts' => array_fill(0, count($labels), 0.0),
      ],
    ];
    $indexMap = array_flip($keys);

    $query = $this->baseContributionQuery();
    $query->addExpression("DATE_FORMAT(c.receive_date, '%Y-%m')", 'month_key');
    $query->addExpression("CASE WHEN c.contribution_recur_id IS NULL OR c.contribution_recur_id = 0 THEN 'one_time' ELSE 'recurring' END", 'segment');
    $query->addExpression('COUNT(DISTINCT c.contact_id)', 'donors');
    $query->addExpression('SUM(c.total_amount)', 'amount');
    $query->condition('c.receive_date', [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')], 'BETWEEN');
    $query->groupBy('month_key');
    $query->groupBy('segment');

    foreach ($query->execute() as $row) {
      $monthKey = (string) $row->month_key;
      if (!isset($indexMap[$monthKey])) {
        continue;
      }
      $segment = $row->segment === 'recurring' ? 'recurring' : 'one_time';
      $index = $indexMap[$monthKey];
      $data[$segment]['donors'][$index] = (int) $row->donors;
      $data[$segment]['amounts'][$index] = round((float) $row->amount, 2);
    }

    return [
      'labels' => $labels,
      'recurring' => $data['recurring'],
      'one_time' => $data['one_time'],
    ];
  }

  /**
   * Returns a summary of grant performance and pipeline.
   */
  public function getGrantsSummary(): array {
    $cid = $this->cacheId('grants_summary');
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    // 1. Pipeline Count (researching, inquiry, writing, waiting)
    // Matches CiviCRM Report ID 37 logic.
    $pipeline = $this->database->select('civicrm_value_funding_7', 'f');
    $pipeline->innerJoin('civicrm_contact', 'ct', 'f.entity_id = ct.id');
    $pipeline->addExpression('COUNT(*)', 'count');
    $pipeline->condition('f.grant_status_14', ['researching', 'inquiry', 'writing', 'waiting'], 'IN');
    $pipeline->condition('ct.is_deleted', 0);
    $pipeline->condition('ct.is_deceased', 0);
    $pipelineCount = (int) $pipeline->execute()->fetchField();

    // 2. Win Ratio (All-time decided grants)
    // Matches CiviCRM Report ID 36 logic but without the 12-month date constraint 
    // to ensure the dashboard has enough data to show a meaningful percentage.
    $ratioQuery = $this->database->select('civicrm_value_funding_7', 'f');
    $ratioQuery->innerJoin('civicrm_contact', 'ct', 'f.entity_id = ct.id');
    $ratioQuery->fields('f', ['grant_status_14']);
    $ratioQuery->condition('f.grant_status_14', ['won', 'lost', 'abandoned'], 'IN');
    $ratioQuery->condition('ct.is_deleted', 0);
    $results = $ratioQuery->execute()->fetchAll();
    
    $counts = ['won' => 0, 'lost' => 0, 'abandoned' => 0];
    foreach ($results as $row) {
      $counts[$row->grant_status_14]++;
    }
    
    $totalDecided = array_sum($counts);
    $winRatio = $totalDecided > 0 ? ($counts['won'] / $totalDecided) : 0.0;

    $data = [
      'pipeline_count' => $pipelineCount,
      'win_ratio' => $winRatio,
      'decided_total' => $totalDecided,
    ];

    $this->cache->set($cid, $data, $this->time->getRequestTime() + $this->ttl, ['civicrm_contact_list']);
    return $data;
  }

  /**
   * Returns the count of grant records submitted in a calendar year-to-date.
   *
   * Submission is inferred from a populated submitted document/link field.
   * Period bucketing is based on due date because no submitted timestamp field
   * currently exists in the funding custom group.
   */
  public function getGrantSubmittedYtdCount(?int $year = NULL): int {
    $targetYear = $year ?? (int) $this->now()->format('Y');
    $now = $this->now();
    $end = $targetYear === (int) $now->format('Y')
      ? $now
      : new \DateTimeImmutable(sprintf('%d-12-31 23:59:59', $targetYear), $now->getTimezone());
    $start = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $targetYear), $now->getTimezone());

    $cid = $this->cacheId(sprintf('grant_submitted_ytd:%d:%s', $targetYear, $end->format('YmdHis')));
    if ($cache = $this->cache->get($cid)) {
      return (int) ($cache->data ?? 0);
    }

    $query = $this->database->select('civicrm_value_funding_7', 'f');
    $query->addExpression('COUNT(*)', 'submitted_count');
    $query->condition('f.date_due_21', [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')], 'BETWEEN');
    $submitted = $query->orConditionGroup();
    $submitted->condition('f.grant_status_14', ['waiting', 'won', 'lost', 'abandoned'], 'IN');
    $submitted->condition('f.submitted_link_22', '', '<>');
    $query->condition($submitted);

    $count = (int) $query->execute()->fetchField();
    $this->cache->set($cid, $count, $this->time->getRequestTime() + $this->ttl, ['civicrm_contact_list']);
    return $count;
  }

  /**
   * Returns monthly submitted-grant counts (oldest-first).
   */
  public function getGrantSubmittedTrend(int $months = 12): array {
    $months = max(1, $months);
    $cid = $this->cacheId('grant_submitted_trend:' . $months);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $end = $this->now();
    $monthSeries = $this->buildMonthSeries($end, $months);
    $monthKeys = array_keys($monthSeries);
    if (!$monthKeys) {
      return [];
    }

    $startDate = new \DateTimeImmutable($monthKeys[0] . '-01 00:00:00', $end->getTimezone());
    $endDate = $end;
    $counts = array_fill_keys($monthKeys, 0);

    $query = $this->database->select('civicrm_value_funding_7', 'f');
    $query->addExpression("DATE_FORMAT(f.date_due_21, '%Y-%m')", 'month_key');
    $query->addExpression('COUNT(*)', 'submitted_count');
    $query->condition('f.date_due_21', [$startDate->format('Y-m-d H:i:s'), $endDate->format('Y-m-d H:i:s')], 'BETWEEN');
    $submitted = $query->orConditionGroup();
    $submitted->condition('f.grant_status_14', ['waiting', 'won', 'lost', 'abandoned'], 'IN');
    $submitted->condition('f.submitted_link_22', '', '<>');
    $query->condition($submitted);
    $query->groupBy('month_key');

    foreach ($query->execute() as $row) {
      $key = (string) $row->month_key;
      if (isset($counts[$key])) {
        $counts[$key] = (int) $row->submitted_count;
      }
    }

    $trend = array_values($counts);
    $this->cache->set($cid, $trend, $this->time->getRequestTime() + $this->ttl, ['civicrm_contact_list']);
    return $trend;
  }

  /**
   * Returns the count of active recurring donors.
   */
  public function getRecurringDonorsCount(): int {
    $recurringContactIds = [];
    $recurringQuery = $this->database->select('civicrm_contribution_recur', 'cr');
    $recurringQuery->addField('cr', 'contact_id');
    $recurringQuery->condition('cr.contribution_status_id', 5); // In Progress.
    $recurringQuery->condition('cr.contact_id', 0, '>');
    foreach ($recurringQuery->execute()->fetchCol() as $contactId) {
      $recurringContactIds[(int) $contactId] = TRUE;
    }

    foreach ($this->getRecurringDonorTaggedContactIds() as $contactId => $_) {
      $recurringContactIds[(int) $contactId] = TRUE;
    }

    return count($recurringContactIds);
  }

  /**
   * Returns donor retention and upgrade statistics.
   */
  public function getDonorStats(): array {
    $cid = $this->cacheId('donor_stats');
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $now = new \DateTimeImmutable();
    $endB = $now->format('Y-m-d H:i:s');
    $startB = $now->modify('-12 months')->format('Y-m-d H:i:s');
    $endA = $now->modify('-12 months')->format('Y-m-d H:i:s');
    $startA = $now->modify('-24 months')->format('Y-m-d H:i:s');

    $summaryA = $this->getDonorSummariesInRange($startA, $endA);
    $summaryB = $this->getDonorSummariesInRange($startB, $endB);

    $retained = 0;
    $upgraded = 0;
    foreach ($summaryA as $id => $amountA) {
      if (isset($summaryB[$id])) {
        $retained++;
        if ($summaryB[$id] > $amountA) {
          $upgraded++;
        }
      }
    }

    $donorCountA = count($summaryA);
    $data = [
      'retention_rate' => $donorCountA > 0 ? ($retained / $donorCountA) : 0.0,
      'upgrades_count' => $upgraded,
      'retained_count' => $retained,
      'previous_period_donors' => $donorCountA,
    ];

    $this->cache->set($cid, $data, $this->time->getRequestTime() + $this->ttl, ['civicrm_contribution_list']);
    return $data;
  }

  /**
   * Helper to get total giving per individual contact in a date range.
   */
  protected function getDonorSummariesInRange(string $start, string $end): array {
    $q = $this->database->select('civicrm_contribution', 'c');
    $q->innerJoin('civicrm_contact', 'ct', 'c.contact_id = ct.id');
    $q->condition('ct.contact_type', 'Individual');
    $q->leftJoin('civicrm_participant_payment', 'pp', 'pp.contribution_id = c.id');
    $q->condition('pp.participant_id', NULL, 'IS NULL');
    $q->condition('c.financial_type_id', 1);
    $q->condition('c.contribution_status_id', 1);
    $q->condition('c.receive_date', [$start, $end], 'BETWEEN');
    $q->fields('c', ['contact_id']);
    $q->addExpression('SUM(c.total_amount)', 'total');
    $q->groupBy('c.contact_id');
    
    return $q->execute()->fetchAllKeyed(0, 1);
  }

  /**
   * Returns quarterly grant win ratios as a trend array (oldest-first).
   *
   * Each point = won / (won + lost + abandoned) for grants whose due date
   * (date_due_21) falls within that quarter.
   */
  public function getGrantWinRatioTrend(int $quarters = 8): array {
    $cid = $this->cacheId('grant_win_ratio_trend:' . $quarters);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $now = $this->now();
    $trend = [];

    for ($i = $quarters - 1; $i >= 0; $i--) {
      $qEnd   = $this->completedQuarterEnd($now, $i);
      $qStart = $this->quarterStartForEnd($qEnd);
      // civicrm_value_funding_7.date_due_21 is stored as 'YYYYMMDDHHmmss'.
      $qStartStr = $qStart->format('Ymd') . '000000';
      $qEndStr   = $qEnd->format('Ymd') . '235959';

      $query = $this->database->select('civicrm_value_funding_7', 'f');
      $query->innerJoin('civicrm_contact', 'ct', 'f.entity_id = ct.id');
      $query->fields('f', ['grant_status_14']);
      $query->condition('f.grant_status_14', ['won', 'lost', 'abandoned'], 'IN');
      $query->condition('f.date_due_21', [$qStartStr, $qEndStr], 'BETWEEN');
      $query->condition('ct.is_deleted', 0);

      $counts = ['won' => 0, 'lost' => 0, 'abandoned' => 0];
      foreach ($query->execute() as $row) {
        if (isset($counts[$row->grant_status_14])) {
          $counts[$row->grant_status_14]++;
        }
      }

      $total = array_sum($counts);
      $trend[] = $total > 0 ? round($counts['won'] / $total, 4) : 0.0;
    }

    $this->cache->set($cid, $trend, $this->time->getRequestTime() + $this->ttl);
    return $trend;
  }

  /**
   * Returns quarterly recurring donor counts as a trend array (oldest-first).
   *
   * Each point = distinct contacts who received at least one recurring
   * contribution in that calendar quarter.
   */
  public function getRecurringDonorsCountTrend(int $quarters = 8): array {
    $cid = $this->cacheId('recurring_donors_trend:' . $quarters);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $now = $this->now();
    $trend = [];
    $taggedContactIds = $this->getRecurringDonorTaggedContactIds();

    for ($i = $quarters - 1; $i >= 0; $i--) {
      $qEnd   = $this->completedQuarterEnd($now, $i);
      $qStart = $this->quarterStartForEnd($qEnd);

      $query = $this->database->select('civicrm_contribution', 'c');
      $query->fields('c', ['contact_id']);
      $query->condition('c.contribution_recur_id', NULL, 'IS NOT NULL');
      $query->condition('c.contribution_recur_id', 0, '>');
      $query->condition('c.contribution_status_id', 1);
      $query->condition('c.is_test', 0);
      $query->condition('c.receive_date', [
        $qStart->format('Y-m-d H:i:s'),
        $qEnd->format('Y-m-d H:i:s'),
      ], 'BETWEEN');
      $recurringOnly = [];
      foreach ($query->execute()->fetchCol() as $contactId) {
        $recurringOnly[(int) $contactId] = TRUE;
      }

      // Tagged recurring donors are counted if they contributed in the quarter.
      if (!empty($taggedContactIds)) {
        $taggedQuarterQuery = $this->database->select('civicrm_contribution', 'c');
        $taggedQuarterQuery->fields('c', ['contact_id']);
        $taggedQuarterQuery->condition('c.contact_id', array_keys($taggedContactIds), 'IN');
        $taggedQuarterQuery->condition('c.contribution_status_id', 1);
        $taggedQuarterQuery->condition('c.is_test', 0);
        $taggedQuarterQuery->condition('c.receive_date', [
          $qStart->format('Y-m-d H:i:s'),
          $qEnd->format('Y-m-d H:i:s'),
        ], 'BETWEEN');
        foreach ($taggedQuarterQuery->execute()->fetchCol() as $contactId) {
          $recurringOnly[(int) $contactId] = TRUE;
        }
      }

      $trend[] = (float) count($recurringOnly);
    }

    $this->cache->set($cid, $trend, $this->time->getRequestTime() + $this->ttl);
    return $trend;
  }

  /**
   * Returns contact IDs tagged as recurring donors.
   *
   * @return array<int, bool>
   *   Contact IDs keyed to TRUE.
   */
  protected function getRecurringDonorTaggedContactIds(): array {
    $tagIds = $this->getRecurringDonorTagIds();
    if (empty($tagIds)) {
      return [];
    }

    $query = $this->database->select('civicrm_entity_tag', 'et');
    $query->fields('et', ['entity_id']);
    $query->condition('et.tag_id', $tagIds, 'IN');
    $query->condition('et.entity_id', 0, '>');
    $entityTableGroup = $query->orConditionGroup()
      ->condition('et.entity_table', 'civicrm_contact')
      ->condition('et.entity_table', NULL, 'IS NULL')
      ->condition('et.entity_table', '');
    $query->condition($entityTableGroup);

    $contactIds = [];
    foreach ($query->execute()->fetchCol() as $contactId) {
      $contactIds[(int) $contactId] = TRUE;
    }

    return $contactIds;
  }

  /**
   * Resolves recurring donor tag IDs by label/name.
   *
   * @return int[]
   *   Matching tag IDs.
   */
  protected function getRecurringDonorTagIds(): array {
    static $cache = NULL;
    if (is_array($cache)) {
      return $cache;
    }

    $query = $this->database->select('civicrm_tag', 't');
    $query->fields('t', ['id']);
    $match = $query->orConditionGroup()
      ->condition('t.label', 'Recurring Donor')
      ->condition('t.name', 'Recurring_Donor')
      ->condition('t.name', 'recurring_donor')
      ->condition('t.name', 'Recurring Donor');
    $query->condition($match);

    $ids = array_map('intval', $query->execute()->fetchCol());
    $cache = array_values(array_unique(array_filter($ids, static fn($id) => $id > 0)));
    return $cache;
  }

  /**
   * Returns quarterly donor retention rates as a trend array (oldest-first).
   *
   * For each quarter Q, retention = donors who gave in Q AND also gave in the
   * same quarter one year prior, divided by the prior-year quarter's donor count.
   */
  public function getDonorRetentionRateTrend(int $quarters = 6): array {
    $cid = $this->cacheId('donor_retention_trend:' . $quarters);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $now = $this->now();
    $trend = [];

    for ($i = $quarters - 1; $i >= 0; $i--) {
      $qEnd   = $this->completedQuarterEnd($now, $i);
      $qStart = $this->quarterStartForEnd($qEnd);

      $prevQEnd   = $qEnd->modify('-1 year');
      $prevQStart = $qStart->modify('-1 year');

      $prevDonors = $this->getDonorSummariesInRange(
        $prevQStart->format('Y-m-d H:i:s'),
        $prevQEnd->format('Y-m-d H:i:s')
      );
      $currDonors = $this->getDonorSummariesInRange(
        $qStart->format('Y-m-d H:i:s'),
        $qEnd->format('Y-m-d H:i:s')
      );

      $prevCount = count($prevDonors);
      if ($prevCount === 0) {
        $trend[] = 0.0;
        continue;
      }

      $retained = count(array_intersect_key($currDonors, $prevDonors));
      $trend[] = round($retained / $prevCount, 4);
    }

    $this->cache->set($cid, $trend, $this->time->getRequestTime() + $this->ttl);
    return $trend;
  }

  /**
   * Returns quarterly donor upgrade counts as a trend array (oldest-first).
   *
   * A donor "upgrades" if their giving in quarter Q exceeds their giving in
   * the same quarter one year prior.
   */
  public function getDonorUpgradesCountTrend(int $quarters = 6): array {
    $cid = $this->cacheId('donor_upgrades_trend:' . $quarters);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $now = $this->now();
    $trend = [];

    for ($i = $quarters - 1; $i >= 0; $i--) {
      $qEnd   = $this->completedQuarterEnd($now, $i);
      $qStart = $this->quarterStartForEnd($qEnd);

      $prevQEnd   = $qEnd->modify('-1 year');
      $prevQStart = $qStart->modify('-1 year');

      $prevDonors = $this->getDonorSummariesInRange(
        $prevQStart->format('Y-m-d H:i:s'),
        $prevQEnd->format('Y-m-d H:i:s')
      );
      $currDonors = $this->getDonorSummariesInRange(
        $qStart->format('Y-m-d H:i:s'),
        $qEnd->format('Y-m-d H:i:s')
      );

      $upgraded = 0;
      foreach ($prevDonors as $id => $prevAmount) {
        if (isset($currDonors[$id]) && (float) $currDonors[$id] > (float) $prevAmount) {
          $upgraded++;
        }
      }
      $trend[] = (float) $upgraded;
    }

    $this->cache->set($cid, $trend, $this->time->getRequestTime() + $this->ttl);
    return $trend;
  }

  /**
   * Returns the end of the Nth most recently completed quarter.
   *
   * quartersAgo=0 → most recently completed quarter.
   * quartersAgo=1 → one quarter before that, etc.
   */
  protected function completedQuarterEnd(\DateTimeImmutable $now, int $quartersAgo): \DateTimeImmutable {
    $month = (int) $now->format('n');
    $year  = (int) $now->format('Y');

    // Determine the most recently completed quarter.
    $prevQ = (int) ceil($month / 3) - 1;
    if ($prevQ <= 0) {
      $prevQ = 4;
      $year--;
    }

    // Step back by $quartersAgo additional quarters.
    $targetQ = $prevQ - $quartersAgo;
    while ($targetQ <= 0) {
      $targetQ += 4;
      $year--;
    }

    $endMonth = $targetQ * 3;
    $lastDay  = (int)(new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $endMonth)))->format('t');
    return new \DateTimeImmutable(sprintf('%d-%02d-%02d 23:59:59', $year, $endMonth, $lastDay));
  }

  /**
   * Returns the first day of the quarter that contains the given quarter-end date.
   */
  protected function quarterStartForEnd(\DateTimeImmutable $quarterEndDate): \DateTimeImmutable {
    $endMonth   = (int) $quarterEndDate->format('n');
    $year       = (int) $quarterEndDate->format('Y');
    $startMonth = $endMonth - 2;
    return new \DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $year, $startMonth));
  }

  /**
   * Returns the total dollar value currently in the grant pipeline.
   */
  public function getGrantsPipelineValue(): float {
    $query = $this->database->select('civicrm_value_funding_7', 'f');
    $query->addExpression('SUM(request_amount_24)', 'total');
    $query->condition('grant_status_14', ['researching', 'inquiry', 'writing', 'waiting'], 'IN');
    return (float) ($query->execute()->fetchField() ?: 0.0);
  }

  /**
   * Builds a cache identifier.
   */
  protected function cacheId(string $suffix): string {
    return 'makerspace_dashboard:development:' . $suffix;
  }

  protected function tablesExist(array $tables): bool {
    $schema = $this->database->schema();
    foreach ($tables as $table) {
      if (!$schema->tableExists($table)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Loads configured donation range definitions.
   */
  protected function getDonationRangeDefinitions(): array {
    $config = $this->configFactory->get('makerspace_snapshot.donation_ranges');
    $stored = $config ? ($config->get('ranges') ?? []) : [];
    $definitions = [];
    $source = !empty($stored) ? $stored : self::DEFAULT_RANGES;
    foreach ($source as $item) {
      $id = isset($item['id']) ? strtolower((string) $item['id']) : '';
      if ($id === '') {
        continue;
      }
      $definitions[$id] = [
        'label' => (string) ($item['label'] ?? strtoupper($id)),
        'min' => isset($item['min']) ? (float) $item['min'] : 0.0,
        'max' => array_key_exists('max', $item) && $item['max'] !== NULL ? (float) $item['max'] : NULL,
      ];
    }
    return $definitions;
  }

  /**
   * Seeds empty range rows keyed by range ID.
   */
  protected function initializeRangeRows(array $definitions): array {
    $rows = [];
    foreach ($definitions as $id => $info) {
      $rows[$id] = [
        'range_key' => $id,
        'label' => $info['label'],
        'min' => $info['min'],
        'max' => $info['max'],
        'donors' => 0,
        'gifts' => 0,
        'amount' => 0.0,
        'donor_pct' => 0.0,
        'amount_pct' => 0.0,
      ];
    }
    return $rows;
  }

  /**
   * Finds the latest donation range month for a year.
   */
  protected function getLatestRangeMonth(int $year): ?int {
    $query = $this->database->select('ms_fact_donation_range_snapshot', 'r');
    $query->innerJoin('ms_snapshot', 's', 's.id = r.snapshot_id');
    $query->addExpression('MAX(r.period_month)', 'max_month');
    $query->condition('s.definition', 'donation_range_metrics');
    $query->condition('s.snapshot_type', 'monthly');
    $query->condition('r.period_year', $year);
    $query->condition('r.is_year_to_date', 1);
    $result = $query->execute()->fetchField();
    return $result ? (int) $result : NULL;
  }

  protected function getLatestDonationMonth(int $year): ?int {
    $query = $this->database->select('ms_fact_donation_snapshot', 'd');
    $query->innerJoin('ms_snapshot', 's', 's.id = d.snapshot_id');
    $query->addExpression('MAX(d.period_month)', 'max_month');
    $query->condition('s.definition', 'donation_metrics');
    $query->condition('s.snapshot_type', 'monthly');
    $query->condition('d.period_year', $year);
    $result = $query->execute()->fetchField();
    return $result ? (int) $result : NULL;
  }

  protected function getClosestDonationMonth(int $year, int $targetMonth): ?int {
    $query = $this->database->select('ms_fact_donation_snapshot', 'd');
    $query->innerJoin('ms_snapshot', 's', 's.id = d.snapshot_id');
    $query->addExpression('MAX(d.period_month)', 'max_month');
    $query->condition('s.definition', 'donation_metrics');
    $query->condition('s.snapshot_type', 'monthly');
    $query->condition('d.period_year', $year);
    $query->condition('d.period_month', $targetMonth, '<=');
    $existing = $query->execute()->fetchField();
    if ($existing) {
      return (int) $existing;
    }
    return $this->getLatestDonationMonth($year);
  }

  /**
   * Builds YTD aggregates for a given year/month cutoff.
   */
  protected function buildYearToDateMetrics(int $year, int $targetMonth): ?array {
    $query = $this->database->select('ms_fact_donation_snapshot', 'd');
    $query->innerJoin('ms_snapshot', 's', 's.id = d.snapshot_id');
    $hasFirstTime = $this->donationSnapshotHasFirstTimeField();
    $query->addField('d', 'period_month');
    $query->addField('d', 'ytd_unique_donors');
    $query->addField('d', 'contributions_count');
    $query->addField('d', 'total_amount');
    if ($hasFirstTime) {
      $query->addField('d', 'first_time_donors_count');
    }
    $query->condition('s.definition', 'donation_metrics');
    $query->condition('s.snapshot_type', 'monthly');
    $query->condition('d.period_year', $year);
    $query->condition('d.period_month', $targetMonth, '<=');
    $query->orderBy('d.period_month', 'ASC');
    $rows = $query->execute()->fetchAll();
    if (empty($rows)) {
      return NULL;
    }

    $gifts = 0;
    $amount = 0.0;
    $firstTime = 0;
    $latestMonth = NULL;
    $latestDonors = 0;
    foreach ($rows as $row) {
      $month = (int) $row->period_month;
      $gifts += (int) $row->contributions_count;
      $amount += (float) $row->total_amount;
      if ($hasFirstTime) {
        $firstTime += (int) ($row->first_time_donors_count ?? 0);
      }
      $latestDonors = (int) $row->ytd_unique_donors;
      $latestMonth = $month;
    }

    $amount = round($amount, 2);
    return [
      'year' => $year,
      'month' => $latestMonth,
      'month_label' => $this->formatMonthLabel($latestMonth),
      'total_amount' => $amount,
      'gifts' => $gifts,
      'donors' => $latestDonors,
      'first_time_donors' => $firstTime,
      'average_gift' => $gifts > 0 ? round($amount / $gifts, 2) : 0.0,
    ];
  }

  /**
   * Builds annual giving stats directly from contributions.
   */
  protected function buildContributionAnnualSummary(int $limit): array {
    $query = $this->baseContributionQuery();
    $query->addExpression('YEAR(c.receive_date)', 'year');
    $query->addExpression('COUNT(DISTINCT c.contact_id)', 'unique_donors');
    $query->addExpression('COUNT(c.id)', 'gift_count');
    $query->addExpression('SUM(c.total_amount)', 'total_amount');
    $first = $this->buildFirstContributionSubquery();
    $query->leftJoin($first, 'first', 'first.contact_id = c.contact_id');
    $query->addExpression('COUNT(DISTINCT IF(first.first_year = YEAR(c.receive_date), c.contact_id, NULL))', 'first_time_donors');
    $query->condition('c.receive_date', NULL, 'IS NOT NULL');
    $query->groupBy('year');
    $query->orderBy('year', 'DESC');
    $query->range(0, $limit);

    $entries = [];
    foreach ($query->execute() as $record) {
      $year = (int) $record->year;
      $giftCount = (int) $record->gift_count;
      $amount = (float) $record->total_amount;
      $entries[] = [
        'year' => $year,
        'donors' => (int) $record->unique_donors,
        'first_time_donors' => (int) $record->first_time_donors,
        'gifts' => $giftCount,
        'average_gift' => $giftCount > 0 ? round($amount / $giftCount, 2) : 0.0,
        'total_amount' => round($amount, 2),
      ];
    }
    return $entries;
  }

  /**
   * Builds gift range distribution from live contributions.
   */
  protected function buildGiftRangeFromContributions(int $targetYear): array {
    $definitions = $this->getDonationRangeDefinitions();
    $ranges = $this->initializeRangeRows($definitions);
    if (!$ranges) {
      return [
        'year' => $targetYear,
        'month' => NULL,
        'ranges' => [],
        'totals' => ['donors' => 0, 'gifts' => 0, 'amount' => 0.0],
      ];
    }

    $now = $this->now();
    $start = (new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $targetYear), $now->getTimezone()));
    if ($targetYear >= (int) $now->format('Y')) {
      $end = $now;
      $month = (int) $now->format('n');
    }
    else {
      $end = (new \DateTimeImmutable(sprintf('%d-12-31 23:59:59', $targetYear), $now->getTimezone()));
      $month = 12;
    }

    $totals = ['donors' => 0, 'gifts' => 0, 'amount' => 0.0];
    foreach ($ranges as $key => $range) {
      $metrics = $this->calculateContributionRangeMetrics($start, $end, $range['min'], $range['max']);
      $ranges[$key]['donors'] = $metrics['donors'];
      $ranges[$key]['gifts'] = $metrics['gifts'];
      $ranges[$key]['amount'] = $metrics['amount'];
      $totals['donors'] += $metrics['donors'];
      $totals['gifts'] += $metrics['gifts'];
      $totals['amount'] += $metrics['amount'];
    }

    $totals['amount'] = round($totals['amount'], 2);
    foreach ($ranges as &$range) {
      $range['donor_pct'] = $totals['donors'] > 0 ? round(($range['donors'] / $totals['donors']) * 100, 2) : 0.0;
      $range['amount_pct'] = $totals['amount'] > 0 ? round(($range['amount'] / $totals['amount']) * 100, 2) : 0.0;
    }
    unset($range);

    return [
      'year' => $targetYear,
      'month' => $month,
      'ranges' => array_values($ranges),
      'totals' => $totals,
    ];
  }

  /**
   * Builds YTD comparisons from contributions.
   */
  protected function buildContributionYtdComparison(int $lookback): array {
    $now = $this->now();
    $comparisons = [];
    $currentYear = (int) $now->format('Y');
    $targetDay = (int) $now->format('d');
    $targetMonth = (int) $now->format('n');

    for ($offset = 0; $offset <= $lookback; $offset++) {
      $year = $currentYear - $offset;
      $start = (new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year), $now->getTimezone()));
      $end = $this->matchMonthDay($year, $targetMonth, $targetDay)->setTime(23, 59, 59);
      if ($end < $start) {
        continue;
      }
      $metrics = $this->buildContributionMetricsForRange($start, $end);
      if (!$metrics) {
        continue;
      }
      $metrics['year'] = $year;
      $metrics['month_label'] = $end->format('M j');
      $comparisons[] = $metrics;
    }
    return $comparisons;
  }

  /**
   * Calculates donors/gifts/amount for a date range.
   */
  protected function buildContributionMetricsForRange(\DateTimeImmutable $start, \DateTimeImmutable $end): ?array {
    $query = $this->baseContributionQuery();
    $query->addExpression('COUNT(DISTINCT c.contact_id)', 'donors');
    $query->addExpression('COUNT(c.id)', 'gifts');
    $query->addExpression('SUM(c.total_amount)', 'amount');
    $first = $this->buildFirstContributionSubquery();
    $query->leftJoin($first, 'first', 'first.contact_id = c.contact_id');
    $query->addExpression('COUNT(DISTINCT IF(first.first_date BETWEEN :start_first AND :end_first, c.contact_id, NULL))', 'first_time_donors', [
      ':start_first' => $start->format('Y-m-d H:i:s'),
      ':end_first' => $end->format('Y-m-d H:i:s'),
    ]);
    $query->condition('c.receive_date', [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')], 'BETWEEN');
    $query->condition('c.receive_date', NULL, 'IS NOT NULL');
    $result = $query->execute()->fetchObject();
    if (!$result) {
      return NULL;
    }

    $donors = (int) $result->donors;
    $gifts = (int) $result->gifts;
    $amount = (float) $result->amount;

    return [
      'total_amount' => round($amount, 2),
      'donors' => $donors,
      'first_time_donors' => (int) $result->first_time_donors,
      'gifts' => $gifts,
      'average_gift' => $gifts > 0 ? round($amount / $gifts, 2) : 0.0,
    ];
  }

  /**
   * Calculates metrics for an individual gift range.
   */
  protected function calculateContributionRangeMetrics(\DateTimeImmutable $start, \DateTimeImmutable $end, float $min, ?float $max): array {
    $query = $this->baseContributionQuery();
    $query->addExpression('COUNT(DISTINCT c.contact_id)', 'donors');
    $query->addExpression('COUNT(c.id)', 'gifts');
    $query->addExpression('SUM(c.total_amount)', 'amount');
    $query->condition('c.receive_date', [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')], 'BETWEEN');
    $query->condition('c.total_amount', $min, '>=');
    if ($max !== NULL) {
      $query->condition('c.total_amount', $max, '<=');
    }
    $result = $query->execute()->fetchObject();
    if (!$result) {
      return ['donors' => 0, 'gifts' => 0, 'amount' => 0.0];
    }
    return [
      'donors' => (int) $result->donors,
      'gifts' => (int) $result->gifts,
      'amount' => round((float) $result->amount, 2),
    ];
  }

  /**
   * Provides the base contribution query with shared filters.
   */
  protected function baseContributionQuery(): SelectInterface {
    $query = $this->database->select('civicrm_contribution', 'c');
    $query->condition('c.contribution_status_id', 1);
    $query->condition('c.is_test', 0);
    $query->condition('c.total_amount', 0, '>');
    // Exclude event registration payments; civicrm_participant_payment links contributions to event fees.
    $query->leftJoin('civicrm_participant_payment', 'pp', 'pp.contribution_id = c.id');
    $query->condition('pp.participant_id', NULL, 'IS NULL');
    return $query;
  }

  /**
   * Builds subquery mapping contacts to their first contribution date/year.
   */
  protected function buildFirstContributionSubquery(): SelectInterface {
    $sub = $this->database->select('civicrm_contribution', 'fc');
    $sub->addField('fc', 'contact_id');
    $sub->addExpression('MIN(fc.receive_date)', 'first_date');
    $sub->addExpression('MIN(EXTRACT(YEAR FROM fc.receive_date))', 'first_year');
    $sub->condition('fc.contribution_status_id', 1);
    $sub->condition('fc.is_test', 0);
    $sub->condition('fc.receive_date', NULL, 'IS NOT NULL');
    $sub->groupBy('fc.contact_id');
    return $sub;
  }

  /**
   * Returns the EXISTS expression that flags member donors.
   */
  protected function getMembershipExpression(): string {
    return "CASE WHEN EXISTS (
      SELECT 1
      FROM civicrm_membership m
      INNER JOIN civicrm_membership_status ms ON ms.id = m.status_id AND ms.is_current_member = 1
      WHERE m.contact_id = c.contact_id
        AND (m.start_date IS NULL OR m.start_date <= c.receive_date)
        AND (m.end_date IS NULL OR m.end_date >= c.receive_date)
    ) THEN 'member' ELSE 'non_member' END";
  }

  /**
   * Builds a YYYY-MM => label map for recent months.
   */
  protected function buildMonthSeries(\DateTimeImmutable $end, int $months): array {
    $start = $end->modify('first day of this month')->setTime(0, 0, 0)->modify('-' . ($months - 1) . ' months');
    $series = [];
    for ($i = 0; $i < $months; $i++) {
      $month = $start->modify("+$i months");
      $series[$month->format('Y-m')] = $month->format('M Y');
    }
    return $series;
  }

  /**
   * Creates a DateTime object from a month key (YYYY-MM).
   */
  protected function createDateFromKey(string $key): \DateTimeImmutable {
    $key .= '-01';
    return new \DateTimeImmutable($key, $this->now()->getTimezone());
  }

  /**
   * Matches the provided month/day within a given year.
   */
  protected function matchMonthDay(int $year, int $month, int $day): \DateTimeImmutable {
    $base = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month), $this->now()->getTimezone());
    $target = $base->setDate($year, $month, 1);
    $targetDay = min($day, (int) $target->format('t'));
    return $target->setDate($year, $month, $targetDay);
  }

  /**
   * Returns the current timestamp wrapper.
   */
  protected function now(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get() ?: 'UTC'));
  }

  protected function formatMonthLabel(?int $month): string {
    if (empty($month) || $month < 1 || $month > 12) {
      return '';
    }
    return date('F', mktime(0, 0, 0, $month, 1));
  }

  /**
   * Determines whether the donation snapshot table has first-time donor data.
   */
  protected function donationSnapshotHasFirstTimeField(): bool {
    if ($this->donationSnapshotHasFirstTime !== NULL) {
      return $this->donationSnapshotHasFirstTime;
    }
    $schema = $this->database->schema();
    $this->donationSnapshotHasFirstTime = $schema->fieldExists('ms_fact_donation_snapshot', 'first_time_donors_count');
    return $this->donationSnapshotHasFirstTime;
  }

}
