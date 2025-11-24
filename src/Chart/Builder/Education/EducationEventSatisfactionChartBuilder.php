<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Displays average satisfaction by event type.
 */
class EducationEventSatisfactionChartBuilder extends EducationEvaluationChartBuilderBase {

  protected const CHART_ID = 'event_satisfaction';
  protected const WEIGHT = 25;
  protected const TIER = 'supplemental';

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $bounds = $this->calculateRangeBounds($activeRange, $this->now());
    if (!$bounds['start']) {
      $bounds['start'] = $bounds['end']->modify('-1 year');
    }

    $rows = $this->evaluationDataService->getSatisfactionByType($bounds['start'], $bounds['end']);
    if (empty($rows)) {
      return NULL;
    }

    $labels = [];
    $averages = [];
    $counts = [];
    foreach ($rows as $row) {
      $labels[] = $row['type'] ?: (string) $this->t('Unspecified');
      $averages[] = round((float) ($row['average'] ?? 0), 2);
      $counts[] = (int) ($row['responses'] ?? 0);
    }

    $dataset = [
      'label' => (string) $this->t('Average rating'),
      'data' => $averages,
      'backgroundColor' => '#0ea5e9',
      'borderRadius' => 6,
      'makerspaceCounts' => $counts,
    ];

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [$dataset],
      ],
      'options' => [
        'indexAxis' => 'y',
        'scales' => [
          'x' => [
            'min' => 0,
            'max' => 5,
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'decimal',
                'decimals' => 1,
              ]),
            ],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Average satisfaction (1-5)'),
            ],
          ],
        ],
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'decimal',
                'decimals' => 2,
                'suffix' => ' / 5',
              ]),
              'afterLabel' => $this->chartCallback('dataset_members_count', []),
            ],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Window: @start – @end', [
        '@start' => $bounds['start']->format('M j, Y'),
        '@end' => $bounds['end']->format('M j, Y'),
      ]),
      (string) $this->t('Source: Event feedback form (/form/webform-1181) satisfaction question.'),
      (string) $this->t('Processing: Calculates the mean of 1-5 ratings for each selected event type; responses without a rating are excluded.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Event Satisfaction by Type'),
      (string) $this->t('Average 5-point satisfaction scores from event evaluations.'),
      $visualization,
      $notes,
      $this->buildRangeMetadata($activeRange, self::RANGE_OPTIONS),
    );
  }

}
