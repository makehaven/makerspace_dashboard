<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Cumulative dues per joiner by months-since-join, one line per quarterly cohort.
 */
class FinanceCohortLtvChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'cohort_ltv';
  protected const WEIGHT = 35;

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
    $trend = $this->financialDataService->getCohortLtvCurves(6, 24);
    if (empty($trend['labels']) || empty($trend['cohorts'])) {
      return NULL;
    }

    $palette = $this->defaultColorPalette();
    $datasets = [];
    $index = 0;
    foreach ($trend['cohorts'] as $label => $cohort) {
      $color = $palette[$index % count($palette)];
      $datasets[] = [
        'label' => sprintf('%s (n=%d)', $label, $cohort['size']),
        'data' => $cohort['values'],
        'borderColor' => $color,
        'backgroundColor' => $color,
        'pointRadius' => 0,
        'fill' => FALSE,
        'tension' => 0.1,
        'borderWidth' => 2,
      ];
      $index++;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $trend['labels'],
        'datasets' => $datasets,
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'x' => [
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Months since join'),
            ],
          ],
          'y' => [
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Cumulative dues per member'),
            ],
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
      (string) $this->t('Cohort LTV curves (actual)'),
      (string) $this->t('Cumulative monthly dues paid per joiner, plotted by months since their join date, one line per quarterly cohort. A newer cohort tracking below older ones is an early signal that the projected LTV KPI is overstating future revenue.'),
      $visualization,
      [
        (string) $this->t('Source: profile join_date / end_date / field_member_payment_monthly_value.'),
        (string) $this->t('Processing: For each member we compute tenure as (end_date or today) − join_date in whole months. Cumulative dues at month N = min(N, tenure) × monthly_value. The cohort line is the per-member average; cohort size shown in legend.'),
        (string) $this->t('Caveat: This is an expected-collection proxy, not a payment-ledger. It assumes everyone paid their stated monthly dues for every active month — failed payments and partial collections aren\'t deducted. Newer cohorts naturally show shorter curves until they age into the horizon.'),
      ],
    );
  }

}
