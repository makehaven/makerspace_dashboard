<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\makerspace_dashboard\Service\MemberJoinLocationDataService;
use Drupal\makerspace_dashboard\Support\LocationMapTrait;

/**
 * Provides a map of member locations.
 */
class LocationSection extends DashboardSectionBase {

  use LocationMapTrait;

  /**
   * Join location data service.
   */
  protected MemberJoinLocationDataService $memberJoinLocationData;

  /**
   * Constructs the section.
   */
  public function __construct(MemberJoinLocationDataService $member_join_location_data) {
    parent::__construct();
    $this->memberJoinLocationData = $member_join_location_data;
  }

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

    $build['join_locations'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['makerspace-dashboard-join-map-section']],
      'heading' => [
        '#markup' => '<h2>' . $this->t('New member joins by quarter') . '</h2>',
      ],
      'description' => [
        '#markup' => '<p>' . $this->t('Use the buttons below to overlay recent quarters on the map. Each selection aggregates joins for that quarter and adds a privacy-safe jitter before rendering.') . '</p>',
      ],
    ];

    $quarterOptions = $this->memberJoinLocationData->getAvailableQuarters(8);
    if (!empty($quarterOptions)) {
      $defaultQuarter = $quarterOptions[0];
      $build['join_locations']['map'] = $this->buildJoinMapRenderable('joins', $defaultQuarter, $quarterOptions, $this->t('Quarterly joins'));
    }
    else {
      $build['join_locations']['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['makerspace-dashboard-empty']],
        'message' => [
          '#markup' => $this->t('Join-date data has not been recorded yet, so quarterly join maps are not available.'),
        ],
      ];
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list', 'civicrm_contact_list', 'civicrm_address_list'],
    ];

    return $build;
  }

  /**
   * Builds a join-location map render array.
   */
  protected function buildJoinMapRenderable(string $instance, array $defaultQuarter, array $quarters, TranslatableMarkup $title): array {
    $mapId = Html::getUniqueId('join-map-' . $instance);
    $apiUrl = Url::fromRoute('makerspace_dashboard.api.join_locations')->toString();

    $defaultValue = $defaultQuarter['value'] ?? sprintf('%d-Q%d', $defaultQuarter['year'], $defaultQuarter['quarter']);

    $palette = [
      '#f44336',
      '#ff9800',
      '#4caf50',
      '#2196f3',
      '#9c27b0',
      '#00bcd4',
      '#8bc34a',
      '#ff5722',
    ];
    $quartersWithColors = [];

    $buttonContainer = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['join-location-map__controls'],
      ],
    ];
    foreach ($quarters as $index => $quarter) {
      $value = $quarter['value'] ?? sprintf('%d-Q%d', $quarter['year'], $quarter['quarter']);
      $color = $palette[$index % count($palette)];
      $quarterWithColor = $quarter + [
        'value' => $value,
        'color' => $color,
        'params' => [
          'year' => (int) $quarter['year'],
          'quarter' => (int) $quarter['quarter'],
        ],
      ];
      $quartersWithColors[] = $quarterWithColor;

      $buttonClasses = ['button', 'join-location-map__button'];
      if ($value === $defaultValue) {
        $buttonClasses[] = 'active';
      }
      $buttonContainer['quarter_' . $index] = [
        '#type' => 'button',
        '#value' => $quarter['label'],
        '#attributes' => [
          'class' => $buttonClasses,
          'data-join-map-button' => $mapId,
          'data-quarter-value' => $value,
          'data-filter-value' => $value,
          'data-color' => $color,
          'data-year' => (int) $quarter['year'],
          'data-quarter' => (int) $quarter['quarter'],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['join-location-map', 'join-location-map--' . $instance],
      ],
      'title' => [
        '#markup' => '<h3>' . $title . '</h3>',
      ],
      'control_bar' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['join-location-map__control-bar'],
        ],
        'controls' => $buttonContainer,
        'summary' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['join-location-map__summary'],
            'data-join-map-summary' => $mapId,
          ],
          '#markup' => '<p>' . $this->t('Select one or more quarters to visualize where new joins originated. After clearing caches, loading the first dataset can take up to @seconds seconds.', ['@seconds' => 30]) . '</p>',
        ],
        'actions' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['join-location-map__actions'],
          ],
          'toggle' => [
            '#type' => 'button',
            '#value' => $this->t('Expand map'),
            '#attributes' => [
              'class' => ['button', 'join-location-map__toggle'],
              'data-join-map-toggle' => $mapId,
              'data-expanded-label' => $this->t('Collapse map'),
              'data-collapsed-label' => $this->t('Expand map'),
            ],
          ],
        ],
      ],
      'map_wrapper' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['join-location-map__wrapper'],
        ],
        'map' => [
          '#type' => 'container',
          '#attributes' => [
            'id' => $mapId,
            'class' => ['join-location-map__canvas'],
            'data-join-map-canvas' => $mapId,
          ],
        ],
      ],
      '#attached' => [
        'library' => [
          'makerspace_dashboard/join_location_map',
        ],
        'drupalSettings' => [
          'makerspace_dashboard' => [
            'location_filter_maps' => [
              $mapId => [
                'apiUrl' => $apiUrl,
                'defaultFilter' => $defaultValue,
                'filters' => array_map(function (array $quarter) {
                  return [
                    'value' => $quarter['value'],
                    'label' => $quarter['label'],
                    'color' => $quarter['color'],
                    'params' => $quarter['params'] ?? [],
                  ];
                }, $quartersWithColors),
                'emptySummary' => (string) $this->t('Select one or more quarters to visualize where new joins originated.'),
              ],
            ],
          ],
        ],
      ],
    ];
  }

}
