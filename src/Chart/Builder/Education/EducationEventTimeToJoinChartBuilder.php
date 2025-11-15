<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Displays the average lag between attending an event and joining.
 */
class EducationEventTimeToJoinChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'time_to_join';
  protected const WEIGHT = 20;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $window = $this->buildRollingWindow();
    $series = $this->eventsMembershipDataService->getAverageTimeToJoin($window['start'], $window['end']);
    if (empty($series['labels']) || empty($series['values'])) {
      return NULL;
    }

    $labels = array_map(static function (string $key): string {
      try {
        return (new \DateTimeImmutable($key . '-01'))->format('M Y');
      }
      catch (\Exception $e) {
        return $key;
      }
    }, $series['labels']);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Average days'),
          'data' => array_map('floatval', $series['values']),
          'borderColor' => '#0ea5e9',
          'backgroundColor' => 'rgba(14,165,233,0.2)',
          'fill' => FALSE,
          'tension' => 0.2,
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
        ],
        'scales' => [
          'y' => [
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Days from event to join'),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Average Days from Event to Membership'),
      (string) $this->t('Visualizes the rolling average time it takes a counted participant to activate a membership.'),
      $visualization,
      [
        (string) $this->t('Source: Same participant dataset as the conversion funnel with membership join dates from profile__field_member_join_date.'),
        (string) $this->t('Processing: Calculates the average days between an attended event and the member\'s recorded join date, grouped by event month.'),
        (string) $this->t('Definitions: Only participants with a join date contribute to the average; events without downstream joins plot as zero.'),
      ],
    );
  }

}
