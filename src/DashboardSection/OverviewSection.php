<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\FinancialDataService;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;
use Drupal\makerspace_dashboard\Service\InfrastructureDataService;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\makerspace_dashboard\Service\KpiDataService;

/**
 * Defines the OverviewSection class.
 */
class OverviewSection extends DashboardSectionBase {

  protected FinancialDataService $financialDataService;
  protected SnapshotDataService $snapshotDataService;
  protected InfrastructureDataService $infrastructureDataService;
  protected DateFormatterInterface $dateFormatter;
  protected KpiDataService $kpiDataService;

  /**
   * Constructs the section.
   */
  public function __construct(FinancialDataService $financial_data_service, SnapshotDataService $snapshot_data_service, InfrastructureDataService $infrastructure_data_service, DateFormatterInterface $date_formatter, KpiDataService $kpi_data_service) {
    parent::__construct();
    $this->financialDataService = $financial_data_service;
    $this->snapshotDataService = $snapshot_data_service;
    $this->infrastructureDataService = $infrastructure_data_service;
    $this->dateFormatter = $date_formatter;
    $this->kpiDataService = $kpi_data_service;
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
      $chart_id = 'mrr_trend';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
      ];
      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('MRR ($)'),
        '#data' => $mrr_data['data'],
      ];
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
        $monthlyLabels = [];
        $monthlyCounts = [];
        foreach ($monthlySeries as $row) {
            $monthlyLabels[] = $this->dateFormatter->format($row['period_date']->getTimestamp(), 'custom', 'M Y');
            $monthlyCounts[] = $row['members_active'];
        }
        $chart_id = 'snapshot_monthly';
        $chart = [
            '#type' => 'chart',
            '#chart_type' => 'line',
            '#chart_library' => 'chartjs',
        ];
        $chart['series'] = [
            '#type' => 'chart_data',
            '#title' => $this->t('Active members'),
            '#data' => $monthlyCounts,
        ];
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

    // Tools by Status
    $statusCounts = $this->infrastructureDataService->getToolStatusCounts();
    if (!empty($statusCounts)) {
        $labels = array_keys($statusCounts);
        $counts = array_values($statusCounts);
        $chart_id = 'tool_status_breakdown';
        $chart = [
            '#type' => 'chart',
            '#chart_type' => 'bar',
            '#chart_library' => 'chartjs',
        ];
        $chart['series'] = [
            '#type' => 'chart_data',
            '#title' => $this->t('Tools'),
            '#data' => $counts,
        ];
        $chart['xaxis'] = [
            '#type' => 'chart_xaxis',
            '#labels' => array_map('strval', $labels),
        ];
        $build[$chart_id] = $this->buildChartContainer(
            $chart_id,
            $this->t('Tools by Status'),
            $this->t('Counts published equipment records by their current status taxonomy term.'),
            $chart,
            [
              $this->t('Source: Published item nodes joined to field_item_status and taxonomy term labels.'),
              $this->t('Processing: Counts each tool once regardless of category.'),
            ]
        );
        $build[$chart_id]['#weight'] = $weight++;
    }

    return $build;
  }
}
