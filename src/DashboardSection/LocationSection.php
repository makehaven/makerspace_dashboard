<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides a map of member locations.
 */
class LocationSection extends DashboardSectionBase {

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

    $build['map_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'member-location-map', 'style' => 'height: 500px;'],
    ];

    $build['#attached']['library'][] = 'makerspace_dashboard/leaflet';
    $build['#attached']['library'][] = 'makerspace_dashboard/location_map';
    $build['#attached']['drupalSettings']['makerspace_dashboard']['locations_url'] = '/makerspace-dashboard/api/locations';

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list'],
    ];

    return $build;
  }

}
