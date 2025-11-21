<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Operations;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\StoreInventoryData;

/**
 * Shows how fast supplies are restocked vs. consumed.
 */
class OperationsStoreSalesVelocityChartBuilder extends OperationsChartBuilderBase {

  protected const CHART_ID = 'store_sales_velocity';
  protected const WEIGHT = 16;

  protected StoreInventoryData $inventoryData;

  public function __construct(StoreInventoryData $inventoryData, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->inventoryData = $inventoryData;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->inventoryData->getSalesVelocitySeries(12);
    if (empty($series)) {
      return NULL;
    }

    $labels = [];
    $restock = [];
    $consumption = [];
    foreach ($series as $point) {
      $labels[] = (string) ($point['label'] ?? '');
      $restock[] = (int) ($point['restock'] ?? 0);
      $consumption[] = (int) ($point['consumption'] ?? 0);
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Units restocked'),
            'data' => $restock,
            'borderColor' => '#22c55e',
            'backgroundColor' => 'rgba(34,197,94,0.2)',
            'fill' => TRUE,
            'borderWidth' => 3,
            'tension' => 0.3,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
          ],
          [
            'label' => (string) $this->t('Units consumed'),
            'data' => $consumption,
            'borderColor' => '#f97316',
            'backgroundColor' => 'rgba(249,115,22,0.15)',
            'fill' => TRUE,
            'borderWidth' => 3,
            'tension' => 0.3,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'suffix' => ' ' . (string) $this->t('units'),
              ]),
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Units per month'),
            ],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Source: material_inventory adjustments created in the trailing 12 months.'),
      (string) $this->t('Processing: Positive adjustments are counted as restock events, negative adjustments (sales, internal use, education, waste) count as consumption.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Sales & consumption velocity'),
      (string) $this->t('Quantifies how quickly material inventory is added versus used each month.'),
      $visualization,
      $notes,
    );
  }

}
