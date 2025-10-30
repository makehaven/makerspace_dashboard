<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Placeholder for Development dashboard section.
 */
class DevelopmentSection extends DashboardSectionBase {

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;

    $build['intro']['#weight'] = $weight++;

    $build['kpi_table'] = $this->buildKpiTable();
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
