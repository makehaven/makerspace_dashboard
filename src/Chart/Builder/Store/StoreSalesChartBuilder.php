<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Store;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Store sales per month, split by how people paid.
 */
class StoreSalesChartBuilder extends StoreChartBuilderBase {

  protected const CHART_ID = 'store_sales';
  protected const PARTIAL_LAST_PERIOD = TRUE;
  protected const WEIGHT = 1;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $trends = $this->inventoryData->getTrends(static::MONTHS);
    if (!$trends) {
      return NULL;
    }
    $visualization = $this->dollarChart(array_values($trends['labels']), [
      [
        'label' => (string) $this->t('Paid at checkout'),
        'data' => $this->monthly($trends, 'paid'),
        'backgroundColor' => '#2563eb',
      ],
      [
        'label' => (string) $this->t('Added to a tab'),
        'data' => $this->monthly($trends, 'tab'),
        'backgroundColor' => '#f97316',
      ],
    ], [
      'scales' => [
        'x' => ['stacked' => TRUE],
        'y' => ['stacked' => TRUE] + $this->dollarAxis(),
      ],
    ]);

    return $this->newDefinition(
      (string) $this->t('Store sales by month'),
      (string) $this->t('What members bought each month, and whether they paid then or put it on a tab.'),
      $visualization,
      [
        (string) $this->t('Source: inventory adjustments with reason Sale or Unpaid tab, including machine time and cash box entries.'),
        (string) $this->t("Processing: quantity times the item's store price today, so a past month moves if a price changes. Tab lines removed later are left out of the month they were added."),
        (string) $this->t('From January 15 to September 16, 2026, a bug put many Buy Now clicks on a tab, so tab sales in those months overstate how many people chose a tab.'),
      ],
    );
  }

}
