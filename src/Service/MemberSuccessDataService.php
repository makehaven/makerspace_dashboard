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
    $months = max(1, $months);
    $activationDays = max(1, $activationDays);

    $cacheId = sprintf(
      'makerspace_dashboard:member_success:activation:v2:%d:%d',
      $months,
      $activationDays
    );
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $now = new DateTimeImmutable('now');
    $start = $now->modify('first day of this month')->setTime(0, 0)->modify('-' . ($months - 1) . ' months');
    $maturityCutoff = $now->modify('-' . $activationDays . ' days');

    // Find each member's first earned badge. An earned badge is a published
    // badge_request with the canonical active status; pending and duplicate
    // requests are not achievements.
    $firstBadge = $this->database->select('node_field_data', 'badge_node');
    $firstBadge->innerJoin('node__field_member_to_badge', 'badge_member', 'badge_member.entity_id = badge_node.nid AND badge_member.deleted = 0');
    $firstBadge->innerJoin('node__field_badge_status', 'badge_status', 'badge_status.entity_id = badge_node.nid AND badge_status.deleted = 0');
    $firstBadge->addField('badge_member', 'field_member_to_badge_target_id', 'uid');
    $firstBadge->addExpression('MIN(badge_node.created)', 'first_badge_ts');
    $firstBadge->condition('badge_node.type', 'badge_request');
    $firstBadge->condition('badge_node.status', 1);
    $firstBadge->condition('badge_status.field_badge_status_value', 'active');
    $firstBadge->groupBy('badge_member.field_member_to_badge_target_id');

    $query = $this->database->select('profile', 'p');
    $query->leftJoin($firstBadge, 'first_badge', 'first_badge.uid = p.uid');
    $query->leftJoin('user__roles', 'member_role', "member_role.entity_id = p.uid AND member_role.roles_target_id IN ('current_member', 'member')");
    $query->leftJoin('profile__field_member_end_date', 'end_date', 'end_date.entity_id = p.profile_id AND end_date.deleted = 0');
    $query->addExpression("DATE_FORMAT(FROM_UNIXTIME(p.created), '%Y-%m')", 'period_key');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'cohort_total');
    $query->addExpression(
      'COUNT(DISTINCT CASE WHEN first_badge.first_badge_ts BETWEEN p.created AND p.created + :activation_seconds THEN p.uid END)',
      'activated_total',
      [':activation_seconds' => $activationDays * 86400]
    );
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->condition('p.created', [$start->getTimestamp(), $maturityCutoff->getTimestamp()], 'BETWEEN');
    $membership = $query->orConditionGroup()
      ->isNotNull('member_role.entity_id')
      ->isNotNull('end_date.field_member_end_date_value');
    $query->condition($membership);
    $query->groupBy('period_key');
    $query->orderBy('period_key', 'ASC');

    $rows = $query->execute();
    if (!$rows) {
      return [];
    }

    $series = [];
    foreach ($rows as $row) {
      $snapshotDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($row->period_key ?? '') . '-01');
      if (!$snapshotDate) {
        continue;
      }
      $snapshotDate = $snapshotDate->modify('last day of this month');
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

    $this->cache->set($cacheId, $series, $this->time->getRequestTime() + 3600, ['node_list:badge_request', 'profile_list', 'user_list']);
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
   * Returns a tally of risk reasons across all currently at-risk members.
   *
   * Reads the latest snapshot row per member (is_latest = 1), deserializes
   * the risk_reasons array, and counts how many at-risk members carry each
   * reason flag.
   *
   * @return array{total_at_risk: int, reasons: array<string, int>}
   *   'total_at_risk' is the member count; 'reasons' is sorted descending.
   */
  public function getCurrentRiskReasonBreakdown(int $riskThreshold = 20): array {
    if (!$this->isAvailable()) {
      return [];
    }

    $riskThreshold = max(0, $riskThreshold);
    $cacheId = sprintf('makerspace_dashboard:member_success:risk_reason_breakdown:%d', $riskThreshold);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $query = $this->database->select('ms_member_success_snapshot', 's');
    $query->fields('s', ['risk_reasons']);
    $query->condition('s.is_latest', 1);
    $query->condition('s.risk_score', $riskThreshold, '>=');

    $rows = $query->execute()->fetchAll();
    if (!$rows) {
      return [];
    }

    $tally = [];
    $total = 0;
    foreach ($rows as $row) {
      $reasons = @unserialize((string) ($row->risk_reasons ?? ''));
      if (!is_array($reasons)) {
        continue;
      }
      foreach ($reasons as $reason) {
        $reason = (string) $reason;
        if ($reason === '') {
          continue;
        }
        $tally[$reason] = ($tally[$reason] ?? 0) + 1;
      }
      $total++;
    }

    if (!$tally) {
      return [];
    }

    arsort($tally);
    $payload = [
      'total_at_risk' => $total,
      'reasons' => $tally,
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
