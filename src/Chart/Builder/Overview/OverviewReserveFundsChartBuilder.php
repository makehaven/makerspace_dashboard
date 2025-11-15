<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Overview;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\KpiDataService;

/**
 * Builds the reserve funds coverage chart.
 */
class OverviewReserveFundsChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'overview';
  protected const CHART_ID = 'reserve_funds_months';
  protected const WEIGHT = 40;

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected KpiDataService $kpiDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->kpiDataService->getReserveFundsMonthlySeries();
    $labels = array_map('strval', $series['labels'] ?? []);
    $values = array_map(static fn($value) => round((float) $value, 2), $series['values'] ?? []);

    if (!$labels || !array_filter($values)) {
      return NULL;
    }

    $datasets = [[
      'label' => (string) $this->t('Months of coverage'),
      'data' => $values,
      'borderColor' => '#15803d',
      'backgroundColor' => 'rgba(21, 128, 61, 0.15)',
      'fill' => FALSE,
    ]];
    if ($trendDataset = $this->buildTrendDataset($values, (string) $this->t('Trend'))) {
      $datasets[] = $trendDataset;
    }

    $tooltipCallback = 'function(context){ var value = context && context.parsed && context.parsed.y !== undefined ? context.parsed.y : (context && context.yLabel !== undefined ? context.yLabel : (context && context.value !== undefined ? context.value : null)); if (value === null) { return ""; } var label = context.datasetIndex === 0 ? "' . addslashes((string) $this->t('Months of coverage')) . '" : "' . addslashes((string) $this->t('Trend')) . '"; return label + ": " + Number(value).toFixed(1) + " months"; }';

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'elements' => [
          'line' => ['tension' => 0.25],
        ],
        'interaction' => [
          'mode' => 'index',
          'intersect' => FALSE,
        ],
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'mode' => 'index',
            'intersect' => FALSE,
            'callbacks' => [
              'label' => $tooltipCallback,
            ],
          ],
        ],
        'hover' => [
          'mode' => 'index',
          'intersect' => FALSE,
        ],
        'scales' => [
          'y' => [
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Months of operating expense'),
            ],
            'ticks' => [
              'display' => TRUE,
              'padding' => 6,
              'color' => '#475569',
              'callback' => 'function(value){ return Number(value).toFixed(1) + " mo"; }',
            ],
            'grid' => [
              'color' => 'rgba(148, 163, 184, 0.25)',
            ],
            'min' => 0,
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Source: Balance-Sheet tab ("Cash and Cash Equivalents") and Income-Statement tab ("Total Expense").'),
      (string) $this->t('Processing: Converts the latest cash balance into months of runway using the trailing four-quarter average monthly expense.'),
    ];
    if (!empty($series['last_updated'])) {
      $notes[] = (string) $this->t('Last sheet update: @date', ['@date' => $series['last_updated']]);
    }

    return $this->newDefinition(
      (string) $this->t('Reserve Funds Coverage'),
      (string) $this->t('Tracks how many months of operating expense current cash reserves can sustain, showing trends over time.'),
      $visualization,
      $notes,
    );
  }

}
