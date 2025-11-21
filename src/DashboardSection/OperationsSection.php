<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\storage_manager\Service\StatisticsService;

/**
 * Operational metrics (store, lending library, storage, etc.).
 */
class OperationsSection extends DashboardSectionBase {

  protected const SUBSECTIONS = [
    'store' => [
      'label' => 'Store',
      'chart_ids' => [
        'store_category_contribution',
        'store_sales_velocity',
      ],
    ],
    'lending' => [
      'label' => 'Lending Library',
      'chart_ids' => [
        'lending_monthly_loans',
        'lending_value_vs_items',
        'lending_category_breakdown',
      ],
    ],
    'storage' => [
      'label' => 'Storage',
      'chart_ids' => [
        'storage_occupancy',
        'storage_vacancy_trend',
      ],
    ],
    'engagement' => [
      'label' => 'Tours & Orientation',
      'chart_ids' => [
        'activity_types',
      ],
    ],
  ];

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
    $charts = $this->buildChartsFromDefinitions($filters);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['operations-dashboard']],
    ];

    foreach (self::SUBSECTIONS as $subsection_id => $info) {
      $section_build = [
        '#type' => 'container',
        '#attributes' => ['class' => ['operations-subsection']],
        'heading' => [
          '#markup' => '<h2>' . $this->t($info['label']) . '</h2>',
        ],
      ];
      $weight = 0;

      foreach ($info['chart_ids'] as $chart_id) {
        if (isset($charts[$chart_id])) {
          $section_build[$chart_id] = $charts[$chart_id];
          $section_build[$chart_id]['#weight'] = $weight++;
          unset($charts[$chart_id]);
        }
      }

      if ($subsection_id === 'storage') {
        $tables = $this->buildStorageTables($weight);
        if ($tables) {
          $section_build['storage_tables'] = $tables;
        }
      }

      if (count($section_build) > 1) {
        $build[$subsection_id] = $section_build;
      }
    }

    if (!empty($charts)) {
      $build['misc'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['operations-subsection']],
        'heading' => ['#markup' => '<h2>' . $this->t('Other metrics') . '</h2>'],
      ];
      foreach ($charts as $chart_id => $chart_render_array) {
        $build['misc'][$chart_id] = $chart_render_array;
      }
    }

    return $build;
  }

  /**
   * Builds the legacy storage overview tables from storage_manager.
   */
  protected function buildStorageTables(int $weight): array {
    $stats = $this->statisticsService->getStatistics();
    if (empty($stats)) {
      return [];
    }

    $container = [
      '#type' => 'container',
      '#attributes' => ['class' => ['operations-storage-tables']],
      '#weight' => $weight,
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
