<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Compares workshops vs other events across skill levels.
 */
class EducationEventSkillLevelsChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'skill_levels';
  protected const WEIGHT = 60;
  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['3m', '1y', '2y'];

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $logLine = sprintf("[%s] skill_levels range=%s filters=%s\n", date('c'), $activeRange, var_export($filters, TRUE));
    @file_put_contents('/tmp/makerspace_range.log', $logLine, FILE_APPEND);
    $bounds = $this->calculateRangeBounds($activeRange, $this->now());
    $skills = $this->eventsMembershipDataService->getSkillLevelBreakdown($bounds['start'], $bounds['end']);
    if (empty($skills['levels'])) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => array_map('strval', $skills['levels']),
        'datasets' => [
          [
            'label' => (string) $this->t('Workshops'),
            'data' => array_map('intval', $skills['workshop']),
            'backgroundColor' => '#8b5cf6',
            'maxBarThickness' => 28,
          ],
          [
            'label' => (string) $this->t('Other events'),
            'data' => array_map('intval', $skills['other']),
            'backgroundColor' => '#f97316',
            'maxBarThickness' => 28,
          ],
        ],
      ],
      'options' => [
        'indexAxis' => 'y',
        'plugins' => [
          'legend' => ['position' => 'bottom'],
        ],
        'scales' => [
          'x' => [
            'ticks' => ['precision' => 0],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Skill Levels by Event Type'),
      (string) $this->t('Compare workshop offerings to other event types across advertised skill levels.'),
      $visualization,
      [
        (string) $this->t('Source: CiviCRM event field_event_skill_level for events in the selected range.'),
        (string) $this->t('Processing: Buckets events tagged as workshops vs other types. Events missing a skill level fall into the "Unknown" bucket.'),
      ],
      $this->buildRangeMetadata($activeRange, self::RANGE_OPTIONS),
    );
  }

}
