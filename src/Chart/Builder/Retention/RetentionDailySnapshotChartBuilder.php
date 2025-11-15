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
  protected const WEIGHT = 10;

  public function __construct(SnapshotDataService $snapshot_data, DateFormatterInterface $date_formatter, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($snapshot_data, $date_formatter, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $snapshots = $this->snapshotData->getMembershipCountSeries('day');
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
              'maxTicksLimit' => 14,
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

    $notes = $this->getSnapshotNotes();
    if ($coverage = $this->buildCoverageNote($snapshots)) {
      $notes[] = $coverage;
    }

    return $this->newDefinition(
      (string) $this->t('Active Members (Daily Snapshots)'),
      (string) $this->t('Latest @count snapshots of active membership headcount.', ['@count' => count($series)]),
      $visualization,
      $notes,
    );
  }

}
