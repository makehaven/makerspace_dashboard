<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Entrepreneurship;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Shows the share of active members pursuing entrepreneurship goals.
 */
class EntrepreneurshipActiveGoalShareChartBuilder extends EntrepreneurshipChartBuilderBase {

  protected const CHART_ID = 'entrepreneur_active_goal_share';
  protected const WEIGHT = 30;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $snapshot = $this->entrepreneurshipData->getActiveEntrepreneurSnapshot();
    $goalCounts = $snapshot['totals']['goal_counts'] ?? [];
    if (empty($goalCounts)) {
      return NULL;
    }

    $goalMap = [
      'entrepreneur' => [
        'label' => $this->t('Business entrepreneurship'),
        'color' => '#ea580c',
      ],
      'seller' => [
        'label' => $this->t('Produce products/art to sell'),
        'color' => '#0ea5e9',
      ],
      'inventor' => [
        'label' => $this->t('Develop a prototype/product'),
        'color' => '#6366f1',
      ],
      'other' => [
        'label' => $this->t('Other goals'),
        'color' => '#94a3b8',
      ],
    ];

    $labels = [];
    $values = [];
    $colors = [];
    foreach ($goalMap as $key => $meta) {
      $count = (int) ($goalCounts[$key] ?? 0);
      $labels[] = (string) $meta['label'];
      $values[] = $count;
      $colors[] = $meta['color'];
    }

    $datasets = [[
      'label' => (string) $this->t('Members'),
      'data' => $values,
      'backgroundColor' => $colors,
      'borderRadius' => 6,
      'maxBarThickness' => 32,
    ]];

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'responsive' => TRUE,
        'indexAxis' => 'y',
        'scales' => [
          'x' => [
            'ticks' => ['precision' => 0],
          ],
        ],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'suffix' => (string) $this->t('members'),
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Active Members with Entrepreneurship-related Goals'),
      (string) $this->t('Counts active members who selected entrepreneurship-oriented goals on their profile.'),
      $visualization,
      [
        (string) $this->t('Source: Active member profiles tagged with membership roles (default: current_member, member).'),
        (string) $this->t('Processing: Members may appear in multiple goal categories when they select more than one option.'),
      ],
    );
  }

}
