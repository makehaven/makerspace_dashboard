<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
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
      return [];
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
    $this->cache->set($cid, $result, $this->time->getRequestTime() + $this->ttl);

    return $result;
  }

  /**
   * Returns YTD metrics for the current year plus lookback years.
   */
  public function getYearToDateComparison(int $lookback = 2): array {
    if (!$this->tablesExist(['ms_snapshot', 'ms_fact_donation_snapshot'])) {
      return [];
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

    $this->cache->set($cid, $comparisons, $this->time->getRequestTime() + $this->ttl);
    return $comparisons;
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
