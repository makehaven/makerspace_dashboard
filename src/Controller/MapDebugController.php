<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\makerspace_dashboard\Support\LocationMapTrait;

/**
 * Provides a standalone view for testing the member location map.
 */
class MapDebugController extends ControllerBase {

  use LocationMapTrait;

  /**
   * Renders the member map without any other dashboard UI for debugging.
   */
  public function memberMap(): array {
    return [
      '#title' => $this->t('Member Location Map (Debug)'),
      'intro' => [
        '#markup' => '<p>' . $this->t('This page renders only the member location map so heatmap/marker issues can be isolated from the rest of the dashboard.') . '</p>',
      ],
      'map' => $this->buildLocationMapRenderable([
        'initial_view' => 'heatmap',
        'fit_bounds' => TRUE,
      ]),
      '#attached' => [
        'library' => [
          'makerspace_dashboard/dashboard',
        ],
      ],
    ];
  }

}
