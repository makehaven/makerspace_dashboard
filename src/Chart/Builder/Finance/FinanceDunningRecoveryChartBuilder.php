<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Monthly dunning recovery: annualized $ touched, recovered, and lost.
 */
class FinanceDunningRecoveryChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'dunning_recovery';
  protected const WEIGHT = 70;
  protected const TIER = 'supplemental';

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
    $trend = $this->financialDataService->getDunningRecoveryTrend(12);
    if (empty($trend['labels'])) {
      return NULL;
    }
    if (array_sum($trend['total_touched']) <= 0) {
      return NULL;
    }

    $datasets = [
      [
        'label' => (string) $this->t('Positive outreach outcome'),
        'data' => $trend['recovered'],
        'backgroundColor' => 'rgba(22, 163, 74, 0.75)',
        'borderColor' => '#16a34a',
        'stack' => 'dunning',
      ],
      [
        'label' => (string) $this->t('In flight'),
        'data' => $trend['in_flight'],
        'backgroundColor' => 'rgba(148, 163, 184, 0.65)',
        'borderColor' => '#94a3b8',
        'stack' => 'dunning',
      ],
      [
        'label' => (string) $this->t('Confirmed cancellation'),
        'data' => $trend['lost'],
        'backgroundColor' => 'rgba(220, 38, 38, 0.75)',
        'borderColor' => '#dc2626',
        'stack' => 'dunning',
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
          'x' => ['stacked' => TRUE],
          'y' => [
            'stacked' => TRUE,
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
      (string) $this->t('Payment outreach outcomes: associated annualized dues'),
      (string) $this->t('Current recorded monthly dues × 12 for members contacted each month, grouped by outreach outcome. Positive outcomes include payment updated, will return, and no action needed; they do not establish cash collected or revenue saved.'),
      $visualization,
      [
        (string) $this->t('Source: member outreach records and current recorded monthly dues, annualized (× 12). Historical bars can change when recorded dues change.'),
        (string) $this->t('Processing: Each member appears once per month. Confirmed cancellation takes priority over a positive outcome, followed by unresolved contacts. A member can appear in several months; do not sum the bars as unique savings.'),
        (string) $this->t('Note: For per-channel and resolution-rate breakdowns of the same activity, see the intervention charts in the Retention section.'),
      ],
    );
  }

}
