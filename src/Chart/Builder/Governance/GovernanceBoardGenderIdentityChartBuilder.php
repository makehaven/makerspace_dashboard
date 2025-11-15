<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Governance;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Builds the gender identity goal vs. actual visualization.
 */
class GovernanceBoardGenderIdentityChartBuilder extends GovernanceChartBuilderBase {

  protected const CHART_ID = 'board_gender_identity';
  protected const WEIGHT = 10;

  /**
   * Shared color palette to keep slices consistent.
   */
  protected const COLOR_MAP = [
    'Male' => '#2563eb',
    'Female' => '#dc2626',
    'Non-Binary' => '#f97316',
    'Other/Unknown' => '#7c3aed',
  ];

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $composition = $this->getComposition();
    $gender = $composition['gender'] ?? NULL;
    if (!$gender) {
      return NULL;
    }

    $goalValues = $this->formatPercentValues($gender['goal_pct'] ?? []);
    $actualValues = $this->formatPercentValues($gender['actual_pct'] ?? []);

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
        (string) $this->t('Processing: Actual share aggregates the Gender column in the Board-Roster tab; goals rely on goal_gender_* rows from Goals-Percent.'),
        (string) $this->t('Definitions: Non-Binary reflects self-reported values; Other/Unknown captures blank or custom entries.'),
      ]
    );

    return $this->newDefinition(
      (string) $this->t('Board Gender Identity'),
      (string) $this->t('Breaks down the board by self-reported gender and compares current representation to our goals.'),
      $visualization,
      $notes,
    );
  }

}
