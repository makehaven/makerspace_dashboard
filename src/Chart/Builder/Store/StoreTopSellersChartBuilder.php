<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Store;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * The items that brought in the most over the last year.
 */
class StoreTopSellersChartBuilder extends StoreChartBuilderBase {

  protected const CHART_ID = 'store_top_sellers';
  protected const WEIGHT = 3;

  /**
   * Bars shown.
   */
  protected const LIMIT = 15;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $trends = $this->inventoryData->getTrends(static::MONTHS);
    if (empty($trends['items'])) {
      return NULL;
    }
    $top = array_slice($trends['items'], 0, static::LIMIT, TRUE);
    $labels = [];
    foreach (array_keys($top) as $nid) {
      $labels[] = (string) ($trends['titles'][$nid] ?? $this->t('(deleted item)'));
    }
    $visualization = $this->dollarChart($labels, [
      [
        'label' => (string) $this->t('Sales'),
        'data' => array_map(fn($value) => round($value, 2), array_values($top)),
        'backgroundColor' => '#2563eb',
      ],
    ], [
      'indexAxis' => 'y',
      'scales' => ['x' => $this->dollarAxis()],
    ]);

    return $this->newDefinition(
      (string) $this->t('Top sellers'),
      (string) $this->t('The @n items that brought in the most in the last 12 months.', ['@n' => count($top)]),
      $visualization,
      [
        (string) $this->t("Source: inventory adjustments with reason Sale or Unpaid tab, at today's store price. Includes machine time."),
      ],
    );
  }

}
