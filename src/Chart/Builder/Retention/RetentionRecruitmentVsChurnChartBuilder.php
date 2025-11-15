<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\RetentionFlowDataService;

/**
 * Builds the recruitment vs churn bar chart.
 */
class RetentionRecruitmentVsChurnChartBuilder extends RetentionFlowChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'net_membership';
  protected const WEIGHT = 40;

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
    $joinedSeries = [];
    $endedSeries = [];
    foreach ($periodKeys as $key) {
      $joinedSeries[] = (int) ($incomingTotals[$key] ?? 0);
      $endedSeries[] = (int) ($endingTotals[$key] ?? 0);
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Joined'),
            'data' => $joinedSeries,
            'backgroundColor' => '#2563eb',
          ],
          [
            'label' => (string) $this->t('Ended'),
            'data' => $endedSeries,
            'backgroundColor' => '#f97316',
          ],
        ],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['position' => 'top'],
          'tooltip' => [
            'callbacks' => [
              'label' => 'function(context){ const value = context.parsed.y ?? context.raw; return context.dataset.label + ": " + value.toLocaleString(); }',
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Monthly Recruitment vs Churn'),
      (string) $this->t('Total members who joined or ended each month (all membership types).'),
      $visualization,
      [
        (string) $this->t('Source: MembershipMetricsService::getFlow aggregates member profile creation timestamps (join dates) and recorded end dates.'),
        (string) $this->t('Processing: Distinct members per month; expands to 24 months when the most recent 12 months have no data.'),
        (string) $this->t('Definitions: "Joined" counts users whose default member profile was created during the month; "Ended" uses field_member_end_date.'),
      ],
    );
  }

}
