<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Monthly MRR change waterfall: dollars added by joins vs. lost to ends.
 */
class FinanceMrrWaterfallChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'mrr_waterfall';
  protected const WEIGHT = 11;

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
    $trend = $this->financialDataService->getMrrWaterfallTrend(12);
    if (empty($trend['labels'])) {
      return NULL;
    }
    $reactivated = $trend['reactivated'] ?? array_fill(0, count($trend['labels']), 0.0);
    if (array_sum($trend['joined']) <= 0 && array_sum($reactivated) <= 0 && array_sum($trend['ended']) <= 0) {
      return NULL;
    }

    $endedNegative = array_map(static fn($v) => -1 * (float) $v, $trend['ended']);

    $datasets = [
      [
        'type' => 'bar',
        'label' => (string) $this->t('+ MRR added (new joins)'),
        'data' => $trend['joined'],
        'backgroundColor' => 'rgba(22, 163, 74, 0.75)',
        'borderColor' => '#16a34a',
        'stack' => 'mrr_change',
        'order' => 2,
      ],
      [
        'type' => 'bar',
        'label' => (string) $this->t('+ MRR added (reactivations)'),
        'data' => $reactivated,
        'backgroundColor' => 'rgba(101, 163, 13, 0.75)',
        'borderColor' => '#65a30d',
        'stack' => 'mrr_change',
        'order' => 2,
      ],
      [
        'type' => 'bar',
        'label' => (string) $this->t('− MRR lost (ends)'),
        'data' => $endedNegative,
        'backgroundColor' => 'rgba(220, 38, 38, 0.75)',
        'borderColor' => '#dc2626',
        'stack' => 'mrr_change',
        'order' => 2,
      ],
      [
        'type' => 'line',
        'label' => (string) $this->t('Net change'),
        'data' => $trend['net'],
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

    $atRisk = (float) ($trend['at_risk_today'] ?? 0.0);
    $notes = [
      (string) $this->t('Source: profile first-join-date (COALESCE field_member_join_date with profile.created), reactivation date (field_member_reactivation_date), and end_date (field_member_end_date), each paired with the per-member field_member_payment_monthly_value. Restricted to members with a non-empty Chargebee plan so the chart matches Chargebee\'s MRR scope (excludes comps, founders, manually-billed members).'),
      (string) $this->t('Processing: 12 trailing months. New joins and reactivations both add MRR; ends remove MRR. Net = joins + reactivations − ends.'),
      (string) $this->t('Known blind spot: Plan upgrades/downgrades, pauses, resumes, and mid-tenure price changes still aren\'t visible here — they require monthly MRR snapshots. The makerspace_snapshot module captures these (ms_fact_revenue_snapshot table) but the historical record is mostly seed values until the cron writes a few months of real data.'),
    ];
    if ($atRisk > 0) {
      $notes[] = (string) $this->t('Currently at risk: $@amount in monthly dues from active members with failed payments (today\'s snapshot — not plotted on the chart). See the Retention section\'s intervention charts for recovery details.', [
        '@amount' => number_format($atRisk, 0),
      ]);
    }

    return $this->newDefinition(
      (string) $this->t('MRR change waterfall'),
      (string) $this->t('Monthly recurring revenue gained from new members vs. lost to ended memberships, with net change line. Predictive companion to the MRR trend chart above.'),
      $visualization,
      $notes,
    );
  }

}
