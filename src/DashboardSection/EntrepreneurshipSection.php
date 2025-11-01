<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
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
   * Constructs the section.
   */
  public function __construct(KpiDataService $kpi_data_service) {
    parent::__construct();
    $this->kpiDataService = $kpi_data_service;
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

    $build['intro']['#weight'] = $weight++;

    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('entrepreneurship'));
    $build['kpi_table']['#weight'] = $weight++;

    return $build;
  }

}
