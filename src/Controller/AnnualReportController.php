<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\lending_library\Service\StatsCollectorInterface;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;
use Drupal\makerspace_dashboard\Service\EventsMembershipDataService;
use Drupal\makerspace_dashboard\Service\DevelopmentDataService;
use Drupal\makerspace_dashboard\Service\UtilizationDataService;
use Drupal\makerspace_dashboard\Service\UtilizationWindowService;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;
use Drupal\makerspace_dashboard\Service\RetentionFlowDataService;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;
use Drupal\makerspace_dashboard\Service\EntrepreneurshipDataService;
use Drupal\makerspace_dashboard\Service\EngagementDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Generates a Year-over-Year Impact Report.
 */
class AnnualReportController extends ControllerBase {

  /**
   * Key Greater New Haven municipalities with 2020 Census populations.
   */
  private const GREATER_NEW_HAVEN_TOWNS = [
    ['name' => 'New Haven', 'population' => 134023],
    ['name' => 'Hamden', 'population' => 61169],
    ['name' => 'Meriden', 'population' => 60850],
    ['name' => 'West Haven', 'population' => 55292],
    ['name' => 'Milford', 'population' => 50558],
    ['name' => 'Wallingford', 'population' => 44396],
    ['name' => 'Branford', 'population' => 28273],
    ['name' => 'East Haven', 'population' => 27923],
    ['name' => 'North Haven', 'population' => 24253],
    ['name' => 'Guilford', 'population' => 22073],
    ['name' => 'Madison', 'population' => 17691],
    ['name' => 'Orange', 'population' => 14280],
    ['name' => 'North Branford', 'population' => 13544],
    ['name' => 'Woodbridge', 'population' => 9087],
    ['name' => 'Bethany', 'population' => 5297],
  ];

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
  protected UtilizationWindowService $utilizationWindowService;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

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
      $container->get('makerspace_dashboard.engagement_data'),
      $container->get('config.factory'),
      $container->get('makerspace_dashboard.utilization_window')
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
    EngagementDataService $engagement_data,
    ConfigFactoryInterface $config_factory,
    UtilizationWindowService $utilization_window_service
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
    $this->configFactory = $config_factory;
    $this->utilizationWindowService = $utilization_window_service;
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
    $tables = $this->buildTables();

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
      '#tables' => $tables,
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
   * Builds tables for the report.
   */
  protected function buildTables(): array {
    $tables = [];

    $localityCounts = $this->demographicsData->getLocalityCounts();
    if (empty(self::GREATER_NEW_HAVEN_TOWNS)) {
      return $tables;
    }

    $listedRows = [];
    $listedMemberTotal = 0;
    $coreTownKeys = [];
    foreach (self::GREATER_NEW_HAVEN_TOWNS as $town) {
      $key = mb_strtolower($town['name']);
      $coreTownKeys[$key] = TRUE;
      $members = (int) ($localityCounts[$key]['count'] ?? 0);
      $population = (int) $town['population'];
      $penetration = $population > 0 ? ($members / $population) * 100 : 0;
      $listedRows[] = [
        'town' => $town['name'],
        'population' => $population,
        'members' => $members,
        'penetration' => $penetration,
      ];
      $listedMemberTotal += $members;
    }

    $knownMemberTotal = 0;
    foreach ($localityCounts as $key => $row) {
      if ($key === '__unknown') {
        continue;
      }
      $knownMemberTotal += (int) ($row['count'] ?? 0);
    }
    $unknownCount = (int) ($localityCounts['__unknown']['count'] ?? 0);
    $profiledMembers = $knownMemberTotal + $unknownCount;
    $coverageShare = $profiledMembers > 0 ? $knownMemberTotal / $profiledMembers : 0;

    $na = (string) $this->t('N/A');

    $tableRows = [];
    foreach ($listedRows as $row) {
      $adjustedPenetration = ($row['population'] > 0 && $coverageShare > 0)
        ? min(100, ($row['members'] / $coverageShare) / $row['population'] * 100)
        : 0;
      $membershipShare = $profiledMembers > 0 ? ($row['members'] / $profiledMembers) * 100 : 0;
      $tableRows[] = [
        $row['town'],
        number_format($row['population']),
        number_format($row['members']),
        $this->formatPercent($membershipShare, 1),
        $this->formatPercent($row['penetration']),
        $this->formatPercent($adjustedPenetration),
      ];
    }
    
    $tables['regional_saturation'] = [
      '#type' => 'details',
      '#title' => $this->t('Greater New Haven Saturation'),
      '#open' => TRUE,
      'table' => [
        '#type' => 'table',
        '#header' => [
          (string) $this->t('Profile locality'),
          (string) $this->t('Population (2020)'),
          (string) $this->t('Active Members'),
          (string) $this->t('% of Active Members'),
          (string) $this->t('% of Population'),
          (string) $this->t('Est. % (All Members)'),
        ],
        '#rows' => $tableRows,
        '#empty' => (string) $this->t('No profile locality data found for the selected towns.'),
      ],
    ];

    return $tables;
  }

