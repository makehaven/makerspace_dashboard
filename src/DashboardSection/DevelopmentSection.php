<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\KpiDataService;

/**
 * Placeholder for Development dashboard section.
 */
class DevelopmentSection extends DashboardSectionBase {

  /**
   * KPI data service.
   */
  protected KpiDataService $kpiDataService;

  /**
   * Constructs the section.
   */
  public function __construct(KpiDataService $kpi_data_service) {
    parent::__construct();
    $this->kpiDataService = $kpi_data_service;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;


    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('development'));
    $build['kpi_table']['#weight'] = $weight++;

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'development';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Development');
  }

}
