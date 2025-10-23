<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\makerspace_dashboard\DashboardSectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Base class for placeholder dashboard sections.
 */
abstract class PlaceholderSection implements DashboardSectionInterface {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    return [
      '#markup' => $this->t('This section is under development and will be available soon.'),
    ];
  }

}
