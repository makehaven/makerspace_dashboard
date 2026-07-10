<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Infrastructure;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\UtilizationDataService;
use Drupal\makerspace_dashboard\Service\UtilizationWindowService;

/**
 * Day-of-week by hour-of-day entry profile rendered as stacked bars.
 *
 * The React renderer only supports the bar/line/pie Chart.js types, so the
 * day x hour matrix is expressed as one dataset per weekday stacked on an
 * hour-of-day axis instead of a true heatmap.
 */
class InfrastructureEntryHeatmapChartBuilder extends InfrastructureUtilizationChartBuilderBase {

  protected const SECTION_ID = 'infrastructure';
  protected const CHART_ID = 'entry_heatmap';
  protected const WEIGHT = 65;
  protected const TIER = 'supplemental';

  /**
   * First hour of day shown on the axis.
   */
  protected const HOUR_START = 6;

  /**
   * Last hour of day shown on the axis.
   */
  protected const HOUR_END = 23;

  /**
   * Utilization data service.
   */
  protected UtilizationDataService $utilizationDataService;

  public function __construct(UtilizationWindowService $windowService, UtilizationDataService $utilizationDataService, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($windowService, $stringTranslation);
    $this->utilizationDataService = $utilizationDataService;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $metrics = $this->getMetrics();
    $start = $metrics['rolling_start'] ?? NULL;
    $end = $metrics['end_of_day'] ?? NULL;
    if (!$start instanceof \DateTimeInterface || !$end instanceof \DateTimeInterface) {
      return NULL;
    }

    $dayHour = $this->utilizationDataService->getAverageEntriesByDayHour($start->getTimestamp(), $end->getTimestamp());
    $averages = $dayHour['averages'] ?? [];
    if (empty($averages) || empty($dayHour['total_entries'])) {
      return NULL;
    }

    $labels = [];
    foreach (range(self::HOUR_START, self::HOUR_END) as $hour) {
      $labels[] = sprintf('%02d:00', $hour);
    }

    $weekdayLabels = $metrics['weekday_labels'] ?? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $palette = $this->defaultColorPalette();
    $datasets = [];
    foreach ($weekdayLabels as $weekday => $label) {
      $data = [];
      foreach (range(self::HOUR_START, self::HOUR_END) as $hour) {
        $data[] = round((float) ($averages[$weekday][$hour] ?? 0), 2);
      }
      $datasets[] = [
        'label' => (string) $label,
        'data' => $data,
        'backgroundColor' => $palette[$weekday % count($palette)],
        'stack' => 'entries',
      ];
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'x' => [
            'stacked' => TRUE,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Hour of day'),
            ],
          ],
          'y' => [
            'stacked' => TRUE,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Average entries'),
            ],
          ],
        ],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'mode' => 'index',
            'intersect' => FALSE,
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'decimal',
                'decimals' => 2,
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Entry Profile by Day and Hour'),
      (string) $this->t('Average member entries for each weekday and hour of day over the last @days days.', ['@days' => $metrics['summary']['days'] ?? 0]),
      $visualization,
      [
        (string) $this->t('Source: Raw access_control_request logs for the same window as the other utilization charts.'),
        (string) $this->t('Processing: Buckets every entry by weekday and hour, then divides each bucket by the number of times that weekday occurs in the window.'),
        (string) $this->t("Definitions: Each stacked segment is that weekday's average for the hour; hours outside 06:00-23:00 are omitted. Includes repeat entries."),
      ],
    );
  }

}
