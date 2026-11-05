<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;

/**
 * Participation as it was recorded each month, overall and by segment.
 *
 * Reads the monthly KPI snapshots rather than recomputing past quarters: there
 * is no roster history, so recomputing divides old door activity by today's
 * members, and past periods read about ten points low.
 */
class RetentionActiveParticipationChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'active_participation_trend';
  protected const TIER = 'key';
  protected const WEIGHT = 40;

  /**
   * First monthly snapshot written at the time by cron.
   *
   * Earlier rows were backfilled by hand in April 2026 with today's roster.
   */
  protected const FIRST_RECORDED_MONTH = '2026-04';

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected SnapshotDataService $snapshotData,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $segments = [
      'kpi_active_participation' => [(string) $this->t('All members'), '#2563eb'],
      'kpi_active_participation_bipoc' => [(string) $this->t('BIPOC members'), '#16a34a'],
      'kpi_active_participation_female_nb' => [(string) $this->t('Female / Non-Binary'), '#7c3aed'],
    ];

    $readings = [];
    foreach (array_keys($segments) as $kpiId) {
      foreach ($this->snapshotData->getKpiMetricSeries($kpiId) as $record) {
        $date = $record['snapshot_date'] ?? NULL;
        if (!$date instanceof \DateTimeImmutable || !is_numeric($record['value'] ?? NULL)) {
          continue;
        }
        $month = $date->format('Y-m');
        if ($month >= self::FIRST_RECORDED_MONTH) {
          $readings[$month][$kpiId] = round((float) $record['value'] * 100, 1);
        }
      }
    }
    if (count($readings) < 2) {
      return NULL;
    }
    ksort($readings);

    $labels = [];
    foreach (array_keys($readings) as $month) {
      $labels[] = (new \DateTimeImmutable($month . '-01'))->format('M Y');
    }
    $datasets = [];
    foreach ($segments as $kpiId => [$label, $color]) {
      $datasets[] = [
        'label' => $label,
        'data' => array_map(static fn(array $row) => $row[$kpiId] ?? NULL, array_values($readings)),
        'borderColor' => $color,
        'backgroundColor' => $color,
        'fill' => FALSE,
        'spanGaps' => TRUE,
        'tension' => 0.25,
        'pointRadius' => 4,
        'borderWidth' => 2,
      ];
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
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'percent',
                'decimals' => 1,
              ]),
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'min' => 0,
            'max' => 100,
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('% who visited in the last 90 days'),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Active Participation Rate'),
      (string) $this->t('Share of members with a door visit in the trailing 90 days, as recorded at the start of each month.'),
      $visualization,
      [
        (string) $this->t('Source: Monthly KPI snapshots of door-access logs.'),
        (string) $this->t('Starts April 2026, the first month recorded at the time. Earlier values were reconstructed with today\'s roster and read low, so they are left out.'),
        (string) $this->t('Definitions: BIPOC = members with non-white racial/ethnic identity; Female/NB = members with female or non-binary gender identity.'),
      ],
    );
  }

}
