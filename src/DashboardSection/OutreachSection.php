<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\KpiDataService;
use Drupal\makerspace_dashboard\Service\MemberJoinLocationDataService;

/**
 * Provides outreach-related demographic breakdowns.
 */
class OutreachSection extends DashboardSectionBase {

  /**
   * KPI data provider.
   */
  protected KpiDataService $kpiDataService;

  /**
   * Join-location data service.
   */
  protected MemberJoinLocationDataService $memberJoinLocationData;

  /**
   * Constructs the section.
   */
  public function __construct(KpiDataService $kpi_data_service, MemberJoinLocationDataService $member_join_location_data, ChartBuilderManager $chart_builder_manager) {
    parent::__construct(NULL, $chart_builder_manager);
    $this->kpiDataService = $kpi_data_service;
    $this->memberJoinLocationData = $member_join_location_data;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'outreach';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Outreach');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;

    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('outreach'));
    $build['kpi_table']['#weight'] = $weight++;

    foreach ($this->buildTieredChartContainers($filters) as $tier => $container) {
      $container['#weight'] = $weight++;
      $build['tier_' . $tier] = $container;
    }

    $quarters = $this->memberJoinLocationData->getAvailableQuarters(12);
    if (!empty($quarters)) {
      $defaultQuarter = $quarters[0];
      $build['join_locations'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['makerspace-dashboard-join-map-section']],
        '#weight' => $weight++,
        'heading' => [
          '#markup' => '<h2>' . $this->t('New member joins by quarter') . '</h2>',
        ],
        'description' => [
          '#markup' => '<p>' . $this->t('Toggle recent quarters to see where outreach converted into new members. Each layer aggregates profile creations for that quarter and jitters markers for privacy.') . '</p>',
        ],
        'map' => $this->buildJoinQuarterMap('outreach', $defaultQuarter, $quarters),
      ];
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list', 'civicrm_contact_list', 'civicrm_address_list'],
      'contexts' => ['timezone'],
    ];

    return $build;
  }

  /**
   * Builds the reusable join map render array.
   */
  protected function buildJoinQuarterMap(string $instance, array $defaultQuarter, array $quarters): array {
    $mapId = Html::getUniqueId('join-map-' . $instance);
    $apiUrl = Url::fromRoute('makerspace_dashboard.api.join_locations')->toString();

    $palette = [
      '#f44336',
      '#ff9800',
      '#4caf50',
      '#2196f3',
      '#9c27b0',
      '#00bcd4',
      '#8bc34a',
      '#ff5722',
      '#6d4c41',
      '#009688',
      '#3f51b5',
      '#c2185b',
    ];
    $defaultValue = $defaultQuarter['value'] ?? sprintf('%d-Q%d', $defaultQuarter['year'], $defaultQuarter['quarter']);

    $quartersWithColors = [];
    $buttonContainer = [
      '#type' => 'container',
      '#attributes' => ['class' => ['join-location-map__controls']],
    ];

    foreach ($quarters as $index => $quarter) {
      $value = $quarter['value'] ?? sprintf('%d-Q%d', $quarter['year'], $quarter['quarter']);
      $color = $palette[$index % count($palette)];
      $quartersWithColors[] = $quarter + [
        'value' => $value,
        'color' => $color,
        'params' => [
          'year' => (int) $quarter['year'],
          'quarter' => (int) $quarter['quarter'],
        ],
      ];
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
      'control_bar' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['join-location-map__control-bar']],
        'controls' => $buttonContainer,
        'summary' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['join-location-map__summary'],
            'data-join-map-summary' => $mapId,
          ],
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
                'defaultFilter' => $defaultValue,
                'filters' => array_map(function (array $quarter) {
                  return [
                    'value' => $quarter['value'],
                    'label' => $quarter['label'],
                    'color' => $quarter['color'],
                    'params' => [
                      'year' => (int) $quarter['year'],
                      'quarter' => (int) $quarter['quarter'],
                    ],
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
