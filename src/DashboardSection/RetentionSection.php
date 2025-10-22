<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Tracks recruitment and retention cohorts.
 */
class RetentionSection extends DashboardSectionBase {

  /**
   * Membership metrics service.
   */
  protected MembershipMetricsService $membershipMetrics;

  /**
   * Date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Time service.
   */
  protected TimeInterface $time;

  /**
   * Constructs the retention section.
   */
  public function __construct(MembershipMetricsService $membership_metrics, DateFormatterInterface $date_formatter, TimeInterface $time) {
    parent::__construct();
    $this->membershipMetrics = $membership_metrics;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'retention';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Recruitment & Retention');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Compare monthly inflow vs churn and track long-term cohort retention across membership types.'),
    ];

    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
      ->setTime(0, 0);
    $flowStart = $now->modify('-11 months');

    $flowData = $this->membershipMetrics->getFlow($flowStart, $now, 'month');
    if (empty($flowData['incoming']) && empty($flowData['ending'])) {
      $flowStart = $now->modify('-23 months');
      $flowData = $this->membershipMetrics->getFlow($flowStart, $now, 'month');
    }
    $incomingTotals = [];
    $endingTotals = [];
    foreach ($flowData['incoming'] as $row) {
      $incomingTotals[$row['period']] = ($incomingTotals[$row['period']] ?? 0) + $row['count'];
    }
    foreach ($flowData['ending'] as $row) {
      $endingTotals[$row['period']] = ($endingTotals[$row['period']] ?? 0) + $row['count'];
    }
    $periodKeys = array_unique(array_merge(array_keys($incomingTotals), array_keys($endingTotals)));
    sort($periodKeys);

    if ($periodKeys) {
      $monthLabels = [];
      $incomingSeries = [];
      $endingSeries = [];
      $netSeries = [];
      foreach ($periodKeys as $key) {
        $timestamp = strtotime($key);
        $monthLabels[] = $this->dateFormatter->format($timestamp, 'custom', 'M Y');
        $incomingValue = $incomingTotals[$key] ?? 0;
        $endingValue = $endingTotals[$key] ?? 0;
        $incomingSeries[] = $incomingValue;
        $endingSeries[] = $endingValue;
        $netSeries[] = $incomingValue - $endingValue;
      }

      $build['net_membership'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Monthly recruitment vs churn'),
        '#description' => $this->t('Total members who joined or ended each month (all membership types).'),
      ];
      $build['net_membership']['joined'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Joined'),
        '#data' => $incomingSeries,
      ];
      $build['net_membership']['ended'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Ended'),
        '#data' => $endingSeries,
      ];
      $build['net_membership']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $monthLabels,
      ];

      $build['net_balance'] = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Net membership change'),
        '#description' => $this->t('Joined minus ended members per month.'),
      ];
      $build['net_balance']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Net change'),
        '#data' => $netSeries,
      ];
      $build['net_balance']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $monthLabels,
      ];
    }
    else {
      $build['net_membership_empty'] = [
        '#markup' => $this->t('No membership inflow data available in the selected window.'),
      ];
    }

    $currentYear = (int) $now->format('Y');
    $cohortData = $this->membershipMetrics->getAnnualCohorts($currentYear - 5, $currentYear);
    $cohortLabels = [];
    $activeSeries = [];
    $inactiveSeries = [];
    $retentionSeries = [];
    foreach ($cohortData as $row) {
      $cohortLabels[] = (string) $row['year'];
      $activeSeries[] = $row['active'];
      $inactiveSeries[] = $row['inactive'];
      $retentionSeries[] = $row['retention_percent'];
    }

    if ($cohortLabels) {
      $build['annual_cohorts'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Cohort composition by join year'),
        '#description' => $this->t('Active vs inactive members for each join year cohort.'),
      ];
      $build['annual_cohorts']['active'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Still active'),
        '#data' => $activeSeries,
      ];
      $build['annual_cohorts']['inactive'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('No longer active'),
        '#data' => $inactiveSeries,
      ];
      $build['annual_cohorts']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $cohortLabels,
      ];

      $build['annual_retention'] = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Retention percentage by join year'),
        '#description' => $this->t('Share of each cohort that is still active today.'),
      ];
      $build['annual_retention']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Retention %'),
        '#data' => $retentionSeries,
      ];
      $build['annual_retention']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $cohortLabels,
      ];
    }
    else {
      $build['annual_cohorts_empty'] = [
        '#markup' => $this->t('No cohort data available for the selected years.'),
      ];
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list'],
    ];

    return $build;
  }

}
