<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\KpiDataService;

/**
 * Defines the OverviewSection class.
 */
class OverviewSection extends DashboardSectionBase {

  protected KpiDataService $kpiDataService;

  /**
   * Constructs the section.
   */
  public function __construct(KpiDataService $kpi_data_service, ChartBuilderManager $chart_builder_manager) {
    parent::__construct(NULL, $chart_builder_manager);
    $this->kpiDataService = $kpi_data_service;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'overview';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Overview');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;

    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('overview'));
    $build['kpi_table']['#weight'] = $weight++;

    $charts = $this->buildChartsFromDefinitions($filters);
    if ($charts) {
      $build['charts_section_heading'] = [
        '#markup' => '<h2>' . $this->t('Charts') . '</h2>',
        '#weight' => $weight++,
      ];

      foreach ($charts as $chart_id => $chart_render_array) {
        $chart_render_array['#weight'] = $weight++;
        $build[$chart_id] = $chart_render_array;
      }
    }

    return $build;
  }

}
