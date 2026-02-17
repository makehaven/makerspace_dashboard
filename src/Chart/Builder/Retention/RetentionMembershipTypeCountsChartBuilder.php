<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;

/**
 * Builds the stacked membership type snapshot chart (absolute counts).
 */
class RetentionMembershipTypeCountsChartBuilder extends RetentionMembershipTypeSnapshotChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'snapshot_membership_type_counts';
  protected const WEIGHT = 63;
  protected const TIER = 'supplemental';

  public function __construct(SnapshotDataService $snapshot_data, DateFormatterInterface $date_formatter, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($snapshot_data, $date_formatter, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->getMembershipTypeSnapshots();
    if (empty($series)) {
      return NULL;
    }
    $labels = $this->buildPeriodLabels($series);
    $typeLabels = $this->determineTypeOrder($series);
    if (empty($labels) || empty($typeLabels)) {
      return NULL;
    }

    $datasetMutator = static function (array $dataset): array {
      unset($dataset['maxBarThickness']);
      $dataset['type'] = 'line';
      $dataset['fill'] = 'origin';
      $dataset['tension'] = 0.35;
      $dataset['pointRadius'] = 0;
      $dataset['pointHoverRadius'] = 4;
      $dataset['pointHitRadius'] = 8;
      $dataset['borderWidth'] = 2;
      $dataset['borderColor'] = $dataset['backgroundColor'];
      return $dataset;
    };
    $datasets = $this->buildTypeDatasets(
      $series,
      $typeLabels,
      static fn(int $count, array $row): int => $count,
      FALSE,
      $datasetMutator,
    );
    if (empty($datasets)) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'elements' => [
          'line' => ['fill' => TRUE],
          'point' => ['radius' => 0],
        ],
        'plugins' => [
          'legend' => ['position' => 'top'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'showLabel' => TRUE,
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
              'maxTicksLimit' => 18,
            ],
          ],
          'y' => [
            'stacked' => TRUE,
            'beginAtZero' => TRUE,
            'ticks' => [
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
      (string) $this->t('Source: makerspace_snapshot membership_types snapshots joined with ms_fact_membership_type_snapshot data.'),
      (string) $this->t('Processing: Keeps the most recent membership_types snapshot per calendar month (max @count months) and stacks active counts per membership type.', ['@count' => count($series)]),
    ];
    if ($coverage = $this->buildCoverageNote($series)) {
      $notes[] = $coverage;
    }

    return $this->newDefinition(
      (string) $this->t('Membership Mix by Snapshot'),
      (string) $this->t('Shows how many active members are recorded in each membership type snapshot over time.'),
      $visualization,
      $notes,
    );
  }

}
