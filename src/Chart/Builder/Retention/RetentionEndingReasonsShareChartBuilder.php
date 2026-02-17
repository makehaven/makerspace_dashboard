<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\RetentionFlowDataService;

/**
 * Builds a 100% stacked ending memberships by reason chart.
 */
class RetentionEndingReasonsShareChartBuilder extends RetentionEndingReasonsChartBuilder {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'ending_reasons_share';
  protected const WEIGHT = 71;
  protected const TIER = 'supplemental';

  public function __construct(RetentionFlowDataService $flowDataService, DateFormatterInterface $dateFormatter, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($flowDataService, $dateFormatter, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $window = $this->getWindowData();
    $endingByReason = $window['ending_by_reason'] ?? [];
    $periodKeys = $window['period_keys'] ?? [];
    if (empty($endingByReason) || !$periodKeys) {
      return NULL;
    }

    $recentPeriods = array_slice($periodKeys, -6);
    if (!$recentPeriods) {
      return NULL;
    }

    $labels = $this->formatMonthLabels($recentPeriods);
    $reasonTotals = $window['ending_reason_totals'] ?? [];
    $reasonOrder = $this->buildReasonOrder($endingByReason, $reasonTotals);
    $reasonLabels = $this->buildReasonLabels($reasonOrder);

    $periodTotals = [];
    foreach ($recentPeriods as $period) {
      $total = 0;
      foreach ($reasonOrder as $reason) {
        $total += (int) ($endingByReason[$reason][$period] ?? 0);
      }
      $periodTotals[$period] = max(1, $total);
    }

    $colors = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#3b82f6', '#6366f1', '#a855f7', '#ec4899', '#0d9488'];
    $datasets = [];
    foreach ($reasonOrder as $index => $reason) {
      $series = [];
      $rawCounts = [];
      foreach ($recentPeriods as $period) {
        $count = (int) ($endingByReason[$reason][$period] ?? 0);
        $rawCounts[] = $count;
        $series[] = round(($count / $periodTotals[$period]) * 100, 1);
      }
      $datasets[] = [
        'label' => $reasonLabels[$reason],
        'data' => $series,
        'rawCounts' => $rawCounts,
        'backgroundColor' => $colors[$index % count($colors)],
        'stack' => 'reasons_share',
      ];
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'plugins' => [
          'legend' => ['position' => 'top'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'percent',
                'decimals' => 1,
                'showLabel' => TRUE,
              ]),
              'afterLabel' => $this->chartCallback('dataset_members_count', []),
            ],
          ],
        ],
        'scales' => [
          'x' => ['stacked' => TRUE],
          'y' => [
            'stacked' => TRUE,
            'min' => 0,
            'max' => 100,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Share of monthly endings'),
            ],
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Ending Memberships by Reason (100% Stacked)'),
      (string) $this->t('Each month sums to 100% to compare reason share even when total churn volume changes.'),
      $visualization,
      [
        (string) $this->t('Source: Membership end-date events joined to field_member_end_reason values.'),
        (string) $this->t('Processing: Distinct members per reason per month converted to percent-of-month totals.'),
        (string) $this->t('Tooltips show percentage and raw member counts.'),
        $this->buildReasonKeyLegendNote($reasonOrder, $reasonLabels),
      ],
    );
  }

}
