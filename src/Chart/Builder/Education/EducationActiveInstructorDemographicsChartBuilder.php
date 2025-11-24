<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Summarizes demographics for active instructors.
 */
class EducationActiveInstructorDemographicsChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'active_instructor_demographics';
  protected const WEIGHT = 95;
  protected const TIER = 'supplemental';
  protected const RANGE_OPTIONS = ['3m', '1y', '2y'];
  protected const RANGE_DEFAULT = '1y';
  protected const QUALIFYING_EVENT_TYPES = ['Ticketed Workshop', 'Program'];

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $rangeKey = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $bounds = $this->calculateRangeBounds($rangeKey, new \DateTimeImmutable('now'));
    $start = $bounds['start'] ?? $bounds['end']->modify('-1 year');
    $data = $this->eventsMembershipDataService->getActiveInstructorDemographics($start, $bounds['end'], self::QUALIFYING_EVENT_TYPES);

    $total = (int) ($data['total'] ?? 0);
    if ($total <= 0) {
      return NULL;
    }

    $genderChart = $this->buildPieChart($data['gender_counts'] ?? [], (string) $this->t('Gender'));
    $ethnicityChart = $this->buildPieChart($data['ethnicity_counts'] ?? [], (string) $this->t('Ethnicity'), TRUE);

    $visualization = [
      'type' => 'container',
      'children' => [
        'charts' => [
          'type' => 'container',
          'attributes' => ['class' => ['pie-chart-pair-container']],
          'children' => [
            'gender' => $genderChart,
            'ethnicity' => $ethnicityChart,
          ],
        ],
      ],
    ];

    $rangeMetadata = $this->buildRangeMetadata($rangeKey, self::RANGE_OPTIONS);
    $notes = [
      (string) $this->t('@count instructors taught workshops/programs between @start and @end.', [
        '@count' => number_format($total),
        '@start' => $start->format('M Y'),
        '@end' => $bounds['end']->format('M Y'),
      ]),
      (string) $this->t('Includes Drupal users with the Instructor role linked to CiviCRM events via the instructor field.'),
      (string) $this->t('Demographics pulled from CiviCRM contact gender and demographics custom fields.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Active Instructor Demographics'),
      (string) $this->t('Shows gender and ethnicity distribution for instructors leading workshops or programs during the selected window.'),
      $visualization,
      $notes,
      $rangeMetadata
    );
  }

  /**
   * Builds a pie chart for the provided dataset.
   */
  protected function buildPieChart(array $counts, string $title, bool $allowOther = FALSE): array {
    $dataset = $this->prepareCountDataset($counts, $allowOther);
    $palette = $this->defaultColorPalette();
    $colors = [];
    foreach ($dataset['values'] as $index => $_) {
      $colors[] = $palette[$index % count($palette)];
    }

    return [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'doughnut',
      'data' => [
        'labels' => $dataset['labels'],
        'datasets' => [[
          'data' => $dataset['values'],
          'backgroundColor' => $colors,
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'suffix' => ' ' . (string) $this->t('instructors'),
              ]),
              'afterLabel' => $this->chartCallback('dataset_share_percent', [
                'decimals' => 1,
              ]),
            ],
          ],
          'title' => [
            'display' => TRUE,
            'text' => $title,
          ],
        ],
      ],
    ];
  }

  /**
   * Converts an associative count map into sorted labels/values.
   */
  protected function prepareCountDataset(array $counts, bool $allowOther = FALSE): array {
    if (!$counts) {
      return [
        'labels' => [(string) $this->t('Unspecified')],
        'values' => [0],
      ];
    }

    arsort($counts);
    if ($allowOther && count($counts) > 6) {
      $primary = array_slice($counts, 0, 5, TRUE);
      $other = array_sum(array_slice($counts, 5));
      $counts = $primary + ['Other' => $other];
    }

    return [
      'labels' => array_keys($counts),
      'values' => array_values($counts),
    ];
  }

}
