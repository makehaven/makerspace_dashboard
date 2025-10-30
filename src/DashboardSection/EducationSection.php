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
    $weight = 0;

    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    $range = $this->dataService->getDefaultRange($now);
    $snapshot = $this->dataService->getEngagementSnapshot($range['start'], $range['end']);

    $activationDays = $this->dataService->getActivationWindowDays();
    $cohortStart = $this->dateFormatter->format($range['start']->getTimestamp(), 'custom', 'M j, Y');
    $cohortEnd = $this->dateFormatter->format($range['end']->getTimestamp(), 'custom', 'M j, Y');

      '@start' => $cohortStart,
      '@end' => $cohortEnd,
      '@days' => $activationDays,
    ]));
    $build['intro']['#weight'] = $weight++;

    $build['kpi_table'] = $this->buildKpiTable();
    $build['kpi_table']['#weight'] = $weight++;

    $build['charts_section_heading'] = [
      '#markup' => '<h2>' . $this->t('Charts') . '</h2>',
      '#weight' => $weight++,
    ];

    $end_date = new \DateTimeImmutable();
    $start_date = $end_date->modify('-1 year');
    $conversion_data = $this->eventsMembershipDataService->getEventToMembershipConversion($start_date, $end_date);
    $time_to_join_data = $this->eventsMembershipDataService->getAverageTimeToJoin($start_date, $end_date);
    $registrations_by_type = $this->eventsMembershipDataService->getMonthlyRegistrationsByType($start_date, $end_date);
    $avg_revenue_by_type = $this->eventsMembershipDataService->getAverageRevenuePerRegistration($start_date, $end_date);
    $capacity_placeholder = $this->eventsMembershipDataService->getSampleCapacitySeries();

    if (!empty(array_filter($conversion_data))) {
      $chart_id = 'conversion_funnel';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
      ];

      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => array_values($conversion_data),
      ];

      $chart['xaxis'] = [
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
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Event-to-Membership Conversion'),
        $this->t('Aggregate attendees by cohort month and show how many activate a membership within 30/60/90 days.'),
        $chart,
        $conversionInfo
      );
      $build[$chart_id]['#weight'] = $weight++;
    }
    else {
      $build['conversion_empty'] = [
        '#markup' => $this->t('Event conversion metrics require CiviCRM event participation data. No activity found for the selected window.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
        '#weight' => $weight++,
      ];
    }

    if (!empty(array_filter($time_to_join_data))) {
      $chart_id = 'time_to_join';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
      ];

      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Days'),
        '#data' => $time_to_join_data,
      ];

      $chart['xaxis'] = [
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
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Average Days from Event to Membership'),
        $this->t('Visualize rolling averages for conversion velocity by program type.'),
        $chart,
        [
          $this->t('Source: Same participant dataset as the conversion funnel with membership join dates from profile__field_member_join_date.'),
          $this->t('Processing: Calculates the average days between an attended event and the member\'s recorded join date, grouped by the month of the event.'),
          $this->t('Definitions: Only participants with a join date contribute to the average; events without follow-on joins plot as zero.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;
    }

    if (!empty($registrations_by_type['types'])) {
      $chart_id = 'registrations_by_type';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#stacking' => 1,
      ];
      $colorPalette = ['#2563eb', '#f97316', '#22c55e', '#a855f7', '#eab308', '#14b8a6', '#f43f5e'];
      $paletteIndex = 0;
      foreach ($registrations_by_type['types'] as $type => $counts) {
        $chart['series_' . $paletteIndex] = [
          '#type' => 'chart_data',
          '#title' => $type,
          '#data' => $counts,
          '#color' => $colorPalette[$paletteIndex % count($colorPalette)],
        ];
        $paletteIndex++;
      }
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $registrations_by_type['months']),
      ];
      $chart['yaxis'] = [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Registrations'),
      ];
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Event Registrations by Type'),
        $this->t('Counts counted registrations per month, grouped by event type.'),
        $chart,
        [
          $this->t('Source: CiviCRM participants joined to events where the participant status “is counted”.'),
          $this->t('Processing: Grouped by event start month and event type; canceled/pending statuses are excluded automatically.'),
          $this->t('Definitions: Event type labels come from the CiviCRM event type option list.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;
    }

    if (!empty($avg_revenue_by_type['types'])) {
      $chart_id = 'revenue_per_registration';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#raw_options' => [
          'options' => [
            'interaction' => ['mode' => 'index', 'intersect' => FALSE],
            'spanGaps' => TRUE,
            'plugins' => [
              'legend' => [
                'position' => 'bottom',
                'labels' => ['usePointStyle' => TRUE],
                'onClick' => 'function(e, legendItem, legend){ const index = legendItem.datasetIndex; const chart = legend.chart; const meta = chart.getDatasetMeta(index); if (meta.hidden === null) { meta.hidden = !chart.data.datasets[index].hidden; } else { meta.hidden = null; } chart.update(); }',
              ],
              'tooltip' => [
                'callbacks' => [
                  'label' => 'function(context){ const value = context.parsed.y ?? context.raw; return context.dataset.label + \': $\' + (value ?? 0).toFixed(2); }',
                ],
              ],
            ],
            'scales' => [
              'y' => [
                'ticks' => [
                  'callback' => 'function(value){ return "$" + Number(value ?? 0).toFixed(2); }',
                ],
              ],
            ],
          ],
        ],
      ];
      $colorPalette = ['#6366f1', '#0ea5e9', '#ec4899', '#84cc16', '#f59e0b', '#ef4444'];
      $paletteIndex = 0;
      foreach ($avg_revenue_by_type['types'] as $type => $values) {
        $chart['series_' . $paletteIndex] = [
          '#type' => 'chart_data',
          '#title' => $type,
          '#data' => $values,
          '#color' => $colorPalette[$paletteIndex % count($colorPalette)],
          '#settings' => [
            'borderColor' => $colorPalette[$paletteIndex % count($colorPalette)],
            'backgroundColor' => 'transparent',
            'fill' => FALSE,
            'tension' => 0.25,
            'borderWidth' => 2,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
          ],
        ];
        $paletteIndex++;
      }
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $avg_revenue_by_type['months']),
      ];
      $chart['yaxis'] = [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Average $ per registration'),
      ];
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Average Revenue per Registration'),
        $this->t('Average paid amount (from CiviCRM contributions) per counted registration, by event type.'),
        $chart,
        [
          $this->t('Source: CiviCRM participant payments joined to contributions for counted registrations.'),
          $this->t('Processing: Sums paid contributions per month and divides by the number of counted registrations for each event type.'),
          $this->t('Definitions: Registrations without payments contribute $0; refunded amounts are not excluded presently. Use the legend to toggle individual event types.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;
    }

    $rangeDefault = '1y';

    // Top areas of interest chart.
    $interestRanges = ['1m', '3m', '1y', '2y', 'all'];
    $interestRange = $this->resolveSelectedRange($filters, 'interest_breakdown', $rangeDefault, $interestRanges);
    $interestBounds = $this->calculateRangeBounds($interestRange, $end_date);
    $interestData = $this->eventsMembershipDataService->getEventInterestBreakdown($interestBounds['start'], $interestBounds['end'], 8);
    if (!empty($interestData['items'])) {
      $interestLabels = array_map(fn(array $row) => $row['interest'], $interestData['items']);
      $interestEventCounts = array_map(fn(array $row) => $row['events'], $interestData['items']);
      $interestAvgTickets = array_map(fn(array $row) => $row['avg_ticket'], $interestData['items']);

      $interestChart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#raw_options' => [
          'options' => [
            'interaction' => ['mode' => 'index', 'intersect' => FALSE],
            'plugins' => [
              'legend' => ['position' => 'bottom'],
              'tooltip' => [
                'callbacks' => [
                  'label' => 'function(context){ if (context.dataset.yAxisID === "y1") { return context.dataset.label + ": $" + Number(context.parsed.y ?? 0).toFixed(2); } return context.dataset.label + ": " + Number(context.parsed.y ?? 0).toLocaleString(); }',
                ],
              ],
            ],
            'scales' => [
              'y' => [
                'title' => ['display' => TRUE, 'text' => (string) $this->t('Events')],
                'ticks' => ['precision' => 0],
              ],
              'y1' => [
                'position' => 'right',
                'title' => ['display' => TRUE, 'text' => (string) $this->t('Average ticket ($)')],
                'grid' => ['drawOnChartArea' => FALSE],
                'ticks' => [
                  'callback' => 'function(value){ return "$" + Number(value ?? 0).toFixed(0); }',
                ],
              ],
            ],
          ],
        ],
      ];
      $interestChart['events'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Events'),
        '#data' => $interestEventCounts,
        '#color' => '#34d399',
        '#settings' => [
          'backgroundColor' => 'rgba(52,211,153,0.4)',
          'borderColor' => '#10b981',
          'borderWidth' => 1,
        ],
      ];
      $interestChart['avg_ticket'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Average ticket'),
        '#data' => $interestAvgTickets,
        '#color' => '#6366f1',
        '#settings' => [
          'type' => 'line',
          'yAxisID' => 'y1',
          'borderColor' => '#6366f1',
          'backgroundColor' => 'rgba(99,102,241,0.2)',
          'fill' => FALSE,
          'tension' => 0.25,
          'borderWidth' => 2,
          'pointRadius' => 3,
          'pointHoverRadius' => 5,
        ],
      ];
      $interestChart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $interestLabels),
      ];
      $interestContent = [
        '#type' => 'container',
        'chart' => $interestChart,
        'info' => $this->buildChartInfo([
          $this->t('Source: CiviCRM events tagged with Areas of Interest (taxonomy) over the selected window.'),
          $this->t('Processing: Events contribute to each top-level interest they are tagged with; average ticket is total contributions divided by counted registrations.'),
          $this->t('Definitions: Registrations include counted participant statuses only; revenue reflects linked participant payments.'),
        ]),
        'summary' => [
          '#theme' => 'item_list',
          '#items' => [
            $this->t('Top interests total events: @count', ['@count' => number_format($interestData['total_events'])]),
            $this->t('Top interests total registrations: @count', ['@count' => number_format($interestData['total_registrations'])]),
          ],
          '#attributes' => ['class' => ['makerspace-dashboard-summary']],
        ],
      ];
      $build['interest_breakdown'] = $this->wrapChartWithRangeControls('interest_breakdown', $this->buildChartContainer(
        'interest_breakdown',
        $this->t('Events by Area of Interest (Top @limit)', ['@limit' => count($interestLabels)]),
        $this->t('Highlights the busiest interest areas by event count, with average ticket revenue as a secondary axis.'),
        $interestContent['chart'],
        $interestContent['info']['list']['#items']
      ), $interestRanges, $interestRange);
      $build['interest_breakdown']['#weight'] = $weight++;
    }
    else {
      $emptyInterest = $this->buildRangeEmptyContent($this->t('No events tagged with areas of interest were found for the selected time range.'));
      $build['interest_breakdown'] = $this->wrapChartWithRangeControls('interest_breakdown', $emptyInterest, $interestRanges, $interestRange);
      $build['interest_breakdown']['#weight'] = $weight++;
    }

    // Skill level split chart.
    $skillRanges = ['3m', '1y', '2y', 'all'];
    $skillRange = $this->resolveSelectedRange($filters, 'skill_levels', $rangeDefault, $skillRanges);
    $skillBounds = $this->calculateRangeBounds($skillRange, $end_date);
    $skillData = $this->eventsMembershipDataService->getSkillLevelBreakdown($skillBounds['start'], $skillBounds['end']);
    if (!empty($skillData['levels'])) {
      $totalSkill = array_sum($skillData['workshop']) + array_sum($skillData['other']);
      if ($totalSkill > 0) {
        $skillChart = [
          '#type' => 'chart',
          '#chart_type' => 'bar',
          '#chart_library' => 'chartjs',
          '#raw_options' => [
            'options' => [
              'plugins' => [
                'legend' => ['position' => 'bottom'],
              ],
              'scales' => [
                'y' => [
                  'ticks' => ['precision' => 0],
                ],
              ],
            ],
          ],
        ];
        $skillChart['workshop'] = [
          '#type' => 'chart_data',
          '#title' => $this->t('Workshops'),
          '#data' => $skillData['workshop'],
          '#color' => '#fb7185',
        ];
        $skillChart['other'] = [
          '#type' => 'chart_data',
          '#title' => $this->t('Other events'),
          '#data' => $skillData['other'],
          '#color' => '#0ea5e9',
        ];
        $skillChart['xaxis'] = [
          '#type' => 'chart_xaxis',
          '#labels' => array_map('strval', $skillData['levels']),
        ];
        $skillContent = $this->buildChartContainer(
          'skill_levels',
          $this->t('Events by Skill Level'),
          $this->t('Compare workshop offerings to other event types across advertised skill levels.'),
          $skillChart,
          [
            $this->t('Source: CiviCRM event field_event_skill_level for events in the selected range.'),
            $this->t('Processing: Counts each event once; workshops identified via event type labels containing "workshop".'),
            $this->t('Definitions: Skill level labels follow the configured taxonomy in Drupal (Introductory, Intermediate, Advanced).'),
          ]
        );
        $build['skill_levels'] = $this->wrapChartWithRangeControls('skill_levels', $skillContent, $skillRanges, $skillRange);
        $build['skill_levels']['#weight'] = $weight++;
      }
      else {
        $emptySkill = $this->buildRangeEmptyContent($this->t('No events with skill level classifications were recorded for the selected time range.'));
        $build['skill_levels'] = $this->wrapChartWithRangeControls('skill_levels', $emptySkill, $skillRanges, $skillRange);
        $build['skill_levels']['#weight'] = $weight++;
      }
    }
    else {
      $emptySkill = $this->buildRangeEmptyContent($this->t('Skill level tracking is not available for this data set.'));
      $build['skill_levels'] = $this->wrapChartWithRangeControls('skill_levels', $emptySkill, $skillRanges, $skillRange);
      $build['skill_levels']['#weight'] = $weight++;
    }

    $demographicRanges = ['3m', '1y', '2y', 'all'];

    // Gender demographics.
    $genderRange = $this->resolveSelectedRange($filters, 'demographics_gender', $rangeDefault, $demographicRanges);
    $genderBounds = $this->calculateRangeBounds($genderRange, $end_date);
    $genderData = $this->eventsMembershipDataService->getParticipantDemographics($genderBounds['start'], $genderBounds['end']);
    if (!empty($genderData['gender']['labels'])) {
      $genderTotal = array_sum($genderData['gender']['workshop']) + array_sum($genderData['gender']['other']);
      if ($genderTotal > 0) {
        $genderChart = [
          '#type' => 'chart',
          '#chart_type' => 'bar',
          '#chart_library' => 'chartjs',
          '#raw_options' => [
            'options' => [
              'plugins' => ['legend' => ['position' => 'bottom']],
              'scales' => ['y' => ['ticks' => ['precision' => 0]]],
            ],
          ],
        ];
        $genderChart['workshop'] = [
          '#type' => 'chart_data',
          '#title' => $this->t('Workshops'),
          '#data' => $genderData['gender']['workshop'],
          '#color' => '#8b5cf6',
        ];
        $genderChart['other'] = [
          '#type' => 'chart_data',
          '#title' => $this->t('Other events'),
          '#data' => $genderData['gender']['other'],
          '#color' => '#f97316',
        ];
        $genderChart['xaxis'] = [
          '#type' => 'chart_xaxis',
          '#labels' => array_map('strval', $genderData['gender']['labels']),
        ];
        $genderContent = $this->buildChartContainer(
          'demographics_gender',
          $this->t('Participant Gender by Event Type'),
          $this->t('Counted participants grouped by gender across workshops and other events.'),
          $genderChart,
          [
            $this->t('Source: Participant gender from CiviCRM contacts linked to workshop and other event registrations.'),
            $this->t('Processing: Includes counted participant statuses only; unspecified genders are shown explicitly.'),
          ]
        );
        $build['demographics_gender'] = $this->wrapChartWithRangeControls('demographics_gender', $genderContent, $demographicRanges, $genderRange);
        $build['demographics_gender']['#weight'] = $weight++;
      }
      else {
        $emptyGender = $this->buildRangeEmptyContent($this->t('No participant gender data is available for the selected time range.'));
        $build['demographics_gender'] = $this->wrapChartWithRangeControls('demographics_gender', $emptyGender, $demographicRanges, $genderRange);
        $build['demographics_gender']['#weight'] = $weight++;
      }
    }
    else {
      $emptyGender = $this->buildRangeEmptyContent($this->t('Participant gender reporting is not available for this data set.'));
      $build['demographics_gender'] = $this->wrapChartWithRangeControls('demographics_gender', $emptyGender, $demographicRanges, $genderRange);
      $build['demographics_gender']['#weight'] = $weight++;
    }

    // Ethnicity demographics.
    $ethnicityRange = $this->resolveSelectedRange($filters, 'demographics_ethnicity', $rangeDefault, $demographicRanges);
    $ethBounds = $this->calculateRangeBounds($ethnicityRange, $end_date);
    $ethnicityData = $this->eventsMembershipDataService->getParticipantDemographics($ethBounds['start'], $ethBounds['end']);
    if (!empty($ethnicityData['ethnicity']['labels'])) {
      $ethnicityTotal = array_sum($ethnicityData['ethnicity']['workshop']) + array_sum($ethnicityData['ethnicity']['other']);
      if ($ethnicityTotal > 0) {
        $ethnicityChart = [
          '#type' => 'chart',
          '#chart_type' => 'bar',
          '#chart_library' => 'chartjs',
          '#raw_options' => [
            'options' => [
              'indexAxis' => 'y',
              'plugins' => ['legend' => ['position' => 'bottom']],
              'responsive' => TRUE,
              'maintainAspectRatio' => TRUE,
              'aspectRatio' => 1.8,
              'scales' => [
                'x' => [
                  'ticks' => ['precision' => 0],
                ],
              ],
            ],
          ],
          '#attributes' => ['class' => ['makerspace-dashboard-horizontal-chart']],
        ];
        $ethnicityChart['workshop'] = [
          '#type' => 'chart_data',
          '#title' => $this->t('Workshops'),
          '#data' => $ethnicityData['ethnicity']['workshop'],
          '#color' => '#14b8a6',
          '#settings' => [
            'maxBarThickness' => 28,
          ],
        ];
        $ethnicityChart['other'] = [
          '#type' => 'chart_data',
          '#title' => $this->t('Other events'),
          '#data' => $ethnicityData['ethnicity']['other'],
          '#color' => '#ef4444',
          '#settings' => [
            'maxBarThickness' => 28,
          ],
        ];
        $ethnicityChart['xaxis'] = [
          '#type' => 'chart_xaxis',
          '#labels' => array_map('strval', $ethnicityData['ethnicity']['labels']),
        ];
        $ethnicityContent = $this->buildChartContainer(
          'demographics_ethnicity',
          $this->t('Participant Ethnicity (Top 10)'),
          $this->t('Top reported ethnicities for counted participants, comparing workshops to other events.'),
          $ethnicityChart,
          [
            $this->t('Source: Ethnicity selections from the Demographics custom profile linked to event participants.'),
            $this->t('Processing: Multi-select values are split across the chart; categories beyond the top ten roll into "Other".'),
          ]
        );
        $build['demographics_ethnicity'] = $this->wrapChartWithRangeControls('demographics_ethnicity', $ethnicityContent, $demographicRanges, $ethnicityRange);
        $build['demographics_ethnicity']['#weight'] = $weight++;
      }
      else {
        $emptyEthnicity = $this->buildRangeEmptyContent($this->t('No participant ethnicity data is available for the selected time range.'));
        $build['demographics_ethnicity'] = $this->wrapChartWithRangeControls('demographics_ethnicity', $emptyEthnicity, $demographicRanges, $ethnicityRange);
        $build['demographics_ethnicity']['#weight'] = $weight++;
      }
    }
    else {
      $emptyEthnicity = $this->buildRangeEmptyContent($this->t('Participant ethnicity reporting is not available for this data set.'));
      $build['demographics_ethnicity'] = $this->wrapChartWithRangeControls('demographics_ethnicity', $emptyEthnicity, $demographicRanges, $ethnicityRange);
      $build['demographics_ethnicity']['#weight'] = $weight++;
    }

    // Age demographics.
    $ageRange = $this->resolveSelectedRange($filters, 'demographics_age', $rangeDefault, $demographicRanges);
    $ageBounds = $this->calculateRangeBounds($ageRange, $end_date);
    $ageData = $this->eventsMembershipDataService->getParticipantDemographics($ageBounds['start'], $ageBounds['end']);
    if (!empty($ageData['age']['labels'])) {
      $ageTotal = array_sum($ageData['age']['workshop']) + array_sum($ageData['age']['other']);
      if ($ageTotal > 0) {
        $ageChart = [
          '#type' => 'chart',
          '#chart_type' => 'line',
          '#chart_library' => 'chartjs',
          '#raw_options' => [
            'options' => [
              'plugins' => ['legend' => ['position' => 'bottom']],
              'scales' => [
                'y' => ['ticks' => ['precision' => 0]],
              ],
            ],
          ],
        ];
        $ageChart['workshop'] = [
          '#type' => 'chart_data',
          '#title' => $this->t('Workshops'),
          '#data' => $ageData['age']['workshop'],
          '#color' => '#2563eb',
          '#settings' => [
            'borderColor' => '#2563eb',
            'backgroundColor' => 'rgba(37,99,235,0.2)',
            'fill' => FALSE,
            'tension' => 0.3,
            'borderWidth' => 2,
          ],
        ];
        $ageChart['other'] = [
          '#type' => 'chart_data',
          '#title' => $this->t('Other events'),
          '#data' => $ageData['age']['other'],
          '#color' => '#facc15',
          '#settings' => [
            'borderColor' => '#facc15',
            'backgroundColor' => 'rgba(250,204,21,0.2)',
            'fill' => FALSE,
            'tension' => 0.3,
            'borderWidth' => 2,
            'borderDash' => [6, 4],
          ],
        ];
        $ageChart['xaxis'] = [
          '#type' => 'chart_xaxis',
          '#labels' => array_map('strval', $ageData['age']['labels']),
        ];
        $ageContent = $this->buildChartContainer(
          'demographics_age',
          $this->t('Participant Age Distribution'),
          $this->t('Age buckets for counted participants, evaluated at event date and split by workshops vs other events.'),
          $ageChart,
          [
            $this->t('Source: Participant birth dates from CiviCRM contacts. Age evaluated on the event start date.'),
            $this->t('Processing: Uses fixed buckets (Under 18 through 65+); records without birth dates are skipped.'),
          ]
        );
        $build['demographics_age'] = $this->wrapChartWithRangeControls('demographics_age', $ageContent, $demographicRanges, $ageRange);
        $build['demographics_age']['#weight'] = $weight++;
      }
      else {
        $emptyAge = $this->buildRangeEmptyContent($this->t('No participant age data is available for the selected time range.'));
        $build['demographics_age'] = $this->wrapChartWithRangeControls('demographics_age', $emptyAge, $demographicRanges, $ageRange);
        $build['demographics_age']['#weight'] = $weight++;
      }
    }
    else {
      $emptyAge = $this->buildRangeEmptyContent($this->t('Participant age reporting is not available for this data set.'));
      $build['demographics_age'] = $this->wrapChartWithRangeControls('demographics_age', $emptyAge, $demographicRanges, $ageRange);
      $build['demographics_age']['#weight'] = $weight++;
    }


    if (!empty($capacity_placeholder['data'])) {
      $chart_id = 'workshop_capacity_placeholder';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
      ];
      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Utilization %'),
        '#data' => $capacity_placeholder['data'],
      ];
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $capacity_placeholder['months']),
      ];
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Workshop Capacity Utilization (Sample)'),
        $this->t('Placeholder illustrating capacity tracking. Replace with actual utilization logic.'),
        $chart,
        [
          $this->t('Placeholder: Replace with actual capacity metrics. Currently showing illustrative values only.'),
          $this->t('Next steps: join CiviCRM or scheduling data to calculate registrations as a share of capacity.'),
          $this->t('Observation: @note', ['@note' => $capacity_placeholder['note']]),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;
    }

    $funnel = $snapshot['funnel'];
    if (empty($funnel['totals']['joined'])) {
      $build['empty'] = [
        '#markup' => $this->t('No new members joined within the configured cohort window. Adjust the engagement settings or check recent member activity.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
        '#weight' => $weight++,
      ];
      return $build;
    }
    $labels = array_map(fn($label) => $this->t($label), $funnel['labels']);

    $chart_id = 'badge_funnel';
    $chart = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
    ];
    $chart['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Members'),
      '#data' => array_map('intval', $funnel['counts']),
    ];
    $chart['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => array_map('strval', $labels),
    ];
    $build[$chart_id] = $this->buildChartContainer(
      $chart_id,
      $this->t('Badge Activation Funnel'),
      $this->t('Progression of new members through orientation, first badge, and tool-enabled badges.'),
      $chart,
      [
        $this->t('Source: Badge request nodes completed within the activation window for members who joined during the cohort range.'),
        $this->t('Processing: Orientation completion is keyed off configured orientation badge term IDs; first/tool-enabled badges use the earliest qualifying badge within the activation window (default 90 days).'),
        $this->t('Definitions: Members without any qualifying badge remain at the "Joined" stage; tool-enabled requires the taxonomy flag field_badge_access_control.'),
      ]
    );
    $build[$chart_id]['#weight'] = $weight++;

    $velocity = $snapshot['velocity'];
    $velocityLabels = array_map(fn($label) => $this->t($label), $velocity['labels']);

    $chart_id = 'engagement_velocity';
    $chart = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
    ];
    $chart['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Members'),
      '#data' => array_map('intval', $velocity['counts']),
    ];
    $chart['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => array_map('strval', $velocityLabels),
    ];
    $build[$chart_id] = $this->buildChartContainer(
      $chart_id,
      $this->t('Days to First Badge'),
      $this->t('Distribution of days elapsed from join date to first non-orientation badge.'),
      $chart,
      [
        $this->t('Source: First non-orientation badge timestamps pulled from badge requests for the same cohort used in the funnel chart.'),
        $this->t('Processing: Calculates elapsed days between join date and first badge award, then buckets into ranges (0-3, 4-7, 8-14, 15-30, 31-60, 60+, no badge).'),
        $this->t('Definitions: Members without a qualifying badge fall into the "No badge yet" bucket; orientation-only completions do not count toward the distribution.'),
      ]
    );
    $build[$chart_id]['#weight'] = $weight++;

    $badgeVolume = $snapshot['badge_volume'];
    if (!empty($badgeVolume['counts']) && array_sum($badgeVolume['counts']) > 0) {
      $chart_id = 'badge_volume';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
      ];
      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Badges awarded'),
        '#data' => $badgeVolume['counts'],
      ];
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map(fn($label) => (string) $this->t($label), $badgeVolume['labels']),
      ];
      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('Badge Awards by Time Since Join'),
        $this->t('Counts all badges (including orientation) earned within the activation window, grouped by days from join date.'),
        $chart,
        [
          $this->t('Source: All active badge requests tied to cohort members within the activation window.'),
          $this->t('Processing: For each badge completion, calculates days from join and increments the corresponding bucket (0-3, 4-7, 8-14, 15-30, 31-60, 60+).'),
          $this->t('Definitions: Members can contribute multiple badges across buckets; orientation badges are included for full workload context.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;
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
      '#weight' => $weight++,
    ];


    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => ['user_list', 'config:makerspace_dashboard.settings', 'civicrm_participant_list'],
    ];

    return $build;
  }

}
