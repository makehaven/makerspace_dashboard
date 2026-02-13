<?php

namespace Drupal\makerspace_dashboard\Service;

use DateTimeImmutable;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Aggregates lifecycle and risk metrics from member success snapshots.
 */
class MemberSuccessDataService {

  /**
   * Cached table-exists check.
   */
  protected ?bool $tableExists = NULL;

  /**
   * Constructs the service.
   */
  public function __construct(
    protected Connection $database,
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
  ) {
  }

  /**
   * Returns whether member success snapshots are available.
   */
  public function isAvailable(): bool {
    if ($this->tableExists !== NULL) {
      return $this->tableExists;
    }
    $this->tableExists = $this->database->schema()->tableExists('ms_member_success_snapshot');
    return $this->tableExists;
  }

  /**
   * Returns monthly risk share snapshots at the latest date each month.
   */
  public function getMonthlyRiskShareSeries(int $months = 18, int $riskThreshold = 20): array {
    if (!$this->isAvailable()) {
      return [];
    }

    $months = max(1, $months);
    $riskThreshold = max(0, $riskThreshold);
    $cacheId = sprintf('makerspace_dashboard:member_success:risk:%d:%d', $months, $riskThreshold);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $start = (new DateTimeImmutable('first day of this month'))
      ->modify('-' . ($months + 1) . ' months')
      ->format('Y-m-d');

    $query = $this->database->select('ms_member_success_snapshot', 's');
    $query->fields('s', ['snapshot_date']);
    $query->addExpression('COUNT(*)', 'total_members');
    $query->addExpression('SUM(CASE WHEN risk_score >= :risk_threshold THEN 1 ELSE 0 END)', 'at_risk_members', [
      ':risk_threshold' => $riskThreshold,
    ]);
    $query->condition('s.snapshot_type', 'daily');
    $query->condition('s.snapshot_date', $start, '>=');
    $query->groupBy('s.snapshot_date');
    $query->orderBy('s.snapshot_date', 'ASC');

    $daily = $query->execute()->fetchAllAssoc('snapshot_date');
    if (!$daily) {
      return [];
    }

    $monthly = $this->takeLatestSnapshotPerMonth($daily);
    $series = [];
    foreach ($monthly as $row) {
      $snapshotDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($row->snapshot_date ?? ''));
      if (!$snapshotDate) {
        continue;
      }
      $total = (int) ($row->total_members ?? 0);
      $atRisk = (int) ($row->at_risk_members ?? 0);
      $series[] = [
        'period_key' => $snapshotDate->format('Y-m'),
        'snapshot_date' => $snapshotDate,
        'total' => $total,
        'at_risk' => $atRisk,
        'ratio' => $total > 0 ? ($atRisk / $total) : NULL,
      ];
    }

