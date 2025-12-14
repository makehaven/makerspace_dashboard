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
   *
   * @param array|string $options
   *   Options array or string (for backward compatibility as initialView).
   *   Supported keys:
   *   - initial_view: 'markers' or 'heatmap' (default: 'markers').
   *   - fit_bounds: bool (default: TRUE).
   *   - zoom: int (default: 11).
   *   - lat: float (default: 41.3083).
   *   - lon: float (default: -72.9279).
   */
  protected function buildLocationMapRenderable($options = []): array {
    // Handle legacy string argument
    if (is_string($options)) {
      $options = ['initial_view' => $options];
    }
    $options += [
      'initial_view' => 'markers',
      'fit_bounds' => TRUE,
      'zoom' => 11,
      'lat' => 41.3083,
      'lon' => -72.9279,
    ];

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
        'data-initial-view' => $options['initial_view'],
        'data-fit-bounds' => $options['fit_bounds'] ? 'true' : 'false',
        'data-zoom' => $options['zoom'],
        'data-lat' => $options['lat'],
        'data-lon' => $options['lon'],
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

    $markersClass = ['button'];
    $heatmapClass = ['button'];
    
    if ($options['initial_view'] === 'heatmap') {
      $heatmapClass[] = 'active';
    } else {
      $markersClass[] = 'active';
    }

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
            'class' => $markersClass,
            'data-map-view' => 'markers',
          ],
        ],
        'heatmap' => [
          '#type' => 'button',
          '#value' => $this->t('Heatmap'),
          '#attributes' => [
            'class' => $heatmapClass,
            'data-map-view' => 'heatmap',
          ],
        ],
      ],
      'map' => $map_renderable,
    ];
  }

}
