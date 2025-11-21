<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Support\LocationMapTrait;

/**
 * Provides a map of member locations.
 */
class LocationSection extends DashboardSectionBase {

  use LocationMapTrait;

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'location';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Location');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];

    $build['map_container'] = $this->buildLocationMapRenderable();

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list'],
    ];

    return $build;
  }

}
