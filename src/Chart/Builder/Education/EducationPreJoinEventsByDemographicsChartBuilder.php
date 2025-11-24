<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Shows pre-join event counts grouped by gender and race.
 */
class EducationPreJoinEventsByDemographicsChartBuilder extends EducationPreJoinEventsChartBuilderBase {

  protected const SECTION_ID = 'outreach';
  protected const CHART_ID = 'prejoin_events_demographics';
  protected const WEIGHT = 72;
  protected const TIER = 'supplemental';

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $selection = $this->resolvePreJoinDataset($filters, static function (?array $data): bool {
      $genderSeries = $data['demographics']['gender'] ?? [];
      $raceSeries = $data['demographics']['race'] ?? [];
      $series = array_merge($genderSeries, $raceSeries);
      foreach ($series as $row) {
        if (($row['members'] ?? 0) > 0) {
          return TRUE;
        }
      }
      return FALSE;
    });
    if (!$selection) {
      return NULL;
    }

    $data = $selection['data'];
    $window = $selection['window'];
    $bucketKeys = $data['bucket_keys'] ?? [];
    $genderSeries = $data['demographics']['gender'] ?? [];
    $raceSeries = $data['demographics']['race'] ?? [];
    $series = array_values(array_filter(array_merge($genderSeries, $raceSeries), static function (array $row): bool {
      return ($row['members'] ?? 0) > 0;
    }));

    if (empty($series) || empty($bucketKeys)) {
      return NULL;
    }

    $bucketLabels = $this->buildBucketLabels($bucketKeys);
    $datasets = $this->buildStackedDatasets($series, $bucketLabels, 'prejoin_demographics');
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
      (string) $this->t('Gender values come from CiviCRM contact records while race groups collapse ethnicity selections into White, BIPOC/Multiracial, or Unspecified.'),
      (string) $this->t('Each bar shows how many total events a member attended before joining, broken out by demographic group.'),
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
      (string) $this->t('Events Attended Before Joining (by Demographics)'),
      (string) $this->t('Compares pre-join event counts across gender and race groups for new members.'),
      $visualization,
      $notes,
      $selection['range_metadata'],
    );
  }

}
