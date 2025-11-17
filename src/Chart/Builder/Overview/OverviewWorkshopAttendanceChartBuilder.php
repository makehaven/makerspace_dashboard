<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Overview;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\EventsMembershipDataService;
use Drupal\makerspace_dashboard\Support\RangeSelectionTrait;

/**
 * Builds the ticketed workshop attendance chart.
 */
class OverviewWorkshopAttendanceChartBuilder extends ChartBuilderBase {

  use RangeSelectionTrait;

  protected const SECTION_ID = 'overview';
  protected const CHART_ID = 'workshop_attendance_trend';
  protected const WEIGHT = 30;
  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['3m', '1y', '2y', 'all'];

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
    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $rangeEnd = new \DateTimeImmutable('first day of this month');
    $bounds = $this->calculateRangeBounds($activeRange, $rangeEnd);
    $startDate = $bounds['start'] ?? $rangeEnd->modify('-20 years');
    $endDate = $bounds['end']->modify('-1 day');
    if ($startDate > $endDate) {
      return NULL;
    }

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
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'suffix' => (string) $this->t('attendees'),
              ]),
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
      (string) $this->t('Displays the monthly count of participants registered for ticketed workshops across the selected range.'),
      $visualization,
      [
        (string) $this->t('Source: CiviCRM participant records with counted statuses and event type "Ticketed Workshop".'),
        (string) $this->t('Processing: Groups registrations by workshop month; months with no registrations render as zero.'),
      ],
      [
        'active' => $activeRange,
        'options' => $this->getRangePresets(self::RANGE_OPTIONS),
      ],
    );
  }

}
