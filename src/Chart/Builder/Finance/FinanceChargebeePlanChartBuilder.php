<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Builds the Chargebee plan distribution chart.
 */
class FinanceChargebeePlanChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'chargebee_plans';
  protected const WEIGHT = 40;
  private const QUARTER_LIMIT = 6;

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected FinancialDataService $financialDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    if ($stacked = $this->buildStackedChart()) {
      return $stacked;
    }

    $plans = $this->financialDataService->getChargebeePlanDistribution();
    if (empty($plans)) {
      return NULL;
    }

    return $this->buildPieChartDefinition($plans);
  }

  /**
   * Attempts to build the stacked plan-share chart from snapshots.
   */
  private function buildStackedChart(): ?ChartDefinition {
    $history = $this->financialDataService->getPaymentMixSnapshots(self::QUARTER_LIMIT);
    $quarters = $history['quarters'] ?? [];
    $planLabels = $history['plans'] ?? [];
    if (empty($quarters) || empty($planLabels)) {
      return NULL;
    }

    $planTotals = [];
    foreach ($quarters as $quarter) {
      foreach ($planLabels as $code => $label) {
        $planTotals[$code] = ($planTotals[$code] ?? 0) + ($quarter['counts'][$code] ?? 0);
      }
    }
    arsort($planTotals);
    $orderedCodes = array_keys(array_filter($planTotals));
    if (!$orderedCodes) {
      return NULL;
    }

    $labels = array_map(static fn($quarter) => (string) ($quarter['label'] ?? ''), $quarters);
    $palette = $this->defaultColorPalette();
    $colorCount = count($palette);
    $datasets = [];

    foreach ($orderedCodes as $index => $code) {
      $counts = [];
      $percentages = [];
      foreach ($quarters as $quarter) {
        $count = (int) ($quarter['counts'][$code] ?? 0);
        $counts[] = $count;
        $total = (int) ($quarter['total'] ?? 0);
        $percentages[] = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
      }
      if (!array_filter($counts)) {
        continue;
      }
      $datasets[] = [
        'label' => $planLabels[$code] ?? $code,
        'data' => $percentages,
        'makerspaceCounts' => $counts,
        'backgroundColor' => $palette[$index % $colorCount],
        'borderColor' => '#ffffff',
        'borderWidth' => 1,
        'stack' => 'plans',
        'maxBarThickness' => 28,
      ];
    }

    if (!$datasets) {
      return NULL;
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
        'maintainAspectRatio' => FALSE,
        'scales' => [
          'x' => [
            'stacked' => TRUE,
            'min' => 0,
            'max' => 100,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Share of active members'),
            ],
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
              ]),
            ],
          ],
          'y' => [
            'stacked' => TRUE,
          ],
        ],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'mode' => 'index',
            'intersect' => FALSE,
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'percent',
                'decimals' => 1,
              ]),
              'afterLabel' => $this->chartCallback('payment_mix_members_count', []),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Chargebee Plan Distribution'),
      (string) $this->t('Quarterly view of active members per Chargebee plan based on snapshot history.'),
      $visualization,
      [
        (string) $this->t('Source: makerspace_snapshot plan snapshots filtered to active members.'),
        (string) $this->t('Processing: Converts the latest snapshot in each quarter into a share of total members, enabling trend comparisons.'),
        (string) $this->t('Notes: Bars are stacked horizontally so each quarter totals 100%; download CSV for per-plan counts.'),
      ],
    );
  }

  /**
   * Builds the pie fallback when snapshots are unavailable.
   */
  private function buildPieChartDefinition(array $plans): ChartDefinition {
    $labels = array_map('strval', array_keys($plans));
    $values = array_map('intval', array_values($plans));
    $palette = $this->defaultColorPalette();
    $colors = [];
    for ($i = 0; $i < count($values); $i++) {
      $colors[] = $palette[$i % count($palette)];
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'pie',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => $values,
          'backgroundColor' => $colors,
          'borderColor' => '#ffffff',
          'borderWidth' => 2,
          'hoverOffset' => 6,
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['position' => 'top'],
          'datalabels' => [
            'formatter' => $this->chartCallback('dataset_share_percent', [
              'decimals' => 1,
            ]),
          ],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'suffix' => (string) $this->t('members'),
                'showLabel' => FALSE,
              ]),
              'afterLabel' => $this->chartCallback('dataset_share_percent', [
                'decimals' => 1,
                'suffix' => '%',
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Chargebee Plan Distribution'),
      (string) $this->t('Active users grouped by Chargebee plan assignment.'),
      $visualization,
      [
        (string) $this->t('Source: user profile field_user_chargebee_plan for published members.'),
        (string) $this->t('Processing: Counts distinct users per plan; empty values appear as "Unassigned".'),
        (string) $this->t('Notes: Historical view will replace this snapshot view once plan snapshots are populated.'),
      ],
    );
  }

}
