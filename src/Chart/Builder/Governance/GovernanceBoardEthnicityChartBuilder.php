<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Governance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\GovernanceBoardDataService;

/**
 * Builds the stacked ethnicity goal vs. actual chart.
 */
class GovernanceBoardEthnicityChartBuilder extends GovernanceChartBuilderBase {

  protected const CHART_ID = 'board_ethnicity';
  protected const WEIGHT = 30;

  protected const COLOR_MAP = [
    'Black or African American' => '#dc2626',
    'Asian' => '#0ea5e9',
    'Middle Eastern or North African' => '#f97316',
    'Native Hawaiian or Pacific Islander' => '#16a34a',
    'Hispanic or Latino' => '#f59e0b',
    'American Indian or Alaska Native' => '#9333ea',
    'Other / Multi' => '#a855f7',
    'White/Caucasian' => '#6366f1',
  ];

  public function __construct(GovernanceBoardDataService $boardDataService, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($boardDataService, $stringTranslation);
  }

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
    // @todo Re-enable membership comparison after CiviCRM demographics are normalized.
    $membershipPercentages = NULL;

    if (
      !$this->hasMeaningfulValues($goalPercentages)
      && !$this->hasMeaningfulValues($actualPercentages)
    ) {
      return NULL;
    }

    $seriesLabels = $this->buildEthnicityLabelOrder(array_keys($goalPercentages));
    $barLabels = [
      (string) $this->t('Goal %'),
      (string) $this->t('Board %'),
    ];

    $datasets = [];
    foreach ($seriesLabels as $label) {
      $datasets[] = [
        'label' => $label,
        'data' => [
          (float) ($goalPercentages[$label] ?? 0),
          (float) ($actualPercentages[$label] ?? 0),
        ],
        'backgroundColor' => self::COLOR_MAP[$label] ?? '#9ca3af',
        'stack' => 'ethnicity',
        'borderWidth' => 0,
      ];
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $barLabels,
        'datasets' => $datasets,
      ],
      'options' => [
        'indexAxis' => 'y',
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => [
            'position' => 'bottom',
          ],
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
              'text' => (string) $this->t('Share (%)'),
            ],
          ],
          'y' => [
            'stacked' => TRUE,
          ],
        ],
      ],
    ];

    $notes = array_merge(
      [$this->buildSourceNote()],
      [
        (string) $this->t('Processing: Members may select multiple ethnicities; board shares therefore can exceed 100%.'),
        (string) $this->t('Definitions: Goals use goal_ethnicity_* rows. Membership comparisons will return once we stabilize the raw demographic inputs.'),
      ]
    );

    return $this->newDefinition(
      (string) $this->t('Board Ethnicity'),
      (string) $this->t('Compares the ethnic diversity of the board against goals and the overall membership.'),
      $visualization,
      $notes,
    );
  }

  /**
   * Orders ethnicity labels consistently across datasets.
   */
  private function buildEthnicityLabelOrder(array $goalLabels): array {
    $ordered = array_keys(self::COLOR_MAP);
    $combined = array_unique(array_merge($goalLabels, $ordered));
    return array_values(array_intersect($ordered, $combined));
  }

  /**
   * Normalizes membership distribution into board label buckets.
   */
  private function normalizeMembershipEthnicity(array $summary): array {
    $distribution = $summary['distribution'] ?? [];
    $totalMembers = max(1, (int) ($summary['reported_members'] ?? 0));
    $buckets = array_fill_keys(array_keys(self::COLOR_MAP), 0.0);

    foreach ($distribution as $machine => $count) {
      $label = $this->mapEthnicityMachineToLabel($machine);
      if (!isset($buckets[$label])) {
        $buckets[$label] = 0.0;
      }
      $buckets[$label] += (float) $count;
    }

    $percentages = [];
    foreach (array_keys(self::COLOR_MAP) as $label) {
      $value = $buckets[$label] ?? 0.0;
      $percentages[$label] = $value / $totalMembers;
    }

    return $percentages;
  }

  /**
   * Maps machine values to board label names.
   */
  private function mapEthnicityMachineToLabel(string $machine): string {
    $map = [
      'asian' => 'Asian',
      'black' => 'Black or African American',
      'black or african american' => 'Black or African American',
      'mena' => 'Middle Eastern or North African',
      'middleeast' => 'Middle Eastern or North African',
      'middle eastern or north african' => 'Middle Eastern or North African',
      'nhpi' => 'Native Hawaiian or Pacific Islander',
      'native hawaiian or pacific islander' => 'Native Hawaiian or Pacific Islander',
      'islander' => 'Native Hawaiian or Pacific Islander',
      'hispanic' => 'Hispanic or Latino',
      'hispanic or latino' => 'Hispanic or Latino',
      'aian' => 'American Indian or Alaska Native',
      'american indian or alaska native' => 'American Indian or Alaska Native',
      'white' => 'White/Caucasian',
      'white/caucasian' => 'White/Caucasian',
      'multi' => 'Other / Multi',
      'other' => 'Other / Multi',
      'other / multi' => 'Other / Multi',
      'not_specified' => 'Other / Multi',
      'not specified' => 'Other / Multi',
    ];
    $normalized = strtolower(trim($machine));
    return $map[$normalized] ?? 'Other / Multi';
  }

}
