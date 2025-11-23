<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\KpiDataService;
use Drupal\makerspace_dashboard\Service\MemberDemographicLocationDataService;

/**
 * Provides demographic breakdowns without exposing individual identities.
 */
class DeiSection extends DashboardSectionBase {

  /**
   * KPI data service.
   */
  protected KpiDataService $kpiDataService;

  /**
   * Demographic location data service.
   */
  protected MemberDemographicLocationDataService $demographicLocationData;

  /**
   * Constructs the section.
   */
  public function __construct(KpiDataService $kpi_data_service, MemberDemographicLocationDataService $demographic_location_data, ChartBuilderManager $chart_builder_manager) {
    parent::__construct(NULL, $chart_builder_manager);
    $this->kpiDataService = $kpi_data_service;
    $this->demographicLocationData = $demographic_location_data;
  }

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

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;

    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('dei'));
    $build['kpi_table']['#weight'] = $weight++;

    foreach ($this->buildTieredChartContainers($filters) as $tier => $container) {
      $container['#weight'] = $weight++;
      $build['tier_' . $tier] = $container;
    }

    $build['demographic_maps_intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['makerspace-dashboard-definition']],
      '#weight' => $weight++,
      'text' => [
        '#markup' => $this->t('The maps below let you overlay member home regions by self-reported demographics. Locations are jittered to protect privacy and limited to our Connecticut focus area.'),
      ],
    ];

    $ethnicityOptions = $this->demographicLocationData->getEthnicityOptions(10);
    if (!empty($ethnicityOptions)) {
      $build['ethnicity_map'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['makerspace-dashboard-join-map-section']],
        '#weight' => $weight++,
        'heading' => [
          '#markup' => '<h3>' . $this->t('Locations by ethnicity') . '</h3>',
        ],
        'description' => [
          '#markup' => '<p>' . $this->t('Toggle the buttons to see where members from each ethnic group live.') . '</p>',
        ],
        'map' => $this->buildDemographicMapRenderable('ethnicity', 'ethnicity', $ethnicityOptions, $this->t('Select one or more ethnicities to overlay.')),
      ];
    }

    $ageOptions = $this->demographicLocationData->getAgeBucketOptions();
    if (!empty($ageOptions)) {
      $build['age_map'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['makerspace-dashboard-join-map-section']],
        '#weight' => $weight++,
        'heading' => [
          '#markup' => '<h3>' . $this->t('Locations by age group') . '</h3>',
        ],
        'description' => [
          '#markup' => '<p>' . $this->t('Each button overlays members whose profile birthday places them in that age range.') . '</p>',
        ],
        'map' => $this->buildDemographicMapRenderable('age', 'age', $ageOptions, $this->t('Select age ranges to overlay.')),
      ];
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list', 'civicrm_contact_list', 'civicrm_address_list'],
    ];

    return $build;
  }

  /**
   * Builds a demographic map render array.
   */
  protected function buildDemographicMapRenderable(string $instance, string $filterType, array $options, TranslatableMarkup $emptySummary): array {
    $mapId = Html::getUniqueId('demographic-map-' . $instance);
    $apiUrl = Url::fromRoute('makerspace_dashboard.api.demographic_locations')->toString();
    $palette = [
      '#f44336', '#ff9800', '#4caf50', '#2196f3',
      '#9c27b0', '#00bcd4', '#8bc34a', '#ff5722',
      '#6d4c41', '#009688', '#3f51b5', '#c2185b',
    ];

    $defaultValue = $options[0]['value'] ?? '';
    $filters = [];
    $buttons = [
      '#type' => 'container',
      '#attributes' => ['class' => ['join-location-map__controls']],
    ];

    foreach ($options as $index => $option) {
      $value = (string) $option['value'];
      $label = (string) ($option['label'] ?? $value);
      $color = $palette[$index % count($palette)];
      $filters[] = [
        'value' => $value,
        'label' => $label,
        'color' => $color,
        'params' => [
          'type' => $filterType,
          'value' => $value,
        ],
      ];
      $buttonClasses = ['button', 'join-location-map__button'];
      if ($value === $defaultValue) {
        $buttonClasses[] = 'active';
      }
      $buttons['filter_' . $index] = [
        '#type' => 'button',
        '#value' => $label,
        '#attributes' => [
          'class' => $buttonClasses,
          'data-join-map-button' => $mapId,
          'data-quarter-value' => $value,
          'data-filter-value' => $value,
          'data-color' => $color,
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['join-location-map', 'join-location-map--' . $instance],
      ],
      'control_bar' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['join-location-map__control-bar']],
        'controls' => $buttons,
        'summary' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['join-location-map__summary'],
            'data-join-map-summary' => $mapId,
          ],
          '#markup' => '<p>' . $emptySummary . '</p>',
        ],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['join-location-map__actions']],
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
        '#attributes' => ['class' => ['join-location-map__wrapper']],
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
                'filters' => $filters,
                'defaultFilter' => $defaultValue,
                'emptySummary' => (string) $emptySummary,
              ],
            ],
          ],
        ],
      ],
    ];
  }

}
