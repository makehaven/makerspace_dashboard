<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Builds the Finance section MRR trend chart.
 */
class FinanceMrrTrendChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'mrr';
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
    $series = $this->financialDataService->getMrrTrend($startDate, $endDate);

    $values = array_map('floatval', $series['data'] ?? []);
    if (empty(array_filter($values))) {
      return NULL;
    }

    $labels = array_map('strval', $series['labels'] ?? []);
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
              'label' => $this->chartCallback('series_value', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
              ]),
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
              'callback' => $this->chartCallback('value_format', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Monthly Recurring Revenue Trend'),
      (string) $this->t('Aggregate by billing source to highlight sustainability of recruitment and retention efforts.'),
      $visualization,
      [
        (string) $this->t('Source: Member join dates (profile__field_member_join_date) paired with membership type taxonomy terms.'),
        (string) $this->t('Processing: Includes joins within the selected six-month window and applies assumed monthly values ($50 individual, $75 family, others default to $0).'),
        (string) $this->t('Definitions: Additional membership types require configured pricing before contributing to this model.'),
      ],
    );
  }

}
