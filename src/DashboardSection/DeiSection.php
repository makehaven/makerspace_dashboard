<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\KpiDataService;

/**
 * Provides demographic breakdowns without exposing individual identities.
 */
class DeiSection extends DashboardSectionBase {

  /**
   * KPI data service.
   */
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
    return 'dei';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('DEI');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;

    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('dei'));
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

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list'],
    ];

    return $build;
  }

}
