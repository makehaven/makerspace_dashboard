<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\EngagementDataService;
use Drupal\makerspace_dashboard\Service\EventsMembershipDataService;

/**
 * Shows early member engagement signals like badges earned and trainings.
 */
class EducationSection extends DashboardSectionBase {

  protected EngagementDataService $dataService;

  protected DateFormatterInterface $dateFormatter;

  protected TimeInterface $time;

  protected EventsMembershipDataService $eventsMembershipDataService;

  public function __construct(EngagementDataService $data_service, DateFormatterInterface $date_formatter, TimeInterface $time, EventsMembershipDataService $events_membership_data_service) {
    parent::__construct();
    $this->dataService = $data_service;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
    $this->eventsMembershipDataService = $events_membership_data_service;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'education';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Education');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];

    $end_date = new \DateTimeImmutable();
    $start_date = $end_date->modify('-1 year');
    $conversion_data = $this->eventsMembershipDataService->getEventToMembershipConversion($start_date, $end_date);
    $time_to_join_data = $this->eventsMembershipDataService->getAverageTimeToJoin($start_date, $end_date);
    $registrations_by_type = $this->eventsMembershipDataService->getMonthlyRegistrationsByType($start_date, $end_date);
    $avg_revenue_by_type = $this->eventsMembershipDataService->getAverageRevenuePerRegistration($start_date, $end_date);
    $capacity_placeholder = $this->eventsMembershipDataService->getSampleCapacitySeries();

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
        '#labels' => array_map('strval', [
          $this->t('Event attendees'),
          $this->t('30-day joins'),
          $this->t('60-day joins'),
          $this->t('90-day joins'),
        ]),
      ];
      $conversionInfo = [
        $this->t('Source: CiviCRM participants (status = Attended) joined to event start dates and Drupal member join dates through civicrm_uf_match.'),
        $this->t('Processing: Counts attendees whose membership start occurs within 30, 60, or 90 days of the event; time window defaults to the most recent year.'),
        $this->t('Definitions: Each participant/event record is counted once even if the contact attends multiple events; members without a join date are excluded from the join buckets.'),
      ];
      $joinTotal = ($conversion_data['joins_30_days'] ?? 0)
        + ($conversion_data['joins_60_days'] ?? 0)
        + ($conversion_data['joins_90_days'] ?? 0);
      if ($joinTotal === 0) {
        $conversionInfo[] = $this->t('Observation: No event attendees in this window converted to memberships within 90 days. Validate join dates or broader time ranges if this seems unexpected.');
      }
      else {
        $conversionInfo[] = $this->t('Observation: @count attendees converted within 90 days of attending an event.', ['@count' => $joinTotal]);
      }
      $build['conversion_funnel_info'] = $this->buildChartInfo($conversionInfo);
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
        '#labels' => array_map('strval', [
          $this->t('Jan'),
          $this->t('Feb'),
          $this->t('Mar'),
          $this->t('Apr'),
          $this->t('May'),
          $this->t('Jun'),
        ]),
      ];
      $build['time_to_join_info'] = $this->buildChartInfo([
        $this->t('Source: Same participant dataset as the conversion funnel with membership join dates from profile__field_member_join_date.'),
        $this->t('Processing: Calculates the average days between an attended event and the member\'s recorded join date, grouped by the month of the event.'),
        $this->t('Definitions: Only participants with a join date contribute to the average; events without follow-on joins plot as zero.'),
      ]);
    }

    if (!empty($registrations_by_type['types'])) {
      $build['registrations_by_type'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Event registrations by type'),
        '#description' => $this->t('Counts counted registrations per month, grouped by event type.'),
        '#stacking' => 1,
      ];
      $colorPalette = ['#2563eb', '#f97316', '#22c55e', '#a855f7', '#eab308', '#14b8a6', '#f43f5e'];
      $paletteIndex = 0;
      foreach ($registrations_by_type['types'] as $type => $counts) {
        $build['registrations_by_type']['series_' . $paletteIndex] = [
          '#type' => 'chart_data',
          '#title' => $type,
          '#data' => $counts,
          '#color' => $colorPalette[$paletteIndex % count($colorPalette)],
        ];
        $paletteIndex++;
      }
      $build['registrations_by_type']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $registrations_by_type['months']),
      ];
      $build['registrations_by_type']['yaxis'] = [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Registrations'),
      ];
      $build['registrations_by_type_info'] = $this->buildChartInfo([
        $this->t('Source: CiviCRM participants joined to events where the participant status “is counted”.'),
        $this->t('Processing: Grouped by event start month and event type; canceled/pending statuses are excluded automatically.'),
        $this->t('Definitions: Event type labels come from the CiviCRM event type option list.'),
      ]);
    }

    if (!empty($avg_revenue_by_type['types'])) {
      $build['revenue_per_registration'] = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Average revenue per registration'),
        '#description' => $this->t('Average paid amount (from CiviCRM contributions) per counted registration, by event type.'),
      ];
      $colorPalette = ['#6366f1', '#0ea5e9', '#ec4899', '#84cc16', '#f59e0b', '#ef4444'];
      $paletteIndex = 0;
      foreach ($avg_revenue_by_type['types'] as $type => $values) {
        $build['revenue_per_registration']['series_' . $paletteIndex] = [
          '#type' => 'chart_data',
          '#title' => $type,
          '#data' => $values,
          '#color' => $colorPalette[$paletteIndex % count($colorPalette)],
        ];
        $paletteIndex++;
      }
      $build['revenue_per_registration']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $avg_revenue_by_type['months']),
      ];
      $build['revenue_per_registration']['yaxis'] = [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Average $ per registration'),
      ];
      $build['revenue_per_registration_info'] = $this->buildChartInfo([
        $this->t('Source: CiviCRM participant payments joined to contributions for counted registrations.'),
        $this->t('Processing: Sums paid contributions per month and divides by the number of counted registrations for each event type.'),
        $this->t('Definitions: Registrations without payments contribute $0; refunded amounts are not excluded presently.'),
      ]);
    }

    if (!empty($capacity_placeholder['data'])) {
      $build['workshop_capacity_placeholder'] = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Workshop capacity utilization (sample)'),
        '#description' => $this->t('Placeholder illustrating capacity tracking. Replace with actual utilization logic.'),
      ];
      $build['workshop_capacity_placeholder']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Utilization %'),
        '#data' => $capacity_placeholder['data'],
      ];
      $build['workshop_capacity_placeholder']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $capacity_placeholder['months']),
      ];
      $build['workshop_capacity_placeholder_info'] = $this->buildChartInfo([
        $this->t('Placeholder: Replace with actual capacity metrics. Currently showing illustrative values only.'),
        $this->t('Next steps: join CiviCRM or scheduling data to calculate registrations as a share of capacity.'),
        $this->t('Observation: @note', ['@note' => $capacity_placeholder['note']]),
      ]);
    }

    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    $range = $this->dataService->getDefaultRange($now);
    $snapshot = $this->dataService->getEngagementSnapshot($range['start'], $range['end']);

    $activationDays = $this->dataService->getActivationWindowDays();
    $cohortStart = $this->dateFormatter->format($range['start']->getTimestamp(), 'custom', 'M j, Y');
    $cohortEnd = $this->dateFormatter->format($range['end']->getTimestamp(), 'custom', 'M j, Y');

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Tracking new members who joined between @start and @end. Activation window: @days days from join date.', [
        '@start' => $cohortStart,
        '@end' => $cohortEnd,
        '@days' => $activationDays,
      ]),
    ];

    $funnel = $snapshot['funnel'];
    if (empty($funnel['totals']['joined'])) {
      $build['empty'] = [
        '#markup' => $this->t('No new members joined within the configured cohort window. Adjust the engagement settings or check recent member activity.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
      ];
      return $build;
    }
    $labels = array_map(fn($label) => $this->t($label), $funnel['labels']);

    $build['badge_funnel'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Badge activation funnel'),
      '#description' => $this->t('Progression of new members through orientation, first badge, and tool-enabled badges.'),
    ];
    $build['badge_funnel']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Members'),
      '#data' => array_map('intval', $funnel['counts']),
    ];
    $build['badge_funnel']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => array_map('strval', $labels),
    ];
    $build['badge_funnel_info'] = $this->buildChartInfo([
      $this->t('Source: Badge request nodes completed within the activation window for members who joined during the cohort range.'),
      $this->t('Processing: Orientation completion is keyed off configured orientation badge term IDs; first/tool-enabled badges use the earliest qualifying badge within the activation window (default 90 days).'),
      $this->t('Definitions: Members without any qualifying badge remain at the "Joined" stage; tool-enabled requires the taxonomy flag field_badge_access_control.'),
    ]);

    $velocity = $snapshot['velocity'];
    $velocityLabels = array_map(fn($label) => $this->t($label), $velocity['labels']);

    $build['engagement_velocity'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Days to first badge'),
      '#description' => $this->t('Distribution of days elapsed from join date to first non-orientation badge.'),
    ];
    $build['engagement_velocity']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Members'),
      '#data' => array_map('intval', $velocity['counts']),
    ];
    $build['engagement_velocity']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => array_map('strval', $velocityLabels),
    ];
    $build['engagement_velocity_info'] = $this->buildChartInfo([
      $this->t('Source: First non-orientation badge timestamps pulled from badge requests for the same cohort used in the funnel chart.'),
      $this->t('Processing: Calculates elapsed days between join date and first badge award, then buckets into ranges (0-3, 4-7, 8-14, 15-30, 31-60, 60+, no badge).'),
      $this->t('Definitions: Members without a qualifying badge fall into the "No badge yet" bucket; orientation-only completions do not count toward the distribution.'),
    ]);

    $badgeVolume = $snapshot['badge_volume'];
    if (!empty($badgeVolume['counts']) && array_sum($badgeVolume['counts']) > 0) {
      $build['badge_volume'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Badge awards by time since join'),
        '#description' => $this->t('Counts all badges (including orientation) earned within the activation window, grouped by days from join date.'),
      ];
      $build['badge_volume']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Badges awarded'),
        '#data' => $badgeVolume['counts'],
      ];
      $build['badge_volume']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map(fn($label) => (string) $this->t($label), $badgeVolume['labels']),
      ];
      $build['badge_volume_info'] = $this->buildChartInfo([
        $this->t('Source: All active badge requests tied to cohort members within the activation window.'),
        $this->t('Processing: For each badge completion, calculates days from join and increments the corresponding bucket (0-3, 4-7, 8-14, 15-30, 31-60, 60+).'),
        $this->t('Definitions: Members can contribute multiple badges across buckets; orientation badges are included for full workload context.'),
      ]);
    }

    $joined = (int) $funnel['totals']['joined'];
    $firstBadge = (int) $funnel['totals']['first_badge'];
    $toolEnabled = (int) $funnel['totals']['tool_enabled'];

    $build['summary'] = [
      '#theme' => 'item_list',
      '#items' => array_filter([
        $this->t('Cohort size: @count members', ['@count' => $joined]),
        $joined ? $this->t('@percent% reach their first badge within @days days', [
          '@percent' => $velocity['cohort_percent'],
          '@days' => $activationDays,
        ]) : NULL,
        $firstBadge ? $this->t('Median days to first badge: @median', ['@median' => $velocity['median']]) : NULL,
        $toolEnabled ? $this->t('@count members earn a tool-enabled badge', ['@count' => $toolEnabled]) : NULL,
      ]),
      '#attributes' => ['class' => ['makerspace-dashboard-summary']],
    ];


    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => ['user_list', 'config:makerspace_dashboard.settings', 'civicrm_participant_list'],
    ];

    return $build;
  }

}
