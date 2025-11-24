<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Highlights tour participation before members join.
 */
class EducationPreJoinToursChartBuilder extends EducationPreJoinEventsChartBuilderBase {

  protected const SECTION_ID = 'outreach';
  protected const CHART_ID = 'prejoin_tours';
  protected const WEIGHT = 75;
  protected const TIER = 'supplemental';

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $selection = $this->resolvePreJoinDataset($filters, static function (?array $data): bool {
      return !empty($data['tour_summary']['with_tour']) || !empty($data['tour_summary']['without_tour']);
    });
    if (!$selection) {
      return NULL;
    }

    $data = $selection['data'];
    $window = $selection['window'];
    $summary = $data['tour_summary'] ?? [];
    $withTour = (int) ($summary['with_tour'] ?? 0);
    $withoutTour = (int) ($summary['without_tour'] ?? 0);
    $total = $withTour + $withoutTour;
    if ($total <= 0) {
      return NULL;
    }

    $labels = [
      (string) $this->t('Completed a tour before joining'),
      (string) $this->t('No tour recorded before joining'),
    ];
    $values = [$withTour, $withoutTour];

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'doughnut',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'data' => $values,
          'backgroundColor' => ['#2563eb', '#d1d5db'],
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('value_format', [
                'format' => 'integer',
                'suffix' => ' ' . (string) $this->t('members'),
              ]),
              'afterLabel' => $this->chartCallback('dataset_share_percent', [
                'decimals' => 1,
              ]),
            ],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Profiles created between @start and @end', [
        '@start' => $window['start']->format('M Y'),
        '@end' => $window['end']->format('M Y'),
      ]),
      (string) $this->t('Total members included: @count', ['@count' => number_format($total)]),
      (string) $this->t('Tours are detected by CiviCRM event types whose label contains “tour” and only counted when the tour occurred before the member’s join date.'),
    ];
    if (($selection['months'] ?? 0) !== ($selection['selected_months'] ?? 0)) {
      $notes[] = (string) $this->t('Expanded to a @months-month window due to limited data in the selected range.', [
        '@months' => $selection['months'],
      ]);
    }

    return $this->newDefinition(
      (string) $this->t('Tours Completed Before Joining'),
      (string) $this->t('Shows the share of new members who completed a tour before joining within the selected window.'),
      $visualization,
      $notes,
      $selection['range_metadata'],
    );
  }

}
