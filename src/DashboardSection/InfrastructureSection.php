<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Placeholder for Infrastructure dashboard section.
 */
class InfrastructureSection extends PlaceholderSection {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'infrastructure';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Infrastructure');
  }

}
