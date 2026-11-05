<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Dei;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Builds the age distribution chart.
 */
class DeiAgeDistributionChartBuilder extends DeiChartBuilderBase {

  protected const CHART_ID = 'age_distribution';
  protected const TIER = 'key';
  protected const WEIGHT = 50;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $rows = $this->demographicsDataService->getAgeDistribution(13, 100);
    if (empty($rows)) {
      return NULL;
    }

    $bands = [
      'Under 25' => [0, 24],
      '25–34' => [25, 34],
      '35–44' => [35, 44],
      '45–54' => [45, 54],
      '55–64' => [55, 64],
      '65+' => [65, 200],
    ];
    $counts = array_fill_keys(array_keys($bands), 0);
    foreach ($rows as $row) {
      $age = (int) $row['label'];
      foreach ($bands as $band => [$min, $max]) {
        if ($age >= $min && $age <= $max) {
          $counts[$band] += (int) $row['count'];
          break;
        }
      }
    }
    if (!array_sum($counts)) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => array_keys($counts),
        'datasets' => [
          [
            'label' => (string) $this->t('Members'),
            'data' => array_values($counts),
            'backgroundColor' => '#f97316',
            'borderWidth' => 0,
          ],
        ],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
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
            'ticks' => ['precision' => 0],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Age Distribution of Active Members'),
      (string) $this->t('Active members by age band.'),
      $visualization,
      [
        (string) $this->t('Source: Birthdays recorded on active, default member profiles with valid dates.'),
        (string) $this->t('Processing: Calculates age as of today in the site timezone and filters to ages 13–100.'),
        (string) $this->t('Definitions: Ages are completed years; records with missing or out-of-range birthdays are excluded. Ages are shown in bands, not single years, so that no one stands out and no child’s exact age is published.'),
      ],
    );
  }

}
