<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\lending_library\Service\StatsCollectorInterface;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;
use Drupal\makerspace_dashboard\Service\EventsMembershipDataService;
use Drupal\makerspace_dashboard\Service\DevelopmentDataService;
use Drupal\makerspace_dashboard\Service\UtilizationDataService;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;
use Drupal\makerspace_dashboard\Service\RetentionFlowDataService;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;
use Drupal\makerspace_dashboard\Service\EntrepreneurshipDataService;
use Drupal\makerspace_dashboard\Service\EngagementDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates a Year-over-Year Impact Report.
 */
class AnnualReportController extends ControllerBase {

  protected Connection $database;
  protected StatsCollectorInterface $lendingStats;
  protected DateFormatterInterface $dateFormatter;
  protected SnapshotDataService $snapshotData;
  protected EventsMembershipDataService $eventsData;
  protected DevelopmentDataService $developmentData;
  protected UtilizationDataService $utilizationData;
  protected DemographicsDataService $demographicsData;
  protected RetentionFlowDataService $retentionFlowData;
  protected MembershipMetricsService $membershipMetrics;
  protected EntrepreneurshipDataService $entrepreneurshipData;
  protected EngagementDataService $engagementData;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('lending_library.stats_collector'),
      $container->get('date.formatter'),
      $container->get('makerspace_dashboard.snapshot_data'),
      $container->get('makerspace_dashboard.events_membership_data'),
      $container->get('makerspace_dashboard.development_data'),
      $container->get('makerspace_dashboard.utilization_data'),
      $container->get('makerspace_dashboard.demographics_data'),
      $container->get('makerspace_dashboard.retention_flow_data'),
      $container->get('makerspace_dashboard.membership_metrics'),
      $container->get('makerspace_dashboard.entrepreneurship_data'),
      $container->get('makerspace_dashboard.engagement_data')
    );
  }

  /**
   * Constructs the controller.
   */
  public function __construct(
    Connection $database,
    StatsCollectorInterface $lending_stats,
    DateFormatterInterface $date_formatter,
    SnapshotDataService $snapshot_data,
    EventsMembershipDataService $events_data,
    DevelopmentDataService $development_data,
    UtilizationDataService $utilization_data,
    DemographicsDataService $demographics_data,
    RetentionFlowDataService $retention_flow_data,
    MembershipMetricsService $membership_metrics,
    EntrepreneurshipDataService $entrepreneurship_data,
    EngagementDataService $engagement_data
  ) {
    $this->database = $database;
    $this->lendingStats = $lending_stats;
    $this->dateFormatter = $date_formatter;
    $this->snapshotData = $snapshot_data;
    $this->eventsData = $events_data;
    $this->developmentData = $development_data;
    $this->utilizationData = $utilization_data;
    $this->demographicsData = $demographics_data;
    $this->retentionFlowData = $retention_flow_data;
    $this->membershipMetrics = $membership_metrics;
    $this->entrepreneurshipData = $entrepreneurship_data;
    $this->engagementData = $engagement_data;
  }

  /**
   * Displays the annual report.
   */
  public function report() {
    // 1. Calculate Periods (Last 12 Full Months vs Previous 12 Months).
    $now = new \DateTimeImmutable();
    $currentEnd = $now->modify('first day of this month')->setTime(0, 0, 0);
    $currentStart = $currentEnd->modify('-12 months');

    $prevEnd = $currentStart;
    $prevStart = $prevEnd->modify('-12 months');

    $periods = [
      'current' => ['start' => $currentStart, 'end' => $currentEnd, 'label' => 'Last 12 Months'],
      'prev' => ['start' => $prevStart, 'end' => $prevEnd, 'label' => 'Previous 12 Months'],
    ];

    $data = [];
    foreach ($periods as $key => $period) {
      $data[$key] = $this->gatherData($period['start'], $period['end']);
    }

    $tableRows = $this->buildTableRows($data['prev'], $data['current']);
    $metricCards = $this->buildMetricCards($data['prev'], $data['current']);
    $charts = $this->buildCharts($currentEnd);
    $highlights = $this->calculateHighlights($data['prev'], $data['current']);

    return [
      '#theme' => 'annual_report',
      '#title' => $this->t('Year-over-Year Impact Report'),
      '#description' => $this->t('Comparing @prev_start to @prev_end vs @curr_start to @curr_end.', [
        '@prev_start' => $prevStart->format('M Y'),
        '@prev_end' => $prevEnd->modify('-1 day')->format('M Y'),
        '@curr_start' => $currentStart->format('M Y'),
        '@curr_end' => $currentEnd->modify('-1 day')->format('M Y'),
      ]),
      '#highlights' => $highlights,
      '#rows' => $metricCards,
      '#table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Metric'),
          $this->t('Previous Period'),
          $this->t('Current Period'),
          $this->t('Change'),
          $this->t('% Change'),
        ],
        '#rows' => $tableRows,
        '#attributes' => ['class' => ['comparison-table']],
      ],
      '#copy_help' => [
        '#markup' => '<p><small>' . $this->t('Tip: You can copy and paste the table below directly into a spreadsheet or email.') . '</small></p>',
      ],
      '#charts' => $charts,
      '#attached' => [
        'library' => [
          'makerspace_dashboard/annual_report',
          'makerspace_dashboard/location_map',
        ],
        'drupalSettings' => [
          'makerspace_dashboard' => [
            'locations_url' => '/makerspace-dashboard/api/locations',
          ],
        ],
      ],
    ];
  }

  /**
   * Calculates key highlights for the report.
   */
  protected function calculateHighlights(array $prev, array $curr): array {
    $highlights = [];
    
    // Check for significant growth (>10%) in key metrics
    if ($prev['total_joins'] > 0 && $curr['total_joins'] > $prev['total_joins']) {
       $pct = round((($curr['total_joins'] - $prev['total_joins']) / $prev['total_joins']) * 100);
       $highlights[] = $this->t('New membership growth is up <strong>@pct%</strong> year-over-year.', ['@pct' => $pct]);
    }
    
    if ($curr['events_held'] > $prev['events_held']) {
      $highlights[] = $this->t('We hosted <strong>@count</strong> workshops this year.', ['@count' => number_format($curr['events_held'])]);
    }

    if ($curr['donation_amount'] > $prev['donation_amount']) {
      $diff = $curr['donation_amount'] - $prev['donation_amount'];
       $highlights[] = $this->t('Fundraising increased by <strong>@amount</strong>.', ['@amount' => '$' . number_format($diff)]);
    }

    return $highlights;
  }

  /**
   * Gathers data for a specific period.
   */
  protected function gatherData(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $stats = [];
    $lastMonthOfPeriod = $end->modify('-1 month')->format('Y-m-01');

    $query = $this->database->select('ms_fact_org_snapshot', 'f');
    $query->join('ms_snapshot', 's', 's.id = f.snapshot_id');
    $query->fields('f', ['members_active']);
    $query->condition('s.snapshot_type', 'monthly');
    $query->condition('s.snapshot_date', $lastMonthOfPeriod);
    $result = $query->execute()->fetchField();
    $stats['members_active'] = $result ? (int) $result : 0;

    $query = $this->database->select('ms_fact_org_snapshot', 'f');
    $query->join('ms_snapshot', 's', 's.id = f.snapshot_id');
    $query->addExpression('SUM(f.joins)', 'total_joins');
    $query->condition('s.snapshot_type', 'monthly');
    $query->condition('s.snapshot_date', $start->format('Y-m-01'), '>=');
    $query->condition('s.snapshot_date', $end->format('Y-m-01'), '<');
    $stats['total_joins'] = (int) $query->execute()->fetchField();

    $query = $this->database->select('ms_fact_event_type_snapshot', 'f');
    $query->join('ms_snapshot', 's', 's.id = f.snapshot_id');
    $query->addExpression('SUM(f.events_count)', 'total_events');
    $query->addExpression('SUM(f.participant_count)', 'total_participants');
    $query->condition('s.snapshot_type', 'monthly');
    $query->condition('s.snapshot_date', $start->format('Y-m-01'), '>=');
    $query->condition('s.snapshot_date', $end->format('Y-m-01'), '<');
    $events = $query->execute()->fetchAssoc();
    $stats['events_held'] = (int) ($events['total_events'] ?? 0);
    $stats['event_registrations'] = (int) ($events['total_participants'] ?? 0);

    $query = $this->database->select('ms_fact_donation_snapshot', 'f');
    $query->join('ms_snapshot', 's', 's.id = f.snapshot_id');
    $query->addExpression('SUM(f.total_amount)', 'total_amount');
    $query->addExpression('SUM(f.contributions_count)', 'total_contributions');
    $query->condition('s.snapshot_type', 'monthly');
    $query->condition('s.snapshot_date', $start->format('Y-m-01'), '>=');
    $query->condition('s.snapshot_date', $end->format('Y-m-01'), '<');
    $donations = $query->execute()->fetchAssoc();
    $stats['donation_amount'] = (float) ($donations['total_amount'] ?? 0);
    $stats['donation_count'] = (int) ($donations['total_contributions'] ?? 0);

    $lending = $this->lendingStats->getStatsForPeriod($start, $end);
    $stats['lending_loans'] = $lending['loan_count'] ?? 0;
    $stats['lending_borrowers'] = $lending['unique_borrowers'] ?? 0;
    $stats['lending_value'] = $lending['total_value'] ?? 0.0;

    return $stats;
  }

  /**
   * Builds the comparison table rows.
   */
  protected function buildTableRows(array $prev, array $curr): array {
    $metrics = $this->getMetricsDefinitions();
    $rows = [];
    foreach ($metrics as $key => $info) {
      $valPrev = $prev[$key] ?? 0;
      $valCurr = $curr[$key] ?? 0;
      $diff = $valCurr - $valPrev;
      $pct = $valPrev > 0 ? round(($diff / $valPrev) * 100, 1) . '%' : ($valCurr > 0 ? '100%' : '0%');
      $diffClass = $diff >= 0 ? 'status-ok' : 'status-warning';

      $valPrevStr = $this->formatValue($valPrev, $info['format']);
      $valCurrStr = $this->formatValue($valCurr, $info['format']);
      $diffStr = ($diff >= 0 ? '+' : '-') . $this->formatValue(abs($diff), $info['format']);

      $rows[] = [
        ['data' => $info['label'], 'header' => TRUE],
        ['data' => $valPrevStr, 'class' => ['numeric']],
        ['data' => $valCurrStr, 'style' => 'font-weight: bold;', 'class' => ['numeric']],
        ['data' => $diffStr, 'class' => [$diffClass, 'numeric']],
        ['data' => $pct, 'class' => [$diffClass, 'numeric']],
      ];
    }
    return $rows;
  }

  /**
   * Builds metric card data.
   */
  protected function buildMetricCards(array $prev, array $curr): array {
    $metrics = $this->getMetricsDefinitions();
    $cards = [];
    foreach ($metrics as $key => $info) {
      $valPrev = $prev[$key] ?? 0;
      $valCurr = $curr[$key] ?? 0;
      $diff = $valCurr - $valPrev;
      $pct = $valPrev > 0 ? round(($diff / $valPrev) * 100, 1) . '%' : ($valCurr > 0 ? '100%' : '0%');
      
      $trendClass = 'trend-neutral';
      if ($diff > 0) {
        $trendClass = 'trend-up';
      } elseif ($diff < 0) {
        $trendClass = 'trend-down';
      }

      $cards[] = [
        'label' => $info['label'],
        'current' => $this->formatValue($valCurr, $info['format']),
        'previous' => $this->formatValue($valPrev, $info['format']),
        'change' => ($diff >= 0 ? '+' : '-') . $this->formatValue(abs($diff), $info['format']),
        'percent' => $pct,
        'trend_class' => $trendClass,
      ];
    }
    return $cards;
  }

  /**
   * Returns metric definitions.
   */
  protected function getMetricsDefinitions(): array {
    return [
      'members_active' => ['label' => 'Active Members', 'format' => 'number'],
      'total_joins' => ['label' => 'New Members', 'format' => 'number'],
      'events_held' => ['label' => 'Workshops Held', 'format' => 'number'],
      'event_registrations' => ['label' => 'Registrations', 'format' => 'number'],
      'lending_loans' => ['label' => 'Tool Loans', 'format' => 'number'],
      'lending_borrowers' => ['label' => 'Unique Borrowers', 'format' => 'number'],
      'lending_value' => ['label' => 'Loan Value', 'format' => 'currency'],
      'donation_count' => ['label' => 'Donations', 'format' => 'number'],
      'donation_amount' => ['label' => 'Donation Total', 'format' => 'currency'],
    ];
  }

  /**
   * Helper to format values.
   */
  protected function formatValue($value, $format) {
    if ($format === 'currency') {
      return '$' . number_format($value, 2);
    }
    return number_format($value);
  }

  /**
   * Builds the charts section grouped by theme.
   */
  protected function buildCharts(\DateTimeImmutable $end): array {
    $groups = [
      'growth' => ['#type' => 'container', '#title' => $this->t('Community Growth'), '#attributes' => ['class' => ['report-section']]],
      'education' => ['#type' => 'container', '#title' => $this->t('Education & Skills'), '#attributes' => ['class' => ['report-section']]],
      'diversity' => ['#type' => 'container', '#title' => $this->t('Diversity & Reach'), '#attributes' => ['class' => ['report-section']]],
      'usage' => ['#type' => 'container', '#title' => $this->t('Facility Usage'), '#attributes' => ['class' => ['report-section']]],
    ];

    $startDate = $end->modify('-24 months');

    // --- GROWTH ---
    // 1. New Members Trend
    $series = $this->snapshotData->getMembershipCountSeries('month');
    $labels = [];
    $values = [];
    foreach ($series as $row) {
      if ($row['period_date'] >= $startDate && $row['period_date'] < $end) {
        $labels[] = $row['period_date']->format('M Y');
        $values[] = (int) ($row['joins'] ?? 0);
      }
    }
    if ($values) {
      $groups['growth']['membership'] = [
        '#type' => 'details',
        '#title' => $this->t('New Members Joined (2 Years)'),
        '#open' => TRUE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'column',
          'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $labels],
          'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('New Members')],
          'joins' => ['#type' => 'chart_data', '#title' => $this->t('New Members'), '#data' => $values, '#color' => '#2563eb'],
        ],
      ];
    }

    // Cohort & Retention
    $currentYear = (int) $end->format('Y');
    $startYear = $currentYear - 5;
    $cohorts = $this->membershipMetrics->getAnnualCohorts($startYear, $currentYear);
    if ($cohorts) {
      $cohortYears = array_column($cohorts, 'year');
      $cohortJoined = array_column($cohorts, 'joined');
      $cohortActive = array_column($cohorts, 'active');
      $groups['growth']['cohort_composition'] = [
        '#type' => 'details',
        '#title' => $this->t('Cohort Composition by Join Year'),
        '#open' => FALSE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'column',
          'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $cohortYears],
          'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Members')],
          'joined' => ['#type' => 'chart_data', '#title' => $this->t('Total Joined'), '#data' => $cohortJoined, '#color' => '#94a3b8'],
          'active' => ['#type' => 'chart_data', '#title' => $this->t('Still Active'), '#data' => $cohortActive, '#color' => '#2563eb'],
        ],
      ];
    }

    // --- EDUCATION ---
    // Workshop Trend
    $workshopSeries = $this->eventsData->getMonthlyWorkshopAttendanceSeries($startDate, $end->modify('-1 day'));
    if (!empty($workshopSeries['counts'])) {
      $groups['education']['workshops'] = [
        '#type' => 'details',
        '#title' => $this->t('Workshop Attendance (2 Years)'),
        '#open' => TRUE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'line',
          'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $workshopSeries['labels']],
          'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Attendees')],
          'attendees' => ['#type' => 'chart_data', '#title' => $this->t('Monthly Attendees'), '#data' => $workshopSeries['counts'], '#color' => '#64748b'],
        ],
      ];
    }

    // Engagement Funnel (New)
    $funnelData = $this->engagementData->getEngagementSnapshot($end->modify('-12 months'), $end);
    if (!empty($funnelData['funnel']['counts'])) {
      $groups['education']['engagement_funnel'] = [
        '#type' => 'details',
        '#title' => $this->t('New Member Journey (Last 12 Months)'),
        '#open' => TRUE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'bar', // Horizontal bar often better for funnels, but 'bar' is usually vertical in Chart.js. 'horizontalBar' might be needed depending on library.
          'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $funnelData['funnel']['labels']],
          'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Members')],
          'funnel' => ['#type' => 'chart_data', '#title' => $this->t('Count'), '#data' => $funnelData['funnel']['counts'], '#color' => '#8b5cf6'],
        ],
      ];
    }
    
    // Entrepreneurship Goals
    $goalTrend = $this->entrepreneurshipData->getEntrepreneurGoalTrend($startDate, $end);
    if ($goalTrend) {
        $groups['education']['entrepreneur_trend'] = [
            '#type' => 'details',
            '#title' => $this->t('Entrepreneurship Goals (New Members)'),
            '#open' => FALSE,
            'chart' => [
                '#type' => 'chart',
                '#chart_type' => 'column',
                '#options' => ['isStacked' => TRUE],
                'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $goalTrend['labels']],
                'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Members')],
            ],
        ];
        foreach ($goalTrend['series'] as $goal => $counts) {
            $groups['education']['entrepreneur_trend']['chart'][$goal] = [
                '#type' => 'chart_data',
                '#title' => ucfirst($goal),
                '#data' => $counts,
            ];
        }
    }


    // --- DIVERSITY ---
    // Ethnicity (BIPOC)
    $bipocData = $this->demographicsData->getMembershipEthnicitySummary();
    if (!empty($bipocData['distribution'])) {
      $eDist = $bipocData['distribution'];
      arsort($eDist);
      // Filter out small values if too many
      if (count($eDist) > 10) $eDist = array_slice($eDist, 0, 10);
      
      $eLabels = array_map(fn($k) => ucfirst(str_replace('_', ' ', $k)), array_keys($eDist));
      $eCounts = array_values($eDist);
      
      $groups['diversity']['ethnicity_dist'] = [
        '#type' => 'details',
        '#title' => $this->t('Ethnicity Distribution'),
        '#open' => TRUE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'pie',
          'pie_data' => ['#type' => 'chart_data', '#title' => $this->t('Members'), '#labels' => $eLabels, '#data' => $eCounts],
        ],
      ];
    }
    
    // Gender
    $gender = $this->demographicsData->getGenderDistribution();
    if ($gender) {
      $gLabels = array_column($gender, 'label');
      $gCounts = array_column($gender, 'count');
      $groups['diversity']['gender_dist'] = [
        '#type' => 'details',
        '#title' => $this->t('Gender Distribution'),
        '#open' => FALSE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'pie',
          'pie_data' => ['#type' => 'chart_data', '#title' => $this->t('Members'), '#labels' => $gLabels, '#data' => $gCounts],
        ],
      ];
    }

    // Ages
    $ages = $this->demographicsData->getAgeDistribution();
    if ($ages) {
        $ageLabels = array_column($ages, 'label');
        $ageCounts = array_column($ages, 'count');
        $groups['diversity']['age_dist'] = [
            '#type' => 'details',
            '#title' => $this->t('Age Distribution'),
            '#open' => FALSE,
            'chart' => [
                '#type' => 'chart',
                '#chart_type' => 'column',
                'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $ageLabels],
                'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Members')],
                'ages' => ['#type' => 'chart_data', '#title' => $this->t('Members'), '#data' => $ageCounts, '#color' => '#6366f1'],
            ],
        ];
    }
    
    // Towns
    $towns = $this->demographicsData->getLocalityDistribution(5, 10);
    if ($towns) {
      $townLabels = array_column($towns, 'label');
      $townCounts = array_column($towns, 'count');
      $groups['diversity']['towns'] = [
        '#type' => 'details',
        '#title' => $this->t('Regional Reach (Top Towns)'),
        '#open' => FALSE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'pie',
          'pie_data' => ['#type' => 'chart_data', '#title' => $this->t('Members'), '#labels' => $townLabels, '#data' => $townCounts],
        ],
      ];
    }
    
    // Member Map (Heatmap)
    $groups['diversity']['map'] = [
      '#type' => 'details',
      '#title' => $this->t('Member Location Heatmap'),
      '#open' => TRUE,
      'chart' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['makerspace-dashboard-location-map-wrapper']],
        'map' => [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'member-location-map',
            'class' => ['makerspace-dashboard-location-map'],
            'data-initial-view' => 'heatmap',
            'data-fit-bounds' => 'false',
            'data-zoom' => '10',
          ],
        ],
        'controls' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['makerspace-dashboard-map-controls', 'text-center', 'mt-2']],
          'heatmap_btn' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Heatmap'),
            '#attributes' => ['class' => ['button', 'button--small'], 'data-map-view' => 'heatmap'],
          ],
          'markers_btn' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Clusters'),
            '#attributes' => ['class' => ['button', 'button--small'], 'data-map-view' => 'markers'],
          ],
        ],
      ],
    ];


    // --- USAGE ---
    // Entries
    $dailyEntries = $this->utilizationData->getDailyUniqueEntries($startDate->getTimestamp(), $end->getTimestamp());
    $monthlyEntries = [];
    foreach ($dailyEntries as $date => $count) {
      $month = substr($date, 0, 7);
      if (!isset($monthlyEntries[$month])) $monthlyEntries[$month] = 0;
      $monthlyEntries[$month] += $count;
    }
    if ($monthlyEntries) {
      $entryLabels = [];
      $entryValues = [];
      foreach ($monthlyEntries as $month => $count) {
        $entryLabels[] = (new \DateTimeImmutable($month . '-01'))->format('M Y');
        $entryValues[] = $count;
      }
      $groups['usage']['entries'] = [
        '#type' => 'details',
        '#title' => $this->t('Monthly Member Visits (Unique)'),
        '#open' => TRUE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'column',
          'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $entryLabels],
          'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Unique Visits')],
          'visits' => ['#type' => 'chart_data', '#title' => $this->t('Unique Visits'), '#data' => $entryValues, '#color' => '#10b981'],
        ],
      ];
    }

    // Lending
    $stats = $this->lendingStats->collect();
    $history = $stats['chart_data']['full_history'] ?? [];
    $history24 = array_slice($history, -24);
    if ($history24) {
      $lendingLabels = array_column($history24, 'label');
      $loans = array_column($history24, 'loans');
      $groups['usage']['lending'] = [
        '#type' => 'details',
        '#title' => $this->t('Lending Library Usage'),
        '#open' => TRUE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'area',
          'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $lendingLabels],
          'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Loans')],
          'loans' => ['#type' => 'chart_data', '#title' => $this->t('Monthly Loans'), '#data' => $loans, '#color' => '#f97316'],
        ],
      ];
    }

    return $groups;
  }

}
