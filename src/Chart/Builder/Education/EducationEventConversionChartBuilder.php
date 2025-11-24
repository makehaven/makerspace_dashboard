<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Event attendee to membership conversion funnel.
 */
class EducationEventConversionChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'conversion_funnel';
  protected const WEIGHT = 10;
  protected const TIER = 'key';

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $window = $this->buildRollingWindow();
    $conversion = $this->eventsMembershipDataService->getEventToMembershipConversion($window['start'], $window['end']);
    if (empty(array_filter($conversion))) {
      return NULL;
    }

    $labels = [
      (string) $this->t('Event attendees'),
      (string) $this->t('30-day joins'),
      (string) $this->t('60-day joins'),
      (string) $this->t('90-day joins'),
    ];

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => array_values(array_map('intval', $conversion)),
          'backgroundColor' => ['#1d4ed8', '#0ea5e9', '#38bdf8', '#67e8f9'],
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
        ],
      ],
    ];

    $joinTotal = ($conversion['joins_30_days'] ?? 0)
      + ($conversion['joins_60_days'] ?? 0)
      + ($conversion['joins_90_days'] ?? 0);

    $notes = [
      (string) $this->t('Source: CiviCRM participants (status = Attended) joined to event start dates and Drupal member join dates through civicrm_uf_match.'),
      (string) $this->t('Processing: Counts attendees whose membership start occurs within 30, 60, or 90 days of the event across the most recent year.'),
      (string) $this->t('Definitions: Each participant record counts once—even if the contact attends multiple events; members without a join date are excluded from the join buckets.'),
    ];
    if ($joinTotal === 0) {
      $notes[] = (string) $this->t('Observation: No event attendees in this window converted to memberships within 90 days. Validate join dates or broaden the time range if this seems unexpected.');
    }
    else {
      $notes[] = (string) $this->t('Observation: @count attendees converted within 90 days of attending an event.', ['@count' => $joinTotal]);
    }

    return $this->newDefinition(
      (string) $this->t('Event-to-Membership Conversion'),
      (string) $this->t('Aggregates attendees and shows how many activate a membership within 30/60/90 days.'),
      $visualization,
      $notes,
    );
  }

}
