<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Base class for multi-series cohort retention charts.
 */
abstract class RetentionSegmentedAnnualRetentionChartBuilderBase extends RetentionCohortChartBuilderBase {

  /**
   * Default color palette for datasets.
   */
  protected array $palette = [
    '#2563eb',
    '#16a34a',
    '#dc2626',
    '#9333ea',
    '#ea580c',
    '#0d9488',
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct(MembershipMetricsService $membershipMetrics, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($membershipMetrics, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $baseCohorts = $this->getCohorts();
    if (empty($baseCohorts)) {
      return NULL;
    }

    $years = array_map(static fn(array $row) => (int) $row['year'], $baseCohorts);
    if (empty($years)) {
      return NULL;
    }

    $labels = array_map('strval', $years);
    $startYear = (int) min($years);
    $endYear = (int) max($years);
    $dimension = $this->getDimension();
    $options = $this->membershipMetrics->getCohortFilterOptions($dimension, $this->getSeriesLimit());
    if (empty($options)) {
      return NULL;
    }

    $datasets = [];
    foreach ($options as $index => $option) {
      $filter = [
        'type' => $dimension,
        'value' => $option['value'],
      ];
      $cohortRows = $this->membershipMetrics->getAnnualCohorts($startYear, $endYear, $filter);
      if (empty($cohortRows)) {
        continue;
      }
      $series = $this->mapSeriesToYears($years, $cohortRows);
      if (!$this->hasRenderableData($series)) {
        continue;
      }
      $color = $this->palette[$index % count($this->palette)];
      $datasets[] = $this->buildDataset($option['label'], $series, $color);
    }

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
        'interaction' => [
          'intersect' => FALSE,
        ],
        'scales' => [
          'y' => [
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      $this->getChartTitle(),
      $this->getChartDescription(),
      $visualization,
      $this->getNotes(),
    );
  }

  /**
   * Returns the segment dimension handled by the chart.
   */
  abstract protected function getDimension(): string;

  /**
   * Chart title string.
   */
  abstract protected function getChartTitle(): string;

  /**
   * Chart description string.
   */
  abstract protected function getChartDescription(): string;

  /**
   * Additional notes/sources.
   */
  protected function getNotes(): array {
    return [
      (string) $this->t('Source: Member profile demographics joined to the cohort dataset defined by profile__field_member_join_date.'),
      (string) $this->t('Processing: Same annualized retention calculation as the overall cohort chart, filtered to the selected demographic group.'),
    ];
  }

  /**
   * Number of series to display.
   */
  protected function getSeriesLimit(): int {
    return 5;
  }

  /**
   * Maps cohort rows to the requested year order.
   */
  protected function mapSeriesToYears(array $years, array $cohortRows): array {
    $lookup = [];
    foreach ($cohortRows as $row) {
      $lookup[(int) $row['year']] = isset($row['annualized_retention_percent']) ? round((float) $row['annualized_retention_percent'], 2) : NULL;
    }
    $series = [];
    foreach ($years as $year) {
      $series[] = $lookup[$year] ?? NULL;
    }
    return $series;
  }

  /**
   * Determines if a dataset has at least one data point.
   */
  protected function hasRenderableData(array $series): bool {
    foreach ($series as $value) {
      if ($value !== NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Builds a dataset definition for chart.js.
   */
  protected function buildDataset(string $label, array $series, string $color): array {
    return [
      'label' => $label,
      'data' => $series,
      'borderColor' => $color,
      'backgroundColor' => $color,
      'borderWidth' => 2,
      'pointRadius' => 3,
      'tension' => 0.25,
      'fill' => FALSE,
    ];
  }

}

