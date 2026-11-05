<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Overview;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Membership dues received per quarter, from the income statement.
 */
class OverviewMembershipDuesChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'overview';
  protected const CHART_ID = 'membership_dues';
  protected const WEIGHT = 20;

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
    $mix = $this->financialDataService->getRevenueMixTrend(8);
    $values = array_map('floatval', $mix['series']['Membership dues'] ?? []);
    if (empty(array_filter($values))) {
      return NULL;
    }

    $datasets = [[
      'label' => (string) $this->t('Membership dues ($)'),
      'data' => $values,
      'borderColor' => '#1d4ed8',
      'backgroundColor' => 'rgba(37, 99, 235, 0.15)',
      'pointRadius' => 3,
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
        'labels' => array_map('strval', $mix['labels'] ?? []),
        'datasets' => $datasets,
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
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
        'scales' => [
          'y' => [
            'beginAtZero' => TRUE,
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
      (string) $this->t('Membership Dues by Quarter'),
      (string) $this->t('Dues received each completed quarter.'),
      $visualization,
      [
        (string) $this->t('Source: Income Statement tab, membership income.'),
      ],
    );
  }

}
