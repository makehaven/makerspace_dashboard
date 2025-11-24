<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Badge counts grouped by days since join.
 */
class EducationBadgeVolumeChartBuilder extends EducationEngagementChartBuilderBase {

  protected const CHART_ID = 'badge_volume';
  protected const WEIGHT = 130;
  protected const TIER = 'supplemental';

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $snapshot = $this->getSnapshot($filters);
    $volume = $snapshot['badge_volume'] ?? NULL;
    if (!$volume || empty($volume['counts']) || array_sum($volume['counts']) === 0) {
      return NULL;
    }

    $labels = array_map(fn($label) => (string) $this->t($label), $volume['labels'] ?? []);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Badges awarded'),
          'data' => array_map('intval', $volume['counts']),
          'backgroundColor' => '#0d9488',
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Badge Awards by Time Since Join'),
      (string) $this->t('Counts all badges (including orientation) earned within the activation window, grouped by days from join date.'),
      $visualization,
      [
        (string) $this->t('Source: All active badge requests tied to cohort members within the activation window.'),
        (string) $this->t('Processing: For each badge completion, calculates days from join and increments the corresponding bucket (0-3, 4-7, 8-14, 15-30, 31-60, 60+).'),
        (string) $this->t('Definitions: Members can contribute multiple badges across buckets; orientation badges are included for workload context.'),
      ],
    );
  }

}
