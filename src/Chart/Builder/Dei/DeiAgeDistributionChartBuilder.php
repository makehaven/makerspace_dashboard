<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Dei;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Builds the age distribution line chart.
 */
class DeiAgeDistributionChartBuilder extends DeiChartBuilderBase {

  protected const CHART_ID = 'age_distribution';
  protected const WEIGHT = 40;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $rows = $this->demographicsDataService->getAgeDistribution(13, 100);
    if (empty($rows)) {
      return NULL;
    }

    $labels = array_map(static fn(array $row) => (string) $row['label'], $rows);
    $counts = array_map(static fn(array $row) => (int) $row['count'], $rows);
    if (!array_sum($counts)) {
      return NULL;
    }

    $trend = $this->movingAverage($counts, 2);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Members'),
            'data' => $counts,
            'borderColor' => '#f97316',
            'backgroundColor' => 'rgba(249,115,22,0.15)',
            'fill' => FALSE,
            'borderWidth' => 2,
            'pointRadius' => 2,
          ],
          [
            'label' => (string) $this->t('Trend (5-point MA)'),
            'data' => $trend,
            'borderColor' => '#2563eb',
            'backgroundColor' => 'transparent',
            'fill' => FALSE,
            'borderWidth' => 2,
            'borderDash' => [6, 4],
            'pointRadius' => 0,
          ],
        ],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['position' => 'top'],
        ],
        'elements' => [
          'line' => ['tension' => 0.15],
        ],
        'scales' => [
          'x' => [
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Age'),
            ],
          ],
          'y' => [
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Members'),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Age Distribution of Active Members'),
      (string) $this->t('Counts members by age based on field_member_birthday.'),
      $visualization,
      [
        (string) $this->t('Source: Birthdays recorded on active, default member profiles with valid dates.'),
        (string) $this->t('Processing: Calculates age as of today in the site timezone and filters to ages 13–100.'),
        (string) $this->t('Definitions: Age buckets reflect completed years; records with missing or out-of-range birthdays are excluded. A 5-point moving average (blue dashed line) smooths the curve.'),
      ],
    );
  }

}
