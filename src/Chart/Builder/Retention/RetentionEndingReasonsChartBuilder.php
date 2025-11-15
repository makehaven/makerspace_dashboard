<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\RetentionFlowDataService;

/**
 * Builds the ending memberships by reason chart.
 */
class RetentionEndingReasonsChartBuilder extends RetentionFlowChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'ending_reasons';
  protected const WEIGHT = 70;

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
    $reasonOrder = array_keys($endingByReason);
    usort($reasonOrder, function ($a, $b) use ($reasonTotals) {
      return ($reasonTotals[$b] ?? 0) <=> ($reasonTotals[$a] ?? 0);
    });

    $colors = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#3b82f6', '#6366f1', '#a855f7', '#ec4899', '#0d9488'];
    $datasets = [];
    foreach ($reasonOrder as $index => $reason) {
      $series = [];
      foreach ($recentPeriods as $period) {
        $series[] = (int) ($endingByReason[$reason][$period] ?? 0);
      }
      $datasets[] = [
        'label' => ucwords(str_replace('_', ' ', $reason)),
        'data' => $series,
        'backgroundColor' => $colors[$index % count($colors)],
        'stack' => 'reasons',
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
        ],
        'scales' => [
          'x' => ['stacked' => TRUE],
          'y' => [
            'stacked' => TRUE,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Members ending'),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Ending Memberships by Reason'),
      (string) $this->t('Monthly churn totals stacked by recorded end reason (latest 6 months).'),
      $visualization,
      [
        (string) $this->t('Source: Membership end-date events joined to field_member_end_reason values.'),
        (string) $this->t('Processing: Distinct members per reason per month; stacked bars show each reason’s contribution to total churn.'),
        (string) $this->t('Definitions: Reasons reflect list-string options configured on member profiles when ending access.'),
      ],
    );
  }

}
