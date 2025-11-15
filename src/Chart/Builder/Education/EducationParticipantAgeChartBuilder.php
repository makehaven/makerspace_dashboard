<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Participant age buckets split by workshops vs other events.
 */
class EducationParticipantAgeChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'demographics_age';
  protected const WEIGHT = 90;
  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['1m', '3m', '1y', '2y', 'all'];

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $bounds = $this->calculateRangeBounds($activeRange, $this->now());
    $demographics = $this->eventsMembershipDataService->getParticipantDemographics($bounds['start'], $bounds['end']);
    $age = $demographics['age'] ?? NULL;
    if (empty($age['labels'])) {
      return NULL;
    }

    $overall = array_sum($age['workshop']) + array_sum($age['other']);
    if ($overall === 0) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => array_map('strval', $age['labels']),
        'datasets' => [
          [
            'label' => (string) $this->t('Workshops'),
            'data' => array_map('intval', $age['workshop']),
            'borderColor' => '#2563eb',
            'backgroundColor' => 'rgba(37,99,235,0.2)',
            'fill' => FALSE,
            'tension' => 0.3,
          ],
          [
            'label' => (string) $this->t('Other events'),
            'data' => array_map('intval', $age['other']),
            'borderColor' => '#facc15',
            'backgroundColor' => 'rgba(250,204,21,0.2)',
            'fill' => FALSE,
            'tension' => 0.3,
            'borderDash' => [6, 4],
          ],
        ],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['position' => 'bottom'],
        ],
        'scales' => [
          'y' => ['ticks' => ['precision' => 0]],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Participant Age Distribution'),
      (string) $this->t('Age buckets for counted participants, evaluated at event date and split by workshops vs other events.'),
      $visualization,
      [
        (string) $this->t('Source: Participant birth dates from CiviCRM contacts. Age evaluated on the event start date.'),
        (string) $this->t('Processing: Uses fixed buckets (Under 18 through 65+); records without birth dates are skipped.'),
      ],
      $this->buildRangeMetadata($activeRange, self::RANGE_OPTIONS),
    );
  }

}
