<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Placeholder for Development dashboard section.
 */
class DevelopmentSection extends PlaceholderSection {

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
