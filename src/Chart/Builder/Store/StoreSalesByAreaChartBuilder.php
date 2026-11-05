<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Store;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Store sales by makerspace area.
 */
class StoreSalesByAreaChartBuilder extends StoreChartBuilderBase {

  protected const CHART_ID = 'store_sales_by_area';
  protected const WEIGHT = 4;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $trends = $this->inventoryData->getTrends(static::MONTHS);
    if (empty($trends['areas'])) {
      return NULL;
    }
    $labels = [];
    foreach (array_keys($trends['areas']) as $area) {
      $labels[] = $area === '' ? (string) $this->t('No area set') : (string) $area;
    }
    $visualization = $this->dollarChart($labels, [
      [
        'label' => (string) $this->t('Sales'),
        'data' => array_map(fn($value) => round($value, 2), array_values($trends['areas'])),
        'backgroundColor' => '#2563eb',
      ],
    ], [
      'indexAxis' => 'y',
      'scales' => ['x' => $this->dollarAxis()],
    ]);

    return $this->newDefinition(
      (string) $this->t('Sales by area'),
      (string) $this->t('Which areas of the makerspace members are buying for, over the last 12 months.'),
      $visualization,
      [
        (string) $this->t('Source: the area tags on each store item, rolled up to the top-level area. An item in several areas is split evenly.'),
        (string) $this->t('"No area set" means the item has no area tag, so it is a gap in the tagging and not an area of its own.'),
      ],
    );
  }

}
