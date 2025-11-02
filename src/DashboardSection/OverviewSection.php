<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\FinancialDataService;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\makerspace_dashboard\Service\KpiDataService;
use Drupal\makerspace_dashboard\Service\EventsMembershipDataService;

/**
 * Defines the OverviewSection class.
 */
class OverviewSection extends DashboardSectionBase {

  protected FinancialDataService $financialDataService;
  protected SnapshotDataService $snapshotDataService;
  protected DateFormatterInterface $dateFormatter;
  protected KpiDataService $kpiDataService;
  protected EventsMembershipDataService $eventsMembershipDataService;

  /**
   * Constructs the section.
   */
  public function __construct(FinancialDataService $financial_data_service, SnapshotDataService $snapshot_data_service, DateFormatterInterface $date_formatter, KpiDataService $kpi_data_service, EventsMembershipDataService $events_membership_data_service) {
    parent::__construct();
    $this->financialDataService = $financial_data_service;
    $this->snapshotDataService = $snapshot_data_service;
    $this->dateFormatter = $date_formatter;
    $this->kpiDataService = $kpi_data_service;
    $this->eventsMembershipDataService = $events_membership_data_service;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'overview';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Overview');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;

    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('overview'));
    $build['kpi_table']['#weight'] = $weight++;

    $build['charts_section_heading'] = [
      '#markup' => '<h2>' . $this->t('Charts') . '</h2>',
      '#weight' => $weight++,
    ];

    // Monthly Recurring Revenue Trend
    $end_date = new \DateTimeImmutable();
    $start_date = $end_date->modify('-6 months');
    $mrr_data = $this->financialDataService->getMrrTrend($start_date, $end_date);

