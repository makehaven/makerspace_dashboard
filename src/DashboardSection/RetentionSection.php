<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;

/**
 * Tracks recruitment and retention cohorts.
 */
class RetentionSection extends DashboardSectionBase {

  /**
   * Membership metrics service.
   */
  protected MembershipMetricsService $membershipMetrics;

  /**
   * Demographics data service.
   */
  protected DemographicsDataService $demographicsData;

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
  public function __construct(MembershipMetricsService $membership_metrics, DateFormatterInterface $date_formatter, TimeInterface $time, DemographicsDataService $demographics_data) {
    parent::__construct();
    $this->membershipMetrics = $membership_metrics;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
    $this->demographicsData = $demographics_data;
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
    $endingReasonRows = $this->membershipMetrics->getEndReasonsByPeriod($flowStart, $now, 'month');
    $endingByReason = [];
    $endingReasonTotals = [];
    foreach ($endingReasonRows as $row) {
      $endingByReason[$row['reason']][$row['period']] = $row['count'];
      $endingReasonTotals[$row['reason']] = ($endingReasonTotals[$row['reason']] ?? 0) + $row['count'];
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
        '#color' => '#2563eb',
      ];
      $build['net_membership']['ended'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Ended'),
        '#data' => $endingSeries,
        '#color' => '#f97316',
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
        '#color' => '#0f172a',
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

      $discoveryRows = $this->demographicsData->getDiscoveryDistribution();
      if (!empty($discoveryRows)) {
        $discoveryLabels = array_map(fn(array $row) => $row['label'], $discoveryRows);
        $discoveryCounts = array_map(fn(array $row) => $row['count'], $discoveryRows);

        $build['discovery_sources'] = [
          '#type' => 'chart',
          '#chart_type' => 'bar',
          '#chart_library' => 'chartjs',
          '#title' => $this->t('How members discovered us'),
          '#description' => $this->t('Self-reported discovery sources from member profiles.'),
        ];
        $build['discovery_sources']['series'] = [
          '#type' => 'chart_data',
          '#title' => $this->t('Members'),
          '#data' => $discoveryCounts,
        ];
        $build['discovery_sources']['xaxis'] = [
          '#type' => 'chart_xaxis',
          '#labels' => $discoveryLabels,
        ];
        $build['discovery_sources_info'] = $this->buildChartInfo([
          $this->t('Source: field_member_discovery on active default member profiles with membership roles (defaults: current_member, member).'),
          $this->t('Processing: Aggregates responses and rolls options with fewer than five members into "Other".'),
          $this->t('Definitions: Missing responses surface as "Not captured"; encourage staff to populate this field for richer recruitment insights.'),
        ]);
      }

      if (!empty($endingByReason)) {
        $recentPeriods = array_slice($periodKeys, -6);
        $periodLabels = array_map(fn($period) => $this->dateFormatter->format(strtotime($period), 'custom', 'M Y'), $recentPeriods);
        $reasonLabels = array_keys($endingReasonTotals);
        usort($reasonLabels, function ($a, $b) use ($endingReasonTotals) {
          return ($endingReasonTotals[$b] ?? 0) <=> ($endingReasonTotals[$a] ?? 0);
        });
        $colors = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#3b82f6', '#6366f1', '#a855f7', '#ec4899'];

        $endingChart = [
          '#type' => 'chart',
          '#chart_type' => 'bar',
          '#chart_library' => 'chartjs',
          '#title' => $this->t('Ending memberships by reason'),
          '#description' => $this->t('Monthly churn totals stacked by recorded end reason (latest 6 months).'),
          '#stacking' => 1,
          '#raw_options' => [
            'scales' => [
              'x' => ['stacked' => TRUE],
              'y' => ['stacked' => TRUE],
            ],
          ],
        ];

        foreach ($reasonLabels as $index => $reason) {
          $series = [];
          foreach ($recentPeriods as $period) {
            $series[] = $endingByReason[$reason][$period] ?? 0;
          }
          $endingChart['reason_' . $index] = [
            '#type' => 'chart_data',
            '#title' => ucwords(str_replace('_', ' ', $reason)),
            '#data' => $series,
            '#color' => $colors[$index % count($colors)],
          ];
        }

        $endingChart['xaxis'] = [
          '#type' => 'chart_xaxis',
          '#labels' => $periodLabels,
        ];
        $endingChart['yaxis'] = [
          '#type' => 'chart_yaxis',
          '#title' => $this->t('Members ending'),
        ];

        $build['ending_reasons'] = $endingChart;
        $build['ending_reasons_info'] = $this->buildChartInfo([
          $this->t('Source: Membership end-date events joined to field_member_end_reason values.'),
          $this->t('Processing: Distinct members per reason per month; stacked bars show the contribution of each reason to total churn.'),
          $this->t('Definitions: Reasons reflect list-string options configured on member profiles when ending access.'),
        ]);
      }
    }
    else {
      $build['net_membership_empty'] = [
        '#markup' => $this->t('No membership inflow data available in the selected window. Expand the date range or verify join/end dates.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
      ];
    }

    $currentYear = (int) $now->format('Y');
    $cohortData = $this->membershipMetrics->getAnnualCohorts(2012, $currentYear);
    $cohortLabels = [];
    $activeSeries = [];
    $inactiveSeries = [];
    $retentionSeries = [];
    foreach ($cohortData as $row) {
      $cohortLabels[] = (string) $row['year'];
      $activeSeries[] = $row['active'];
      $inactiveSeries[] = $row['inactive'];
      $retentionSeries[] = $row['annualized_retention_percent'];
    }

    if ($cohortLabels && array_sum($retentionSeries) > 0) {
      $build['annual_cohorts'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Cohort composition by join year'),
        '#description' => $this->t('Active vs inactive members for each join year cohort.'),
        '#stacking' => 1,
        '#raw_options' => [
          'plugins' => [
            'tooltip' => [
              'callbacks' => [
                'afterBody' => "function(context){ var index = context[0].dataIndex; var chart = context[0].chart; var datasets = chart.data.datasets; var active = datasets[0].data[index] || 0; var inactive = datasets[1].data[index] || 0; var total = active + inactive; return ['Total: ' + total, 'Active: ' + active, 'Inactive: ' + inactive]; }",
              ],
            ],
          ],
        ],
      ];
      $build['annual_cohorts']['active'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Still active'),
        '#data' => $activeSeries,
        '#color' => '#ef4444',
      ];
      $build['annual_cohorts']['inactive'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('No longer active'),
        '#data' => $inactiveSeries,
        '#color' => '#94a3b8',
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
        '#title' => $this->t('Average annual retention by cohort'),
        '#description' => $this->t('Annualized retention rate estimating the average share of members retained each year since joining.'),
      ];
      $build['annual_retention']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Avg annual retention %'),
        '#data' => $retentionSeries,
        '#color' => '#ef4444',
      ];
      $build['annual_retention']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $cohortLabels,
      ];
      $build['annual_retention_info'] = $this->buildChartInfo([
        $this->t('Source: Same cohort dataset as the composition chart, using join dates from profile__field_member_join_date.'),
        $this->t('Processing: Converts the share of members still active into an annualized retention rate (geometric mean) to normalize for cohort age.'),
        $this->t('Definitions: Active roles default to current_member/member; cohorts without active members report 0% annualized retention.'),
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
