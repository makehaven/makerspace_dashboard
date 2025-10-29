<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;
use Drupal\makerspace_dashboard\Service\UtilizationDataService;

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
   * Utilization data service.
   */
  protected UtilizationDataService $utilizationDataService;

  /**
   * Config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Snapshot data service.
   */
  protected SnapshotDataService $snapshotData;

  /**
   * Constructs the retention section.
   */
  public function __construct(MembershipMetricsService $membership_metrics, DateFormatterInterface $date_formatter, TimeInterface $time, UtilizationDataService $utilization_data_service, ConfigFactoryInterface $config_factory, SnapshotDataService $snapshot_data) {
    parent::__construct();
    $this->membershipMetrics = $membership_metrics;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
    $this->utilizationDataService = $utilization_data_service;
    $this->configFactory = $config_factory;
    $this->snapshotData = $snapshot_data;
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
    return $this->t('Retention');
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

    $dailySnapshots = $this->snapshotData->getMembershipCountSeries('day');
    $monthlySnapshots = $this->snapshotData->getMembershipCountSeries('month');
    $yearlySnapshots = $this->snapshotData->getMembershipCountSeries('year');

    $dailySeries = $dailySnapshots ? array_slice($dailySnapshots, -120) : [];
    $monthlySeries = $monthlySnapshots ? array_slice($monthlySnapshots, -36) : [];
    $yearlySeries = $yearlySnapshots ? array_slice($yearlySnapshots, -12) : [];

    if ($dailySeries) {
      $dailyLabels = [];
      $dailyCounts = [];
      foreach ($dailySeries as $row) {
        $dailyLabels[] = $this->dateFormatter->format($row['period_date']->getTimestamp(), 'custom', 'M j, Y');
        $dailyCounts[] = $row['members_active'];
      }

      $dailyFirst = NULL;
      $dailyLast = NULL;
      if ($dailySnapshots) {
        $firstKey = array_key_first($dailySnapshots);
        $lastKey = array_key_last($dailySnapshots);
        if ($firstKey !== NULL) {
          $dailyFirst = $dailySnapshots[$firstKey];
        }
        if ($lastKey !== NULL) {
          $dailyLast = $dailySnapshots[$lastKey];
        }
      }

      $build['snapshot_daily'] = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Active members (daily snapshots)'),
        '#description' => $this->t('Latest @count snapshots of active membership headcount.', ['@count' => count($dailySeries)]),
      ];
      $build['snapshot_daily']['#raw_options']['options'] = [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => 'function(context){ const value = context.parsed.y ?? context.raw; return value.toLocaleString() + " active members"; }',
            ],
          ],
        ],
        'scales' => [
          'x' => [
            'ticks' => [
              'autoSkip' => TRUE,
              'maxRotation' => 0,
              'minRotation' => 0,
              'maxTicksLimit' => 14,
            ],
          ],
          'y' => [
            'ticks' => [
              'precision' => 0,
              'callback' => 'function(value){ return value.toLocaleString(); }',
            ],
          ],
        ],
      ];
      $build['snapshot_daily']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Active members'),
        '#data' => $dailyCounts,
        '#color' => '#2563eb',
        '#settings' => [
          'borderColor' => '#2563eb',
          'backgroundColor' => 'rgba(37,99,235,0.18)',
          'fill' => TRUE,
          'tension' => 0.25,
          'pointRadius' => 3,
          'pointHoverRadius' => 5,
          'borderWidth' => 2,
        ],
      ];
      $build['snapshot_daily']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $dailyLabels),
      ];
      $build['snapshot_daily']['yaxis'] = [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Active members'),
      ];

      $dailyNotes = [
        $this->t('Source: makerspace_snapshot membership_totals snapshots joined with ms_fact_org_snapshot (members_active).'),
        $this->t('Processing: Keeps the latest membership_totals snapshot captured each day; snapshots flagged as tests are excluded.'),
      ];
      if ($coverage = $this->formatSnapshotCoverage($dailyFirst, $dailyLast, count($dailySnapshots))) {
        $dailyNotes[] = $coverage;
      }
      $build['snapshot_daily_info'] = $this->buildChartInfo($dailyNotes, $this->t('Daily snapshot notes'));
    }
    else {
      $build['snapshot_daily_empty'] = [
        '#markup' => $this->t('No membership snapshots were found. Trigger a makerspace_snapshot capture to populate this trend.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
      ];
    }

    if ($monthlySeries) {
      $monthlyLabels = [];
      $monthlyCounts = [];
      foreach ($monthlySeries as $row) {
        $monthlyLabels[] = $this->dateFormatter->format($row['period_date']->getTimestamp(), 'custom', 'M Y');
        $monthlyCounts[] = $row['members_active'];
      }

      $monthlyFirst = NULL;
      $monthlyLast = NULL;
      if ($monthlySnapshots) {
        $firstKey = array_key_first($monthlySnapshots);
        $lastKey = array_key_last($monthlySnapshots);
        if ($firstKey !== NULL) {
          $monthlyFirst = $monthlySnapshots[$firstKey];
        }
        if ($lastKey !== NULL) {
          $monthlyLast = $monthlySnapshots[$lastKey];
        }
      }

      $build['snapshot_monthly'] = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Active members (monthly snapshot anchor)'),
        '#description' => $this->t('Collapses snapshots to one point per month using the latest capture inside each month (max @count months).', ['@count' => count($monthlySeries)]),
      ];
      $build['snapshot_monthly']['#raw_options']['options'] = [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => 'function(context){ const value = context.parsed.y ?? context.raw; return value.toLocaleString() + " active members"; }',
            ],
          ],
        ],
        'scales' => [
          'x' => [
            'ticks' => [
              'autoSkip' => TRUE,
              'maxRotation' => 0,
              'minRotation' => 0,
              'padding' => 8,
              'maxTicksLimit' => 18,
            ],
          ],
          'y' => [
            'ticks' => [
              'precision' => 0,
              'callback' => 'function(value){ return value.toLocaleString(); }',
            ],
          ],
        ],
      ];
      $build['snapshot_monthly']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Active members'),
        '#data' => $monthlyCounts,
        '#color' => '#4338ca',
        '#settings' => [
          'borderColor' => '#4338ca',
          'backgroundColor' => 'rgba(67,56,202,0.18)',
          'fill' => TRUE,
          'tension' => 0.25,
          'pointRadius' => 4,
          'pointHoverRadius' => 6,
          'borderWidth' => 2,
        ],
      ];
      $build['snapshot_monthly']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $monthlyLabels),
      ];
      $build['snapshot_monthly']['yaxis'] = [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Active members'),
      ];

      $monthlyNotes = [
        $this->t('Source: makerspace_snapshot membership_totals snapshots joined with ms_fact_org_snapshot (members_active).'),
        $this->t('Processing: Groups snapshots by calendar month and keeps the most recent capture in each month to smooth overlapping manual runs or scheduled captures.'),
      ];
      if ($coverage = $this->formatSnapshotCoverage($monthlyFirst, $monthlyLast, count($monthlySnapshots))) {
        $monthlyNotes[] = $coverage;
      }
      $build['snapshot_monthly_info'] = $this->buildChartInfo($monthlyNotes, $this->t('Monthly snapshot notes'));
    }

    if ($yearlySeries) {
      $yearlyLabels = [];
      $yearlyCounts = [];
      foreach ($yearlySeries as $row) {
        $yearlyLabels[] = $this->dateFormatter->format($row['period_date']->getTimestamp(), 'custom', 'Y');
        $yearlyCounts[] = $row['members_active'];
      }

      $yearlyFirst = NULL;
      $yearlyLast = NULL;
      if ($yearlySnapshots) {
        $firstKey = array_key_first($yearlySnapshots);
        $lastKey = array_key_last($yearlySnapshots);
        if ($firstKey !== NULL) {
          $yearlyFirst = $yearlySnapshots[$firstKey];
        }
        if ($lastKey !== NULL) {
          $yearlyLast = $yearlySnapshots[$lastKey];
        }
      }

      $build['snapshot_yearly'] = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Active members (year-end snapshot anchor)'),
        '#description' => $this->t('Uses the latest snapshot per calendar year to show longitudinal membership scale.'),
      ];
      $build['snapshot_yearly']['#raw_options']['options'] = [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => 'function(context){ const value = context.parsed.y ?? context.raw; return value.toLocaleString() + " active members"; }',
            ],
          ],
        ],
        'scales' => [
          'x' => [
            'ticks' => [
              'autoSkip' => FALSE,
              'maxRotation' => 0,
              'minRotation' => 0,
            ],
          ],
          'y' => [
            'ticks' => [
              'precision' => 0,
              'callback' => 'function(value){ return value.toLocaleString(); }',
            ],
          ],
        ],
      ];
      $build['snapshot_yearly']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Active members'),
        '#data' => $yearlyCounts,
        '#color' => '#0f172a',
        '#settings' => [
          'borderColor' => '#0f172a',
          'backgroundColor' => 'rgba(15,23,42,0.15)',
          'fill' => TRUE,
          'tension' => 0.15,
          'pointRadius' => 4,
          'pointHoverRadius' => 6,
          'borderWidth' => 2,
        ],
      ];
      $build['snapshot_yearly']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $yearlyLabels),
      ];
      $build['snapshot_yearly']['yaxis'] = [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Active members'),
      ];

      $yearlyNotes = [
        $this->t('Source: makerspace_snapshot membership_totals snapshots joined with ms_fact_org_snapshot (members_active).'),
        $this->t('Processing: Keeps the last snapshot logged in each calendar year to highlight long-term growth.'),
      ];
      if ($coverage = $this->formatSnapshotCoverage($yearlyFirst, $yearlyLast, count($yearlySnapshots))) {
        $yearlyNotes[] = $coverage;
      }
      $build['snapshot_yearly_info'] = $this->buildChartInfo($yearlyNotes, $this->t('Yearly snapshot notes'));
    }

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
        '#labels' => array_map('strval', $monthLabels),
      ];
      $build['net_membership_info'] = $this->buildChartInfo([
        $this->t('Source: MembershipMetricsService::getFlow aggregates main member profile creation timestamps (treated as join dates) alongside recorded end dates (field_member_end_date) for published users.'),
        $this->t('Processing: Distinct members are grouped by calendar month; if the most recent 12 months are empty the query expands to 24 months.'),
        $this->t('Definitions: "Joined" counts unique users whose default member profile was created that month; "Ended" counts unique users whose end date falls in that month regardless of membership type.'),
      ]);

      $build['net_balance'] = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Net membership change'),
        '#description' => $this->t('Joined minus ended members per month with join/end overlays for context.'),
      ];
      $build['net_balance']['#raw_options']['options'] = [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => 'function(context){ const value = context.parsed.y ?? context.raw; return context.dataset.label + \': \' + value.toLocaleString(); }',
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'ticks' => [
              'precision' => 0,
              'callback' => 'function(value){ return value.toLocaleString(); }',
            ],
          ],
        ],
      ];
      $build['net_balance']['series_joined'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Joined'),
        '#data' => $incomingSeries,
        '#color' => '#2563eb',
        '#settings' => [
          'borderColor' => '#2563eb',
          'backgroundColor' => 'rgba(37,99,235,0.15)',
          'fill' => FALSE,
          'tension' => 0.2,
          'borderWidth' => 2,
          'pointRadius' => 3,
          'pointHoverRadius' => 5,
        ],
      ];
      $build['net_balance']['series_ended'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Ended'),
        '#data' => $endingSeries,
        '#color' => '#f97316',
        '#settings' => [
          'borderColor' => '#f97316',
          'backgroundColor' => 'rgba(249,115,22,0.15)',
          'fill' => FALSE,
          'tension' => 0.2,
          'borderWidth' => 2,
          'pointRadius' => 3,
          'pointHoverRadius' => 5,
        ],
      ];
      $build['net_balance']['series_net'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Net change'),
        '#data' => $netSeries,
        '#color' => '#0f172a',
        '#settings' => [
          'borderColor' => '#0f172a',
          'backgroundColor' => 'rgba(15,23,42,0.1)',
          'fill' => FALSE,
          'tension' => 0.25,
          'borderWidth' => 2,
          'borderDash' => [6, 4],
          'pointRadius' => 0,
          'pointHoverRadius' => 4,
        ],
      ];
      $build['net_balance']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $monthLabels),
      ];
      $build['net_balance_info'] = $this->buildChartInfo([
        $this->t('Source: Derived from the same monthly join and end counts used in the recruitment vs churn chart.'),
        $this->t('Processing: Calculates joined minus ended members for each month and overlays raw join/end totals so spikes are easy to attribute.'),
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
          '#labels' => array_map('strval', $periodLabels),
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
        '#labels' => array_map('strval', $cohortLabels),
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
        '#labels' => array_map('strval', $cohortLabels),
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

    $config = $this->configFactory->get('makerspace_dashboard.settings');
    $dailyWindow = max(7, (int) ($config->get('utilization.daily_window_days') ?? 90));
    $rollingWindow = max($dailyWindow, (int) ($config->get('utilization.rolling_window_days') ?? 365));
    $window = 7;

    $timezone = new \DateTimeZone(date_default_timezone_get());
    $end_of_day = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone($timezone)
      ->setTime(23, 59, 59);

    $totalWindowDays = max($dailyWindow, $rollingWindow);
    $totalInterval = new \DateInterval('P' . max(0, $totalWindowDays - 1) . 'D');
    $startAll = $end_of_day->sub($totalInterval)->setTime(0, 0, 0);

    $daily_counts = $this->utilizationDataService->getDailyUniqueEntries($startAll->getTimestamp(), $end_of_day->getTimestamp());

    $allDates = [];
    $allData = [];
    $current = $startAll;
    for ($i = 0; $i < $totalWindowDays; $i++) {
      $day_key = $current->format('Y-m-d');
      $allDates[] = $current;
      $allData[] = $daily_counts[$day_key] ?? 0;
      $current = $current->add(new \DateInterval('P1D'));
    }

    $dailyData = array_slice($allData, -$dailyWindow);
    $dailyDates = array_slice($allDates, -$dailyWindow);

    $dailyLabels = [];
    $dailyCount = count($dailyDates);
    foreach ($dailyDates as $index => $date) {
      if ($index === 0 || $index === $dailyCount - 1 || $date->format('j') === '1') {
        $dailyLabels[] = $this->dateFormatter->format($date->getTimestamp(), 'custom', 'M Y');
      }
      else {
        $dailyLabels[] = '';
      }
    }

    $rollingDates = array_slice($allDates, -$rollingWindow);
    $rollingCounts = array_slice($allData, -$rollingWindow);

    $monthlyBuckets = [];
    foreach ($allDates as $index => $date) {
      $monthKey = $date->format('Y-m');
      if (!isset($monthlyBuckets[$monthKey])) {
        $monthlyBuckets[$monthKey] = [
          'label' => $this->dateFormatter->format($date->getTimestamp(), 'custom', 'M Y'),
          'value' => 0,
        ];
      }
      $monthlyBuckets[$monthKey]['value'] += $allData[$index];
    }
    $monthlyKeys = array_keys($monthlyBuckets);
    $monthlySlice = array_slice($monthlyKeys, -12);
    $monthlyLabels = [];
    $monthlyData = [];
    foreach ($monthlySlice as $key) {
      $monthlyLabels[] = $monthlyBuckets[$key]['label'];
      $monthlyData[] = $monthlyBuckets[$key]['value'];
    }

    $rollingLabels = [];
    $rollingAverage = [];
    $running_sum = 0;
    $rollingCount = count($rollingCounts);
    $sumX = 0;
    $sumY = 0;
    $sumXY = 0;
    $sumXX = 0;
    for ($i = 0; $i < $rollingCount; $i++) {
      $running_sum += $rollingCounts[$i];
      if ($i >= $window) {
        $running_sum -= $rollingCounts[$i - $window];
      }
      $divisor = $i < $window - 1 ? $i + 1 : $window;
      $rollingAverage[] = $divisor > 0 ? round($running_sum / $divisor, 2) : 0;

      $day = $rollingDates[$i];
      if ($i === 0 || $i === $rollingCount - 1 || $day->format('j') === '1') {
        $rollingLabels[] = $this->dateFormatter->format($day->getTimestamp(), 'custom', 'M Y');
      }
      else {
        $rollingLabels[] = '';
      }

      $x = $i;
      $y = $rollingAverage[$i];
      $sumX += $x;
      $sumY += $y;
      $sumXY += $x * $y;
      $sumXX += $x * $x;
    }

    $trendLine = [];
    $slope = NULL;
    $intercept = NULL;
    if ($rollingCount > 1) {
      $denominator = $rollingCount * $sumXX - ($sumX * $sumX);
      $slope = $denominator ? (($rollingCount * $sumXY) - ($sumX * $sumY)) / $denominator : 0;
      $intercept = ($sumY - $slope * $sumX) / $rollingCount;
      for ($i = 0; $i < $rollingCount; $i++) {
        $trendLine[] = round($slope * $i + $intercept, 2);
      }
    }

    $total_entries = array_sum($dailyData);
    $average_per_day = $dailyWindow > 0 ? round($total_entries / $dailyWindow, 2) : 0;
    $max_value = 0;
    $max_label = $this->t('n/a');
    foreach ($dailyData as $index => $value) {
      if ($value > $max_value) {
        $max_value = $value;
        $max_label = $dailyLabels[$index];
      }
    }

    $weekdayTotals = array_fill(0, 7, 0);
    $weekdayCounts = array_fill(0, 7, 0);
    foreach ($rollingDates as $index => $date) {
      $weekday = (int) $date->format('w');
      $weekdayTotals[$weekday] += $rollingCounts[$index];
      $weekdayCounts[$weekday]++;
    }
    $weekdayLabels = [];
    $weekdayAverages = [];
    $weekdayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    foreach ($weekdayNames as $index => $shortName) {
      $weekdayLabels[] = $this->t($shortName);
      $count = $weekdayCounts[$index] ?: 1;
      $weekdayAverages[] = round($weekdayTotals[$index] / $count, 2);
    }

    $rollingStartDate = $rollingDates[0] ?? $startAll;
    $range_start_label = $this->dateFormatter->format($rollingStartDate->getTimestamp(), 'custom', 'M j, Y');
    $range_end_label = $this->dateFormatter->format($end_of_day->getTimestamp(), 'custom', 'M j, Y');

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Aggregates door access requests into unique members per day to highlight peak usage windows. Showing @start – @end.', [
        '@start' => $range_start_label,
        '@end' => $range_end_label,
      ]),
    ];

    $summary = [
      $this->t('Total entries in last @days days: @total', ['@days' => $dailyWindow, '@total' => $total_entries]),
      $this->t('Average per day: @avg', ['@avg' => $average_per_day]),
      $this->t('Busiest day: @day (@count members)', ['@day' => $max_label, '@count' => $max_value]),
    ];
    if ($slope !== NULL) {
      $summary[] = $this->t('Rolling trend: Δ = @per_day members/day (@per_week / week, @per_month / month)', [
        '@per_day' => round($slope, 3),
        '@per_week' => round($slope * 7, 2),
        '@per_month' => round($slope * 30, 2),
      ]);
      $summary[] = $this->t('Trendline: y = @m x + @b', [
        '@m' => round($slope, 3),
        '@b' => round($intercept, 2),
      ]);
    }
    $build['summary'] = [
      '#theme' => 'item_list',
      '#items' => array_filter($summary),
      '#attributes' => ['class' => ['makerspace-dashboard-summary']],
    ];

    $build['definition_note'] = [
      '#type' => 'markup',
      '#markup' => $this->t('An entry counts every access-control request. A unique entry counts each member once per day, even if they badge multiple times.'),
      '#prefix' => '<div class="makerspace-dashboard-definition">',
      '#suffix' => '</div>',
    ];

    $build['daily_unique_entries'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Monthly member entries (unique visits)'),
      '#description' => $this->t('Sums the number of unique members badging in per day into monthly totals.'),
    ];
    $build['daily_unique_entries']['#raw_options']['options']['scales']['x']['ticks'] = [
      'autoSkip' => FALSE,
      'maxRotation' => 0,
      'minRotation' => 0,
      'padding' => 8,
      'callback' => 'function(value){ return value || ""; }',
    ];

    $build['daily_unique_entries']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Unique entries'),
      '#data' => $monthlyData,
    ];

    $build['daily_unique_entries']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#title' => $this->t('Month'),
      '#labels' => array_map('strval', $monthlyLabels),
    ];

    $build['daily_unique_entries']['yaxis'] = [
      '#type' => 'chart_yaxis',
      '#title' => $this->t('Unique members'),
    ];
    $build['daily_unique_entries_info'] = $this->buildChartInfo([
      $this->t('Source: Access-control request logs (type = access_control_request) joined to the requesting user.'),
      $this->t('Processing: Counts distinct members per day within the configured window, then aggregates those counts into monthly totals (latest 12 months).'),
      $this->t('Definitions: Only users with active membership roles (defaults: current_member, member) are included.'),
    ]);

    if (!empty($rollingAverage)) {
      $build['rolling_average_chart'] = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('7 day rolling average of unique entries'),
        '#description' => $this->t('Smooths daily fluctuations to highlight longer-term trends over the last @days days.', ['@days' => $rollingWindow]),
      ];
      $build['rolling_average_chart']['#raw_options']['options']['scales']['x']['ticks'] = [
        'autoSkip' => FALSE,
        'maxRotation' => 0,
        'minRotation' => 0,
        'padding' => 8,
        'callback' => 'function(value){ return value || ""; }',
      ];
      $build['rolling_average_chart']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Rolling average'),
        '#data' => $rollingAverage,
        '#settings' => [
          'borderColor' => '#ff7f0e',
          'backgroundColor' => 'rgba(255,127,14,0.2)',
          'fill' => FALSE,
          'tension' => 0.3,
          'borderWidth' => 2,
          'pointRadius' => 0,
        ],
      ];
      if (!empty($trendLine)) {
        $build['rolling_average_chart']['trend'] = [
          '#type' => 'chart_data',
          '#title' => $this->t('Trend'),
          '#data' => $trendLine,
          '#settings' => [
            'borderColor' => '#2ca02c',
            'borderDash' => [6, 4],
            'fill' => FALSE,
            'pointRadius' => 0,
            'borderWidth' => 2,
          ],
        ];
      }
      $build['rolling_average_chart']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $rollingLabels),
      ];
      $build['rolling_average_chart']['yaxis'] = [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Average unique members'),
      ];
      $build['rolling_average_chart_info'] = $this->buildChartInfo([
        $this->t('Source: Seven-day rolling average derived from the daily unique member counts.'),
        $this->t('Processing: Uses a sliding seven-day window (or shorter for the first few points) and overlays a least-squares trendline.'),
        $this->t('Definitions: Positive slope indicates growing average traffic; negative slope signals declining activity.'),
      ]);
    }

    $frequency_buckets = $this->utilizationDataService->getVisitFrequencyBuckets($rollingStartDate->getTimestamp(), $end_of_day->getTimestamp());
    $bucket_labels = [
      'no_visits' => $this->t('No visits'),
      'one_per_month' => $this->t('1 visit / month'),
      'two_to_three' => $this->t('2-3 visits / month'),
      'weekly' => $this->t('Weekly (4-6)'),
      'twice_weekly' => $this->t('2-3 per week'),
      'daily_plus' => $this->t('Daily or more'),
    ];
    $frequency_data = [];
    $frequency_label_values = [];
    foreach ($bucket_labels as $bucket_key => $label) {
      $frequency_data[] = $frequency_buckets[$bucket_key] ?? 0;
      $frequency_label_values[] = $label;
    }

    $build['frequency_buckets'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Visit frequency distribution'),
      '#description' => $this->t('Distribution of member visit frequency based on distinct check-in days over the last @days days.', ['@days' => $rollingWindow]),
    ];

    $build['frequency_buckets']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Share of members'),
      '#data' => $frequency_data,
    ];
    $build['frequency_buckets']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => array_map('strval', $frequency_label_values),
    ];
    $build['frequency_buckets_info'] = $this->buildChartInfo([
      $this->t('Source: Distinct visit days per member calculated from access-control logs over the rolling window.'),
      $this->t('Processing: Normalizes visits to a 30-day window before bucketing (none, 1, 2-3, 4-6, 7-12, 13+ per month equivalent).'),
      $this->t('Definitions: Each active member appears in exactly one bucket; members with no visits during the window are counted explicitly.'),
    ]);

    if (!empty($rollingDates)) {
      $build['weekday_profile'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Average members by day of week'),
        '#description' => $this->t('Average unique members badging in on each weekday over the last @days days.', ['@days' => $rollingWindow]),
      ];
      $build['weekday_profile']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Average members'),
        '#data' => $weekdayAverages,
      ];
      $build['weekday_profile']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $weekdayLabels),
      ];
      $build['weekday_profile_info'] = $this->buildChartInfo([
        $this->t('Source: Same daily unique member dataset used for the rolling average chart.'),
        $this->t('Processing: Totals unique members per weekday over the configured window and divides by the number of occurrences.'),
        $this->t('Definitions: Values represent average unique members per weekday; blank weekdays indicate no data within the window.'),
      ]);
    }

    $hourlyAverages = $this->utilizationDataService->getAverageEntriesByHour($startAll->getTimestamp(), $end_of_day->getTimestamp());
    $hourlyData = $hourlyAverages['averages'] ?? [];
    if (!empty($hourlyData)) {
      $hourLabels = [];
      foreach (array_keys($hourlyData) as $hour) {
        $hourLabels[] = sprintf('%02d:00', (int) $hour);
      }

      $build['hourly_entry_profile'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Average entries per hour of day'),
        '#description' => $this->t('Average access-control requests in each hour, averaged across @days days.', ['@days' => $hourlyAverages['days'] ?? count($rollingDates)]),
      ];
      $build['hourly_entry_profile']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Average entries'),
        '#data' => array_values($hourlyData),
        '#color' => '#3b82f6',
      ];
      $build['hourly_entry_profile']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $hourLabels),
      ];
      $build['hourly_entry_profile']['yaxis'] = [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Average entries / day'),
      ];
      $build['hourly_entry_profile_info'] = $this->buildChartInfo([
        $this->t('Source: Raw access_control_request logs for the same window as the utilization charts.'),
        $this->t('Processing: Counts every entry event per hour, sums across the window, and divides by the number of days to produce an hourly average.'),
        $this->t('Definitions: Includes repeat entries; filter above charts for unique-member analysis.'),
      ]);
    }

    $timeOfDayLabels = $this->utilizationDataService->getTimeOfDayBucketLabels();
    $firstEntryBuckets = $this->utilizationDataService->getFirstEntryBucketsByWeekday($rollingStartDate->getTimestamp(), $end_of_day->getTimestamp());
    $weekdayOrder = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $bucketOrder = array_keys($timeOfDayLabels);
    $firstEntryLabels = array_map(fn($day) => $this->t($day), $weekdayOrder);

    $firstEntryChart = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('First entry time by weekday (stacked)'),
      '#description' => $this->t('Shows when members first badge in each day, grouped by weekday and time-of-day bucket.'),
      '#stacking' => 1,
    ];

    foreach ($bucketOrder as $index => $bucketId) {
      $series = [];
      foreach (range(0, 6) as $weekdayIndex) {
        $series[] = $firstEntryBuckets[$weekdayIndex][$bucketId] ?? 0;
      }
      $firstEntryChart['series_' . $index] = [
        '#type' => 'chart_data',
        '#title' => $timeOfDayLabels[$bucketId],
        '#data' => $series,
      ];
    }

    $firstEntryChart['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => array_map('strval', $firstEntryLabels),
    ];
    $firstEntryChart['yaxis'] = [
      '#type' => 'chart_yaxis',
      '#title' => $this->t('Members (first entry)'),
    ];

    $build['weekday_first_entry'] = $firstEntryChart;
    $build['weekday_first_entry_info'] = $this->buildChartInfo([
      $this->t('Source: Access-control requests within the rolling window grouped by member, day, and time of day.'),
      $this->t('Processing: Members are counted once per weekday/time bucket (early morning, morning, midday, afternoon, evening, night) for each day they badge in that range.'),
      $this->t('Definitions: Buckets use 24-hour ranges; night spans 22:00-04:59 and rolls across midnight.'),
    ]);

    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => ['access_control_log_list', 'user_list', 'config:makerspace_dashboard.settings'],
    ];

    return $build;
  }

  /**
   * Builds a friendly coverage string for snapshot-based charts.
   */
  protected function formatSnapshotCoverage(?array $first, ?array $last, int $total): ?TranslatableMarkup {
    if (!$first || !$last || $total < 1) {
      return NULL;
    }

    $start = $first['snapshot_date'] ?? NULL;
    $end = $last['snapshot_date'] ?? NULL;
    if (!$start instanceof \DateTimeImmutable || !$end instanceof \DateTimeImmutable) {
      return NULL;
    }

    $startLabel = $this->dateFormatter->format($start->getTimestamp(), 'custom', 'M j, Y');
    $endLabel = $this->dateFormatter->format($end->getTimestamp(), 'custom', 'M j, Y');

    return $this->t('Coverage: @start — @end (@count snapshots).', [
      '@start' => $startLabel,
      '@end' => $endLabel,
      '@count' => $total,
    ]);
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
      '#labels' => array_map('strval', $labels),
    ];

    return $build;
  }

}