  /**
   * Formats a percentage for display.
   */
  protected function formatPercent(float $value, int $precision = 2): string {
    return number_format($value, $precision) . '%';
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

    $query = $this->database->select('profile', 'p');
    $query->condition('p.type', 'main');
    $query->condition('p.is_default', 1);
    $query->condition('p.created', $start->getTimestamp(), '>=');
    $query->condition('p.created', $end->getTimestamp(), '<');
    $count_query = $query->countQuery();
    $stats['total_joins'] = (int) $count_query->execute()->fetchField();

    $workshopEventTypeId = (int) ($this->configFactory->get('makerspace_dashboard.settings')->get('events.workshop_event_type_id') ?? 6);

    $query = $this->database->select('civicrm_event', 'e');
    $query->condition('e.event_type_id', $workshopEventTypeId);
    $query->condition('e.start_date', $start->format('Y-m-d H:i:s'), '>=');
    $query->condition('e.start_date', $end->format('Y-m-d H:i:s'), '<');
    $count_query = $query->countQuery();
    $stats['events_held'] = (int) $count_query->execute()->fetchField();
    
    $query = $this->database->select('civicrm_participant', 'cp');
    $query->join('civicrm_event', 'ce', 'cp.event_id = ce.id');
    $query->condition('ce.event_type_id', $workshopEventTypeId);
    $query->condition('ce.start_date', $start->format('Y-m-d H:i:s'), '>=');
    $query->condition('ce.start_date', $end->format('Y-m-d H:i:s'), '<');
    $count_query = $query->countQuery();
    $stats['event_registrations'] = (int) $count_query->execute()->fetchField();

    $contributionStats = $this->developmentData->getContributionStats($start, $end);
    $stats['donation_amount'] = $contributionStats['total_amount'];
    $stats['donation_count'] = $contributionStats['count'];

    $lending = $this->lendingStats->getStatsForPeriod($start, $end);
    $stats['lending_loans'] = $lending['loan_count'] ?? 0;
    $stats['lending_borrowers'] = $lending['unique_borrowers'] ?? 0;

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
      'total_joins' => ['label' => 'New Members (Last 12 Months)', 'format' => 'number'],
      'events_held' => ['label' => 'Workshops Held (Year)', 'format' => 'number'],
      'event_registrations' => ['label' => 'Workshop Registrations', 'format' => 'number'],
      'lending_loans' => ['label' => 'Tool Loans (Last 12 Months)', 'format' => 'number'],
      'lending_borrowers' => ['label' => 'Unique Borrowers (Last 12 Months)', 'format' => 'number'],
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
   * Helper to get new member goals for a period.
   */
  protected function getNewMemberGoalsForPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $query = $this->database->select('profile', 'p');
    $query->leftJoin('profile__field_member_goal', 'goal', 'goal.entity_id = p.profile_id AND goal.deleted = 0');
    $query->addField('goal', 'field_member_goal_value', 'goal_value');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->condition('p.created', [$start->getTimestamp(), $end->getTimestamp()], 'BETWEEN');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');
    $query->groupBy('goal.field_member_goal_value');
    
    $results = $query->execute();
    
    $goals = [];
    foreach ($results as $record) {
        $goalValue = trim((string) ($record->goal_value ?? ''));
        $key = $goalValue ?: 'other';
        $goals[$key] = (int) $record->member_count;
    }
    return $goals;
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
    $recruitmentHistory = $this->membershipMetrics->getMonthlyRecruitmentHistory();
    $labels = [];
    $values = [];
    $dateIterator = clone $startDate;
    while ($dateIterator < $end) {
        $year = (int)$dateIterator->format('Y');
        $month = (int)$dateIterator->format('n');
        $labels[] = $dateIterator->format('M Y');
        $values[] = $recruitmentHistory[$year][$month] ?? 0;
        $dateIterator = $dateIterator->modify('+1 month');
    }

    if ($values) {
      $groups['growth']['membership'] = [
        '#type' => 'details',
        '#title' => $this->t('New Members Joined (Profile Creation)'),
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

    // Net Membership Change
    $flowData = $this->membershipMetrics->getFlow($startDate, $end, 'month');
    if (!empty($flowData['totals'])) {
        $flowLabels = [];
        $netChange = [];
        foreach ($flowData['totals'] as $total) {
            $flowLabels[] = (new \DateTimeImmutable($total['period']))->format('M Y');
            $netChange[] = $total['incoming'] - $total['ending'];
        }

        $groups['growth']['net_change'] = [
            '#type' => 'details',
            '#title' => $this->t('Net Membership Change'),
            '#open' => TRUE,
            'chart' => [
                '#type' => 'chart',
                '#chart_type' => 'line',
                'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $flowLabels],
                'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Net Change')],
                'net' => ['#type' => 'chart_data', '#title' => $this->t('Net Change'), '#data' => $netChange, '#color' => '#2563eb'],
            ],
        ];
    }

    // Cohort & Retention
    $currentYear = (int) $end->format('Y');
    $startYear = $currentYear - 5;
    $cohorts = $this->membershipMetrics->getAnnualCohorts($startYear, $currentYear);
    if ($cohorts) {
      $cohortYears = array_column($cohorts, 'year');
      $cohortActive = array_column($cohorts, 'active');
      $cohortInactive = array_column($cohorts, 'inactive');
      $groups['growth']['cohort_composition'] = [
        '#type' => 'details',
        '#title' => $this->t('Cohort Composition by Join Year'),
        '#open' => FALSE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'column',
          '#options' => ['isStacked' => TRUE],
          'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $cohortYears],
          'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Members')],
          'active' => ['#type' => 'chart_data', '#title' => $this->t('Still active'), '#data' => $cohortActive, '#color' => '#ef4444'],
          'inactive' => ['#type' => 'chart_data', '#title' => $this->t('No longer active'), '#data' => $cohortInactive, '#color' => '#94a3b8'],
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


    
    // Entrepreneurship Goals
    $chartCurrentEnd = $end;
    $chartCurrentStart = $chartCurrentEnd->modify('-12 months');
    $chartPrevEnd = $chartCurrentStart;
    $chartPrevStart = $chartPrevEnd->modify('-12 months');
    $chartPeriods = [
      'current' => ['start' => $chartCurrentStart, 'end' => $chartCurrentEnd, 'label' => 'Last 12 Months'],
      'prev' => ['start' => $chartPrevStart, 'end' => $chartPrevEnd, 'label' => 'Previous 12 Months'],
    ];

    $currentPeriodGoals = $this->getNewMemberGoalsForPeriod($chartCurrentStart, $chartCurrentEnd);
    $previousPeriodGoals = $this->getNewMemberGoalsForPeriod($chartPrevStart, $chartPrevEnd);

    if ($currentPeriodGoals || $previousPeriodGoals) {
        $goalKeys = array_unique(array_merge(array_keys($currentPeriodGoals), array_keys($previousPeriodGoals)));
        $labels = array_map(fn($k) => ucfirst($k), $goalKeys);
        
        $currentValues = [];
        $previousValues = [];
        foreach ($goalKeys as $key) {
            $currentValues[] = $currentPeriodGoals[$key] ?? 0;
            $previousValues[] = $previousPeriodGoals[$key] ?? 0;
        }

        $groups['education']['entrepreneur_trend'] = [
            '#type' => 'details',
            '#title' => $this->t('Entrepreneurship Goals (New Members)'),
            '#open' => FALSE,
            'chart' => [
                '#type' => 'chart',
                '#chart_type' => 'bar',
                'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $labels],
                'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('New Members')],
                'current' => ['#type' => 'chart_data', '#title' => $chartPeriods['current']['label'], '#data' => $currentValues],
                'previous' => ['#type' => 'chart_data', '#title' => $chartPeriods['prev']['label'], '#data' => $previousValues],
            ],
        ];
    }

    // Badge Tenure Correlation
    $badgeTenureData = $this->membershipMetrics->getBadgeTenureCorrelation();
    if ($badgeTenureData) {
        $badgeTenureLabels = [];
        $badgeTenureAvg = [];
        $badgeTenureMedian = [];

        foreach ($badgeTenureData as $bucket) {
            $label = $bucket['badge_min'];
            if ($bucket['badge_max']) {
                if ($bucket['badge_max'] > $bucket['badge_min']) {
                    $label .= '-' . $bucket['badge_max'];
                }
            } else {
                $label .= '+';
            }
            $badgeTenureLabels[] = $label . ' badges';
            $badgeTenureAvg[] = $bucket['average_years'];
            $badgeTenureMedian[] = $bucket['median_years'];
        }

        $groups['education']['badge_tenure'] = [
            '#type' => 'details',
            '#title' => $this->t('Badges vs. Membership Tenure'),
            '#open' => TRUE,
            'chart' => [
                '#type' => 'chart',
                '#chart_type' => 'bar',
                'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $badgeTenureLabels],
                'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Years')],
                'avg' => ['#type' => 'chart_data', '#title' => $this->t('Average Tenure (Years)'), '#data' => $badgeTenureAvg],
                'median' => ['#type' => 'chart_data', '#title' => $this->t('Median Tenure (Years)'), '#data' => $badgeTenureMedian],
            ],
        ];
    }

    // Members by Badges Earned
    $badgeCountData = $this->membershipMetrics->getBadgeCountDistribution();
    if ($badgeCountData) {
        $badgeCountLabels = [];
        $badgeCountValues = [];
        foreach ($badgeCountData as $bucket) {
            $label = $bucket['badge_min'];
            if ($bucket['badge_max']) {
                if ($bucket['badge_max'] > $bucket['badge_min']) {
                    $label .= '-' . $bucket['badge_max'];
                }
            } else {
                $label .= '+';
            }
            $badgeCountLabels[] = $label . ' badges';
            $badgeCountValues[] = $bucket['member_count'];
        }

        if (array_sum($badgeCountValues) > 0) {
            $groups['education']['badge_count'] = [
                '#type' => 'details',
                '#title' => $this->t('Members by Badges Earned'),
                '#open' => TRUE,
                'chart' => [
                    '#type' => 'chart',
                    '#chart_type' => 'pie',
                    'pie_data' => ['#type' => 'chart_data', '#title' => $this->t('Members'), '#labels' => $badgeCountLabels, '#data' => $badgeCountValues],
                ],
            ];
        }
    }

    // Active Members with Entrepreneurship-related Goals
    $entrepreneurshipSnapshot = $this->entrepreneurshipData->getActiveEntrepreneurSnapshot();
    if (!empty($entrepreneurshipSnapshot['totals']['goal_counts'])) {
        $goalCounts = $entrepreneurshipSnapshot['totals']['goal_counts'];
        $goalLabels = array_map(fn($k) => ucfirst($k), array_keys($goalCounts));
        $goalValues = array_values($goalCounts);

        if (array_sum($goalValues) > 0) {
            $groups['education']['entrepreneurship_goals'] = [
                '#type' => 'details',
                '#title' => $this->t('Active Members with Entrepreneurship-related Goals'),
                '#open' => TRUE,
                'chart' => [
                    '#type' => 'chart',
                    '#chart_type' => 'pie',
                    'pie_data' => ['#type' => 'chart_data', '#title' => $this->t('Members'), '#labels' => $goalLabels, '#data' => $goalValues],
                ],
            ];
        }
    }


    // --- DIVERSITY ---
    // Ethnicity (BIPOC)
    $bipocData = $this->demographicsData->getMembershipEthnicitySummary();
    if (!empty($bipocData['distribution'])) {
      $eDist = $bipocData['distribution'];
      unset($eDist['not_specified']);
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
      $gender = array_filter($gender, function($row) {
        return !in_array($row['label'], ['Prefer Not To Say', 'Not provided']);
      });
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
        $ageCounts = array_values(array_column($ages, 'count'));

        // Calculate moving average
        $movingAvgData = [];
        $window = 5;
        for ($i = 0; $i < count($ageCounts); $i++) {
            $sliceStart = max(0, $i - intdiv($window, 2));
            $sliceEnd = min(count($ageCounts) - 1, $i + intdiv($window, 2));
            $slice = array_slice($ageCounts, $sliceStart, $sliceEnd - $sliceStart + 1);
            $movingAvgData[] = round(array_sum($slice) / count($slice), 2);
        }

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
                'avg' => ['#type' => 'chart_data', '#title' => $this->t('Moving Average'), '#data' => $movingAvgData, '#type' => 'line'],
            ],
        ];
    }
    
    // Greater New Haven Saturation
    $localityCounts = $this->demographicsData->getLocalityCounts();
    if (!empty(self::GREATER_NEW_HAVEN_TOWNS) && $localityCounts) {
        $saturationRows = [];
        foreach (self::GREATER_NEW_HAVEN_TOWNS as $town) {
            $key = mb_strtolower($town['name']);
            $members = (int) ($localityCounts[$key]['count'] ?? 0);
            $population = (int) $town['population'];
            $penetration = $population > 0 ? ($members / $population) * 100 : 0;

            $saturationRows[] = [
                $town['name'],
                number_format($population),
                number_format($members),
                number_format($penetration, 2) . '%',
            ];
        }

        $groups['diversity']['saturation'] = [
            '#type' => 'details',
            '#title' => $this->t('Greater New Haven Saturation'),
            '#open' => TRUE,
            'chart' => [
                '#type' => 'table',
                '#header' => [
                    $this->t('Town'),
                    $this->t('Population'),
                    $this->t('Members'),
                    $this->t('Penetration %'),
                ],
                '#rows' => $saturationRows,
                '#attributes' => ['class' => ['makerspace-dashboard-table']],
            ],
        ];
    }
    
    // Member Map (Heatmap)
    $mapId = Html::getUniqueId('member-location-map');

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
            'id' => $mapId,
            'class' => ['makerspace-dashboard-location-map'],
            'data-locations-url' => '/makerspace-dashboard/api/locations',
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
    $usageStartTimestamp = $startDate instanceof \DateTimeInterface
      ? $startDate->getTimestamp()
      : (int) $startDate;
    $dailyEntries = $this->utilizationData->getDailyUniqueEntries($usageStartTimestamp, $end->getTimestamp());
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

    // 7-Day Rolling Average
    $windowMetrics = $this->utilizationWindowService->getWindowMetrics();
    $rollingLabels = $windowMetrics['rolling_labels'] ?? [];
    $rollingAverages = $windowMetrics['rolling_average'] ?? [];
    $trendLine = $windowMetrics['trend_line'] ?? [];

    if ($rollingAverages) {
      $groups['usage']['rolling_avg'] = [
        '#type' => 'details',
        '#title' => $this->t('7-Day Rolling Average of Unique Entries'),
        '#open' => TRUE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'line',
          'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $rollingLabels],
          'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Rolling Average')],
          'visits' => ['#type' => 'chart_data', '#title' => $this->t('7-Day Average'), '#data' => $rollingAverages, '#color' => '#f97316'],
          'trend' => ['#type' => 'chart_data', '#title' => $this->t('Trend'), '#data' => $trendLine, '#color' => '#22c55e'],
        ],
      ];
    }

    // Visit Frequency
    $visitFrequencyData = $this->utilizationData->getVisitFrequencyBuckets($end->modify('-1 year')->getTimestamp(), $end->getTimestamp());
    if ($visitFrequencyData) {
        $visitFrequencyLabels = [
            'no_visits' => $this->t('No Visits'),
            'less_than_quarterly' => $this->t('~1-2/year'),
            'quarterly' => $this->t('~Quarterly'),
            'monthly' => $this->t('~1/month'),
            'two_to_three' => $this->t('2-3/month'),
            'weekly' => $this->t('~Weekly'),
            'twice_weekly' => $this->t('~Twice Weekly'),
            'daily_plus' => $this->t('Daily+'),
        ];
        $chartLabels = [];
        $chartData = [];
        foreach ($visitFrequencyLabels as $key => $label) {
            if (isset($visitFrequencyData[$key])) {
                $chartLabels[] = $label;
                $chartData[] = $visitFrequencyData[$key];
            }
        }

        if ($chartData) {
            $groups['usage']['visit_frequency'] = [
                '#type' => 'details',
                '#title' => $this->t('Visit Frequency Distribution (1 Year)'),
                '#open' => TRUE,
                'chart' => [
                    '#type' => 'chart',
                    '#chart_type' => 'pie',
                    'pie_data' => ['#type' => 'chart_data', '#title' => $this->t('Members'), '#labels' => $chartLabels, '#data' => $chartData],
                ],
            ];
        }
    }

    // Average Entries by Hour
    $hourlyData = $this->utilizationData->getAverageEntriesByHour($end->modify('-1 year')->getTimestamp(), $end->getTimestamp());
    if (!empty($hourlyData['averages'])) {
        $hourlyLabels = [];
        foreach (array_keys($hourlyData['averages']) as $hour) {
            $hourlyLabels[] = \DateTime::createFromFormat('!H', $hour)->format('g a');
        }

        $groups['usage']['hourly_avg'] = [
            '#type' => 'details',
            '#title' => $this->t('Average Entries per Hour of Day (1 Year)'),
            '#open' => TRUE,
            'chart' => [
                '#type' => 'chart',
                '#chart_type' => 'bar',
                'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $hourlyLabels],
                'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Average Entries')],
                'entries' => ['#type' => 'chart_data', '#title' => $this->t('Average Entries'), '#data' => array_values($hourlyData['averages']), '#color' => '#10b981'],
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
