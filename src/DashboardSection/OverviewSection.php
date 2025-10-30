<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the OverviewSection class.
 */
class OverviewSection extends DashboardSectionBase {

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

    $build['intro'] = $this->buildIntro($this->t('This section is under development.'));
    $build['intro']['#weight'] = $weight++;

    $build['kpi_table'] = $this->buildKpiTable();
    $build['kpi_table']['#weight'] = $weight++;

    return $build;
  }

}
