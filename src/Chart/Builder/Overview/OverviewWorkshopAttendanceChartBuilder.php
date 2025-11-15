<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Overview;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\EventsMembershipDataService;

/**
 * Builds the ticketed workshop attendance chart.
 */
class OverviewWorkshopAttendanceChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'overview';
  protected const CHART_ID = 'workshop_attendance_trend';
  protected const WEIGHT = 30;

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected EventsMembershipDataService $eventsMembershipDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $endDate = new \DateTimeImmutable('last day of last month');
    $startDate = $endDate->modify('-23 months');
    $series = $this->eventsMembershipDataService->getMonthlyWorkshopAttendanceSeries($startDate, $endDate);
    $labels = array_map('strval', $series['labels'] ?? []);
    $counts = array_map('intval', $series['counts'] ?? []);

    if (!$labels || !array_filter($counts)) {
      return NULL;
    }

    $datasets = [[
      'label' => (string) $this->t('Monthly attendees'),
      'data' => $counts,
      'borderColor' => '#64748b',
      'backgroundColor' => 'rgba(100, 116, 139, 0.2)',
      'pointRadius' => 3,
      'pointBackgroundColor' => '#64748b',
      'fill' => FALSE,
    ]];
    if ($trendDataset = $this->buildTrendDataset($counts, (string) $this->t('Trend'))) {
      $datasets[] = $trendDataset;
    }

    $tooltipCallback = 'function(context){ var value = context && context.parsed && context.parsed.y !== undefined ? context.parsed.y : (context && context.yLabel !== undefined ? context.yLabel : (context && context.value !== undefined ? context.value : null)); if (value === null) { return ""; } var label = context.datasetIndex === 0 ? "' . addslashes((string) $this->t('Monthly attendees')) . '" : "' . addslashes((string) $this->t('Trend')) . '"; return label + ": " + Number(value).toLocaleString() + " attendees"; }';

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'interaction' => [
          'mode' => 'index',
          'intersect' => FALSE,
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
        'hover' => [
          'mode' => 'index',
          'intersect' => FALSE,
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Workshop Attendance (Ticketed Workshops)'),
      (string) $this->t('Displays the monthly count of participants registered for ticketed workshops over the past two years.'),
      $visualization,
      [
        (string) $this->t('Source: CiviCRM participant records with counted statuses and event type "Ticketed Workshop".'),
        (string) $this->t('Processing: Groups registrations by workshop month; months with no registrations render as zero.'),
      ],
    );
  }

}
