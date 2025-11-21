<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\KpiDataService;
use Drupal\makerspace_dashboard\Support\LocationMapTrait;

/**
 * Provides demographic breakdowns without exposing individual identities.
 */
class DeiSection extends DashboardSectionBase {

  use LocationMapTrait;

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

    foreach ($this->buildTieredChartContainers($filters) as $tier => $container) {
      $container['#weight'] = $weight++;
      $build['tier_' . $tier] = $container;
    }

    $build['member_locations_heading'] = [
      '#markup' => '<h2>' . $this->t('Member Location Heatmap') . '</h2>',
      '#weight' => $weight++,
    ];

    $build['member_locations_intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['makerspace-dashboard-definition']],
      '#weight' => $weight++,
      'text' => [
        '#markup' => $this->t('Aggregated hometown data from member profiles highlights regional reach without exposing specific addresses. Each point contributes to a heatmap so clusters indicate higher concentrations of active members.'),
      ],
    ];

    $build['member_locations_map'] = $this->buildLocationMapRenderable();
    $build['member_locations_map']['#weight'] = $weight++;

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list'],
    ];

    return $build;
  }

}
