<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Placeholder for Entrepreneurship dashboard section.
 */
class EntrepreneurshipSection extends PlaceholderSection {

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

}
