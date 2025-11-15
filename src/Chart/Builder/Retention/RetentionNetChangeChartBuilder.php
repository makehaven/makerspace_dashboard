<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\RetentionFlowDataService;

/**
 * Builds the joined/ended/net change line chart.
 */
class RetentionNetChangeChartBuilder extends RetentionFlowChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'net_balance';
  protected const WEIGHT = 50;

  public function __construct(RetentionFlowDataService $flowDataService, DateFormatterInterface $dateFormatter, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($flowDataService, $dateFormatter, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $window = $this->getWindowData();
    $periodKeys = $window['period_keys'] ?? [];
    if (!$periodKeys) {
      return NULL;
    }

    $incomingTotals = $window['incoming_totals'] ?? [];
    $endingTotals = $window['ending_totals'] ?? [];
    if (!array_sum($incomingTotals) && !array_sum($endingTotals)) {
      return NULL;
    }

    $labels = $this->formatMonthLabels($periodKeys);
    $joined = [];
    $ended = [];
    $net = [];
    foreach ($periodKeys as $key) {
      $joinedValue = (int) ($incomingTotals[$key] ?? 0);
      $endedValue = (int) ($endingTotals[$key] ?? 0);
      $joined[] = $joinedValue;
      $ended[] = $endedValue;
      $net[] = $joinedValue - $endedValue;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Joined'),
            'data' => $joined,
            'borderColor' => '#2563eb',
            'backgroundColor' => 'rgba(37,99,235,0.15)',
            'fill' => FALSE,
            'borderWidth' => 2,
            'pointRadius' => 3,
            'tension' => 0.2,
          ],
          [
            'label' => (string) $this->t('Ended'),
            'data' => $ended,
            'borderColor' => '#f97316',
            'backgroundColor' => 'rgba(249,115,22,0.15)',
            'fill' => FALSE,
            'borderWidth' => 2,
            'pointRadius' => 3,
            'tension' => 0.2,
          ],
          [
            'label' => (string) $this->t('Net change'),
            'data' => $net,
            'borderColor' => '#0f172a',
            'backgroundColor' => 'rgba(15,23,42,0.1)',
            'fill' => FALSE,
            'borderDash' => [6, 4],
            'borderWidth' => 2,
            'pointRadius' => 0,
            'tension' => 0.25,
          ],
        ],
      ],
      'options' => [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => 'function(context){ const value = context.parsed.y ?? context.raw; return context.dataset.label + ": " + value.toLocaleString(); }',
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'ticks' => [
              'precision' => 0,
              'callback' => 'function(value){ return value.toLocaleString(); }',
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Net Membership Change'),
      (string) $this->t('Joined minus ended members per month with join/end overlays for context.'),
      $visualization,
      [
        (string) $this->t('Source: Derived from monthly totals returned by MembershipMetricsService::getFlow.'),
        (string) $this->t('Processing: Calculates joined minus ended counts for each month and overlays the underlying join/end totals.'),
        (string) $this->t('Definitions: Positive values indicate headcount growth; negative values indicate net attrition.'),
      ],
    );
  }

}
