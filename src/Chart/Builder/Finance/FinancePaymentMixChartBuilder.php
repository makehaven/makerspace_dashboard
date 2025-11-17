<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Builds the payment mix distribution chart.
 */
class FinancePaymentMixChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'payment_mix';
  protected const WEIGHT = 20;
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
    $stackedDefinition = $this->buildQuarterlyStackedChart();
    if ($stackedDefinition) {
      return $stackedDefinition;
    }

    $mix = $this->financialDataService->getPaymentMix();
    if (empty($mix)) {
      return NULL;
    }

    return $this->buildPieChartDefinition($mix);
  }

  /**
   * Builds the historical stacked bar chart when snapshot data exists.
   */
  private function buildQuarterlyStackedChart(): ?ChartDefinition {
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
        'stack' => 'mix',
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
              'afterLabel' => $this->chartCallback('payment_mix_members_count', []),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Payment Method Mix'),
      (string) $this->t('Breaks down active members by their recorded payment method.'),
      $visualization,
      [
        (string) $this->t('Source: makerspace_snapshot plan snapshots filtered to active members.'),
        (string) $this->t('Processing: Uses the latest monthly plan snapshot from each quarter and converts plan counts to percentage share of all active members.'),
        (string) $this->t('Notes: Bars are stacked horizontally so the total always equals 100% per quarter.'),
      ],
    );
  }

  /**
   * Fallback pie chart builder when historical snapshots are unavailable.
   */
  private function buildPieChartDefinition(array $mix): ChartDefinition {
    $rawLabels = array_map('strval', array_keys($mix));
    $values = array_map('intval', array_values($mix));
    $total = array_sum($values) ?: 1;
    $labels = [];
    foreach ($rawLabels as $index => $label) {
      $value = $values[$index] ?? 0;
      $percentage = $value / $total * 100;
      $decimals = $percentage < 10 ? 1 : 0;
      $labels[] = sprintf('%s (%s%%)', $label, number_format($percentage, $decimals));
    }

    $palette = $this->defaultColorPalette();
    $backgroundColors = [];
    for ($i = 0; $i < count($values); $i++) {
      $backgroundColors[] = $palette[$i % count($palette)];
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
          'backgroundColor' => $backgroundColors,
          'borderColor' => '#ffffff',
          'borderWidth' => 2,
          'hoverOffset' => 6,
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => [
            'position' => 'top',
            'labels' => ['boxWidth' => 16],
          ],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'suffix' => (string) $this->t('members'),
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Payment Method Mix'),
      (string) $this->t('Breaks down active members by their recorded payment method.'),
      $visualization,
      [
        (string) $this->t('Source: profile field_member_payment_method values on active members.'),
        (string) $this->t('Processing: Counts the first recorded payment method per member; multi-select entries contribute once per unique value.'),
        (string) $this->t('Notes: Historical view will replace this snapshot once plan-based snapshots become available.'),
      ],
    );
  }

}
