<?php

namespace Drupal\makerspace_dashboard\Support;

use Drupal\Core\Url;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Provides a reusable render array for the member location map.
 */
trait LocationMapTrait {

  /**
   * Builds the member location map container and settings.
   */
  protected function buildLocationMapRenderable(): array {
    $locationsUrl = NULL;
    try {
      $locationsUrl = Url::fromRoute('makerspace_dashboard.api.locations')->toString();
    }
    catch (RouteNotFoundException $exception) {
      watchdog_exception('makerspace_dashboard', $exception);
    }

    if ($locationsUrl === NULL) {
      $message = method_exists($this, 't')
        ? $this->t('Member location data is not available at the moment.')
        : 'Member location data is not available at the moment.';
      return [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['makerspace-dashboard-empty'],
        ],
        'message' => [
          '#markup' => $message,
        ],
      ];
    }

    $map_renderable = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'member-location-map',
        'class' => ['makerspace-dashboard-location-map'],
      ],
      '#attached' => [
        'library' => [
          'makerspace_dashboard/leaflet',
          'makerspace_dashboard/location_map',
        ],
        'drupalSettings' => [
          'makerspace_dashboard' => [
            'locations_url' => $locationsUrl,
          ],
        ],
      ],
    ];

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['makerspace-dashboard-location-map-wrapper'],
      ],
      'toggle' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['makerspace-dashboard-location-map-toggle'],
        ],
        'markers' => [
          '#type' => 'button',
          '#value' => $this->t('Markers'),
          '#attributes' => [
            'class' => ['button', 'active'],
            'data-map-view' => 'markers',
          ],
        ],
        'heatmap' => [
          '#type' => 'button',
          '#value' => $this->t('Heatmap'),
          '#attributes' => [
            'class' => ['button'],
            'data-map-view' => 'heatmap',
          ],
        ],
      ],
      'map' => $map_renderable,
    ];
  }

}