    $series = array_slice($series, -$months);
    $this->cache->set($cacheId, $series, $this->time->getRequestTime() + 3600, ['civicrm_activity_list', 'user_list']);
    return $series;
  }

  /**
   * Returns monthly 28-day activation snapshots at the latest date each month.
   */
  public function getMonthlyActivationSeries(int $months = 18, int $activationDays = 28, int $cohortWindowDays = 30): array {
    if (!$this->isAvailable()) {
      return [];
    }

    $months = max(1, $months);
    $activationDays = max(1, $activationDays);
    $cohortWindowDays = max(7, $cohortWindowDays);

    $cacheId = sprintf(
      'makerspace_dashboard:member_success:activation:%d:%d:%d',
      $months,
      $activationDays,
      $cohortWindowDays
    );
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $start = (new DateTimeImmutable('first day of this month'))
      ->modify('-' . ($months + 2) . ' months')
      ->format('Y-m-d');
    $minAge = $activationDays;
    $maxAge = $activationDays + $cohortWindowDays;

    $query = $this->database->select('ms_member_success_snapshot', 's');
    $query->fields('s', ['snapshot_date']);
    $query->addExpression(
      'SUM(CASE WHEN join_date IS NOT NULL AND DATEDIFF(snapshot_date, join_date) BETWEEN :min_age AND :max_age THEN 1 ELSE 0 END)',
      'cohort_total',
      [
        ':min_age' => $minAge,
        ':max_age' => $maxAge,
      ]
    );
    $query->addExpression(
      'SUM(CASE WHEN join_date IS NOT NULL AND DATEDIFF(snapshot_date, join_date) BETWEEN :min_age_2 AND :max_age_2 AND badge_count_total >= 1 THEN 1 ELSE 0 END)',
      'activated_total',
      [
        ':min_age_2' => $minAge,
        ':max_age_2' => $maxAge,
      ]
    );
    $query->condition('s.snapshot_type', 'daily');
    $query->condition('s.snapshot_date', $start, '>=');
    $query->groupBy('s.snapshot_date');
    $query->orderBy('s.snapshot_date', 'ASC');

    $daily = $query->execute()->fetchAllAssoc('snapshot_date');
    if (!$daily) {
      return [];
    }

    $monthly = $this->takeLatestSnapshotPerMonth($daily);
    $series = [];
    foreach ($monthly as $row) {
      $snapshotDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($row->snapshot_date ?? ''));
      if (!$snapshotDate) {
        continue;
      }
      $cohortTotal = (int) ($row->cohort_total ?? 0);
      $activatedTotal = (int) ($row->activated_total ?? 0);
      $series[] = [
        'period_key' => $snapshotDate->format('Y-m'),
        'snapshot_date' => $snapshotDate,
        'cohort_total' => $cohortTotal,
        'activated_total' => $activatedTotal,
        'ratio' => $cohortTotal > 0 ? ($activatedTotal / $cohortTotal) : NULL,
      ];
    }

    $series = array_slice($series, -$months);
    $this->cache->set($cacheId, $series, $this->time->getRequestTime() + 3600, ['civicrm_activity_list', 'user_list']);
    return $series;
  }

  /**
   * Returns monthly lifecycle stage counts using month-end snapshots.
   */
  public function getLifecycleStageSeries(int $months = 12): array {
    if (!$this->isAvailable()) {
      return [];
    }

    $months = max(1, $months);
    $cacheId = sprintf('makerspace_dashboard:member_success:lifecycle:%d', $months);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $start = (new DateTimeImmutable('first day of this month'))
      ->modify('-' . ($months + 1) . ' months')
      ->format('Y-m-d');

    $query = $this->database->select('ms_member_success_snapshot', 's');
    $query->fields('s', ['snapshot_date', 'stage']);
    $query->addExpression('COUNT(*)', 'stage_total');
    $query->condition('s.snapshot_type', 'daily');
    $query->condition('s.snapshot_date', $start, '>=');
    $query->groupBy('s.snapshot_date');
    $query->groupBy('s.stage');
    $query->orderBy('s.snapshot_date', 'ASC');

    $rows = $query->execute()->fetchAll();
    if (!$rows) {
      return [];
    }

    $daily = [];
    foreach ($rows as $row) {
      $date = (string) ($row->snapshot_date ?? '');
      if ($date === '') {
        continue;
      }
      if (!isset($daily[$date])) {
        $daily[$date] = [
          'snapshot_date' => $date,
          'stages' => [],
        ];
      }
      $stage = (string) ($row->stage ?? 'unknown');
      $daily[$date]['stages'][$stage] = (int) ($row->stage_total ?? 0);
    }

    $monthly = $this->takeLatestSnapshotPerMonth($daily);
    $series = [];
    foreach ($monthly as $row) {
      $snapshotDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($row['snapshot_date'] ?? ''));
      if (!$snapshotDate) {
        continue;
      }
      $series[] = [
        'period_key' => $snapshotDate->format('Y-m'),
        'snapshot_date' => $snapshotDate,
        'stages' => $row['stages'] ?? [],
      ];
    }

    $series = array_slice($series, -$months);
    $this->cache->set($cacheId, $series, $this->time->getRequestTime() + 3600, ['civicrm_activity_list', 'user_list']);
    return $series;
  }

  /**
   * Returns latest onboarding funnel counts for the recent join cohort.
   */
  public function getLatestOnboardingFunnel(int $cohortDays = 90): array {
    if (!$this->isAvailable()) {
      return [];
    }

    $cohortDays = max(14, $cohortDays);
    $cacheId = sprintf('makerspace_dashboard:member_success:onboarding_funnel:%d', $cohortDays);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $latestQuery = $this->database->select('ms_member_success_snapshot', 's');
    $latestQuery->addExpression('MAX(snapshot_date)', 'latest_snapshot');
    $latestQuery->condition('s.snapshot_type', 'daily');
    $latestSnapshot = $latestQuery->execute()->fetchField();
    if (!$latestSnapshot || !is_string($latestSnapshot)) {
      return [];
    }

    $latestDate = DateTimeImmutable::createFromFormat('Y-m-d', $latestSnapshot);
    if (!$latestDate) {
      return [];
    }
    $cutoffDate = $latestDate->modify('-' . $cohortDays . ' days')->format('Y-m-d');

    $query = $this->database->select('ms_member_success_snapshot', 's');
    $query->addExpression(
      'SUM(CASE WHEN join_date IS NOT NULL AND join_date BETWEEN :cutoff AND :latest THEN 1 ELSE 0 END)',
      'joined_recent',
      [':cutoff' => $cutoffDate, ':latest' => $latestSnapshot]
    );
    $query->addExpression(
      'SUM(CASE WHEN join_date IS NOT NULL AND join_date BETWEEN :cutoff_2 AND :latest_2 AND orientation_date IS NOT NULL THEN 1 ELSE 0 END)',
      'orientation_complete',
      [':cutoff_2' => $cutoffDate, ':latest_2' => $latestSnapshot]
    );
    $query->addExpression(
      'SUM(CASE WHEN join_date IS NOT NULL AND join_date BETWEEN :cutoff_3 AND :latest_3 AND LOWER(COALESCE(door_badge_status, \'\')) LIKE :active_status THEN 1 ELSE 0 END)',
      'badge_active',
      [':cutoff_3' => $cutoffDate, ':latest_3' => $latestSnapshot, ':active_status' => '%active%']
    );
    $query->addExpression(
      'SUM(CASE WHEN join_date IS NOT NULL AND join_date BETWEEN :cutoff_4 AND :latest_4 AND serial_number_present = 1 THEN 1 ELSE 0 END)',
      'serial_present',
      [':cutoff_4' => $cutoffDate, ':latest_4' => $latestSnapshot]
    );
    $query->condition('s.snapshot_type', 'daily');
    $query->condition('s.snapshot_date', $latestSnapshot);

    $result = $query->execute()->fetchAssoc();
    $payload = [
      'snapshot_date' => $latestDate,
      'cohort_days' => $cohortDays,
      'joined_recent' => (int) ($result['joined_recent'] ?? 0),
      'orientation_complete' => (int) ($result['orientation_complete'] ?? 0),
      'badge_active' => (int) ($result['badge_active'] ?? 0),
      'serial_present' => (int) ($result['serial_present'] ?? 0),
    ];

    $this->cache->set($cacheId, $payload, $this->time->getRequestTime() + 3600, ['civicrm_activity_list', 'user_list']);
    return $payload;
  }

  /**
   * Chooses the latest snapshot row for each month.
   *
   * @param array $dailyRows
   *   Daily rows keyed by snapshot date.
   */
  protected function takeLatestSnapshotPerMonth(array $dailyRows): array {
    $monthly = [];
    foreach ($dailyRows as $snapshotDate => $row) {
      $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $snapshotDate);
      if (!$date) {
        continue;
      }
      $monthKey = $date->format('Y-m');
      $existingDate = '';
      if (isset($monthly[$monthKey])) {
        $existing = $monthly[$monthKey];
        if (is_object($existing) && isset($existing->snapshot_date)) {
          $existingDate = (string) $existing->snapshot_date;
        }
        elseif (is_array($existing) && isset($existing['snapshot_date'])) {
          $existingDate = (string) $existing['snapshot_date'];
        }
      }

      if (!isset($monthly[$monthKey]) || $snapshotDate > $existingDate) {
        $monthly[$monthKey] = $row;
      }
    }
    ksort($monthly);
    return array_values($monthly);
  }

}
