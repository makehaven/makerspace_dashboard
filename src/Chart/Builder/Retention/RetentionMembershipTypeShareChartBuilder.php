<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;

/**
 * Builds the 100% stacked membership type snapshot chart (share view).
 */
class RetentionMembershipTypeShareChartBuilder extends RetentionMembershipTypeSnapshotChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'snapshot_membership_type_share';
  protected const WEIGHT = 26;

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

    $valueCallback = static function (int $count, array $row): float {
      $total = (int) ($row['members_total'] ?? 0);
      if ($total <= 0) {
        $total = array_sum($row['types'] ?? []);
      }
      $total = max(1, $total);
      return round(($count / $total) * 100, 1);
    };
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
    $datasets = $this->buildTypeDatasets($series, $typeLabels, $valueCallback, TRUE, $datasetMutator);
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
        'plugins' => [
          'legend' => ['position' => 'top'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'percent',
                'decimals' => 1,
                'showLabel' => TRUE,
              ]),
              'afterLabel' => $this->chartCallback('dataset_members_count', []),
            ],
          ],
        ],
        'elements' => [
          'line' => ['fill' => TRUE],
          'point' => ['radius' => 0],
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
            'min' => 0,
            'max' => 100,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Share of active members'),
            ],
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
              ]),
            ],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Source: makerspace_snapshot membership_types snapshots joined with ms_fact_membership_type_snapshot data.'),
      (string) $this->t('Processing: Converts the latest membership_types snapshot in each month into share-of-total percentages so each bar equals 100%.'),
      (string) $this->t('Tooltips list both the percentage share and raw member count per type.'),
    ];
    if ($coverage = $this->buildCoverageNote($series)) {
      $notes[] = $coverage;
    }

    return $this->newDefinition(
      (string) $this->t('Membership Mix Share by Snapshot'),
      (string) $this->t('Shows how the membership mix shifts over time by converting each stacked bar to 100%.'),
      $visualization,
      $notes,
    );
  }

}
