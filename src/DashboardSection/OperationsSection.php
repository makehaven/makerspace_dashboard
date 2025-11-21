<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\storage_manager\Service\StatisticsService;

/**
 * Operational metrics (store, lending library, storage, etc.).
 */
class OperationsSection extends DashboardSectionBase {

  protected StatisticsService $statisticsService;

  public function __construct(ChartBuilderManager $chart_builder_manager, StatisticsService $statisticsService) {
    parent::__construct(NULL, $chart_builder_manager);
    $this->statisticsService = $statisticsService;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'operations';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Operations');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['operations-dashboard']],
    ];

    $tiers = $this->buildTieredChartContainers($filters);

    $tables = $this->buildStorageTables();
    if ($tables) {
      if (isset($tiers['supplemental'])) {
        $tiers['supplemental']['storage_tables'] = $tables;
      }
      else {
        $tiers['supplemental'] = [
          '#type' => 'details',
          '#open' => FALSE,
          '#title' => $this->t('Supplemental context'),
          '#attributes' => ['class' => ['chart-tier', 'chart-tier--supplemental']],
          'storage_tables' => $tables,
        ];
      }
    }

    $weight = 0;
    foreach ($tiers as $tier => $container) {
      $container['#weight'] = $weight++;
      $build['tier_' . $tier] = $container;
    }

    return $build;
  }

  /**
   * Builds the legacy storage overview tables from storage_manager.
   */
  protected function buildStorageTables(): array {
    $stats = $this->statisticsService->getStatistics();
    if (empty($stats)) {
      return [];
    }

    $container = [
      '#type' => 'container',
      '#attributes' => ['class' => ['operations-storage-tables']],
    ];

    $container['overview'] = [
      'title' => ['#markup' => '<h3>' . $this->t('Storage overview') . '</h3>'],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Metric'), $this->t('Value')],
        '#rows' => [
          [$this->t('Total units'), $stats['overall']['total_units']],
          [$this->t('Occupied units'), $stats['overall']['occupied_units']],
          [$this->t('Vacant units'), $stats['overall']['vacant_units']],
          [$this->t('Vacancy rate'), sprintf('%.1f%%', $stats['overall']['vacancy_rate'])],
          [$this->t('Billed value (monthly)'), $this->formatCurrency($stats['overall']['billed_value'])],
          [$this->t('Complimentary value (monthly)'), $this->formatCurrency($stats['overall']['complimentary_value'])],
          [$this->t('Potential value (vacant)'), $this->formatCurrency($stats['overall']['potential_value'])],
        ],
      ],
    ];

    if (!empty($stats['by_type'])) {
      $rows = [];
      foreach ($stats['by_type'] as $name => $data) {
        $rows[] = [
          $name,
          $data['total_units'],
          $data['occupied_units'],
          sprintf('%.1f%%', $data['vacancy_rate']),
          $this->formatCurrency($data['billed_value']),
          $this->formatCurrency($data['complimentary_value']),
          $this->formatCurrency($data['potential_value']),
          $this->formatCurrency($data['total_inventory_value']),
        ];
      }
      $container['by_type'] = [
        'title' => ['#markup' => '<h3>' . $this->t('Units by type') . '</h3>'],
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Type'),
            $this->t('Total'),
            $this->t('Occupied'),
            $this->t('Vacancy %'),
            $this->t('Billed $'),
            $this->t('Complimentary $'),
            $this->t('Potential $'),
            $this->t('Total inventory $'),
          ],
          '#rows' => $rows,
        ],
      ];
    }

    if (!empty($stats['by_area'])) {
      $rows = [];
      foreach ($stats['by_area'] as $name => $data) {
        $rows[] = [
          $name,
          $data['total_units'],
          $data['occupied_units'],
          sprintf('%.1f%%', $data['vacancy_rate']),
          $this->formatCurrency($data['billed_value']),
          $this->formatCurrency($data['complimentary_value']),
          $this->formatCurrency($data['potential_value']),
          $this->formatCurrency($data['total_inventory_value']),
        ];
      }
      $container['by_area'] = [
        'title' => ['#markup' => '<h3>' . $this->t('Units by area') . '</h3>'],
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Area'),
            $this->t('Total'),
            $this->t('Occupied'),
            $this->t('Vacancy %'),
            $this->t('Billed $'),
            $this->t('Complimentary $'),
            $this->t('Potential $'),
            $this->t('Total inventory $'),
          ],
          '#rows' => $rows,
        ],
      ];
    }

    if (!empty($stats['violations'])) {
      $container['violations'] = [
        'title' => ['#markup' => '<h3>' . $this->t('Violations') . '</h3>'],
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Metric'), $this->t('Value')],
          '#rows' => [
            [$this->t('Active violations'), $stats['violations']['active_violations']],
            [$this->t('Total accrued charges'), $this->formatCurrency($stats['violations']['total_accrued'])],
          ],
        ],
      ];
    }

    return $container;
  }

  /**
   * Formats US currency values for the tables.
   */
  protected function formatCurrency(float $amount): string {
    return '$' . number_format((float) $amount, 2);
  }

}
