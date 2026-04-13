<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Governance;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Builds the Board Governance KPI composite score visualization.
 */
class GovernanceBoardGovernanceKpiChartBuilder extends GovernanceChartBuilderBase {

  protected const CHART_ID = 'board_governance_kpi';
  protected const WEIGHT = 40;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $stats = $this->boardDataService->getBoardGovernanceKpi();

    $current = $stats['value'];
    $lastUpdated = $stats['last_updated'] ?? NULL;
    $responseCount = $stats['response_count'] ?? 0;

    $baseline = 80.0;
    $goal = 90.0;

    $labels = [
      (string) $this->t('2025 Baseline'),
      (string) $this->t('2030 Goal'),
    ];
    $datasets = [
      [
        'label' => (string) $this->t('Target (%)'),
        'data' => [$baseline, $goal],
        'backgroundColor' => ['rgba(148, 163, 184, 0.6)', 'rgba(21, 128, 61, 0.6)'],
        'borderColor' => ['#94a3b8', '#15803d'],
        'borderWidth' => 1,
        'borderRadius' => 4,
      ],
    ];

    if ($current !== NULL) {
      $currentPct = round((float) $current * 100, 1);
      $labels = array_merge(
        [(string) $this->t('Current Score')],
        $labels
      );
      $datasets[0]['data'] = array_merge([$currentPct], $datasets[0]['data']);
      $datasets[0]['backgroundColor'] = array_merge(
        ['rgba(37, 99, 235, 0.7)'],
        $datasets[0]['backgroundColor']
      );
      $datasets[0]['borderColor'] = array_merge(
        ['#2563eb'],
        $datasets[0]['borderColor']
      );
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'indexAxis' => 'y',
        'responsive' => TRUE,
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'percent',
                'decimals' => 1,
              ]),
            ],
          ],
        ],
        'scales' => [
          'x' => [
            'beginAtZero' => TRUE,
            'max' => 100,
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('% of responses scoring 4 or 5'),
            ],
          ],
          'y' => [
            'grid' => ['display' => FALSE],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Source: Annual Board Governance Effectiveness Self-Assessment webform (/survey/board).'),
      (string) $this->t('Processing: Composite of 7 categories — strategic focus, information preparation, culture of challenge, board composition, fiduciary oversight, meeting effectiveness, governance impact. Score = % of individual question responses rated 4 or 5 out of 5.'),
    ];
    if ($current === NULL) {
      $notes[] = (string) $this->t('No survey responses recorded for the current year.');
    }
    else {
      if ($responseCount > 0) {
        $notes[] = (string) $this->t('Responses this year: @count', ['@count' => $responseCount]);
      }
      if ($lastUpdated) {
        $notes[] = (string) $this->t('Last submission: @date', ['@date' => $lastUpdated]);
      }
    }

    return $this->newDefinition(
      (string) $this->t('Board Governance KPI'),
      (string) $this->t('Composite effectiveness score from the annual Board self-assessment, measured against 2025 baseline and 2030 goal.'),
      $visualization,
      $notes,
    );
  }

}
