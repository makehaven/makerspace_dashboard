<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Placeholder for DEI dashboard section.
 */
class DeiSection extends PlaceholderSection {

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

}
