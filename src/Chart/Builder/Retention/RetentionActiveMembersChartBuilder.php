<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;

/**
 * Combined active member count chart with period toggle (90d / 3y / all).
 *
 * Automatically selects the appropriate granularity per range:
 *   90d  → daily snapshots (up to 90 points)
 *   3y   → monthly snapshots (up to 36 points)
 *   all  → yearly snapshots (up to 12 points)
 */
class RetentionActiveMembersChartBuilder extends RetentionSnapshotChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'active_members';
  protected const WEIGHT = 3;

  /**
   * Constructs the builder.
   */
  public function __construct(
    SnapshotDataService $snapshot_data,
    DateFormatterInterface $date_formatter,
    ?TranslationInterface $stringTranslation = NULL
  ) {
    parent::__construct($snapshot_data, $date_formatter, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $range = $filters[$this->getChartId() . ':range'] ?? $filters['range'] ?? '3y';
    if (!in_array($range, ['90d', '3y', 'all'], TRUE)) {
      $range = '3y';
    }

    [$snapshots, $granularity, $dateFormat, $limit] = match ($range) {
      '90d' => [
        $this->snapshotData->getMembershipCountSeries('day', FALSE, ['daily']),
        'daily',
        'M j',
        90,
      ],
      'all' => [
        $this->snapshotData->getMembershipCountSeries('year'),
        'yearly',
        'Y',
        12,
      ],
      default => [
        $this->snapshotData->getMembershipCountSeries('month'),
        'monthly',
        'M Y',
        36,
      ],
    };

    // Fall back to monthly if daily snapshots are unavailable.
    if (empty($snapshots) && $granularity === 'daily') {
      $snapshots = $this->snapshotData->getMembershipCountSeries('month');
      $granularity = 'monthly';
      $dateFormat = 'M Y';
      $limit = 36;
    }

    if (empty($snapshots)) {
      return NULL;
    }

    $series = $this->trimSeries($snapshots, $limit);
    $labels = [];
    $values = [];
    foreach ($series as $row) {
      $labels[] = $this->formatDate($row['period_date'], $dateFormat);
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
          'backgroundColor' => 'rgba(37,99,235,0.15)',
          'fill' => TRUE,
          'tension' => 0.25,
          'pointRadius' => $granularity === 'daily' ? 0 : 3,
          'pointHoverRadius' => 5,
          'borderWidth' => 2,
        ]],
      ],
      'options' => [
        'responsive' => TRUE,
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
              'maxTicksLimit' => 12,
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
    if ($coverage = $this->buildCoverageNote($series)) {
      $notes[] = $coverage;
    }
    if ($granularity === 'daily' && count($snapshots) !== count($series)) {
      $notes[] = (string) $this->t('Showing the most recent @count of @total daily snapshots.', [
        '@count' => count($series),
        '@total' => count($snapshots),
      ]);
    }

    return $this->newDefinition(
      (string) $this->t('Total Active Members'),
      (string) $this->t('Active membership headcount over time.'),
      $visualization,
      $notes,
      [
        'active' => $range,
        'options' => [
          ['value' => '90d', 'label' => (string) $this->t('90 days')],
          ['value' => '3y', 'label' => (string) $this->t('3 years')],
          ['value' => 'all', 'label' => (string) $this->t('All time')],
        ],
      ],
    );
  }

}
