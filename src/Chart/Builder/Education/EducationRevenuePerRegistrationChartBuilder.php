<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Plots the average paid amount per counted registration.
 */
class EducationRevenuePerRegistrationChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'revenue_per_registration';
  protected const WEIGHT = 40;
  protected const TIER = 'supplemental';

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $window = $this->buildRollingWindow();
    $series = $this->eventsMembershipDataService->getAverageRevenuePerRegistration($window['start'], $window['end']);
    if (empty($series['types'])) {
      return NULL;
    }

    $palette = ['#6366f1', '#0ea5e9', '#ec4899', '#84cc16', '#f59e0b', '#ef4444'];
    $datasets = [];
    $index = 0;
    foreach ($series['types'] as $type => $values) {
      $color = $palette[$index % count($palette)];
      $datasets[] = [
        'label' => (string) $type,
        'data' => array_map('floatval', $values),
        'borderColor' => $color,
        'backgroundColor' => 'transparent',
        'fill' => FALSE,
        'tension' => 0.25,
        'borderWidth' => 2,
        'pointRadius' => 3,
      ];
      $index++;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => array_map('strval', $series['months']),
        'datasets' => $datasets,
      ],
      'options' => [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'spanGaps' => TRUE,
        'plugins' => [
          'legend' => [
            'position' => 'bottom',
            'labels' => ['usePointStyle' => TRUE],
          ],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 2,
              ]),
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 2,
                'showLabel' => FALSE,
              ]),
            ],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Average $ per registration'),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Average Revenue per Registration'),
      (string) $this->t('Average paid amount (from CiviCRM contributions) per counted registration, by event type.'),
      $visualization,
      [
        (string) $this->t('Source: CiviCRM participant payments joined to contributions for counted registrations.'),
        (string) $this->t('Processing: Sums paid contributions per month and divides by the number of counted registrations for each event type.'),
        (string) $this->t('Definitions: Registrations without payments contribute $0; refunded amounts are not excluded presently. Use the legend to toggle event types.'),
      ],
    );
  }

}