    if (!empty(array_filter($mrr_data['data']))) {
      $primaryLabel = addslashes((string) $this->t('MRR ($)'));
      $trendLabel = addslashes((string) $this->t('Trend'));
      $chart_id = 'mrr_trend';
      $tooltipCallback = 'function(context){ var value = context && context.parsed && context.parsed.y !== undefined ? context.parsed.y : (context && context.yLabel !== undefined ? context.yLabel : (context && context.value !== undefined ? context.value : null)); if (value === null) { return ""; } var label = context.datasetIndex === 0 ? "' . $primaryLabel . '" : "' . $trendLabel . '"; return label + ": $" + Number(value).toLocaleString(); }';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#raw_options' => [
          'options' => [
            'legend' => ['display' => FALSE],
            'tooltips' => [
              'mode' => 'index',
              'intersect' => FALSE,
              'callbacks' => [
                'label' => $tooltipCallback,
              ],
            ],
            'plugins' => [
              'legend' => ['display' => FALSE],
              'tooltip' => [
                'mode' => 'index',
                'intersect' => FALSE,
                'callbacks' => [
                  'label' => $tooltipCallback,
                ],
              ],
            ],
            'interaction' => [
              'mode' => 'index',
              'intersect' => FALSE,
            ],
            'hover' => [
              'mode' => 'index',
              'intersect' => FALSE,
            ],
            'scales' => [
              'y' => [
                'ticks' => [
                  'callback' => 'function(value){ return "$" + Number(value).toLocaleString(); }',
                ],
              ],
            ],
          ],
        ],
      ];
      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('MRR ($)'),
        '#data' => $mrr_data['data'],
        '#color' => '#1d4ed8',
        '#options' => [
          'pointRadius' => 3,
          'pointBackgroundColor' => '#1d4ed8',
          'fill' => FALSE,
        ],
      ];
      if ($trend = $this->buildTrendDataset($mrr_data['data'], $this->t('Trend'), '#9ca3af')) {
        $chart['trend'] = $trend;
      }
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $mrr_data['labels']),
      ];
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Monthly Recurring Revenue Trend'),
        $this->t('Aggregate by billing source to highlight sustainability of recruitment/retention efforts.'),
        $chart,
        [
          $this->t('Source: Member join dates paired with membership type taxonomy terms.'),
          $this->t('Processing: Includes joins within the selected six-month window and multiplies counts by assumed monthly values.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;
    }

    // Active Members (Monthly Snapshot)
    $monthlySnapshots = $this->snapshotDataService->getMembershipCountSeries('month');
    $monthlySeries = $monthlySnapshots ? array_slice($monthlySnapshots, -12) : [];
    if ($monthlySeries) {
        $primaryLabelMembers = addslashes((string) $this->t('Active members'));
        $trendLabelMembers = addslashes((string) $this->t('Trend'));
        $monthlyLabels = [];
        $monthlyCounts = [];
        foreach ($monthlySeries as $row) {
            $monthlyLabels[] = $this->dateFormatter->format($row['period_date']->getTimestamp(), 'custom', 'M Y');
            $monthlyCounts[] = $row['members_active'];
        }
        $chart_id = 'snapshot_monthly';
        $tooltipCallbackMembers = 'function(context){ var value = context && context.parsed && context.parsed.y !== undefined ? context.parsed.y : (context && context.yLabel !== undefined ? context.yLabel : (context && context.value !== undefined ? context.value : null)); if (value === null) { return ""; } var label = context.datasetIndex === 0 ? "' . $primaryLabelMembers . '" : "' . $trendLabelMembers . '"; return label + ": " + Number(value).toLocaleString() + " members"; }';
        $chart = [
            '#type' => 'chart',
            '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#raw_options' => [
            'options' => [
                'legend' => ['display' => FALSE],
                'tooltips' => [
                    'mode' => 'index',
                    'intersect' => FALSE,
                    'callbacks' => [
                        'label' => $tooltipCallbackMembers,
                    ],
                ],
                'plugins' => [
                    'legend' => ['display' => FALSE],
                    'tooltip' => [
                        'mode' => 'index',
                        'intersect' => FALSE,
                        'callbacks' => [
                            'label' => $tooltipCallbackMembers,
                        ],
                    ],
                ],
                'interaction' => [
                    'mode' => 'index',
                    'intersect' => FALSE,
                ],
                'hover' => [
                    'mode' => 'index',
                    'intersect' => FALSE,
                ],
            ],
        ],
        ];
        $chart['series'] = [
            '#type' => 'chart_data',
            '#title' => $this->t('Active members'),
            '#data' => $monthlyCounts,
            '#color' => '#2563eb',
            '#options' => [
                'pointRadius' => 3,
                'pointBackgroundColor' => '#2563eb',
                'fill' => FALSE,
            ],
        ];
        if ($trend = $this->buildTrendDataset($monthlyCounts, $this->t('Trend'), '#9ca3af')) {
            $chart['trend'] = $trend;
        }
        $chart['xaxis'] = [
            '#type' => 'chart_xaxis',
            '#labels' => array_map('strval', $monthlyLabels),
        ];
        $build[$chart_id] = $this->buildChartContainer(
            $chart_id,
            $this->t('Active Members (Monthly Snapshot)'),
            $this->t('Collapses snapshots to one point per month using the latest capture inside each month.'),
            $chart,
            [
              $this->t('Source: makerspace_snapshot membership_totals snapshots.'),
              $this->t('Processing: Groups snapshots by calendar month and keeps the most recent capture in each month.'),
            ]
        );
        $build[$chart_id]['#weight'] = $weight++;
    }

    // Workshop Attendees (Monthly Totals)
    $workshopEnd = new \DateTimeImmutable('last day of last month');
    $workshopStart = $workshopEnd->modify('-23 months');
    $workshopSeries = $this->eventsMembershipDataService->getMonthlyWorkshopAttendanceSeries($workshopStart, $workshopEnd);
    if (!empty($workshopSeries['counts'])) {
        $primaryLabelWorkshops = addslashes((string) $this->t('Monthly attendees'));
        $trendLabelWorkshops = addslashes((string) $this->t('Trend'));
        $labels = array_map('strval', $workshopSeries['labels']);
        $counts = array_map('intval', $workshopSeries['counts']);
        $chart_id = 'workshop_attendance_trend';
            $tooltipCallbackWorkshops = 'function(context){ var value = context && context.parsed && context.parsed.y !== undefined ? context.parsed.y : (context && context.yLabel !== undefined ? context.yLabel : (context && context.value !== undefined ? context.value : null)); if (value === null) { return ""; } var label = context.datasetIndex === 0 ? "' . $primaryLabelWorkshops . '" : "' . $trendLabelWorkshops . '"; return label + ": " + Number(value).toLocaleString() + " attendees"; }';
            $chart = [
                '#type' => 'chart',
                '#chart_type' => 'line',
                '#chart_library' => 'chartjs',
                '#raw_options' => [
                    'options' => [
                        'legend' => ['display' => FALSE],
                        'tooltips' => [
                            'mode' => 'index',
                            'intersect' => FALSE,
                            'callbacks' => [
                                'label' => $tooltipCallbackWorkshops,
                            ],
                        ],
                        'plugins' => [
                            'legend' => ['display' => FALSE],
                            'tooltip' => [
                                'mode' => 'index',
                                'intersect' => FALSE,
                                'callbacks' => [
                                    'label' => $tooltipCallbackWorkshops,
                                ],
                            ],
                        ],
                        'interaction' => [
                            'mode' => 'index',
                            'intersect' => FALSE,
                        ],
                        'hover' => [
                            'mode' => 'index',
                            'intersect' => FALSE,
                        ],
                    ],
                ],
            ];
            $chart['series'] = [
                '#type' => 'chart_data',
                '#title' => $this->t('Monthly attendees'),
                '#data' => $counts,
                '#color' => '#64748b',
                '#options' => [
                    'pointRadius' => 3,
                    'pointBackgroundColor' => '#64748b',
                    'fill' => FALSE,
                ],
            ];
            if ($trend = $this->buildTrendDataset($counts, $this->t('Trend'), '#9ca3af')) {
                $chart['trend'] = $trend;
            }
            $chart['xaxis'] = [
                '#type' => 'chart_xaxis',
                '#labels' => $labels,
            ];
            $build[$chart_id] = $this->buildChartContainer(
                $chart_id,
                $this->t('Workshop Attendance (Ticketed Workshops)'),
                $this->t('Displays the monthly count of participants registered for ticketed workshops over the past two years.'),
                $chart,
                [
                  $this->t('Source: CiviCRM participant records with counted statuses and event type "Ticketed Workshop".'),
                  $this->t('Processing: Groups registrations by workshop month; months with no registrations render as zero.'),
                ]
            );
            $build[$chart_id]['#weight'] = $weight++;
        }
    }

    // Reserve Funds (Months of Operating Expense)
    $reserveSeries = $this->kpiDataService->getReserveFundsMonthlySeries();
    if (!empty($reserveSeries['values'])) {
        $primaryLabelReserve = addslashes((string) $this->t('Months of coverage'));
        $trendLabelReserve = addslashes((string) $this->t('Trend'));
        $reserveLabels = array_map('strval', $reserveSeries['labels']);
        $reserveValues = array_map(static function ($value) {
            return round((float) $value, 2);
        }, $reserveSeries['values']);

        $chart_id = 'reserve_funds_months';
        $tooltipCallbackReserve = 'function(context){ var value = context && context.parsed && context.parsed.y !== undefined ? context.parsed.y : (context && context.yLabel !== undefined ? context.yLabel : (context && context.value !== undefined ? context.value : null)); if (value === null) { return ""; } var label = context.datasetIndex === 0 ? "' . $primaryLabelReserve . '" : "' . $trendLabelReserve . '"; return label + ": " + Number(value).toFixed(1) + " months"; }';
        $chart = [
            '#type' => 'chart',
            '#chart_type' => 'line',
            '#chart_library' => 'chartjs',
            '#raw_options' => [
                'options' => [
                    'elements' => [
                        'line' => ['tension' => 0.25],
                    ],
                    'legend' => ['display' => FALSE],
                    'tooltips' => [
                        'mode' => 'index',
                        'intersect' => FALSE,
                        'callbacks' => [
                            'label' => $tooltipCallbackReserve,
                        ],
                    ],
                    'plugins' => [
                        'legend' => ['display' => FALSE],
                        'tooltip' => [
                            'mode' => 'index',
                            'intersect' => FALSE,
                            'callbacks' => [
                                'label' => $tooltipCallbackReserve,
                            ],
                        ],
                    ],
                    'interaction' => [
                        'mode' => 'index',
                        'intersect' => FALSE,
                    ],
                    'hover' => [
                        'mode' => 'index',
                        'intersect' => FALSE,
                    ],
                    'scales' => [
                        'y' => [
                            'title' => [
                                'display' => TRUE,
                                'text' => (string) $this->t('Months of operating expense'),
                            ],
                            'ticks' => [
                                'display' => TRUE,
                                'padding' => 6,
                                'color' => '#475569',
                                'callback' => 'function(value){ return Number(value).toFixed(1) + " mo"; }',
                            ],
                            'grid' => [
                                'color' => 'rgba(148, 163, 184, 0.25)',
                            ],
                            'min' => 0,
                        ],
                    ],
                ],
            ],
        ];
        $chart['series'] = [
            '#type' => 'chart_data',
            '#title' => $this->t('Months of coverage'),
            '#data' => $reserveValues,
            '#color' => '#15803d',
        ];
        if ($trend = $this->buildTrendDataset($reserveValues, $this->t('Trend'), '#9ca3af')) {
            $chart['trend'] = $trend;
        }
        $chart['xaxis'] = [
            '#type' => 'chart_xaxis',
            '#labels' => $reserveLabels,
        ];

        $info = [
            $this->t('Source: Balance-Sheet tab ("Cash and Cash Equivalents") and Income-Statement tab ("Total Expense").'),
            $this->t('Processing: Converts the latest cash balance into months of runway using the trailing four-quarter average monthly expense.'),
        ];
        if (!empty($reserveSeries['last_updated'])) {
            $info[] = $this->t('Last sheet update: @date', ['@date' => $reserveSeries['last_updated']]);
        }

        $build[$chart_id] = $this->buildChartContainer(
            $chart_id,
            $this->t('Reserve Funds Coverage'),
            $this->t('Tracks how many months of operating expense current cash reserves can sustain, showing trends over time.'),
            $chart,
            $info
        );
        $build[$chart_id]['#weight'] = $weight++;
    }

    return $build;
  }

  /**
   * Builds a trend dataset for Chart.js based on the supplied values.
   */
  private function buildTrendDataset(array $values, TranslatableMarkup $label, string $color = '#9ca3af'): ?array {
    $trendValues = $this->calculateTrendLine($values);
    if (empty($trendValues)) {
      return NULL;
    }

    $rounded = array_map(static function ($value) {
      return round($value, 2);
    }, $trendValues);

    return [
      '#type' => 'chart_data',
      '#title' => $label,
      '#data' => $rounded,
      '#color' => $color,
      '#options' => [
        'borderDash' => [6, 4],
        'pointRadius' => 0,
        'pointHoverRadius' => 0,
        'pointHitRadius' => 0,
        'borderWidth' => 2,
        'fill' => FALSE,
      ],
      '#weight' => 10,
    ];
  }

  /**
   * Calculates a simple linear regression trend line for a dataset.
   */
  private function calculateTrendLine(array $values): array {
    $count = count($values);
    if ($count < 2) {
      return [];
    }

    $sumX = 0.0;
    $sumY = 0.0;
    $sumXY = 0.0;
    $sumX2 = 0.0;

    foreach ($values as $index => $rawValue) {
      if (!is_numeric($rawValue)) {
        return [];
      }
      $x = (float) $index;
      $y = (float) $rawValue;
      $sumX += $x;
      $sumY += $y;
      $sumXY += $x * $y;
      $sumX2 += $x * $x;
    }

    $denominator = ($count * $sumX2) - ($sumX * $sumX);
    if (abs($denominator) < 1e-8) {
      return [];
    }

    $slope = (($count * $sumXY) - ($sumX * $sumY)) / $denominator;
    $intercept = ($sumY - ($slope * $sumX)) / $count;

    $trend = [];
    foreach (array_keys($values) as $index) {
      $trend[] = ($slope * $index) + $intercept;
    }

    return $trend;
  }
}
