<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Governance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\GovernanceBoardDataService;
use Drupal\makerspace_dashboard\Service\KpiDataService;

/**
 * Builds the Committee Effectiveness KPI composite score visualization.
 */
class GovernanceCommitteeEffectivenessKpiChartBuilder extends GovernanceChartBuilderBase {

  protected const CHART_ID = 'committee_effectiveness_kpi';
  protected const TIER = 'supplemental';
  protected const WEIGHT = 50;

  /**
   * Constructs the builder.
   */
  public function __construct(
    GovernanceBoardDataService $boardDataService,
    protected KpiDataService $kpiData,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($boardDataService, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $stats = $this->boardDataService->getCommitteeEffectivenessKpi();

    $current = $stats['value'];
    $lastUpdated = $stats['last_updated'] ?? NULL;
    $responseCount = $stats['response_count'] ?? 0;

    // No survey this year: an empty chart says less than the scorecard's TBD.
    if ($current === NULL) {
      return NULL;
    }

    $goal = $this->kpiData->getSectionKpiDefinitions('governance')['kpi_committee_effectiveness']['goal_2030'] ?? NULL;
    // Goals come from the Goals sheet on this 1-5 scale. The code default is a
    // retired 0-1 percentage, which must not be drawn on this axis.
    $goal = is_numeric($goal) && (float) $goal > 1 ? (float) $goal : NULL;

    $labels = [(string) $this->t('Current score')];
    $values = [(float) $current];
    $colors = ['rgba(37, 99, 235, 0.7)'];
    $borders = ['#2563eb'];
    if ($goal !== NULL) {
      $labels[] = (string) $this->t('2030 goal');
      $values[] = $goal;
      $colors[] = 'rgba(21, 128, 61, 0.6)';
      $borders[] = '#15803d';
    }
    $datasets = [
      [
        'label' => (string) $this->t('Average score (1-5)'),
        'data' => $values,
        'backgroundColor' => $colors,
        'borderColor' => $borders,
        'borderWidth' => 1,
        'borderRadius' => 4,
      ],
    ];

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
                'format' => 'number',
                'decimals' => 2,
              ]),
            ],
          ],
        ],
        'scales' => [
          'x' => [
            'beginAtZero' => TRUE,
            'max' => 5,
            'ticks' => [
              'stepSize' => 1,
            ],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Average score, 1 to 5'),
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
      (string) $this->t('Processing: Composite of 5 categories — role clarity, alignment, time use, communication, contribution. Score = the average of all answers, each rated 1 to 5.'),
    ];
    if ($responseCount > 0) {
      $notes[] = (string) $this->t('Answers this year: @count', ['@count' => $responseCount]);
    }
    if ($lastUpdated) {
      $notes[] = (string) $this->t('Last submission: @date', ['@date' => $lastUpdated]);
    }

    return $this->newDefinition(
      (string) $this->t('Committee Effectiveness KPI'),
      (string) $this->t('Composite effectiveness score from the annual Committee self-assessment, measured against the 2030 goal.'),
      $visualization,
      $notes,
    );
  }

}
