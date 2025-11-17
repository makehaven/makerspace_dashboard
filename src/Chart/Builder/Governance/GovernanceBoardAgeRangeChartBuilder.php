<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Governance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;
use Drupal\makerspace_dashboard\Service\GovernanceBoardDataService;

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
  ];

  protected DemographicsDataService $demographicsDataService;

  public function __construct(GovernanceBoardDataService $boardDataService, DemographicsDataService $demographicsDataService, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($boardDataService, $stringTranslation);
    $this->demographicsDataService = $demographicsDataService;
  }

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

    $membershipSummary = $this->demographicsDataService->getMembershipAgeBucketPercentages();
    $membershipPercentages = $membershipSummary['percentages'] ?? [];
    unset($membershipPercentages['Unknown']);
    $rebasedMembership = $this->normalizePercentSubset($membershipPercentages);
    $membershipValues = $this->formatPercentValues($rebasedMembership);

    $labels = array_keys(self::COLOR_MAP);
    $boardData = [];
    $membershipData = [];
    foreach ($labels as $label) {
      $boardData[] = (float) ($actualValues[$label] ?? 0);
      $membershipData[] = (float) ($membershipValues[$label] ?? 0);
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'type' => 'line',
            'label' => (string) $this->t('Membership %'),
            'data' => $membershipData,
            'borderColor' => '#f97316',
            'backgroundColor' => 'rgba(249, 115, 22, 0.2)',
            'tension' => 0.35,
            'borderWidth' => 2,
            'pointRadius' => 4,
            'yAxisID' => 'y',
            'fill' => FALSE,
          ],
          [
            'type' => 'bar',
            'label' => (string) $this->t('Board %'),
            'data' => $boardData,
            'backgroundColor' => '#2563eb',
            'borderRadius' => 4,
            'order' => 1,
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
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
          'y' => [
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
              'text' => (string) $this->t('Share of members (%)'),
            ],
          ],
          'x' => [
            'stacked' => FALSE,
          ],
        ],
      ],
    ];

    $notes = array_merge(
      [$this->buildSourceNote()],
      [
        (string) $this->t('Processing: Board ages come from the Board-Roster tab; membership ages use profile birthdates bucketed into the same ranges.'),
        (string) $this->t('Definitions: This comparison highlights whether the board’s age mix reflects the broader membership, using percentages rather than raw counts.'),
      ]
    );

    return $this->newDefinition(
      (string) $this->t('Board Age Range'),
      (string) $this->t('Shows the age distribution of current board members alongside our targets.'),
      $visualization,
      $notes,
    );
  }

  /**
   * Converts bucket percentages into a normalized subset excluding Unknown.
   */
  private function normalizePercentSubset(array $values): array {
    $sum = array_sum($values);
    if ($sum <= 0) {
      return $values;
    }
    $normalized = [];
    foreach ($values as $label => $value) {
      $normalized[$label] = $value / $sum;
    }
    return $normalized;
  }

}
