<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Store;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Service\StoreInventoryData;

/**
 * Shared helpers for the Store dashboard charts.
 */
abstract class StoreChartBuilderBase extends ChartBuilderBase {

  protected const SECTION_ID = 'store';

  /**
   * Months each chart covers.
   */
  protected const MONTHS = 12;

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected StoreInventoryData $inventoryData,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * One value per month from the trends.
   */
  protected function monthly(array $trends, string $key): array {
    return array_map(fn(array $month) => round($month[$key], 2), array_values($trends['months']));
  }

  /**
   * A bar chart in whole dollars.
   */
  protected function dollarChart(array $labels, array $datasets, array $options = []): array {
    return [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => ['labels' => $labels, 'datasets' => $datasets],
      'options' => array_replace_recursive([
        'responsive' => TRUE,
        'plugins' => [
          'legend' => ['display' => count($datasets) > 1, 'position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
              ]),
            ],
          ],
        ],
      ], $options),
    ];
  }

  /**
   * Axis settings for dollars.
   */
  protected function dollarAxis(): array {
    return [
      'ticks' => [
        'callback' => $this->chartCallback('value_format', [
          'format' => 'currency',
          'currency' => 'USD',
          'decimals' => 0,
          'showLabel' => FALSE,
        ]),
      ],
    ];
  }

}
