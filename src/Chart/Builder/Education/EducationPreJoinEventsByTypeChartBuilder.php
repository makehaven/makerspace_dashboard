<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Shows how many events members attended before joining, by event type.
 */
class EducationPreJoinEventsByTypeChartBuilder extends EducationPreJoinEventsChartBuilderBase {

  protected const SECTION_ID = 'outreach';
  protected const CHART_ID = 'prejoin_events_type';
  protected const WEIGHT = 70;
  protected const TIER = 'supplemental';

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $selection = $this->resolvePreJoinDataset($filters, static function (?array $data): bool {
      return !empty($data['event_types']);
    });
    if (!$selection) {
      return NULL;
    }

    $data = $selection['data'];
    $window = $selection['window'];
    $series = $data['event_types'] ?? [];
    $bucketKeys = $data['bucket_keys'] ?? [];
    if (empty($series) || empty($bucketKeys)) {
      return NULL;
    }

    $bucketLabels = $this->buildBucketLabels($bucketKeys);
    $datasets = $this->buildStackedDatasets($series, $bucketLabels, 'prejoin_types');
    if (!$datasets) {
      return NULL;
    }

    $labels = $this->formatSeriesLabels($series);
    $memberTotal = (int) ($data['member_total'] ?? 0);
    $windowNote = $this->t('Profiles created between @start and @end', [
      '@start' => $window['start']->format('M Y'),
      '@end' => $window['end']->format('M Y'),
    ]);
    $notes = [
      (string) $windowNote,
      (string) $this->t('Total members included: @count', ['@count' => number_format($memberTotal)]),
      (string) $this->t('Each bar represents all new members in the window and shows how many events of that type they attended before their join date.'),
      (string) $this->t('Zero events means the member joined without experiencing that event type.'),
      (string) $this->t('Facility tours are tracked separately in the “Tours Completed Before Joining” chart.'),
    ];
    if (($selection['months'] ?? 0) !== ($selection['selected_months'] ?? 0)) {
      $notes[] = (string) $this->t('Expanded to a @months-month window due to limited data in the selected range.', [
        '@months' => $selection['months'],
      ]);
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'indexAxis' => 'y',
        'responsive' => TRUE,
        'maintainAspectRatio' => FALSE,
        'scales' => [
          'x' => [
            'stacked' => TRUE,
            'min' => 0,
            'max' => 100,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Share of new members'),
            ],
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
              ]),
            ],
          ],
          'y' => [
            'stacked' => TRUE,
            'ticks' => [
              'autoSkip' => FALSE,
            ],
          ],
        ],
        'plugins' => [
          'legend' => [
            'position' => 'bottom',
          ],
          'tooltip' => [
            'mode' => 'index',
            'intersect' => FALSE,
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'percent',
                'decimals' => 1,
              ]),
              'afterLabel' => $this->chartCallback('dataset_members_count', []),
              'afterBody' => $this->chartCallback('dataset_total_members', []),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Events Attended Before Joining (by Event Type)'),
      (string) $this->t('Shows how many events new members attended before joining (excluding tours), segmented by event type.'),
      $visualization,
      $notes,
      $selection['range_metadata'],
    );
  }

}
