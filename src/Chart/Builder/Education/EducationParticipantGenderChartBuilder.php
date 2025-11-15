<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Participant gender mix across workshops vs other events.
 */
class EducationParticipantGenderChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'demographics_gender';
  protected const WEIGHT = 70;
  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['1m', '3m', '1y', '2y', 'all'];

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $bounds = $this->calculateRangeBounds($activeRange, $this->now());
    $demographics = $this->eventsMembershipDataService->getParticipantDemographics($bounds['start'], $bounds['end']);
    $gender = $demographics['gender'] ?? NULL;
    if (empty($gender['labels'])) {
      return NULL;
    }

    $workshopTotal = array_sum($gender['workshop']);
    $otherTotal = array_sum($gender['other']);
    if (($workshopTotal + $otherTotal) === 0) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => array_map('strval', $gender['labels']),
        'datasets' => [
          [
            'label' => (string) $this->t('Workshops'),
            'data' => array_map('intval', $gender['workshop']),
            'backgroundColor' => '#8b5cf6',
          ],
          [
            'label' => (string) $this->t('Other events'),
            'data' => array_map('intval', $gender['other']),
            'backgroundColor' => '#f97316',
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
      (string) $this->t('Participant Gender by Event Type'),
      (string) $this->t('Counted participants grouped by gender across workshops and other events.'),
      $visualization,
      [
        (string) $this->t('Source: Participant gender from CiviCRM contacts linked to workshop and other event registrations.'),
        (string) $this->t('Processing: Includes counted participant statuses only; unspecified genders are shown explicitly.'),
      ],
      $this->buildRangeMetadata($activeRange, self::RANGE_OPTIONS),
    );
  }

}
