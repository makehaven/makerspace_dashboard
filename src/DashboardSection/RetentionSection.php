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
    $weight = 0;

    $build['intro']['#weight'] = $weight++;

    $build['kpi_table'] = $this->buildKpiTable();
    $build['kpi_table']['#weight'] = $weight++;

    $build['charts_section_heading'] = [
      '#markup' => '<h2>' . $this->t('Charts') . '</h2>',
      '#weight' => $weight++,
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

      $chart_id = 'snapshot_daily';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
      ];
      $chart['#raw_options']['options'] = [
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
      $chart['series'] = [
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
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $dailyLabels),
      ];
      $chart['yaxis'] = [
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
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Active Members (Daily Snapshots)'),
        $this->t('Latest @count snapshots of active membership headcount.', ['@count' => count($dailySeries)]),
        $chart,
        $dailyNotes
      );
      $build[$chart_id]['#weight'] = $weight++;
    }
    else {
      $build['snapshot_daily_empty'] = [
        '#markup' => $this->t('No membership snapshots were found. Trigger a makerspace_snapshot capture to populate this trend.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
        '#weight' => $weight++,
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

      $chart_id = 'snapshot_monthly';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
      ];
      $chart['#raw_options']['options'] = [
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
      $chart['series'] = [
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
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $monthlyLabels),
      ];
      $chart['yaxis'] = [
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
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Active Members (Monthly Snapshot Anchor)'),
        $this->t('Collapses snapshots to one point per month using the latest capture inside each month (max @count months).', ['@count' => count($monthlySeries)]),
        $chart,
        $monthlyNotes
      );
      $build[$chart_id]['#weight'] = $weight++;
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

      $chart_id = 'snapshot_yearly';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
      ];
      $chart['#raw_options']['options'] = [
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
      $chart['series'] = [
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
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $yearlyLabels),
      ];
      $chart['yaxis'] = [
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
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Active Members (Year-End Snapshot Anchor)'),
        $this->t('Uses the latest snapshot per calendar year to show longitudinal membership scale.'),
        $chart,
        $yearlyNotes
      );
      $build[$chart_id]['#weight'] = $weight++;
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

      $chart_id = 'net_membership';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
      ];
      $chart['joined'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Joined'),
        '#data' => $incomingSeries,
        '#color' => '#2563eb',
      ];
      $chart['ended'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Ended'),
        '#data' => $endingSeries,
        '#color' => '#f97316',
      ];
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $monthLabels),
      ];

      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Monthly Recruitment vs Churn'),
        $this->t('Total members who joined or ended each month (all membership types).'),
        $chart,
        [
          $this->t('Source: MembershipMetricsService::getFlow aggregates main member profile creation timestamps (treated as join dates) alongside recorded end dates (field_member_end_date) for published users.'),
          $this->t('Processing: Distinct members are grouped by calendar month; if the most recent 12 months are empty the query expands to 24 months.'),
          $this->t('Definitions: "Joined" counts unique users whose default member profile was created that month; "Ended" counts unique users whose end date falls in that month regardless of membership type.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;

      $chart_id = 'net_balance';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
      ];
      $chart['#raw_options']['options'] = [
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
      $chart['series_joined'] = [
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
      $chart['series_ended'] = [
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
      $chart['series_net'] = [
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
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $monthLabels),
      ];

      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Net Membership Change'),
        $this->t('Joined minus ended members per month with join/end overlays for context.'),
        $chart,
        [
          $this->t('Source: Derived from the same monthly join and end counts used in the recruitment vs churn chart.'),
          $this->t('Processing: Calculates joined minus ended members for each month and overlays raw join/end totals so spikes are easy to attribute.'),
          $this->t('Definitions: Positive values indicate net growth in headcount that month; negative values indicate attrition exceeded recruitment.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;

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
          '#weight' => $weight++,
        ];
      }

      // Membership type breakdown (incoming).
      $chart_id = 'type_incoming';
      $chart = $this->buildTypeChart(
        $monthLabels,
        $incomingByType,
        $periodKeys
      );
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Recruitment by Membership Type'),
        $this->t('Breakdown by membership type across the selected periods.'),
        $chart,
        [
          $this->t('Source: Same join-date dataset as the recruitment totals, segmented by membership type taxonomy terms (profile__field_membership_type).'),
          $this->t('Processing: Counts distinct members per type per month based on the taxonomy term active at join time.'),
          $this->t('Definitions: Type names come from taxonomy terms; unknown or missing terms appear as "Unclassified".'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;

      if (!empty($endingByReason)) {
        $recentPeriods = array_slice($periodKeys, -6);
        $periodLabels = array_map(fn($period) => $this->dateFormatter->format(strtotime($period), 'custom', 'M Y'), $recentPeriods);
        $reasonLabels = array_keys($endingReasonTotals);
        usort($reasonLabels, function ($a, $b) use ($endingReasonTotals) {
          return ($endingReasonTotals[$b] ?? 0) <=> ($endingReasonTotals[$a] ?? 0);
        });
        $colors = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#3b82f6', '#6366f1', '#a855f7', '#ec4899'];

        $chart_id = 'ending_reasons';
        $chart = [
          '#type' => 'chart',
          '#chart_type' => 'bar',
          '#chart_library' => 'chartjs',
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
          $chart['reason_' . $index] = [
            '#type' => 'chart_data',
            '#title' => ucwords(str_replace('_', ' ', $reason)),
            '#data' => $series,
            '#color' => $colors[$index % count($colors)],
          ];
        }

        $chart['xaxis'] = [
          '#type' => 'chart_xaxis',
          '#labels' => array_map('strval', $periodLabels),
        ];
        $chart['yaxis'] = [
          '#type' => 'chart_yaxis',
          '#title' => $this->t('Members ending'),
        ];

        $build[$chart_id] = $this->buildChartContainer(
          $chart_id,
          $this->t('Ending Memberships by Reason'),
          $this->t('Monthly churn totals stacked by recorded end reason (latest 6 months).'),
          $chart,
          [
            $this->t('Source: Membership end-date events joined to field_member_end_reason values.'),
            $this->t('Processing: Distinct members per reason per month; stacked bars show the contribution of each reason to total churn.'),
            $this->t('Definitions: Reasons reflect list-string options configured on member profiles when ending access.'),
          ]
        );
        $build[$chart_id]['#weight'] = $weight++;
      }
    }
    else {
      $build['net_membership_empty'] = [
        '#markup' => $this->t('No membership inflow data available in the selected window. Expand the date range or verify join/end dates.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
        '#weight' => $weight++,
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
      $chart_id = 'annual_cohorts';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
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
      $chart['active'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Still active'),
        '#data' => $activeSeries,
        '#color' => '#ef4444',
      ];
      $chart['inactive'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('No longer active'),
        '#data' => $inactiveSeries,
        '#color' => '#94a3b8',
      ];
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $cohortLabels),
      ];
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Cohort Composition by Join Year'),
        $this->t('Active vs inactive members for each join year cohort.'),
        $chart,
        [
          $this->t('Source: Members with join dates in profile__field_member_join_date grouped by calendar year.'),
          $this->t('Processing: Counts total members per cohort and marks a member as active when they hold an active membership role (defaults: current_member, member).'),
          $this->t('Definitions: "Still active" reflects active role assignment today; "No longer active" covers members without those roles.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;

      $chart_id = 'annual_retention';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
      ];
      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Avg annual retention %'),
        '#data' => $retentionSeries,
        '#color' => '#ef4444',
      ];
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $cohortLabels),
      ];
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Average Annual Retention by Cohort'),
        $this->t('Annualized retention rate estimating the average share of members retained each year since joining.'),
        $chart,
        [
          $this->t('Source: Same cohort dataset as the composition chart, using join dates from profile__field_member_join_date.'),
          $this->t('Processing: Converts the share of members still active into an annualized retention rate (geometric mean) to normalize for cohort age.'),
          $this->t('Definitions: Active roles default to current_member/member; cohorts without active members report 0% annualized retention.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;
    }
    else {
      $build['annual_cohorts_empty'] = [
        '#markup' => $this->t('No cohort data available for the selected years.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
        '#weight' => $weight++,
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

    $build['utilization_intro'] = $this->buildIntro($this->t('Aggregates door access requests into unique members per day to highlight peak usage windows. Showing @start – @end.', [
        '@start' => $range_start_label,
        '@end' => $range_end_label,
      ]));
    $build['utilization_intro']['#weight'] = $weight++;

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
      '#weight' => $weight++,
    ];

    $build['definition_note'] = [
      '#type' => 'markup',
      '#markup' => $this->t('An entry counts every access-control request. A unique entry counts each member once per day, even if they badge multiple times.'),
      '#prefix' => '<div class="makerspace-dashboard-definition">',
      '#suffix' => '</div>',
      '#weight' => $weight++,
    ];

    $chart_id = 'daily_unique_entries';
    $chart = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
    ];
    $chart['#raw_options']['options']['scales']['x']['ticks'] = [
      'autoSkip' => FALSE,
      'maxRotation' => 0,
      'minRotation' => 0,
      'padding' => 8,
      'callback' => 'function(value){ return value || ""; }',
    ];

    $chart['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Unique entries'),
      '#data' => $monthlyData,
    ];

    $chart['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#title' => $this->t('Month'),
      '#labels' => array_map('strval', $monthlyLabels),
    ];

    $chart['yaxis'] = [
      '#type' => 'chart_yaxis',
      '#title' => $this->t('Unique members'),
    ];

    $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Monthly Member Entries (Unique Visits)'),
        $this->t('Sums the number of unique members badging in per day into monthly totals.'),
        $chart,
        [
          $this->t('Source: Access-control request logs (type = access_control_request) joined to the requesting user.'),
          $this->t('Processing: Counts distinct members per day within the configured window, then aggregates those counts into monthly totals (latest 12 months).'),
          $this->t('Definitions: Only users with active membership roles (defaults: current_member, member) are included.'),
        ]
      );
    $build[$chart_id]['#weight'] = $weight++;

    if (!empty($rollingAverage)) {
      $chart_id = 'rolling_average_chart';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
      ];
      $chart['#raw_options']['options']['scales']['x']['ticks'] = [
        'autoSkip' => FALSE,
        'maxRotation' => 0,
        'minRotation' => 0,
        'padding' => 8,
        'callback' => 'function(value){ return value || ""; }',
      ];
      $chart['series'] = [
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
        $chart['trend'] = [
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
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $rollingLabels),
      ];
      $chart['yaxis'] = [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Average unique members'),
      ];

      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('7-Day Rolling Average of Unique Entries'),
        $this->t('Smooths daily fluctuations to highlight longer-term trends over the last @days days.', ['@days' => $rollingWindow]),
        $chart,
        [
          $this->t('Source: Seven-day rolling average derived from the daily unique member counts.'),
          $this->t('Processing: Uses a sliding seven-day window (or shorter for the first few points) and overlays a least-squares trendline.'),
          $this->t('Definitions: Positive slope indicates growing average traffic; negative slope signals declining activity.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;
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

    $chart_id = 'frequency_buckets';
    $chart = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
    ];

    $chart['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Share of members'),
      '#data' => $frequency_data,
    ];
    $chart['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => array_map('strval', $frequency_label_values),
    ];

    $build[$chart_id] = $this->buildChartContainer(
      $chart_id,
      $this->t('Visit Frequency Distribution'),
      $this->t('Distribution of member visit frequency based on distinct check-in days over the last @days days.', ['@days' => $rollingWindow]),
      $chart,
      [
        $this->t('Source: Distinct visit days per member calculated from access-control logs over the rolling window.'),
        $this->t('Processing: Normalizes visits to a 30-day window before bucketing (none, 1, 2-3, 4-6, 7-12, 13+ per month equivalent).'),
        $this->t('Definitions: Each active member appears in exactly one bucket; members with no visits during the window are counted explicitly.'),
      ]
    );
    $build[$chart_id]['#weight'] = $weight++;

    if (!empty($rollingDates)) {
      $chart_id = 'weekday_profile';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
      ];
      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Average members'),
        '#data' => $weekdayAverages,
      ];
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $weekdayLabels),
      ];
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Average Members by Day of Week'),
        $this->t('Average unique members badging in on each weekday over the last @days days.', ['@days' => $rollingWindow]),
        $chart,
        [
          $this->t('Source: Same daily unique member dataset used for the rolling average chart.'),
          $this->t('Processing: Totals unique members per weekday over the configured window and divides by the number of occurrences.'),
          $this->t('Definitions: Values represent average unique members per weekday; blank weekdays indicate no data within the window.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;
    }

    $hourlyAverages = $this->utilizationDataService->getAverageEntriesByHour($startAll->getTimestamp(), $end_of_day->getTimestamp());
    $hourlyData = $hourlyAverages['averages'] ?? [];
    if (!empty($hourlyData)) {
      $hourLabels = [];
      foreach (array_keys($hourlyData) as $hour) {
        $hourLabels[] = sprintf('%02d:00', (int) $hour);
      }

      $chart_id = 'hourly_entry_profile';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
      ];
      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Average entries'),
        '#data' => array_values($hourlyData),
        '#color' => '#3b82f6',
      ];
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $hourLabels),
      ];
      $chart['yaxis'] = [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Average entries / day'),
      ];
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Average Entries per Hour of Day'),
        $this->t('Average access-control requests in each hour, averaged across @days days.', ['@days' => $hourlyAverages['days'] ?? count($rollingDates)]),
        $chart,
        [
          $this->t('Source: Raw access_control_request logs for the same window as the utilization charts.'),
          $this->t('Processing: Counts every entry event per hour, sums across the window, and divides by the number of days to produce an hourly average.'),
          $this->t('Definitions: Includes repeat entries; filter above charts for unique-member analysis.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;
    }

    $timeOfDayLabels = $this->utilizationDataService->getTimeOfDayBucketLabels();
    $firstEntryBuckets = $this->utilizationDataService->getFirstEntryBucketsByWeekday($rollingStartDate->getTimestamp(), $end_of_day->getTimestamp());
    $weekdayOrder = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $bucketOrder = array_keys($timeOfDayLabels);
    $firstEntryLabels = array_map(fn($day) => $this->t($day), $weekdayOrder);

    $chart_id = 'weekday_first_entry';
    $chart = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
      '#stacking' => 1,
    ];

    foreach ($bucketOrder as $index => $bucketId) {
      $series = [];
      foreach (range(0, 6) as $weekdayIndex) {
        $series[] = $firstEntryBuckets[$weekdayIndex][$bucketId] ?? 0;
      }
      $chart['series_' . $index] = [
        '#type' => 'chart_data',
        '#title' => $timeOfDayLabels[$bucketId],
        '#data' => $series,
      ];
    }

    $chart['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => array_map('strval', $firstEntryLabels),
    ];
    $chart['yaxis'] = [
      '#type' => 'chart_yaxis',
      '#title' => $this->t('Members (first entry)'),
    ];

    $build[$chart_id] = $this->buildChartContainer(
      $chart_id,
      $this->t('First Entry Time by Weekday (Stacked)'),
      $this->t('Shows when members first badge in each day, grouped by weekday and time-of-day bucket.'),
      $chart,
      [
        $this->t('Source: Access-control requests within the rolling window grouped by member, day, and time of day.'),
        $this->t('Processing: Members are counted once per weekday/time bucket (early morning, morning, midday, afternoon, evening, night) for each day they badge in that range.'),
        $this->t('Definitions: Buckets use 24-hour ranges; night spans 22:00-04:59 and rolls across midnight.'),
      ]
    );
    $build[$chart_id]['#weight'] = $weight++;

    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => ['access_control_log_list', 'user_list', 'config:makerspace_dashboard.settings'],
    ];

    return $build;
  }
  /**
   * Builds a chart showing membership type distribution per period.
   */
  protected function buildTypeChart(array $labels, array $seriesMap, array $periodKeys, string $chartType = 'bar'): array {
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

}
