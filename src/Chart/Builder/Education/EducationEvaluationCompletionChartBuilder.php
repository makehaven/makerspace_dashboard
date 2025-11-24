<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Compares registrants versus submitted evaluations.
 */
class EducationEvaluationCompletionChartBuilder extends EducationEvaluationChartBuilderBase {

  protected const CHART_ID = 'event_evaluation_completion';
  protected const WEIGHT = 27;
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

    $series = $this->evaluationDataService->getEvaluationCompletionSeries($bounds['start'], $bounds['end']);
    if (empty(array_filter($series['registrations'] ?? [])) && empty(array_filter($series['evaluations'] ?? []))) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $series['labels'],
        'datasets' => [
          [
            'type' => 'bar',
            'label' => (string) $this->t('Registrations'),
            'data' => $series['registrations'],
            'backgroundColor' => 'rgba(59,130,246,0.4)',
            'borderColor' => '#3b82f6',
            'borderWidth' => 1,
            'yAxisID' => 'yCounts',
          ],
          [
            'type' => 'bar',
            'label' => (string) $this->t('Evaluations submitted'),
            'data' => $series['evaluations'],
            'backgroundColor' => 'rgba(16,185,129,0.4)',
            'borderColor' => '#10b981',
            'borderWidth' => 1,
            'yAxisID' => 'yCounts',
          ],
          [
            'type' => 'line',
            'label' => (string) $this->t('Completion %'),
            'data' => $series['completion_rates'],
            'borderColor' => '#f97316',
            'backgroundColor' => 'rgba(249,115,22,0.15)',
            'fill' => FALSE,
            'tension' => 0.25,
            'yAxisID' => 'yRate',
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
                  'yRate' => [
                    'format' => 'percent',
                    'decimals' => 1,
                  ],
                ],
              ]),
            ],
          ],
        ],
        'scales' => [
          'yCounts' => [
            'position' => 'left',
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('People'),
            ],
          ],
          'yRate' => [
            'position' => 'right',
            'grid' => ['drawOnChartArea' => FALSE],
            'min' => 0,
            'max' => 100,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Evaluation completion %'),
            ],
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
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
      (string) $this->t('Source: CiviCRM counted participants compared against submitted event feedback forms with an event ID.'),
      (string) $this->t('Processing: Evaluations inherit the event month when an event ID is provided; otherwise they use submission month. Completion rate = evaluations ÷ registrations.'),
    ];
    if (!empty($series['totals'])) {
      $notes[] = (string) $this->t('Overall: @rate% (@eval / @reg) evaluations received.', [
        '@rate' => $series['totals']['rate'],
        '@eval' => $series['totals']['evaluations'],
        '@reg' => $series['totals']['registrations'],
      ]);
    }

    return $this->newDefinition(
      (string) $this->t('Evaluation Completion Rate'),
      (string) $this->t('Compares monthly registrations vs. completed event evaluations.'),
      $visualization,
      $notes,
      $this->buildRangeMetadata($activeRange, self::RANGE_OPTIONS),
    );
  }

}
