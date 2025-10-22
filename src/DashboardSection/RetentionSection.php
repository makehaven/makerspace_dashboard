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
    $incomingByType = [];
    $endingByType = [];
    foreach ($flowData['incoming'] as $row) {
      $incomingTotals[$row['period']] = ($incomingTotals[$row['period']] ?? 0) + $row['count'];
      $incomingByType[$row['membership_type']][$row['period']] = $row['count'];
    }
    foreach ($flowData['ending'] as $row) {
      $endingTotals[$row['period']] = ($endingTotals[$row['period']] ?? 0) + $row['count'];
      $endingByType[$row['membership_type']][$row['period']] = $row['count'];
    }
    $periodKeys = array_unique(array_merge(array_keys($incomingTotals), array_keys($endingTotals)));
    sort($periodKeys);

    $totals = $flowData['totals'] ?? [];

    if ($periodKeys && array_sum($incomingTotals) + array_sum($endingTotals) > 0) {
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
      $build['net_membership_info'] = $this->buildChartInfo([
        $this->t('Source: MembershipMetricsService::getFlow aggregates profile member join (field_member_join_date) and end (field_member_end_date) dates for users with active membership roles.'),
        $this->t('Processing: Distinct members are grouped by calendar month; if the most recent 12 months are empty the query expands to 24 months.'),
        $this->t('Definitions: "Joined" counts unique users whose join date falls in that month; "Ended" counts unique users whose end date falls in that month regardless of membership type.'),
      ]);

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
      $build['net_balance_info'] = $this->buildChartInfo([
        $this->t('Source: Derived from the same monthly join and end counts used in the recruitment vs churn chart.'),
        $this->t('Processing: Calculates joined minus ended members for each month to highlight net growth or contraction.'),
        $this->t('Definitions: Positive values indicate net growth in headcount that month; negative values indicate attrition exceeded recruitment.'),
      ]);

      if ($totals) {
        $recent = array_slice($totals, -6);
        $increase = 0;
        $decrease = 0;
        $net = 0;
        foreach ($recent as $row) {
          $increase += $row['incoming'];
          $decrease += $row['ending'];
          $net += $row['incoming'] - $row['ending'];
        }
        $build['summary_recent'] = [
          '#theme' => 'item_list',
          '#items' => [
            $this->t('Last 6 months recruitment: @in', ['@in' => $increase]),
            $this->t('Last 6 months churn: @out', ['@out' => $decrease]),
            $this->t('Net change: @net', ['@net' => $net]),
          ],
          '#attributes' => ['class' => ['makerspace-dashboard-summary']],
        ];
      }

      // Membership type breakdown (incoming).
      $build['type_incoming'] = $this->buildTypeChart(
        $this->t('Recruitment by membership type'),
        $monthLabels,
        $incomingByType,
        $periodKeys
      );
      $build['type_incoming_info'] = $this->buildChartInfo([
        $this->t('Source: Same join-date dataset as the recruitment totals, segmented by membership type taxonomy terms (profile__field_membership_type).'),
        $this->t('Processing: Counts distinct members per type per month based on the taxonomy term active at join time.'),
        $this->t('Definitions: Type names come from taxonomy terms; unknown or missing terms appear as "Unclassified".'),
      ]);

      $build['type_ending'] = $this->buildTypeChart(
        $this->t('Ending memberships by type'),
        $monthLabels,
        $endingByType,
        $periodKeys,
        'line'
      );
      $build['type_ending_info'] = $this->buildChartInfo([
        $this->t('Source: Membership end-date records broken out by membership type at the time of termination.'),
        $this->t('Processing: Distinct members per membership type per month; mirrors the recruitment breakdown but for churn events.'),
        $this->t('Definitions: Missing membership types fall back to "Unclassified"; months with no churn display as zero.'),
      ]);
    }
    else {
      $build['net_membership_empty'] = [
        '#markup' => $this->t('No membership inflow data available in the selected window. Expand the date range or verify join/end dates.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
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

    if ($cohortLabels && array_sum($retentionSeries) > 0) {
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
      $build['annual_cohorts_info'] = $this->buildChartInfo([
        $this->t('Source: Members with join dates in profile__field_member_join_date grouped by calendar year.'),
        $this->t('Processing: Counts total members per cohort and marks a member as active when they hold an active membership role (defaults: current_member, member).'),
        $this->t('Definitions: "Still active" reflects active role assignment today; "No longer active" covers members without those roles.'),
      ]);

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
      $build['annual_retention_info'] = $this->buildChartInfo([
        $this->t('Source: Same cohort dataset as the composition chart, using join dates from profile__field_member_join_date.'),
        $this->t('Processing: Calculates the percentage of each cohort still holding an active membership role and converts it to a percentage.'),
        $this->t('Definitions: Active roles default to current_member/member; cohorts without active members report 0% retention.'),
      ]);
    }
    else {
      $build['annual_cohorts_empty'] = [
        '#markup' => $this->t('No cohort data available for the selected years.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
      ];
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list'],
    ];

    return $build;
  }

  /**
   * Builds a chart showing membership type distribution per period.
   */
  protected function buildTypeChart(string $title, array $labels, array $seriesMap, array $periodKeys, string $chartType = 'bar'): array {
    if (empty($seriesMap)) {
      return [
        '#markup' => $this->t('No membership type data available for this window.'),
      ];
    }

    $datasets = [];
    $seriesIndex = 0;
    foreach ($seriesMap as $membershipType => $dataPerPeriod) {
      $series = [];
      foreach ($periodKeys as $period) {
        $series[] = (int) ($dataPerPeriod[$period] ?? 0);
      }
      $datasets['series_' . $seriesIndex] = [
        '#type' => 'chart_data',
        '#title' => $membershipType ?: $this->t('Unclassified'),
        '#data' => $series,
      ];
      $seriesIndex++;
    }

    $build = [
      '#type' => 'chart',
      '#chart_type' => $chartType,
      '#chart_library' => 'chartjs',
      '#title' => $title,
      '#description' => $this->t('Breakdown by membership type across the selected periods.'),
      '#cache' => [
        'max-age' => 3600,
        'tags' => ['profile_list', 'user_list'],
      ],
    ];

    $build += $datasets;

    $build['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => $labels,
    ];

    return $build;
  }

}
