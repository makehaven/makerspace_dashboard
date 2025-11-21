<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Operations;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\storage_manager\Service\StatisticsService;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Visualizes storage utilization by unit type.
 */
class OperationsStorageOccupancyChartBuilder extends OperationsChartBuilderBase {

  protected const CHART_ID = 'storage_occupancy';
  protected const WEIGHT = 30;

  protected StatisticsService $statisticsService;

  public function __construct(StatisticsService $statisticsService, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->statisticsService = $statisticsService;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $stats = $this->statisticsService->getStatistics();
    $types = $stats['by_type'] ?? [];
    if (empty($types)) {
      return NULL;
    }

    $labels = [];
    $occupied = [];
    $vacant = [];
    foreach ($types as $name => $data) {
      $labels[] = (string) $name;
      $occupied[] = (int) ($data['occupied_units'] ?? 0);
      $vacant[] = max(0, (int) ($data['total_units'] ?? 0) - ($data['occupied_units'] ?? 0));
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Occupied'),
            'data' => $occupied,
            'backgroundColor' => '#22c55e',
            'stack' => 'units',
          ],
          [
            'label' => (string) $this->t('Vacant'),
            'data' => $vacant,
            'backgroundColor' => '#94a3b8',
            'stack' => 'units',
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'y' => [
            'stacked' => TRUE,
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Units'),
            ],
          ],
          'x' => [
            'stacked' => TRUE,
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Storage: Occupancy by unit type'),
      (string) $this->t('Highlights how many storage units are occupied versus vacant for each unit type.'),
      $visualization,
      [
        (string) $this->t('Source: storage_manager statistics service (same dataset used by the admin dashboard).'),
        (string) $this->t('Processing: Uses the live storage unit and assignment entities to calculate occupied and vacant counts.'),
      ],
    );
  }

}
