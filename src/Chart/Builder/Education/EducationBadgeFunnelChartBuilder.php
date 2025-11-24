<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Cohort badge progression funnel.
 */
class EducationBadgeFunnelChartBuilder extends EducationEngagementChartBuilderBase {

  protected const CHART_ID = 'badge_funnel';
  protected const WEIGHT = 110;
  protected const TIER = 'key';

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $snapshot = $this->getSnapshot($filters);
    $funnel = $snapshot['funnel'] ?? NULL;
    if (!$funnel || empty($funnel['counts']) || array_sum($funnel['counts']) === 0) {
      return NULL;
    }

    $labels = array_map(fn($label) => (string) $this->t($label), $funnel['labels'] ?? []);
    $counts = array_map('intval', $funnel['counts']);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => $counts,
          'backgroundColor' => ['#1e3a8a', '#0f766e', '#65a30d', '#ea580c'],
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Badge Activation Funnel'),
      (string) $this->t('Progression of new members through orientation, first badge, and tool-enabled badges.'),
      $visualization,
      [
        (string) $this->t('Source: Badge request nodes completed within the activation window for members who joined during the cohort range.'),
        (string) $this->t('Processing: Orientation completion is keyed off configured orientation badge term IDs; first/tool-enabled badges use the earliest qualifying badge within the activation window.'),
        (string) $this->t('Definitions: Members without any qualifying badge remain at the "Joined" stage; tool-enabled requires the taxonomy flag field_badge_access_control.'),
      ],
    );
  }

}
