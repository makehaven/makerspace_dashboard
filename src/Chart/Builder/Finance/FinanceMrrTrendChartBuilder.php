<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Monthly dues from people who joined that month. This is not total MRR.
 */
class FinanceMrrTrendChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'mrr';
  protected const TIER = 'supplemental';
  protected const PARTIAL_LAST_PERIOD = TRUE;
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
      'label' => (string) $this->t('New-joiner dues ($ per month)'),
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
      (string) $this->t('Monthly Dues from New Joiners'),
      (string) $this->t('The monthly dues of the people who joined each month. This is new dues only, not total recurring revenue.'),
      $visualization,
      [
        (string) $this->t('Source: Canonical member profile creation timestamps paired with recorded monthly payment values.'),
        (string) $this->t('Processing: Adds the recorded monthly payment of each member whose profile was created in the month, over six months.'),
        (string) $this->t('Definitions: Dues from existing members are not included. For total membership income, see Revenue mix by income stream.'),
      ],
    );
  }

}
