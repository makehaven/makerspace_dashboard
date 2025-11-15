<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Infrastructure;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\UtilizationWindowService;

/**
 * 7-day rolling average chart.
 */
class InfrastructureRollingAverageChartBuilder extends InfrastructureUtilizationChartBuilderBase {

  protected const SECTION_ID = 'infrastructure';
  protected const CHART_ID = 'rolling_average_chart';
  protected const WEIGHT = 30;

  public function __construct(UtilizationWindowService $windowService, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($windowService, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $metrics = $this->getMetrics();
    $labels = $metrics['rolling_labels'] ?? [];
    $averages = $metrics['rolling_average'] ?? [];
    if (!$labels || !array_filter($averages)) {
      return NULL;
    }

    $datasets = [[
      'label' => (string) $this->t('Rolling average'),
      'data' => $averages,
      'borderColor' => '#f97316',
      'backgroundColor' => 'rgba(249,115,22,0.2)',
      'fill' => FALSE,
      'tension' => 0.3,
      'borderWidth' => 2,
      'pointRadius' => 0,
    ]];
    if (!empty($metrics['trend_line'])) {
      $datasets[] = [
        'label' => (string) $this->t('Trend'),
        'data' => $metrics['trend_line'],
        'borderColor' => '#22c55e',
        'borderDash' => [6, 4],
        'fill' => FALSE,
        'pointRadius' => 0,
        'borderWidth' => 2,
      ];
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
        'scales' => [
          'x' => [
            'ticks' => [
              'autoSkip' => FALSE,
              'maxRotation' => 0,
              'minRotation' => 0,
              'padding' => 8,
            ],
          ],
        ],
        'plugins' => [
          'legend' => ['position' => 'top'],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('7-Day Rolling Average of Unique Entries'),
      (string) $this->t('Smooths daily fluctuations to highlight longer-term trends over the window.'),
      $visualization,
      [
        (string) $this->t('Source: Seven-day rolling average derived from the daily unique member counts.'),
        (string) $this->t('Processing: Uses a sliding seven-day window (or shorter for the first few points) and overlays a least-squares trendline.'),
        (string) $this->t('Definitions: Positive slope indicates growing activity; negative slope signals declining foot traffic.'),
      ],
    );
  }

}
