<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Governance;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Builds the Committee Effectiveness KPI composite score visualization.
 */
class GovernanceCommitteeEffectivenessKpiChartBuilder extends GovernanceChartBuilderBase {

  protected const CHART_ID = 'committee_effectiveness_kpi';
  protected const WEIGHT = 50;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $stats = $this->boardDataService->getCommitteeEffectivenessKpi();

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
        ['rgba(124, 58, 237, 0.7)'],
        $datasets[0]['backgroundColor']
      );
      $datasets[0]['borderColor'] = array_merge(
        ['#7c3aed'],
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
      (string) $this->t('Source: Annual Committee Effectiveness Survey webform (/survey/committee). Covers: Governance, Finance, Facility, Development, Outreach, Education, DEI committees.'),
      (string) $this->t('Processing: Composite of 5 categories — role clarity, alignment, time use, communication, contribution. Score = % of individual question responses rated 4 or 5 out of 5.'),
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
      (string) $this->t('Committee Effectiveness KPI'),
      (string) $this->t('Composite effectiveness score from the annual Committee self-assessment, measured against 2025 baseline and 2030 goal.'),
      $visualization,
      $notes,
    );
  }

}
