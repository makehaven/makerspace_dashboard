<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;

/**
 * Monthly snapshot trend builder.
 */
class RetentionMonthlySnapshotChartBuilder extends RetentionSnapshotChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'snapshot_monthly';
  protected const WEIGHT = 20;

  public function __construct(SnapshotDataService $snapshot_data, DateFormatterInterface $date_formatter, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($snapshot_data, $date_formatter, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $snapshots = $this->snapshotData->getMembershipCountSeries('month');
    if (empty($snapshots)) {
      return NULL;
    }

    $series = $this->trimSeries($snapshots, 36);
    if (!$series) {
      return NULL;
    }

    $labels = [];
    $values = [];
    foreach ($series as $row) {
      $labels[] = $this->formatDate($row['period_date'], 'M Y');
      $values[] = (int) $row['members_active'];
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Active members'),
          'data' => $values,
          'borderColor' => '#4338ca',
          'backgroundColor' => 'rgba(67,56,202,0.18)',
          'fill' => TRUE,
          'tension' => 0.25,
          'pointRadius' => 4,
          'pointHoverRadius' => 6,
          'borderWidth' => 2,
        ]],
      ],
      'options' => [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => 'function(context){ const value = context.parsed.y ?? context.raw; return value.toLocaleString() + " active members"; }',
            ],
          ],
        ],
        'scales' => [
          'x' => [
            'ticks' => [
              'autoSkip' => TRUE,
              'maxRotation' => 0,
              'minRotation' => 0,
              'padding' => 8,
              'maxTicksLimit' => 18,
            ],
          ],
          'y' => [
            'ticks' => [
              'precision' => 0,
              'callback' => 'function(value){ return value.toLocaleString(); }',
            ],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Source: makerspace_snapshot membership_totals snapshots joined with ms_fact_org_snapshot (members_active).'),
      (string) $this->t('Processing: Groups snapshots by calendar month and keeps the most recent capture in each month to smooth overlapping runs.'),
    ];
    if ($coverage = $this->buildCoverageNote($snapshots)) {
      $notes[] = $coverage;
    }

    return $this->newDefinition(
      (string) $this->t('Active Members (Monthly Snapshot Anchor)'),
      (string) $this->t('Collapses snapshots to one point per month using the latest capture inside each month (max @count months).', ['@count' => count($series)]),
      $visualization,
      $notes,
    );
  }

}
