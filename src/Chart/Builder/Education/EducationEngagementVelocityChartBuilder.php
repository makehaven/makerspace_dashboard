<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Distribution of days to first badge for cohort members.
 */
class EducationEngagementVelocityChartBuilder extends EducationEngagementChartBuilderBase {

  protected const CHART_ID = 'engagement_velocity';
  protected const WEIGHT = 120;
  protected const TIER = 'key';

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $snapshot = $this->getSnapshot($filters);
    $velocity = $snapshot['velocity'] ?? NULL;
    if (!$velocity || empty($velocity['counts']) || array_sum($velocity['counts']) === 0) {
      return NULL;
    }

    $labels = array_map(fn($label) => (string) $this->t($label), $velocity['labels'] ?? []);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => array_map('intval', $velocity['counts']),
          'backgroundColor' => '#0284c7',
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Days to First Badge'),
      (string) $this->t('Distribution of days elapsed from join date to first non-orientation badge.'),
      $visualization,
      [
        (string) $this->t('Source: First non-orientation badge timestamps pulled from badge requests for the same cohort used in the funnel chart.'),
        (string) $this->t('Processing: Calculates elapsed days between join date and first badge award, then buckets into ranges (0-3, 4-7, 8-14, 15-30, 31-60, 60+, no badge).'),
        (string) $this->t('Definitions: Members without a qualifying badge fall into the "No badge yet" bucket; orientation-only completions do not count toward the distribution.'),
      ],
    );
  }

}
