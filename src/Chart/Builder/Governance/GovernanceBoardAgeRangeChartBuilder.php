<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Governance;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Builds the board age distribution container chart.
 */
class GovernanceBoardAgeRangeChartBuilder extends GovernanceChartBuilderBase {

  protected const CHART_ID = 'board_age_range';
  protected const WEIGHT = 20;

  protected const COLOR_MAP = [
    '<30' => '#bae6fd',
    '30-39' => '#7dd3fc',
    '40-49' => '#38bdf8',
    '50-59' => '#0ea5e9',
    '60+' => '#2563eb',
    'Unknown' => '#94a3b8',
  ];

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $composition = $this->getComposition();
    $age = $composition['age'] ?? NULL;
    if (!$age) {
      return NULL;
    }

    $goalValues = $this->formatPercentValues($age['goal_pct'] ?? []);
    $actualValues = $this->formatPercentValues($age['actual_pct'] ?? []);

    if (!$this->hasMeaningfulValues($goalValues) && !$this->hasMeaningfulValues($actualValues)) {
      return NULL;
    }

    $visualization = [
      'type' => 'container',
      'attributes' => ['class' => ['pie-chart-pair-container']],
      'children' => [
        'goal' => $this->buildPieVisualization((string) $this->t('Goal %'), $goalValues, self::COLOR_MAP),
        'actual' => $this->buildPieVisualization((string) $this->t('Actual %'), $actualValues, self::COLOR_MAP),
      ],
    ];

    $notes = array_merge(
      [$this->buildSourceNote()],
      [
        (string) $this->t('Processing: Birthdates from the Board-Roster tab are bucketed into the shown ranges; missing or invalid dates fall into "Unknown".'),
        (string) $this->t('Definitions: Goals use goal_age_* rows; values represent the share of board seats in each age range.'),
      ]
    );

    return $this->newDefinition(
      (string) $this->t('Board Age Range'),
      (string) $this->t('Shows the age distribution of current board members alongside our targets.'),
      $visualization,
      $notes,
    );
  }

}
