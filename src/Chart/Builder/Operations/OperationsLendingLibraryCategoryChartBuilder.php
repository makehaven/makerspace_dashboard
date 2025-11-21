<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Operations;

use Drupal\lending_library\Service\StatsCollectorInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Shows top lending library categories.
 */
class OperationsLendingLibraryCategoryChartBuilder extends OperationsChartBuilderBase {

  protected const CHART_ID = 'lending_category_breakdown';
  protected const WEIGHT = 20;

  protected StatsCollectorInterface $statsCollector;

  public function __construct(StatsCollectorInterface $statsCollector, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->statsCollector = $statsCollector;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $stats = $this->statsCollector->collect();
    $series = $stats['chart_data']['top_categories'] ?? [];
    if (empty($series)) {
      return NULL;
    }

    $labels = [];
    $values = [];
    foreach ($series as $entry) {
      $labels[] = (string) ($entry['label'] ?? '');
      $values[] = (int) round((float) ($entry['value'] ?? 0));
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'doughnut',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Loans'),
          'data' => $values,
          'backgroundColor' => $this->buildPalette(count($labels)),
        ]],
      ],
      'options' => [
        'maintainAspectRatio' => FALSE,
        'cutout' => '50%',
      'plugins' => [
          'legend' => [
            'position' => 'bottom',
            'labels' => [
              'usePointStyle' => TRUE,
            ],
          ],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'suffix' => (string) $this->t('loans'),
              ]),
              'afterLabel' => $this->chartCallback('dataset_share_percent', ['decimals' => 1, 'suffix' => '%']),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Lending Library: Loans by category'),
      (string) $this->t('Distribution of loans over the last 90 days by top categories.'),
      $visualization,
      [
        (string) $this->t('Source: lending_library stats collector top_categories dataset.'),
        (string) $this->t('Processing: matches the category breakdown chart inside the Lending Library dashboard.'),
      ],
      NULL,
      NULL,
      [],
      'supplemental',
    );
  }

  /**
   * Builds a repeating palette used by the doughnut chart.
   */
  protected function buildPalette(int $count): array {
    $base = [
      '#2563EB',
      '#0EA5E9',
      '#22C55E',
      '#A855F7',
      '#F97316',
      '#F43F5E',
      '#14B8A6',
      '#EAB308',
    ];
    $palette = [];
    for ($i = 0; $i < $count; $i++) {
      $palette[] = $base[$i % count($base)];
    }
    return $palette;
  }

}
