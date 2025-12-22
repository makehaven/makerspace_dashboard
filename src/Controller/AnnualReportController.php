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
use Drupal\makerspace_dashboard\Service\AppointmentInsightsService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Generates a Year-over-Year Data In Review.
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
  protected AppointmentInsightsService $appointmentInsights;

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
      $container->get('makerspace_dashboard.utilization_window'),
      $container->get('makerspace_dashboard.appointment_insights')
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
    UtilizationWindowService $utilization_window_service,
    AppointmentInsightsService $appointment_insights
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
    $this->appointmentInsights = $appointment_insights;
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
    $notice = $this->t('These metrics feed directly from the live database as part of our new data initiative. We believe they are accurate, but we are still actively validating the pipelines so surprises can happen. If you spot something that looks off, please let @email know.', ['@email' => 'jrlogan@makehaven.org']);

    return [
      '#theme' => 'annual_report',
      '#title' => $this->t('Year-over-Year Data In Review'),
      '#description' => $this->t('Comparing @prev_start to @prev_end vs @curr_start to @curr_end.', [
        '@prev_start' => $prevStart->format('M Y'),
        '@prev_end' => $prevEnd->modify('-1 day')->format('M Y'),
        '@curr_start' => $currentStart->format('M Y'),
        '@curr_end' => $currentEnd->modify('-1 day')->format('M Y'),
      ]),
      '#highlights' => $highlights,
      '#notice' => $notice,
      '#rows' => $metricCards,
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


  protected function calculateHighlights(array $prev, array $curr): array {
    $highlights = [];
    
    $highlights[] = $this->t('MakeHaven community has reached <strong>@count</strong> active members.', ['@count' => number_format($curr['members_active'])]);

    // Check for significant growth (>10%) in key metrics
    if ($prev['total_joins'] > 0 && $curr['total_joins'] > $prev['total_joins']) {
       $pct = round((($curr['total_joins'] - $prev['total_joins']) / $prev['total_joins']) * 100);
       $highlights[] = $this->t('New membership growth is up <strong>@pct%</strong> year-over-year.', ['@pct' => $pct]);
    }
    
    if ($curr['events_held'] > $prev['events_held']) {
      $highlights[] = $this->t('We hosted <strong>@count</strong> workshops this year.', ['@count' => number_format($curr['events_held'])]);
    }

    return $highlights;
  }

  /**
   * Gathers data for a specific period.
   */
  protected function gatherData(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $stats = [];
    $lastMonthOfPeriod = $end->modify('-1 month')->format('Y-m-01');

    $stats['members_active'] = 0;
    // Use the metrics service to calculate historical active counts on-the-fly
    // instead of relying on the snapshot table. 36 months covers the comparison periods.
    $activeCounts = $this->membershipMetrics->getMonthlyActiveMemberCounts(36);
    foreach ($activeCounts as $period) {
      if ($period['period'] === $lastMonthOfPeriod) {
        $stats['members_active'] = $period['count'];
        break;
      }
    }

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

    $query = $this->database->select('node__field_member_to_badge', 'mtb');
    $query->innerJoin('node_field_data', 'n', 'n.nid = mtb.entity_id');
    $query->innerJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid');
    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    $query->condition('status.field_badge_status_value', 'active');
    $query->condition('n.created', [$start->getTimestamp(), $end->getTimestamp()], 'BETWEEN');
    $query->addExpression('COUNT(DISTINCT n.nid)', 'total_badges');
    $stats['badges_earned'] = (int) $query->execute()->fetchField();

    // Total Visits (sum of daily unique entries)
    $dailyEntries = $this->utilizationData->getDailyUniqueEntries($start->getTimestamp(), $end->getTimestamp());
    $stats['total_visits'] = array_sum($dailyEntries);

    $appointmentData = $this->appointmentInsights->getFeedbackOutcomeSeries($start, $end);
    $stats['appointments'] = $appointmentData['totals']['appointments'] ?? 0;

    // Get lending stats from the service's summary data.
    $lendingStats = $this->lendingStats->collect();
    $lendingHistory = $lendingStats['chart_data']['full_history'] ?? [];
    $loanCount = 0;
    foreach ($lendingHistory as $monthData) {
      $monthDate = \DateTimeImmutable::createFromFormat('M Y', $monthData['label']);
      if ($monthDate && $monthDate >= $start && $monthDate < $end) {
        $loanCount += (int)($monthData['loans'] ?? 0);
      }
    }
    $stats['lending_loans'] = $loanCount;
    $stats['lending_borrowers'] = 0; // Set to 0 as we can't calculate this currently.

    // Get total member count (including paused)
    $query = $this->database->select('user__roles', 'ur');
    $query->condition('ur.roles_target_id', ['member', 'current_member'], 'IN');
    $query->addExpression('COUNT(DISTINCT ur.entity_id)', 'total_members');
    $stats['total_members_all'] = (int) $query->execute()->fetchField();

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
      
      // Special handling for metrics where we don't have historical data.
      if (in_array($key, ['members_active', 'total_members_all', 'total_visits'])) {
        $valPrev = NULL;
      }

      if ($valPrev !== NULL) {
        $diff = $valCurr - $valPrev;
        $pct = $valPrev > 0 ? round(($diff / $valPrev) * 100, 1) . '%' : ($valCurr > 0 ? '100%' : '0%');
        $diffClass = $diff >= 0 ? 'status-ok' : 'status-warning';
        $valPrevStr = $this->formatValue($valPrev, $info['format']);
        $diffStr = ($diff >= 0 ? '+' : '-') . $this->formatValue(abs($diff), $info['format']);
      }
      else {
        $valPrevStr = '-';
        $diffStr = '-';
        $pct = '-';
        $diffClass = '';
      }

      $valCurrStr = $this->formatValue($valCurr, $info['format']);

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
      
      // Special handling for Active Members & Total Visits: we don't have historical data yet.
      if (in_array($key, ['members_active', 'total_visits'])) {
        $valPrev = NULL;
      }

      // Special handling for the main member card.
      if ($key === 'members_active') {
        $cards[] = [
          'label' => $this->t('Total Members'),
          'current' => $this->formatValue($curr['total_members_all'] ?? 0, 'number'),
          'previous' => $this->t('Active: @count', ['@count' => $this->formatValue($curr['members_active'] ?? 0, 'number')]),
          'change' => '-',
          'percent' => '-',
          'trend_class' => 'trend-neutral',
        ];
        continue;
      }

      if ($valPrev !== NULL) {
        $diff = $valCurr - $valPrev;
        $pct = $valPrev > 0 ? round(($diff / $valPrev) * 100, 1) . '%' : ($valCurr > 0 ? '100%' : '0%');
        
        $trendClass = 'trend-neutral';
        if ($diff > 0) {
          $trendClass = 'trend-up';
        } elseif ($diff < 0) {
          $trendClass = 'trend-down';
        }
        $valPrevStr = $this->formatValue($valPrev, $info['format']);
        $changeStr = ($diff >= 0 ? '+' : '-') . $this->formatValue(abs($diff), $info['format']);
      } else {
        $pct = '-';
        $trendClass = 'trend-neutral';
        $valPrevStr = '-';
        $changeStr = '-';
      }

      $cards[] = [
        'label' => $info['label'],
        'current' => $this->formatValue($valCurr, $info['format']),
        'previous' => $valPrevStr,
        'change' => $changeStr,
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
      'members_active' => ['label' => 'Total Members', 'format' => 'number'],
      'total_joins' => ['label' => 'Total Joins (Year)', 'format' => 'number'],
      'events_held' => ['label' => 'Workshops Held (Year)', 'format' => 'number'],
      'event_registrations' => ['label' => 'Workshop Registrations', 'format' => 'number'],
      'appointments' => ['label' => 'Volunteer Appointments (Last 12 Months)', 'format' => 'number'],
      'lending_loans' => ['label' => 'Tool Loans (Last 12 Months)', 'format' => 'number'],
      'badges_earned' => ['label' => 'Badges Earned (Last 12 Months)', 'format' => 'number'],
      'total_visits' => ['label' => 'Total Visits (Last 12 Months)', 'format' => 'number'],
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
   * Helper to get goal counts for all currently active members.
   */
  protected function getActiveMemberGoals(): array {
    $query = $this->database->select('profile', 'p');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = u.uid');
    $query->leftJoin('profile__field_member_goal', 'goal', 'goal.entity_id = p.profile_id AND goal.deleted = 0');
    $query->addField('goal', 'field_member_goal_value', 'goal_value');
    
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->condition('u.status', 1);
    $query->condition('ur.roles_target_id', ['member', 'current_member'], 'IN');
    
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');
    $query->groupBy('goal.field_member_goal_value');
    
    $results = $query->execute();
    
    $labels = [
      'entrepreneur' => $this->t('Business entrepreneurship'),
      'seller' => $this->t('Produce products/art to sell'),
      'inventor' => $this->t('Develop a prototype/product'),
      'skill_builder' => $this->t('Learn new skills'),
      'hobbyist' => $this->t('Personal hobby projects'),
      'artist' => $this->t('Work on my art'),
      'networker' => $this->t('Meet other makers'),
      'other' => $this->t('Other goals'),
    ];

    $goals = [];
    foreach ($results as $record) {
        $goalValue = trim((string) ($record->goal_value ?? ''));
        if (empty($goalValue)) {
          continue;
        }
        $label = isset($labels[$goalValue]) ? (string) $labels[$goalValue] : ucfirst($goalValue);
        $key = $goalValue; // Keep machine name as key for filtering, label for display? 
        // Actually return array keyed by machine name with label and count?
        // Or just Label => Count.
        // I need machine name to filter for the Entrepreneur chart.
        $goals[$key] = [
            'label' => $label,
            'count' => (int) $record->member_count,
        ];
    }
    return $goals;
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
    
    $labels = [
      'entrepreneur' => $this->t('Business entrepreneurship'),
      'seller' => $this->t('Produce products/art to sell'),
      'inventor' => $this->t('Develop a prototype/product'),
      'skill_builder' => $this->t('Learn new skills'),
      'hobbyist' => $this->t('Personal hobby projects'),
      'artist' => $this->t('Work on my art'),
      'networker' => $this->t('Meet other makers'),
      'other' => $this->t('Other goals'),
    ];

    $goals = [];
    foreach ($results as $record) {
        $goalValue = trim((string) ($record->goal_value ?? ''));
        if (empty($goalValue)) {
          continue;
        }
        $label = isset($labels[$goalValue]) ? (string) $labels[$goalValue] : ucfirst($goalValue);
        $goals[$label] = (int) $record->member_count;
    }
    return $goals;
  }

  /**
   * Builds the charts section grouped by theme.
   */
  protected function buildCharts(\DateTimeImmutable $end): array {
    $groups = [
      'growth_retention' => ['#type' => 'container', '#title' => $this->t('Community Growth & Retention'), '#attributes' => ['class' => ['report-section']]],
      'demographics' => ['#type' => 'container', '#title' => $this->t('Demographics & Reach'), '#attributes' => ['class' => ['report-section']]],
      'education' => ['#type' => 'container', '#title' => $this->t('Education & Events'), '#attributes' => ['class' => ['report-section']]],
      'entrepreneurship' => ['#type' => 'container', '#title' => $this->t('Member Goals'), '#attributes' => ['class' => ['report-section']]],
      'usage' => ['#type' => 'container', '#title' => $this->t('Membership & Facility Usage'), '#attributes' => ['class' => ['report-section']]],
    ];

    $startDate = $end->modify('-24 months');

    // Discovery Sources
    $discoveryRows = $this->demographicsData->getDiscoveryDistribution();
    if ($discoveryRows) {
        // Filter out "Not captured"
        $discoveryRows = array_filter($discoveryRows, function($row) {
            return stripos($row['label'], 'Not captured') === FALSE;
        });
        // Reset keys to ensure JSON array output (not object)
        $discoveryRows = array_values($discoveryRows);
        
        if ($discoveryRows) {
            $discLabels = array_map(static fn(array $row) => (string) $row['label'], $discoveryRows);
            $discCounts = array_map(static fn(array $row) => (int) $row['count'], $discoveryRows);
            $groups['growth_retention']['discovery'] = [
                '#type' => 'details',
                '#title' => $this->t('How Members Discovered Us'),
                '#open' => TRUE,
                'chart' => [
                    '#type' => 'chart',
                    '#chart_type' => 'column',
                    'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $discLabels],
                    'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Members')],
                    'counts' => ['#type' => 'chart_data', '#title' => $this->t('Members'), '#data' => $discCounts, '#color' => '#0284c7'],
                ],
            ];
        }
    }

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
      $groups['growth_retention']['membership'] = [
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

    // Cohort & Retention
    $currentYear = (int) $end->format('Y');
    $startYear = $currentYear - 10;
    $cohorts = $this->membershipMetrics->getAnnualCohorts($startYear, $currentYear);
    if ($cohorts) {
      $cohortYears = array_map('strval', array_column($cohorts, 'year'));
      $cohortActive = array_column($cohorts, 'active');
      $cohortInactive = array_column($cohorts, 'inactive');
      $groups['growth_retention']['cohort_composition'] = [
        '#type' => 'details',
        '#title' => $this->t('Cohort Composition by Join Year'),
        '#open' => FALSE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'column',
          '#stacking' => TRUE,
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

    // Appointment Trend
    $appointmentSeries = $this->appointmentInsights->getFeedbackOutcomeSeries($startDate, $end->modify('-1 day'));
    if (!empty($appointmentSeries['labels'])) {
      // Sum up all outcomes for total appointment count per month
      $monthlyTotals = [];
      foreach ($appointmentSeries['month_keys'] as $index => $monthKey) {
        $total = 0;
        foreach ($appointmentSeries['results'] as $resultSet) {
          $total += $resultSet[$index] ?? 0;
        }
        $monthlyTotals[] = $total;
      }

      $groups['education']['appointments'] = [
        '#type' => 'details',
        '#title' => $this->t('Appointment Attendance (2 Years)'),
        '#open' => TRUE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'column',
          'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $appointmentSeries['labels']],
          'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Appointments')],
          'appointments' => ['#type' => 'chart_data', '#title' => $this->t('Monthly Appointments'), '#data' => $monthlyTotals, '#color' => '#10b981'],
        ],
      ];
    }


    
    // Badge Tenure Correlation
    $badgeTenureData = $this->membershipMetrics->getBadgeTenureCorrelation();
    if ($badgeTenureData) {
        $badgeTenureLabels = [];
        $badgeTenureAvg = [];
        foreach ($badgeTenureData as $bucket) {
            $memberCount = (int) ($bucket['member_count'] ?? 0);
            $badgeTenureLabels[] = $this->formatBucketLabel($bucket, $memberCount);
            $badgeTenureAvg[] = $bucket['average_years'] ? round($bucket['average_years'], 2) : 0;
        }

        $groups['growth_retention']['badge_tenure'] = [
            '#type' => 'details',
            '#title' => $this->t('Badges vs. Membership Tenure'),
            '#open' => TRUE,
            'chart' => [
                '#type' => 'chart',
                '#chart_type' => 'column',
                'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $badgeTenureLabels],
                'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Average Years')],
                'avg' => ['#type' => 'chart_data', '#title' => $this->t('Average Tenure (Years)'), '#data' => $badgeTenureAvg, '#color' => '#0ea5e9'],
            ],
        ];
    }

    // Members by Badges Earned
    $badgeCountData = $this->membershipMetrics->getBadgeCountDistribution();
    if ($badgeCountData) {
        $badgeCountLabels = [];
        $badgeCountValues = [];
        $hasMembers = FALSE;
        foreach ($badgeCountData as $bucket) {
            $count = (int) ($bucket['member_count'] ?? 0);
            $badgeCountLabels[] = $this->formatBucketLabel($bucket, $count);
            $badgeCountValues[] = $count;
            if ($count > 0) $hasMembers = TRUE;
        }

        if ($hasMembers) {
            $groups['education']['badge_count'] = [
                '#type' => 'details',
                '#title' => $this->t('Members by Badges Earned'),
                '#open' => TRUE,
                'chart' => [
                    '#type' => 'chart',
                    '#chart_type' => 'column',
                    'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $badgeCountLabels],
                    'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Members')],
                    'members' => ['#type' => 'chart_data', '#title' => $this->t('Members'), '#data' => $badgeCountValues, '#color' => '#16a34a'],
                ],
            ];
        }
    }

    // Monthly Badges Issued
    $badgeIssuance = $this->engagementData->getMonthlyBadgeIssuance($startDate, $end->modify('-1 day'));
    if (!empty($badgeIssuance['labels'])) {
        $groups['education']['badge_issuance'] = [
            '#type' => 'details',
            '#title' => $this->t('Monthly Badges Issued (2 Years)'),
            '#open' => TRUE,
            'chart' => [
                '#type' => 'chart',
                '#chart_type' => 'column',
                'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $badgeIssuance['labels']],
                'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Badges')],
                'badges' => ['#type' => 'chart_data', '#title' => $this->t('Badges Issued'), '#data' => $badgeIssuance['counts'], '#color' => '#8b5cf6'],
            ],
        ];
    }

    // Active Members with Entrepreneurship-related Goals
    $activeGoals = $this->getActiveMemberGoals();
    
    // 1. Filtered Entrepreneurship Chart
    $targetGoals = ['entrepreneur', 'seller', 'inventor'];
    $entLabels = [];
    $entValues = [];
    $entColors = [];
    $colorMap = [
      'entrepreneur' => '#ea580c',
      'seller' => '#0ea5e9',
      'inventor' => '#6366f1',
    ];

    foreach ($targetGoals as $key) {
      if (isset($activeGoals[$key])) {
        $entLabels[] = $activeGoals[$key]['label'];
        $entValues[] = $activeGoals[$key]['count'];
        $entColors[] = $colorMap[$key] ?? '#94a3b8';
      }
    }

    if ($entValues) {
        $groups['entrepreneurship']['entrepreneurship_goals'] = [
            '#type' => 'details',
            '#title' => $this->t('Active Members with Entrepreneurship-related Goals'),
            '#open' => TRUE,
            'chart' => [
                '#type' => 'chart',
                '#chart_type' => 'bar',
                '#options' => ['indexAxis' => 'y'], // Horizontal
                'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $entLabels],
                'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Members')],
                'members' => ['#type' => 'chart_data', '#title' => $this->t('Members'), '#data' => $entValues, '#color' => '#ea580c'],
            ],
        ];
    }

    // 2. All Goals Chart
    if ($activeGoals) {
      // Sort by count desc
      uasort($activeGoals, fn($a, $b) => $b['count'] <=> $a['count']);
      $allLabels = array_column($activeGoals, 'label');
      $allValues = array_column($activeGoals, 'count');

      $groups['entrepreneurship']['all_goals'] = [
        '#type' => 'details',
        '#title' => $this->t('Member Goals Breakdown'),
        '#open' => FALSE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'bar',
          '#options' => ['indexAxis' => 'y'], // Horizontal
          'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $allLabels],
          'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Members')],
          'members' => ['#type' => 'chart_data', '#title' => $this->t('Members'), '#data' => $allValues, '#color' => '#10b981'],
        ],
      ];
    }


    // --- DIVERSITY ---
    // Member Map (Heatmap)
    $mapId = Html::getUniqueId('member-location-map');

    $groups['demographics']['map'] = [
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
      
      $groups['demographics']['ethnicity_dist'] = [
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
      $groups['demographics']['gender_dist'] = [
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

        $groups['demographics']['age_dist'] = [
            '#type' => 'details',
            '#title' => $this->t('Age Distribution'),
            '#open' => FALSE,
            'chart' => [
                '#type' => 'chart',
                '#chart_type' => 'column',
                '#options' => [
                  'trendlines' => [
                    0 => [
                      'type' => 'polynomial',
                      'degree' => 3,
                      'color' => '#16a34a',
                      'lineWidth' => 3,
                      'opacity' => 0.8,
                      'visibleInLegend' => TRUE,
                    ],
                  ],
                ],
                'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $ageLabels],
                'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Members')],
                'ages' => ['#type' => 'chart_data', '#title' => $this->t('Members'), '#data' => $ageCounts, '#color' => '#6366f1'],
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

        $groups['demographics']['saturation'] = [
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


    // --- USAGE ---
    // Entries
    $usageStartTimestamp = $startDate instanceof \DateTimeInterface
      ? $startDate->getTimestamp()
      : (int) $startDate;
    $now = new \DateTimeImmutable();
    $dailyEntries = $this->utilizationData->getDailyUniqueEntries($usageStartTimestamp, $now->getTimestamp());
    $monthlyEntries = [];
    foreach ($dailyEntries as $date => $count) {
      $month = substr($date, 0, 7);
      if (!isset($monthlyEntries[$month])) $monthlyEntries[$month] = 0;
      $monthlyEntries[$month] += $count;
    }

    // Filter to start from October 2024
    $monthlyEntries = array_filter(
      $monthlyEntries,
      fn($month) => strtotime($month . '-01') >= strtotime('2024-10-01'),
      ARRAY_FILTER_USE_KEY
    );

    if ($monthlyEntries) {
      $entryLabels = [];
      $entryValues = [];
      foreach ($monthlyEntries as $month => $count) {
        $entryLabels[] = (new \DateTimeImmutable($month . '-01'))->format('M Y');
        $entryValues[] = $count;
      }
      $groups['usage']['entries'] = [
        '#type' => 'details',
        '#title' => $this->t('Total Monthly Visits'),
        '#open' => TRUE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'column',
          'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $entryLabels],
          'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Total Visits')],
          'visits' => ['#type' => 'chart_data', '#title' => $this->t('Total Visits'), '#data' => $entryValues, '#color' => '#10b981'],
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

    // Average Members by Day of Week
    if (!empty($windowMetrics['weekday_labels']) && !empty($windowMetrics['weekday_averages'])) {
      $groups['usage']['weekday_profile'] = [
        '#type' => 'details',
        '#title' => $this->t('Average Members by Day of Week'),
        '#open' => TRUE,
        'chart' => [
          '#type' => 'chart',
          '#chart_type' => 'column',
          'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $windowMetrics['weekday_labels']],
          'yaxis' => ['#type' => 'chart_yaxis', '#title' => $this->t('Average Members')],
          'average' => ['#type' => 'chart_data', '#title' => $this->t('Average Members'), '#data' => $windowMetrics['weekday_averages'], '#color' => '#4f46e5'],
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

    return $groups;
  }

  /**
   * Formats a bucket label for display.
   */
  protected function formatBucketLabel(array $bucket, int $memberCount = 0): string {
    $min = (int) ($bucket['badge_min'] ?? 0);
    $max = $bucket['badge_max'];

    if ($min === 1 && $max === 1) {
      $label = (string) $this->t('1 badge');
    }
    elseif ($max === NULL) {
      $label = (string) $this->t('@min+ badges', ['@min' => $min]);
    }
    elseif ($min === $max) {
      $label = (string) $this->t('@count badges', ['@count' => $min]);
    }
    else {
      $label = (string) $this->t('@min-@max badges', ['@min' => $min, '@max' => (int) $max]);
    }

    if ($memberCount > 0) {
      $label = (string) $this->t('@label (@count)', [
        '@label' => $label,
        '@count' => $memberCount,
      ]);
    }
    return $label;
  }

}
