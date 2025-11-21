<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Entrepreneurship;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Highlights entrepreneurial experience signals among active members.
 */
class EntrepreneurshipExperienceChartBuilder extends EntrepreneurshipChartBuilderBase {

  protected const CHART_ID = 'entrepreneur_experience';
  protected const WEIGHT = 40;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $snapshot = $this->entrepreneurshipData->getActiveEntrepreneurSnapshot();
    $experience = $snapshot['experience'] ?? [];
    if (empty($experience)) {
      return NULL;
    }

    $labels = [];
    $counts = [];
    foreach ($experience as $row) {
      $labels[] = (string) ($row['label'] ?? '');
      $counts[] = (int) ($row['count'] ?? 0);
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => $counts,
          'backgroundColor' => ['#0ea5e9', '#6366f1'],
          'borderRadius' => 6,
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'suffix' => (string) $this->t('members'),
              ]),
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'ticks' => ['precision' => 0],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Entrepreneurial Experience Signals'),
      (string) $this->t('Counts active members who report launching multiple businesses or pursuing patents.'),
      $visualization,
      [
        (string) $this->t('Source: Multi-select entrepreneurship experience field on member profiles.'),
        (string) $this->t('Processing: Each active member contributes once per selected experience.'),
      ],
    );
  }

}
