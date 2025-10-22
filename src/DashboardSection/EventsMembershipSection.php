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

    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => ['civicrm_participant_list', 'user_list', 'profile_list'],
    ];

    return $build;
  }

}
