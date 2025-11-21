<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Operations;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\StoreInventoryData;

/**
 * Shows top store categories by on-hand inventory value.
 */
class OperationsStoreCategoryContributionChartBuilder extends OperationsChartBuilderBase {

  protected const CHART_ID = 'store_category_contribution';
  protected const WEIGHT = 5;

  protected StoreInventoryData $inventoryData;

  public function __construct(StoreInventoryData $inventoryData, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->inventoryData = $inventoryData;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $dataset = $this->inventoryData->getCategoryContribution(8);
    if (empty($dataset)) {
      return NULL;
    }

    $labels = [];
    $values = [];
    $shares = [];
    foreach ($dataset as $entry) {
      $label = (string) ($entry['label'] ?? '');
      if (!empty($entry['is_uncategorized'])) {
        $label = (string) $this->t('Uncategorized');
      }
      $labels[] = $label;
      $values[] = round((float) ($entry['value'] ?? 0), 2);
      $shares[] = round(((float) ($entry['share'] ?? 0)) * 100, 1);
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Inventory value ($)'),
          'data' => $values,
          'backgroundColor' => $this->defaultColorPalette(),
          'borderWidth' => 0,
        ]],
      ],
      'options' => [
        'indexAxis' => 'y',
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
              ]),
              'afterLabel' => $this->chartCallback('dataset_share_percent', [
                'decimals' => 1,
                'suffix' => '% of recorded value',
              ]),
            ],
          ],
        ],
        'scales' => [
          'x' => [
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Source: material node cached inventory counts and values maintained by material_inventory_totals.'),
      (string) $this->t('Processing: Values are split evenly across every category tagged on a material to avoid double-counting.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Store category contribution'),
      (string) $this->t('Highlights which material categories represent the most inventory value.'),
      $visualization,
      $notes,
    );
  }

}
