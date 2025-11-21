<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\EntrepreneurshipDataService;
use Drupal\makerspace_dashboard\Service\KpiDataService;

/**
 * Defines the EntrepreneurshipSection class.
 */
class EntrepreneurshipSection extends DashboardSectionBase {

  /**
   * KPI data service.
   */
  protected KpiDataService $kpiDataService;

  /**
   * Entrepreneurship data service.
   */
  protected EntrepreneurshipDataService $entrepreneurshipData;

  /**
   * Constructs the section.
   */
  public function __construct(KpiDataService $kpi_data_service, ChartBuilderManager $chart_builder_manager, EntrepreneurshipDataService $entrepreneurship_data) {
    parent::__construct(NULL, $chart_builder_manager);
    $this->kpiDataService = $kpi_data_service;
    $this->entrepreneurshipData = $entrepreneurship_data;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'entrepreneurship';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Entrepreneurship');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;

    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('entrepreneurship'));
    $build['kpi_table']['#weight'] = $weight++;

    foreach ($this->buildTieredChartContainers($filters) as $tier => $container) {
      $container['#weight'] = $weight++;
      $build['tier_' . $tier] = $container;
    }

    $summary = $this->entrepreneurshipData->getEntrepreneurGoalSummary();
    $build['goal_summary'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Metric'),
        $this->t('Count'),
      ],
      '#rows' => [
        [
          $this->t('Members who ever selected an entrepreneurship-oriented goal (prototype, sell products, or business entrepreneurship)'),
          number_format($summary['all_time'] ?? 0),
        ],
        [
          $this->t('Active members with an entrepreneurship-oriented goal'),
          number_format($summary['active_goal'] ?? 0),
        ],
        [
          $this->t('Total active members'),
          number_format($summary['active_members'] ?? 0),
        ],
      ],
      '#attributes' => ['class' => ['makerspace-dashboard-table']],
      '#weight' => $weight++,
      '#caption' => $this->t('Entrepreneurship goal overview'),
    ];

    return $build;
  }

}
