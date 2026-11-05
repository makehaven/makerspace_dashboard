<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Store;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * What inventory counts found missing and found extra, per month.
 */
class StoreLostFoundChartBuilder extends StoreChartBuilderBase {

  protected const CHART_ID = 'store_lost_found';
  protected const PARTIAL_LAST_PERIOD = TRUE;
  protected const WEIGHT = 2;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $trends = $this->inventoryData->getTrends(static::MONTHS);
    if (!$trends) {
      return NULL;
    }
    $lost = $this->monthly($trends, 'lost');
    $found = $this->monthly($trends, 'found');
    if (!array_filter($lost) && !array_filter($found)) {
      return NULL;
    }
    $visualization = $this->dollarChart(array_values($trends['labels']), [
      [
        'label' => (string) $this->t('Counted short'),
        'data' => $lost,
        'backgroundColor' => '#dc2626',
      ],
      [
        'label' => (string) $this->t('Counted high'),
        'data' => $found,
        'backgroundColor' => '#16a34a',
      ],
    ], ['scales' => ['y' => $this->dollarAxis()]]);

    return $this->newDefinition(
      (string) $this->t('Inventory counts: short and high'),
      (string) $this->t('@short counted short and @high counted high over these 12 months. Read the two together: many are one miscount showing up as a shortfall one month and a gain the next, so neither bar alone is real loss.', [
        '@short' => '$' . number_format(array_sum($lost)),
        '@high' => '$' . number_format(array_sum($found)),
      ]),
      $visualization,
      [
        (string) $this->t("Source: inventory count adjustments, at today's store price."),
        (string) $this->t('Processing: Counts that matched, deliberate zero-outs of weighed items and a first count of a new item are left out. Weighed items that lost stock often record no change, so this undercounts them.'),
        (string) $this->t('Counts ran quarterly until April 2026 and monthly since, so earlier months with no bar were not counted.'),
      ],
    );
  }

}
