<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Governance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;
use Drupal\makerspace_dashboard\Service\GovernanceBoardDataService;

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
    $gender = $composition['gender'] ?? NULL;
    if (!$gender) {
      return NULL;
    }

    $goalValues = $this->formatPercentValues($gender['goal_pct'] ?? []);
    $actualValues = $this->formatPercentValues($gender['actual_pct'] ?? []);

    if (!$this->hasMeaningfulValues($goalValues) && !$this->hasMeaningfulValues($actualValues)) {
      return NULL;
    }

    $membershipRaw = $this->demographicsDataService->getMembershipGenderPercentages();
    $membershipFiltered = $this->normalizeMembershipGender($membershipRaw);
    $membershipValues = $this->formatPercentValues($membershipFiltered);

    $labels = [
      (string) $this->t('Goal %'),
      (string) $this->t('Board %'),
      (string) $this->t('Membership %'),
    ];

    $datasets = [];
    $orderedLabels = ['Male', 'Non-Binary', 'Female', 'Other/Unknown'];
    foreach ($orderedLabels as $label) {
      $color = self::COLOR_MAP[$label] ?? '#9ca3af';
      $datasets[] = [
        'label' => (string) $label,
        'data' => [
          (float) ($goalValues[$label] ?? 0),
          (float) ($actualValues[$label] ?? 0),
          (float) ($membershipValues[$label] ?? 0),
        ],
        'backgroundColor' => $color,
        'stack' => 'gender',
        'borderWidth' => 0,
      ];
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
        'interaction' => [
          'mode' => 'index',
          'axis' => 'y',
          'intersect' => FALSE,
        ],
        'plugins' => [
          'legend' => [
            'position' => 'bottom',
          ],
          'tooltip' => [
            'mode' => 'index',
            'intersect' => FALSE,
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
            'stacked' => TRUE,
            'max' => 100,
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
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

  /**
   * Normalizes membership gender percentages to exclude unknown values.
   */
  private function normalizeMembershipGender(array $values): array {
    $filtered = [];
    $total = 0.0;
    foreach ($values as $label => $value) {
      if ($label === 'Other/Unknown') {
        continue;
      }
      $filtered[$label] = (float) $value;
      $total += (float) $value;
    }

    if ($total > 0) {
      foreach ($filtered as $label => $value) {
        $filtered[$label] = $value / $total;
      }
    }

    return $filtered;
  }

}
