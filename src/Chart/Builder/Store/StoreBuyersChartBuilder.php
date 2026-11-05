<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Store;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * How many people buy from the store each month. Counts only, never names.
 */
class StoreBuyersChartBuilder extends StoreChartBuilderBase {

  protected const CHART_ID = 'store_buyers';
  protected const PARTIAL_LAST_PERIOD = TRUE;
  protected const WEIGHT = 5;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $trends = $this->inventoryData->getTrends(static::MONTHS);
    if (empty($trends['buyers']['total'])) {
      return NULL;
    }
    $visualization = $this->dollarChart(array_values($trends['labels']), [
      [
        'label' => (string) $this->t('People buying'),
        'data' => array_map('intval', $this->monthly($trends, 'buyers')),
        'backgroundColor' => '#2563eb',
      ],
    ], [
      'plugins' => [
        'tooltip' => [
          'callbacks' => [
            'label' => $this->chartCallback('series_value', ['format' => 'integer']),
          ],
        ],
      ],
      'scales' => ['y' => ['ticks' => ['precision' => 0]]],
    ]);

    return $this->newDefinition(
      (string) $this->t('People buying each month'),
      (string) $this->t('@total people bought something in these 12 months, and @returning of them bought in more than one month.', [
        '@total' => number_format($trends['buyers']['total']),
        '@returning' => number_format($trends['buyers']['returning']),
      ]),
      $visualization,
      [
        (string) $this->t('Source: the buyer on each sale or tab line. Cash box entries do not say who bought, so they are not counted here.'),
        (string) $this->t('Processing: Older sales name the buyer only as text, so someone can occasionally count as two people. Totals are close, not exact.'),
      ],
    );
  }

}
