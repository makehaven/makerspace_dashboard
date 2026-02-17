<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Daily snapshot trend builder.
 */
class RetentionDailySnapshotChartBuilder extends RetentionSnapshotChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'snapshot_daily';
  protected const WEIGHT = 60;
  protected const TIER = 'supplemental';

  public function __construct(SnapshotDataService $snapshot_data, DateFormatterInterface $date_formatter, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($snapshot_data, $date_formatter, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $snapshots = $this->snapshotData->getMembershipCountSeries('day', FALSE, ['daily']);
    $isDailySeries = !empty($snapshots);
    if (!$isDailySeries) {
      $snapshots = $this->snapshotData->getMembershipCountSeries('month');
    }
    if (empty($snapshots)) {
      return NULL;
    }

    $series = $this->trimSeries($snapshots, 120);
    if (!$series) {
      return NULL;
    }

    $labels = [];
    $values = [];
    foreach ($series as $row) {
      $labels[] = $this->formatDate($row['period_date'], 'M j, Y');
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
          'borderColor' => '#2563eb',
          'backgroundColor' => 'rgba(37,99,235,0.18)',
          'fill' => TRUE,
          'tension' => 0.25,
          'pointRadius' => 3,
          'pointHoverRadius' => 5,
          'borderWidth' => 2,
        ]],
      ],
      'options' => [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'showLabel' => FALSE,
                'suffix' => (string) $this->t('active members'),
              ]),
            ],
          ],
        ],
        'scales' => [
          'x' => [
            'ticks' => [
              'autoSkip' => TRUE,
              'maxRotation' => 0,
              'minRotation' => 0,
              'maxTicksLimit' => 14,
            ],
          ],
          'y' => [
            'ticks' => [
              'precision' => 0,
              'callback' => $this->chartCallback('value_format', [
                'format' => 'integer',
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
      ],
    ];

    $notes = $this->getSnapshotNotes();
    if ($coverage = $this->buildCoverageNote($snapshots)) {
      $notes[] = $coverage;
    }
    if (!$isDailySeries) {
      $notes[] = (string) $this->t('Daily snapshot rows are not currently available; this chart is currently showing the monthly cadence.');
    }

    return $this->newDefinition(
      $isDailySeries
        ? (string) $this->t('Active Members (Daily Snapshots)')
        : (string) $this->t('Active Members (Monthly Snapshot Cadence)'),
      $isDailySeries
        ? (string) $this->t('Latest @count daily snapshots of active membership headcount.', ['@count' => count($series)])
        : (string) $this->t('Latest @count monthly snapshots of active membership headcount.', ['@count' => count($series)]),
      $visualization,
      $notes,
    );
  }

}
