<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\EventsMembershipDataService;

/**
 * Connects civicrm event participation with membership conversions.
 */
class EventsMembershipSection extends DashboardSectionBase {

  /**
   * Events and membership data service.
   */
  protected EventsMembershipDataService $eventsMembershipDataService;

  /**
   * Constructs the section.
   *
   * @param \Drupal\makerspace_dashboard\Service\EventsMembershipDataService $events_membership_data_service
   *   The events membership data service.
   */
  public function __construct(EventsMembershipDataService $events_membership_data_service) {
    parent::__construct();
    $this->eventsMembershipDataService = $events_membership_data_service;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'events_membership';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Events ➜ Membership');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Correlate CiviCRM event engagement with subsequent membership starts to inform programming strategy.'),
    ];

    $end_date = new \DateTimeImmutable();
    $start_date = $end_date->modify('-1 year');
    $conversion_data = $this->eventsMembershipDataService->getEventToMembershipConversion($start_date, $end_date);
    $time_to_join_data = $this->eventsMembershipDataService->getAverageTimeToJoin($start_date, $end_date);

    if (!empty(array_filter($conversion_data))) {
      $build['conversion_funnel'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Event-to-membership conversion'),
        '#description' => $this->t('Aggregate attendees by cohort month and show how many activate a membership within 30/60/90 days.'),
      ];

      $build['conversion_funnel']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => array_values($conversion_data),
      ];

      $build['conversion_funnel']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => [
          $this->t('Event attendees'),
          $this->t('30-day joins'),
          $this->t('60-day joins'),
          $this->t('90-day joins'),
        ],
      ];
      $build['conversion_funnel_info'] = $this->buildChartInfo([
        $this->t('Source: CiviCRM participants (status = Attended) joined to event start dates and Drupal member join dates through civicrm_uf_match.'),
        $this->t('Processing: Counts attendees whose membership start occurs within 30, 60, or 90 days of the event; time window defaults to the most recent year.'),
        $this->t('Definitions: Each participant/event record is counted once even if the contact attends multiple events; members without a join date are excluded from the join buckets.'),
      ]);
    }
    else {
      $build['conversion_empty'] = [
        '#markup' => $this->t('Event conversion metrics require CiviCRM event participation data. No activity found for the selected window.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
      ];
    }

    if (!empty(array_filter($time_to_join_data))) {
      $build['time_to_join'] = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Average days from event to membership'),
        '#description' => $this->t('Visualize rolling averages for conversion velocity by program type.'),
      ];

      $build['time_to_join']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Days'),
        '#data' => $time_to_join_data,
      ];

      $build['time_to_join']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => [
          $this->t('Jan'),
          $this->t('Feb'),
          $this->t('Mar'),
          $this->t('Apr'),
          $this->t('May'),
          $this->t('Jun'),
        ],
      ];
      $build['time_to_join_info'] = $this->buildChartInfo([
        $this->t('Source: Same participant dataset as the conversion funnel with membership join dates from profile__field_member_join_date.'),
        $this->t('Processing: Calculates the average days between an attended event and the member\'s recorded join date, grouped by the month of the event.'),
        $this->t('Definitions: Only participants with a join date contribute to the average; events without follow-on joins plot as zero.'),
      ]);
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => ['civicrm_participant_list', 'user_list', 'profile_list'],
    ];

    return $build;
  }

}
