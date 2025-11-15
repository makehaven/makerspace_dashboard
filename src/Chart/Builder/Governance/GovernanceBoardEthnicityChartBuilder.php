<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Governance;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Builds the stacked ethnicity goal vs. actual chart.
 */
class GovernanceBoardEthnicityChartBuilder extends GovernanceChartBuilderBase {

  protected const CHART_ID = 'board_ethnicity';
  protected const WEIGHT = 30;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $composition = $this->getComposition();
    $ethnicity = $composition['ethnicity'] ?? NULL;
    if (!$ethnicity) {
      return NULL;
    }

    $goalPercentages = $this->formatPercentValues($ethnicity['goal_pct'] ?? []);
    $actualPercentages = $this->formatPercentValues($ethnicity['actual_pct'] ?? []);

    if (!$this->hasMeaningfulValues($goalPercentages) && !$this->hasMeaningfulValues($actualPercentages)) {
      return NULL;
    }

    $labels = array_map('strval', array_keys($goalPercentages));
    $goalData = [];
    $actualData = [];
    foreach ($labels as $label) {
      $goalData[] = (float) ($goalPercentages[$label] ?? 0);
      $actualData[] = (float) ($actualPercentages[$label] ?? 0);
    }

    $maxValue = max(
      $goalData ? max($goalData) : 0,
      $actualData ? max($actualData) : 0,
      100
    );

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Goal %'),
            'backgroundColor' => '#c084fc',
            'data' => $goalData,
            'borderRadius' => 4,
          ],
          [
            'label' => (string) $this->t('Actual %'),
            'backgroundColor' => '#2563eb',
            'data' => $actualData,
            'borderRadius' => 4,
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => [
            'position' => 'top',
          ],
          'tooltip' => [
            'callbacks' => [
              'label' => "function(context) { var value = context.parsed.y ?? context.parsed; return context.dataset.label + ': ' + value.toFixed(1) + '%'; }",
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'beginAtZero' => TRUE,
            'suggestedMax' => ceil($maxValue / 10) * 10,
            'ticks' => [
              'callback' => "function(value) { return value + '%'; }",
            ],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Share of board members (%)'),
            ],
          ],
          'x' => [
            'ticks' => [
              'autoSkip' => FALSE,
            ],
          ],
        ],
      ],
    ];

    $notes = array_merge(
      [$this->buildSourceNote()],
      [
        (string) $this->t('Processing: Members may select multiple ethnicities; each checked column increments the Actual % share for that category.'),
        (string) $this->t('Definitions: Goals use goal_ethnicity_* rows and therefore total 100%, whereas actuals can sum above 100% because of multi-select responses.'),
      ]
    );

    return $this->newDefinition(
      (string) $this->t('Board Ethnicity'),
      (string) $this->t('Compares the ethnic diversity of the board to stated goals. Actual totals can exceed 100% because members may identify with multiple groups.'),
      $visualization,
      $notes,
    );
  }

}
