<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;
use Drupal\makerspace_dashboard\Service\EventsMembershipDataService;

/**
 * Provides demographic breakdowns without exposing individual identities.
 */
class OutreachSection extends DashboardSectionBase {

  /**
   * Data service for demographic aggregates.
   */
  protected DemographicsDataService $dataService;

  /**
   * Events membership data service.
   */
  protected EventsMembershipDataService $eventsMembershipDataService;

  /**
   * Constructs the section.
   */
  public function __construct(DemographicsDataService $data_service, EventsMembershipDataService $events_membership_data_service) {
    parent::__construct();
    $this->dataService = $data_service;
    $this->eventsMembershipDataService = $events_membership_data_service;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'outreach';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Outreach');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Summarizes member demographics sourced from profile fields today, with hooks to migrate to CiviCRM contact data later.'),
    ];

    $locality_rows = $this->dataService->getLocalityDistribution();
    if (!empty($locality_rows)) {
      $locality_labels = array_map(fn(array $row) => $row['label'], $locality_rows);
      $locality_counts = array_map(fn(array $row) => $row['count'], $locality_rows);

      $build['town_distribution'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Members by town'),
        '#description' => $this->t('Top hometowns for active members; smaller groups are aggregated into “Other”.'),
      ];
      $build['town_distribution']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $locality_counts,
      ];
      $build['town_distribution']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $locality_labels,
      ];
      $build['town_distribution_info'] = $this->buildChartInfo([
        $this->t('Source: Active "main" member profiles joined to the address locality field for users holding active membership roles (defaults: @roles).', ['@roles' => 'current_member, member']),
        $this->t('Processing: Counts distinct members per town and collapses values under the minimum threshold into "Other (< 5)".'),
        $this->t('Definitions: Only published users with a default profile are included; blank addresses appear as "Unknown / not provided".'),
      ]);
    }
    else {
      $build['town_distribution_empty'] = [
        '#markup' => $this->t('No address data available for current members.'),
      ];
    }

    $gender_rows = $this->dataService->getGenderDistribution();
    if (!empty($gender_rows)) {
      $gender_labels = array_map(fn(array $row) => $row['label'], $gender_rows);
      $gender_counts = array_map(fn(array $row) => $row['count'], $gender_rows);

      $filteredLabels = [];
      $filteredCounts = [];
      $excluded = [];
      foreach ($gender_labels as $index => $label) {
        $normalized = mb_strtolower($label);
        if (in_array($normalized, ['not provided', 'prefer not to say'], TRUE)) {
          $excluded[$label] = ($excluded[$label] ?? 0) + ($gender_counts[$index] ?? 0);
          continue;
        }
        $filteredLabels[] = $label;
        $filteredCounts[] = $gender_counts[$index] ?? 0;
      }

      if (empty($filteredCounts)) {
        $filteredLabels = $gender_labels;
        $filteredCounts = $gender_counts;
        $excluded = [];
      }

      $build['gender_mix'] = [
        '#type' => 'chart',
        '#chart_type' => 'pie',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Gender identity mix'),
        '#description' => $this->t('Aggregated from primary member profile gender selections.'),
        '#data_labels' => TRUE,
      ];
      $build['gender_mix']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $filteredCounts,
      ];
      $build['gender_mix']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $filteredLabels,
      ];
      $build['gender_mix']['#raw_options'] = [
        'plugins' => [
          'datalabels' => [
            'color' => '#0f172a',
            'font' => ['weight' => 'bold'],
            'formatter' => "function(value, ctx) { var data = ctx.chart.data.datasets[0].data; var total = data.reduce(function(acc, curr){ return acc + curr; }, 0); if (!total) { return '0%'; } var pct = (value / total) * 100; return pct.toFixed(1) + '%'; }",
          ],
        ],
      ];

      $genderInfoItems = [
        $this->t('Source: Active "main" member profiles mapped to field_member_gender for members with active roles (defaults: @roles).', ['@roles' => 'current_member, member']),
        $this->t('Processing: Distinct member counts per gender value with buckets under five members merged into "Other (< 5)".'),
      ];
      $genderInfoItems[] = $this->t('Definitions: Missing or blank values surface as "Not provided" and are excluded from the chart to highlight the proportional mix.');
      if (!empty($excluded)) {
        $notes = [];
        foreach ($excluded as $label => $count) {
          $notes[] = $this->t('@label: @count', ['@label' => $label, '@count' => $count]);
        }
        $genderInfoItems[] = $this->t('Excluded from chart (shown below for reference): @list', ['@list' => implode(', ', $notes)]);
      }
      $build['gender_mix_info'] = $this->buildChartInfo($genderInfoItems);
    }
    else {
      $build['gender_mix_empty'] = [
        '#markup' => $this->t('No gender data available for current members.'),
      ];
    }

    $interest_rows = $this->dataService->getInterestDistribution();
    if (!empty($interest_rows)) {
      $interest_labels = array_map(fn(array $row) => $row['label'], $interest_rows);
      $interest_counts = array_map(fn(array $row) => $row['count'], $interest_rows);

      $build['interest_distribution'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Member interests'),
        '#description' => $this->t('Top member interests, based on profile selections.'),
      ];
      $build['interest_distribution']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $interest_counts,
      ];
      $build['interest_distribution']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $interest_labels,
      ];
      $build['interest_distribution_info'] = $this->buildChartInfo([
        $this->t('Source: Active "main" member profiles joined to field_member_interest for users with active membership roles (defaults: @roles).', ['@roles' => 'current_member, member']),
        $this->t('Processing: Aggregates distinct members per interest, returns the top ten values, and respects the configured minimum count. Unknowns display as "Not provided".'),
        $this->t('Definitions: Only published accounts with a default profile and status = 1 are considered.'),
      ]);
    }
    else {
      $build['interest_distribution_empty'] = [
        '#markup' => $this->t('No member interest data available.'),
      ];
    }

    $age_rows = $this->dataService->getAgeDistribution(13, 100);
    if (!empty($age_rows)) {
      $age_labels = array_map(fn(array $row) => $row['label'], $age_rows);
      $age_counts = array_map(fn(array $row) => $row['count'], $age_rows);

      $build['age_distribution'] = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Age distribution of active members'),
        '#description' => $this->t('Counts members by age based on field_member_birthday.'),
        '#raw_options' => [
          'scales' => [
            'x' => ['title' => ['display' => TRUE, 'text' => (string) $this->t('Age')]],
            'y' => ['title' => ['display' => TRUE, 'text' => (string) $this->t('Members')]],
          ],
          'elements' => ['line' => ['tension' => 0.15]],
        ],
      ];
      $build['age_distribution']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $age_counts,
      ];
      $build['age_distribution']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $age_labels,
      ];
      $build['age_distribution_info'] = $this->buildChartInfo([
        $this->t('Source: Birthdays recorded on active, default member profiles with valid dates.'),
        $this->t('Processing: Calculates age as of today in the site timezone and filters to ages 13–100.'),
        $this->t('Definitions: Age buckets reflect completed years; records with missing or out-of-range birthdays are excluded.'),
      ]);
    }
    else {
      $build['age_distribution_empty'] = [
        '#markup' => $this->t('No birthday data available to chart ages.'),
      ];
    }

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
        '#labels' => [
          $this->t('Event attendees'),
          $this->t('30-day joins'),
          $this->t('60-day joins'),
          $this->t('90-day joins'),
        ],
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
        '#labels' => $registrations_by_type['months'],
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
        '#labels' => $avg_revenue_by_type['months'],
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
        '#labels' => $capacity_placeholder['months'],
      ];
      $build['workshop_capacity_placeholder_info'] = $this->buildChartInfo([
        $this->t('Placeholder: Replace with actual capacity metrics. Currently showing illustrative values only.'),
        $this->t('Next steps: join CiviCRM or scheduling data to calculate registrations as a share of capacity.'),
        $this->t('Observation: @note', ['@note' => $capacity_placeholder['note']]),
      ]);
    }

    return $build;
  }

}
