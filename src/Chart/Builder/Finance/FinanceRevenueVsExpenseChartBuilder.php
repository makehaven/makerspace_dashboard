<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Quarterly total revenue versus operating expense (runway view).
 */
class FinanceRevenueVsExpenseChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'revenue_vs_expense';
  protected const WEIGHT = 6;

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
    $trend = $this->financialDataService->getRevenueVsExpenseTrend(8);
    if (empty($trend['labels'])) {
      return NULL;
    }

    $revenue = array_map(static fn($v) => round((float) $v, 2), $trend['revenue']);
    $expense = array_map(static fn($v) => round((float) $v, 2), $trend['expense']);
    if (array_sum($revenue) <= 0 && array_sum($expense) <= 0) {
      return NULL;
    }

    $net = [];
    foreach ($revenue as $i => $rev) {
      $net[] = round($rev - ($expense[$i] ?? 0.0), 2);
    }

    $datasets = [
      [
        'type' => 'bar',
        'label' => (string) $this->t('Revenue'),
        'data' => $revenue,
        'backgroundColor' => 'rgba(22, 163, 74, 0.65)',
        'borderColor' => '#16a34a',
        'order' => 2,
      ],
      [
        'type' => 'bar',
        'label' => (string) $this->t('Operating expense'),
        'data' => $expense,
        'backgroundColor' => 'rgba(220, 38, 38, 0.65)',
        'borderColor' => '#dc2626',
        'order' => 2,
      ],
      [
        'type' => 'line',
        'label' => (string) $this->t('Net'),
        'data' => $net,
        'borderColor' => '#1d4ed8',
        'backgroundColor' => '#1d4ed8',
        'pointRadius' => 4,
        'pointBackgroundColor' => '#1d4ed8',
        'fill' => FALSE,
        'tension' => 0.2,
        'order' => 1,
      ],
    ];

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $trend['labels'],
        'datasets' => $datasets,
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'y' => [
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Revenue vs operating expense'),
      (string) $this->t('Quarterly total revenue, total operating expense, and net surplus/deficit. Pair with the Reserve Funds KPI for runway context.'),
      $visualization,
      [
        (string) $this->t('Source: Income-Statement Google Sheet (income_total, expense_total).'),
        (string) $this->t('Processing: 8 trailing quarters; expense values are absolute (sheet stores them parenthesized/negative). Net = revenue − expense.'),
      ],
    );
  }

}
