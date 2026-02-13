<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Entrepreneurship;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Shows a full breakdown of current members by selected profile goals.
 */
class EntrepreneurshipMemberGoalBreakdownChartBuilder extends EntrepreneurshipChartBuilderBase {

  protected const CHART_ID = 'member_goal_breakdown';
  protected const WEIGHT = 36;
  protected const TIER = 'supplemental';

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $distribution = $this->entrepreneurshipData->getActiveMemberGoalDistribution();
    $goalCounts = $distribution['goal_counts'] ?? [];
    if (empty($goalCounts)) {
      return NULL;
    }

    $knownLabels = [
      'entrepreneur' => (string) $this->t('Business entrepreneurship'),
      'seller' => (string) $this->t('Produce products/art to sell'),
      'inventor' => (string) $this->t('Develop a prototype/product'),
      'skill_builder' => (string) $this->t('Learn new skills'),
      'hobbyist' => (string) $this->t('Personal hobby projects'),
      'artist' => (string) $this->t('Work on my art'),
      'networker' => (string) $this->t('Meet other makers'),
      '_none' => (string) $this->t('No goal selected'),
    ];

    $labels = [];
    $values = [];
    foreach ($goalCounts as $goalKey => $count) {
      $normalizedKey = (string) $goalKey;
      $label = $knownLabels[$normalizedKey] ?? ucwords(str_replace('_', ' ', $normalizedKey));
      $labels[] = $label;
      $values[] = (int) $count;
    }

    if (!$values || max($values) <= 0) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => $values,
          'backgroundColor' => '#10b981',
          'borderRadius' => 6,
          'maxBarThickness' => 28,
        ]],
      ],
      'options' => [
        'responsive' => TRUE,
        'indexAxis' => 'y',
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', ['format' => 'integer']),
            ],
          ],
        ],
        'scales' => [
          'x' => [
            'ticks' => ['precision' => 0],
          ],
        ],
      ],
    ];

    $activeMembers = (int) ($distribution['active_members'] ?? 0);
    $notes = [
      (string) $this->t('Source: active members with profile goal selections from the main member profile.'),
      (string) $this->t('Processing: counts distinct members per goal selection; one member can appear in multiple goal bars.'),
    ];
    if ($activeMembers > 0) {
      $notes[] = (string) $this->t('Active member baseline: @count.', ['@count' => number_format($activeMembers)]);
    }

    return $this->newDefinition(
      (string) $this->t('Member Goals Breakdown'),
      (string) $this->t('Current active members by selected profile goal.'),
      $visualization,
      $notes,
    );
  }

}

