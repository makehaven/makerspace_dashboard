<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Overview;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;

/**
 * Builds the active members monthly snapshot chart.
 */
class OverviewActiveMembersChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'overview';
  protected const CHART_ID = 'snapshot_monthly';
  protected const WEIGHT = 20;

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected SnapshotDataService $snapshotDataService,
    protected DateFormatterInterface $dateFormatter,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->snapshotDataService->getMembershipCountSeries('month');
    if (!$series) {
      return NULL;
    }

    $recent = array_slice($series, -12);
    if (!$recent) {
      return NULL;
    }

    $labels = [];
    $values = [];
    foreach ($recent as $row) {
      $labels[] = $this->dateFormatter->format($row['period_date']->getTimestamp(), 'custom', 'M Y');
      $values[] = (int) $row['members_active'];
    }

    if (!array_filter($values)) {
      return NULL;
    }

    $datasets = [[
      'label' => (string) $this->t('Active members'),
      'data' => $values,
      'borderColor' => '#2563eb',
      'backgroundColor' => 'rgba(37, 99, 235, 0.15)',
      'pointRadius' => 3,
      'pointBackgroundColor' => '#2563eb',
      'fill' => FALSE,
    ]];
    if ($trendDataset = $this->buildTrendDataset($values, (string) $this->t('Trend'))) {
      $datasets[] = $trendDataset;
    }

    $tooltipCallback = 'function(context){ var value = context && context.parsed && context.parsed.y !== undefined ? context.parsed.y : (context && context.yLabel !== undefined ? context.yLabel : (context && context.value !== undefined ? context.value : null)); if (value === null) { return ""; } var label = context.datasetIndex === 0 ? "' . addslashes((string) $this->t('Active members')) . '" : "' . addslashes((string) $this->t('Trend')) . '"; return label + ": " + Number(value).toLocaleString() + " members"; }';

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
              'label' => $tooltipCallback,
            ],
          ],
        ],
        'hover' => [
          'mode' => 'index',
          'intersect' => FALSE,
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Active Members (Monthly Snapshot)'),
      (string) $this->t('Collapses snapshots to one point per month using the latest capture inside each month.'),
      $visualization,
      [
        (string) $this->t('Source: makerspace_snapshot membership_totals snapshots.'),
        (string) $this->t('Processing: Groups snapshots by calendar month and keeps the most recent capture in each month.'),
      ],
    );
  }

}
