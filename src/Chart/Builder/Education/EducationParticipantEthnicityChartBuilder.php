<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Visualizes top ethnicities across workshops vs other events.
 */
class EducationParticipantEthnicityChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'demographics_ethnicity';
  protected const WEIGHT = 80;
  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['1m', '3m', '1y', '2y'];

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $bounds = $this->calculateRangeBounds($activeRange, $this->now());
    $demographics = $this->eventsMembershipDataService->getParticipantDemographics($bounds['start'], $bounds['end']);
    $ethnicity = $demographics['ethnicity'] ?? NULL;
    if (empty($ethnicity['labels'])) {
      return NULL;
    }

    $total = array_sum($ethnicity['workshop']) + array_sum($ethnicity['other']);
    if ($total === 0) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => array_map('strval', $ethnicity['labels']),
        'datasets' => [
          [
            'label' => (string) $this->t('Workshops'),
            'data' => array_map('intval', $ethnicity['workshop']),
            'backgroundColor' => '#14b8a6',
            'maxBarThickness' => 28,
          ],
          [
            'label' => (string) $this->t('Other events'),
            'data' => array_map('intval', $ethnicity['other']),
            'backgroundColor' => '#ef4444',
            'maxBarThickness' => 28,
          ],
        ],
      ],
      'options' => [
        'indexAxis' => 'y',
        'maintainAspectRatio' => TRUE,
        'aspectRatio' => 1.8,
        'plugins' => [
          'legend' => ['position' => 'bottom'],
        ],
        'scales' => [
          'x' => ['ticks' => ['precision' => 0]],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Participant Ethnicity (Top 10)'),
      (string) $this->t('Top reported ethnicities for counted participants, comparing workshops to other events.'),
      $visualization,
      [
        (string) $this->t('Source: Ethnicity selections from the Demographics custom profile linked to event participants.'),
        (string) $this->t('Processing: Multi-select values are split across the chart; categories beyond the top ten roll into "Other".'),
      ],
      $this->buildRangeMetadata($activeRange, self::RANGE_OPTIONS),
    );
  }

}
