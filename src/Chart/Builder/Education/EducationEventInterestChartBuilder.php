<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Highlights top event interests and their economics.
 */
class EducationEventInterestChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'interest_breakdown';
  protected const WEIGHT = 50;

  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['1m', '3m', '1y', '2y', 'all'];

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $bounds = $this->calculateRangeBounds($activeRange, $this->now());
    $interests = $this->eventsMembershipDataService->getEventInterestBreakdown($bounds['start'], $bounds['end'], 8);
    if (empty($interests['items'])) {
      return NULL;
    }

    $labels = array_map(static fn(array $row) => (string) $row['interest'], $interests['items']);
    $eventCounts = array_map(static fn(array $row) => (int) $row['events'], $interests['items']);
    $avgTickets = array_map(static fn(array $row) => (float) $row['avg_ticket'], $interests['items']);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Events'),
            'data' => $eventCounts,
            'backgroundColor' => 'rgba(16,185,129,0.4)',
            'borderColor' => '#10b981',
            'borderWidth' => 1,
            'yAxisID' => 'y',
          ],
          [
            'type' => 'line',
            'label' => (string) $this->t('Average ticket ($)'),
            'data' => $avgTickets,
            'borderColor' => '#6366f1',
            'backgroundColor' => 'rgba(99,102,241,0.2)',
            'fill' => FALSE,
            'tension' => 0.25,
            'yAxisID' => 'y1',
          ],
        ],
      ],
      'options' => [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'perAxis' => [
                  'y1' => [
                    'format' => 'currency',
                    'currency' => 'USD',
                    'decimals' => 2,
                  ],
                ],
              ]),
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'title' => ['display' => TRUE, 'text' => (string) $this->t('Events')],
            'ticks' => ['precision' => 0],
          ],
          'y1' => [
            'position' => 'right',
            'title' => ['display' => TRUE, 'text' => (string) $this->t('Average ticket ($)')],
            'grid' => ['drawOnChartArea' => FALSE],
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Top Event Interests'),
      (string) $this->t('Highlights the busiest event interest areas plus their average ticket price.'),
      $visualization,
      [
        (string) $this->t('Source: Event taxonomy field_civi_event_area_interest terms grouped at the top-level category.'),
        (string) $this->t('Processing: Counts events and associated registrations, calculating the average paid ticket for each interest.'),
        (string) $this->t('Definitions: Limited to the top 8 interests by event count; additional categories roll off this chart.'),
      ],
      $this->buildRangeMetadata($activeRange, self::RANGE_OPTIONS),
    );
  }

}
