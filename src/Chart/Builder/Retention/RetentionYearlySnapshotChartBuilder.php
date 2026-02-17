<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;

/**
 * Year-end snapshot trend builder.
 */
class RetentionYearlySnapshotChartBuilder extends RetentionSnapshotChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'snapshot_yearly';
  protected const WEIGHT = 62;
  protected const TIER = 'supplemental';

  public function __construct(SnapshotDataService $snapshot_data, DateFormatterInterface $date_formatter, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($snapshot_data, $date_formatter, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $snapshots = $this->snapshotData->getMembershipCountSeries('year');
    if (empty($snapshots)) {
      return NULL;
    }

    $series = $this->trimSeries($snapshots, 12);
    if (!$series) {
      return NULL;
    }

    $labels = [];
    $values = [];
    foreach ($series as $row) {
      $labels[] = $this->formatDate($row['period_date'], 'Y');
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
          'borderColor' => '#0f172a',
          'backgroundColor' => 'rgba(15,23,42,0.15)',
          'fill' => TRUE,
          'tension' => 0.15,
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
              'autoSkip' => FALSE,
              'maxRotation' => 0,
              'minRotation' => 0,
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

    $notes = [
      (string) $this->t('Source: makerspace_snapshot membership_totals snapshots joined with ms_fact_org_snapshot (members_active).'),
      (string) $this->t('Processing: Keeps the last snapshot logged in each calendar year to highlight long-term growth.'),
    ];
    if ($coverage = $this->buildCoverageNote($snapshots)) {
      $notes[] = $coverage;
    }

    return $this->newDefinition(
      (string) $this->t('Active Members (Year-End Snapshot Anchor)'),
      (string) $this->t('Uses the latest snapshot per calendar year to show longitudinal membership scale.'),
      $visualization,
      $notes,
    );
  }

}
