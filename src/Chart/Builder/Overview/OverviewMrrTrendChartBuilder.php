<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Overview;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Builds the Overview MRR Trend chart.
 */
class OverviewMrrTrendChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'overview';
  protected const CHART_ID = 'mrr_trend';
  protected const WEIGHT = 10;

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
    $endDate = new \DateTimeImmutable();
    $startDate = $endDate->modify('-6 months');
    $trend = $this->financialDataService->getMrrTrend($startDate, $endDate);
    $values = array_map('floatval', $trend['data'] ?? []);
    if (empty(array_filter($values))) {
      return NULL;
    }

    $labels = array_map('strval', $trend['labels'] ?? []);
    $datasets = [[
      'label' => (string) $this->t('MRR ($)'),
      'data' => $values,
      'borderColor' => '#1d4ed8',
      'backgroundColor' => 'rgba(37, 99, 235, 0.15)',
      'pointRadius' => 3,
      'pointBackgroundColor' => '#1d4ed8',
      'fill' => FALSE,
    ]];

    if ($trendDataset = $this->buildTrendDataset($values, (string) $this->t('Trend'))) {
      $datasets[] = $trendDataset;
    }

    $tooltipCallback = 'function(context){ var value = context && context.parsed && context.parsed.y !== undefined ? context.parsed.y : (context && context.yLabel !== undefined ? context.yLabel : (context && context.value !== undefined ? context.value : null)); if (value === null) { return ""; } var label = context.datasetIndex === 0 ? "' . addslashes((string) $this->t('MRR ($)')) . '" : "' . addslashes((string) $this->t('Trend')) . '"; return label + ": $" + Number(value).toLocaleString(); }';

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
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
            'ticks' => [
              'callback' => 'function(value){ return "$" + Number(value).toLocaleString(); }',
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Monthly Recurring Revenue Trend'),
      (string) $this->t('Aggregate by billing source to highlight sustainability of recruitment/retention efforts.'),
      $visualization,
      [
        (string) $this->t('Source: Member join dates paired with membership type taxonomy terms.'),
        (string) $this->t('Processing: Includes joins within the selected six-month window and multiplies counts by assumed monthly values.'),
      ],
    );
  }

}
