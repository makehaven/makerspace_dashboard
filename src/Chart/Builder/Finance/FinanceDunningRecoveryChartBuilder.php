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
        'label' => (string) $this->t('Recovered'),
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
        'label' => (string) $this->t('Confirmed lost'),
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
      (string) $this->t('Dunning recovery: $ at risk, recovered, and lost'),
      (string) $this->t('Annualized dollars from members in active payment recovery, by month. Total stack height = total revenue touched by dunning that month; green = saved, red = confirmed cancelled, gray = still in flight at month end.'),
      $visualization,
      [
        (string) $this->t('Source: ms_member_outreach_log joined to per-member field_member_payment_monthly_value, annualized (× 12) to match the "Monthly Revenue at Risk" KPI.'),
        (string) $this->t('Processing: Distinct member per (uid, month). Outcome priority is lost > recovered > in-flight, so a member appears once per month in the highest-priority bucket they hit.'),
        (string) $this->t('Note: For per-channel and resolution-rate breakdowns of the same activity, see the intervention charts in the Retention section.'),
      ],
    );
  }

}
