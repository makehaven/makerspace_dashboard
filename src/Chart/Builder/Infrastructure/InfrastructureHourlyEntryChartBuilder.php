<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Infrastructure;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\UtilizationWindowService;

/**
 * Average entries per hour of day.
 */
class InfrastructureHourlyEntryChartBuilder extends InfrastructureUtilizationChartBuilderBase {

  protected const SECTION_ID = 'infrastructure';
  protected const CHART_ID = 'hourly_entry_profile';
  protected const WEIGHT = 60;

  public function __construct(UtilizationWindowService $windowService, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($windowService, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $metrics = $this->getMetrics();
    $hourly = $metrics['hourly_averages']['averages'] ?? [];
    if (empty($hourly)) {
      return NULL;
    }
    $labels = [];
    $data = [];
    foreach ($hourly as $hour => $value) {
      $labels[] = sprintf('%02d:00', (int) $hour);
      $data[] = round($value, 2);
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Average entries'),
          'data' => $data,
          'backgroundColor' => '#3b82f6',
        ]],
      ],
      'options' => [
        'scales' => [
          'y' => [
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Average entries / day'),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Average Entries per Hour of Day'),
      (string) $this->t('Average access-control requests in each hour across the rolling window.'),
      $visualization,
      [
        (string) $this->t('Source: Raw access_control_request logs for the same window as the utilization charts.'),
        (string) $this->t('Processing: Counts every entry event per hour, sums across the window, and divides by the number of days to produce an hourly average.'),
        (string) $this->t('Definitions: Includes repeat entries; unique-member analysis appears in the other charts.'),
      ],
    );
  }

}
