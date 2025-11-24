<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Placeholder visualization for future capacity tracking.
 */
class EducationWorkshopCapacityPlaceholderChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'workshop_capacity_placeholder';
  protected const WEIGHT = 100;
  protected const TIER = 'experimental';

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->eventsMembershipDataService->getSampleCapacitySeries();
    if (empty($series['data'])) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => array_map('strval', $series['months']),
        'datasets' => [[
          'label' => (string) $this->t('Utilization %'),
          'data' => array_map('floatval', $series['data']),
          'borderColor' => '#f97316',
          'backgroundColor' => 'rgba(249,115,22,0.2)',
          'fill' => FALSE,
        ]],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Workshop Capacity Utilization (Sample)'),
      (string) $this->t('Placeholder illustrating capacity tracking. Replace with actual utilization logic.'),
      $visualization,
      [
        (string) $this->t('Placeholder: Replace with actual capacity metrics. Currently showing illustrative values only.'),
        (string) $this->t('Next steps: join CiviCRM or scheduling data to calculate registrations as a share of capacity.'),
        (string) $this->t('Observation: @note', ['@note' => $series['note'] ?? $this->t('Sample data only.')]),
      ],
    );
  }

}
