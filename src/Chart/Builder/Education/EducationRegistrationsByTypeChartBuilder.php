<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Counts monthly registrations grouped by event type.
 */
class EducationRegistrationsByTypeChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'registrations_by_type';
  protected const WEIGHT = 30;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $window = $this->buildRollingWindow();
    $registrations = $this->eventsMembershipDataService->getMonthlyRegistrationsByType($window['start'], $window['end']);
    if (empty($registrations['types'])) {
      return NULL;
    }

    $palette = ['#2563eb', '#f97316', '#22c55e', '#a855f7', '#eab308', '#14b8a6', '#f43f5e'];
    $datasets = [];
    $index = 0;
    foreach ($registrations['types'] as $type => $counts) {
      $color = $palette[$index % count($palette)];
      $datasets[] = [
        'label' => (string) $type,
        'data' => array_map('intval', $counts),
        'backgroundColor' => $color,
        'stack' => 'registrations',
      ];
      $index++;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => array_map('strval', $registrations['months']),
        'datasets' => $datasets,
      ],
      'options' => [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
        ],
        'scales' => [
          'x' => ['stacked' => TRUE],
          'y' => [
            'stacked' => TRUE,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Registrations'),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Event Registrations by Type'),
      (string) $this->t('Counts counted registrations per month, grouped by event type.'),
      $visualization,
      [
        (string) $this->t('Source: CiviCRM participants filtered to statuses where "is counted" is TRUE.'),
        (string) $this->t('Processing: Grouped by event start month and event type; canceled or pending statuses are excluded automatically.'),
        (string) $this->t('Definitions: Event type labels come from the CiviCRM event type option list.'),
      ],
    );
  }

}
