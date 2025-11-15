<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Provides cached membership flow aggregates for retention charts.
 */
class RetentionFlowDataService {

  /**
   * Membership metrics service.
   */
  protected MembershipMetricsService $membershipMetrics;

  /**
   * Time service.
   */
  protected TimeInterface $time;

  /**
   * Cached flow window result.
   */
  protected ?array $windowCache = NULL;

  /**
   * Constructs the data service.
   */
  public function __construct(MembershipMetricsService $membershipMetrics, TimeInterface $time) {
    $this->membershipMetrics = $membershipMetrics;
    $this->time = $time;
  }

  /**
   * Returns aggregated flow data for the most recent 12–24 months.
   */
  public function getFlowWindow(): array {
    if ($this->windowCache !== NULL) {
      return $this->windowCache;
    }

    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
      ->setTime(0, 0);
    $start = $now->modify('-11 months');
    $flow = $this->membershipMetrics->getFlow($start, $now, 'month');
    if (empty($flow['incoming']) && empty($flow['ending'])) {
      $start = $now->modify('-23 months');
      $flow = $this->membershipMetrics->getFlow($start, $now, 'month');
    }

    $incomingTotals = [];
    $endingTotals = [];
    $incomingByType = [];
    $endingByType = [];

    foreach ($flow['incoming'] as $row) {
      $period = $row['period'];
      $incomingTotals[$period] = ($incomingTotals[$period] ?? 0) + $row['count'];
      $type = $row['membership_type'] ?? '';
      $incomingByType[$type][$period] = $row['count'];
    }
    foreach ($flow['ending'] as $row) {
      $period = $row['period'];
      $endingTotals[$period] = ($endingTotals[$period] ?? 0) + $row['count'];
      $type = $row['membership_type'] ?? '';
      $endingByType[$type][$period] = $row['count'];
    }

    $periodKeys = array_unique(array_merge(array_keys($incomingTotals), array_keys($endingTotals)));
    sort($periodKeys);

    $endingReasonRows = $this->membershipMetrics->getEndReasonsByPeriod($start, $now, 'month');
    $endingByReason = [];
    $endingReasonTotals = [];
    foreach ($endingReasonRows as $row) {
      $period = $row['period'];
      $reason = $row['reason'] ?? 'Unknown';
      $endingByReason[$reason][$period] = $row['count'];
      $endingReasonTotals[$reason] = ($endingReasonTotals[$reason] ?? 0) + $row['count'];
    }

    return $this->windowCache = [
      'start' => $start,
      'end' => $now,
      'flow' => $flow,
      'period_keys' => $periodKeys,
      'incoming_totals' => $incomingTotals,
      'ending_totals' => $endingTotals,
      'incoming_by_type' => $incomingByType,
      'ending_by_type' => $endingByType,
      'ending_by_reason' => $endingByReason,
      'ending_reason_totals' => $endingReasonTotals,
    ];
  }

}
