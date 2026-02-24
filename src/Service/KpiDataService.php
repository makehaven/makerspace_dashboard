<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Service for fetching and calculating Key Performance Indicator (KPI) data.
 *
 * This service aggregates data from various other services to provide the
 * calculated KPI values for the dashboard.
 */
class KpiDataService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The financial data service.
   *
   * @var \Drupal\makerspace_dashboard\Service\FinancialDataService
   */
  protected $financialDataService;

  /**
   * The Google Sheet client service.
   *
   * @var \Drupal\makerspace_dashboard\Service\GoogleSheetClientService
   */
  protected $googleSheetClientService;

  /**
   * The events and membership data service.
   *
   * @var \Drupal\makerspace_dashboard\Service\EventsMembershipDataService
   */
  protected $eventsMembershipDataService;

  /**
   * The demographics data service.
   *
   * @var \Drupal\makerspace_dashboard\Service\DemographicsDataService
   */
  protected $demographicsDataService;

  /**
   * The snapshot data service.
   *
   * @var \Drupal\makerspace_dashboard\Service\SnapshotDataService
   */
  protected $snapshotDataService;

  /**
   * The membership metrics service.
   *
   * @var \Drupal\makerspace_dashboard\Service\MembershipMetricsService
   */
  protected $membershipMetricsService;

  /**
   * The utilization data service.
   *
   * @var \Drupal\makerspace_dashboard\Service\UtilizationDataService
   */
  protected $utilizationDataService;

  /**
   * Governance board composition data service.
   *
   * @var \Drupal\makerspace_dashboard\Service\GovernanceBoardDataService
   */
  protected $governanceBoardDataService;

  /**
   * Education evaluation metrics data service.
   *
   * @var \Drupal\makerspace_dashboard\Service\EducationEvaluationDataService
   */
  protected $educationEvaluationDataService;

  /**
   * Development-focused fundraising metrics service.
   *
   * @var \Drupal\makerspace_dashboard\Service\DevelopmentDataService
   */
  protected $developmentDataService;

  /**
   * Entrepreneurship metrics service.
   *
   * @var \Drupal\makerspace_dashboard\Service\EntrepreneurshipDataService
   */
  protected $entrepreneurshipDataService;

  /**
   * Funnel metrics service.
   *
   * @var \Drupal\makerspace_dashboard\Service\FunnelDataService
   */
  protected $funnelDataService;

  /**
   * Member success lifecycle and risk metrics service.
   *
   * @var \Drupal\makerspace_dashboard\Service\MemberSuccessDataService
   */
  protected $memberSuccessDataService;

  /**
   * Sheet-sourced KPI goal overrides cache.
   *
   * @var array|null
   */
  protected ?array $sheetGoalCache = NULL;

  /**
   * Sheet-sourced annual target cache keyed by KPI ID.
   *
   * @var array|null
   */
  protected ?array $sheetAnnualTargets = NULL;

  /**
   * Cached Income-Statement sheet data.
   *
   * @var array|null
   */
  protected ?array $incomeStatementTable = NULL;

  /**
   * Infrastructure data service.
   *
   * @var \Drupal\makerspace_dashboard\Service\InfrastructureDataService
   */
  protected $infrastructureDataService;

  /**
   * Constructs a new KpiDataService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\makerspace_dashboard\Service\FinancialDataService $financial_data_service
   *   The financial data service.
   * @param \Drupal\makerspace_dashboard\Service\GoogleSheetClientService $google_sheet_client_service
   *   The Google Sheet client service.
   * @param \Drupal\makerspace_dashboard\Service\EventsMembershipDataService $events_membership_data_service
   *   The events and membership data service.
   * @param \Drupal\makerspace_dashboard\Service\DemographicsDataService $demographics_data_service
   *   The demographics data service.
   * @param \Drupal\makerspace_dashboard\Service\SnapshotDataService $snapshot_data_service
   *   The snapshot data service.
   * @param \Drupal\makerspace_dashboard\Service\MembershipMetricsService $membership_metrics_service
   *   The membership metrics service.
   * @param \Drupal\makerspace_dashboard\Service\UtilizationDataService $utilization_data_service
   *   The utilization data service.
   * @param \Drupal\makerspace_dashboard\Service\GovernanceBoardDataService $governance_board_data_service
   *   Governance board composition data service.
   * @param \Drupal\makerspace_dashboard\Service\EducationEvaluationDataService $education_evaluation_data_service
   *   Education evaluation metrics data service.
   * @param \Drupal\makerspace_dashboard\Service\DevelopmentDataService $development_data_service
   *   Development-focused fundraising metrics service.
   * @param \Drupal\makerspace_dashboard\Service\EntrepreneurshipDataService $entrepreneurship_data_service
   *   Entrepreneurship metrics service.
   * @param \Drupal\makerspace_dashboard\Service\FunnelDataService $funnel_data_service
   *   Funnel metrics service.
   * @param \Drupal\makerspace_dashboard\Service\MemberSuccessDataService $member_success_data_service
   *   Member success lifecycle and risk metrics service.
   * @param \Drupal\makerspace_dashboard\Service\InfrastructureDataService $infrastructure_data_service
   *   Infrastructure data service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, FinancialDataService $financial_data_service, GoogleSheetClientService $google_sheet_client_service, EventsMembershipDataService $events_membership_data_service, DemographicsDataService $demographics_data_service, SnapshotDataService $snapshot_data_service, MembershipMetricsService $membership_metrics_service, UtilizationDataService $utilization_data_service, GovernanceBoardDataService $governance_board_data_service, EducationEvaluationDataService $education_evaluation_data_service, DevelopmentDataService $development_data_service, EntrepreneurshipDataService $entrepreneurship_data_service, FunnelDataService $funnel_data_service, MemberSuccessDataService $member_success_data_service, InfrastructureDataService $infrastructure_data_service) {
    $this->configFactory = $config_factory;
    $this->financialDataService = $financial_data_service;
    $this->googleSheetClientService = $google_sheet_client_service;
    $this->eventsMembershipDataService = $events_membership_data_service;
    $this->demographicsDataService = $demographics_data_service;
    $this->snapshotDataService = $snapshot_data_service;
    $this->membershipMetricsService = $membership_metrics_service;
    $this->utilizationDataService = $utilization_data_service;
    $this->governanceBoardDataService = $governance_board_data_service;
    $this->educationEvaluationDataService = $education_evaluation_data_service;
    $this->developmentDataService = $development_data_service;
    $this->entrepreneurshipDataService = $entrepreneurship_data_service;
    $this->funnelDataService = $funnel_data_service;
    $this->memberSuccessDataService = $member_success_data_service;
    $this->infrastructureDataService = $infrastructure_data_service;
  }

  /**
   * Gets all KPI data for a given section.
   *
   * @param string $section_id
   *   The ID of the section.
   *
   * @return array
   *   An array of KPI data for the section.
   */
  public function getKpiData(string $section_id): array {
    $kpi_config = $this->getMergedKpiDefinitions($section_id);

    if (!$kpi_config) {
      return [];
    }

    $kpi_data = [];
    $allPriorities = $this->getSectionPriorities();

    foreach ($kpi_config as $kpi_id => $kpi_info) {
      $method_name = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $kpi_id))) . 'Data';
      if (method_exists($this, $method_name)) {
        $result = $this->{$method_name}($kpi_info);
      }
      else {
        // Fallback for KPIs that don't have a dedicated method yet.
        \Drupal::logger('makerspace_dashboard')->notice(
          'KPI @id has no dedicated calculation method; showing placeholder. Add get@method() to KpiDataService.',
          ['@id' => $kpi_id, '@method' => str_replace(' ', '', ucwords(str_replace('_', ' ', $kpi_id))) . 'Data']
        );
        $result = $this->getPlaceholderData($kpi_info, $kpi_id);
      }

      if (!empty($result['status']) && $result['status'] === 'draft') {
        continue;
      }

      $kpi_data[$kpi_id] = $result;
    }

    return $kpi_data;
  }

  /**
   * Builds merged KPI definitions from configuration and static metadata.
   *
   * @param string $section_id
   *   The dashboard section machine name.
   *
   * @return array
   *   An array of KPI definition arrays keyed by KPI ID.
   */
  public function getAllKpiDefinitions(): array {
    $definitions = [];
    $metadataSections = array_keys($this->getStaticKpiMetadata(NULL));
    foreach ($metadataSections as $sectionId) {
      $definitions[$sectionId] = $this->getMergedKpiDefinitions($sectionId);
    }

    $configured = $this->configFactory->get('makerspace_dashboard.kpis')->getRawData() ?? [];
    foreach (array_keys($configured) as $sectionId) {
      if (!isset($definitions[$sectionId])) {
        $definitions[$sectionId] = $this->getMergedKpiDefinitions($sectionId);
      }
    }

    ksort($definitions);
    return $definitions;
  }

  public function getSectionKpiDefinitions(string $section_id): array {
    return $this->getMergedKpiDefinitions($section_id);
  }

  private function getMergedKpiDefinitions(string $section_id): array {
    $config = $this->configFactory->get('makerspace_dashboard.kpis');
    $configured = $config->get($section_id) ?? [];
    $metadata = $this->getStaticKpiMetadata($section_id);
    $goalOverrides = $this->getSheetGoalOverrides();
    $annualGoalTargets = $this->getSheetAnnualTargets();

    $kpi_ids = array_unique(array_merge(array_keys($metadata), array_keys($configured)));
    if (!$kpi_ids) {
      return [];
    }

    $definitions = [];
    foreach ($kpi_ids as $kpi_id) {
      $definition = $metadata[$kpi_id] ?? [];
      $config_values = $configured[$kpi_id] ?? [];

      $definition = array_merge($definition, $config_values);
      if (!isset($definition['label']) || $definition['label'] === '') {
        $definition['label'] = $kpi_id;
      }
      if (empty($definition['description']) && isset($metadata[$kpi_id]['description'])) {
        $definition['description'] = $metadata[$kpi_id]['description'];
      }
      if (isset($goalOverrides[$kpi_id])) {
        $definition['goal_2030'] = $goalOverrides[$kpi_id];
      }
      if (isset($annualGoalTargets[$kpi_id])) {
        if (!isset($definition['annual_values'])) {
          $definition['annual_values'] = [];
        }
        foreach ($annualGoalTargets[$kpi_id] as $year => $value) {
          $definition['annual_values'][(string) $year] = $value;
        }
      }

      if (!empty($definition['status']) && $definition['status'] === 'draft') {
        continue;
      }

      $definitions[$kpi_id] = $definition;
    }

    return $this->applySectionKpiPriorities($section_id, $definitions);
  }

  /**
   * Applies section-specific KPI limits/priorities for dashboard scanning.
   */
  private function applySectionKpiPriorities(string $section_id, array $definitions): array {
    if (empty($definitions)) {
      return $definitions;
    }

    $priorityBySection = $this->getSectionPriorities();

    if (empty($priorityBySection[$section_id])) {
      return $definitions;
    }

    $priority = $priorityBySection[$section_id];
    $filtered = [];
    foreach ($priority as $kpi_id) {
      if (isset($definitions[$kpi_id])) {
        $filtered[$kpi_id] = $definitions[$kpi_id];
      }
    }

    return $filtered;
  }

  /**
   * Returns the canonical priority lists for each dashboard section.
   */
  private function getSectionPriorities(): array {
    return [
      // Keep outreach KPI rows focused on growth plus core funnel conversion signals.
      'outreach' => [
        'kpi_total_new_member_signups',
        'kpi_total_first_time_workshop_participants',
        'kpi_total_new_recurring_revenue',
        'kpi_tours',
        'kpi_tours_to_member_conversion',
        'kpi_guest_waiver_to_member_conversion',
        'kpi_event_participant_to_member_conversion',
      ],
      // Keep retention KPI rows focused on membership scale, health, and early activation.
      'retention' => [
        'kpi_total_active_members',
        'kpi_first_year_member_retention',
        'kpi_member_post_12_month_retention',
        'kpi_retention_poc',
        'kpi_member_nps',
        'kpi_active_participation',
        'kpi_active_participation_bipoc',
        'kpi_active_participation_female_nb',
        'kpi_new_member_first_badge_28_days',
        'kpi_members_at_risk_share',
        'kpi_membership_diversity_bipoc',
      ],
      'dei' => [],
      'finance' => [
        'kpi_reserve_funds_months',
        'kpi_earned_income_sustaining_core',
        'kpi_member_revenue_quarterly',
        'kpi_member_lifetime_value_projected',
        'kpi_revenue_per_member_index',
        'kpi_monthly_revenue_at_risk',
        'kpi_payment_resolution_rate',
      ],
      'infrastructure' => [
        'kpi_equipment_uptime_rate',
        'kpi_active_maintenance_load',
        'kpi_storage_occupancy',
        'kpi_equipment_investment',
        'kpi_adherence_to_shop_budget',
      ],
      'education' => [
        'kpi_workshop_attendees',
        'kpi_workshop_capacity_utilization',
        'kpi_program_capacity_utilization',
        'kpi_workshop_program_capacity_utilization',
        'kpi_education_nps',
        'kpi_workshop_participants_bipoc',
        'kpi_active_instructors_bipoc',
        'kpi_net_income_education',
      ],
      'development' => [
        'kpi_recurring_donors_count',
        'kpi_annual_corporate_sponsorships',
        'kpi_grant_pipeline_count',
        'kpi_grant_win_ratio',
        'kpi_donor_retention_rate',
        'kpi_donor_upgrades_count',
      ],
      'entrepreneurship' => [
        'kpi_incubator_workspace_occupancy',
        'kpi_active_incubator_ventures',
        'kpi_entrepreneurship_event_participation',
        'kpi_entrepreneurship_joins_rate',
        'kpi_entrepreneurship_retention',
      ],
      'governance' => [
        'kpi_board_ethnic_diversity',
        'kpi_board_gender_diversity',
        'kpi_board_governance',
        'kpi_committee_effectiveness',
      ],
      'overview' => [
        'kpi_total_active_members',
        'kpi_workshop_attendees',
        'kpi_reserve_funds_months',
        'kpi_board_governance',
        'kpi_committee_effectiveness',
        'kpi_entrepreneurship_joins_rate',
        'kpi_entrepreneurship_retention',
      ],
    ];
  }

  /**
   * Gets placeholder data for a KPI.
   *
   * @param array $kpi_info
   *   The KPI configuration info.
   *
   * @return array
   *   The placeholder KPI data.
   */
  private function getPlaceholderData(array $kpi_info, ?string $kpiId = NULL): array {
    return $this->buildKpiResult($kpi_info, [], [], NULL, NULL, NULL, NULL, $kpiId);
  }

  /**
   * Normalizes common KPI result structure.
   *
   * @param array $kpi_info
   *   The KPI definition from configuration/metadata.
   * @param array $annualOverrides
   *   Optional keyed array of year => value overrides.
   * @param array $trend
   *   Numeric values used to render the 12 month sparkline.
   * @param float|null $ttm12
   *   Trailing 12 month average (or other value depending on KPI).
   * @param float|null $ttm3
   *   Trailing 3 month average (or other value depending on KPI).
   * @param string|null $lastUpdated
   *   ISO date string representing the freshest snapshot.
   * @param mixed $current
   *   Latest KPI value to display in the dashboard table.
   *
   * @return array
   *   A normalized KPI payload.
   */
  private function buildKpiResult(array $kpi_info, array $annualOverrides = [], array $trend = [], ?float $ttm12 = NULL, ?float $ttm3 = NULL, ?string $lastUpdated = NULL, $current = NULL, ?string $kpiId = NULL, ?string $displayFormat = NULL, ?string $sourceNote = NULL, ?string $trendLabel = NULL, ?string $currentPeriodLabel = NULL, ?float $periodFraction = NULL): array {
    if ($kpiId) {
      $snapshotDefaults = $this->buildSnapshotTrendDefaults($kpiId);
      if (!empty($snapshotDefaults['annual'])) {
        foreach ($snapshotDefaults['annual'] as $year => $value) {
          if (!isset($annualOverrides[(string) $year])) {
            $annualOverrides[(string) $year] = $value;
          }
        }
      }
      if (empty($trend) && !empty($snapshotDefaults['trend'])) {
        $trend = $snapshotDefaults['trend'];
      }
      if ($ttm12 === NULL && isset($snapshotDefaults['ttm12'])) {
        $ttm12 = $snapshotDefaults['ttm12'];
      }
      if ($ttm3 === NULL && isset($snapshotDefaults['ttm3'])) {
        $ttm3 = $snapshotDefaults['ttm3'];
      }
      if ($lastUpdated === NULL && isset($snapshotDefaults['last_updated'])) {
        $lastUpdated = $snapshotDefaults['last_updated'];
      }

      $isMissingCurrent = $current === NULL
        || (is_string($current) && in_array(strtolower(trim($current)), ['', 'tbd', 'n/a'], TRUE));
      if ($isMissingCurrent && array_key_exists('current', $snapshotDefaults) && $snapshotDefaults['current'] !== NULL) {
        $current = $snapshotDefaults['current'];
      }
    }

    $annual = $kpi_info['annual_values'] ?? [];
    foreach ($annualOverrides as $year => $value) {
      $annual[(string) $year] = $value;
    }
    if ($annual) {
      ksort($annual, SORT_STRING);
    }
    $currentYear = (int) date('Y');
    $sheetTargets = $kpiId ? ($this->getSheetAnnualTargets()[$kpiId] ?? []) : [];
    $goalYear = $this->determineGoalYear($annual, $sheetTargets, $currentYear);
    $goalKey = (string) $goalYear;
    
    // Prioritize spreadsheet goals for the Goal column.
    $goalCurrentYear = $sheetTargets[$goalKey] ?? $annual[$goalKey] ?? NULL;

    return [
      'label' => $kpi_info['label'] ?? '',
      'base_2025' => $kpi_info['base_2025'] ?? NULL,
      'goal_2030' => $kpi_info['goal_2030'] ?? NULL,
      'goal_direction' => $kpi_info['goal_direction'] ?? 'higher',
      'goal_current_year' => $goalCurrentYear,
      'goal_current_year_label' => $goalYear,
      'annual_values' => $annual,
      'ttm_12' => $ttm12,
      'ttm_3' => $ttm3,
      'trend' => $trend,
      'description' => $kpi_info['description'] ?? '',
      'last_updated' => $lastUpdated,
      'current' => $current ?? 'TBD',
      'display_format' => $displayFormat,
      'source_note' => $sourceNote,
      'trend_label' => $trendLabel,
      'current_period_label' => $currentPeriodLabel,
      'period_fraction' => $periodFraction,
    ];
  }

  /**
   * Builds fallback trend/current metadata from KPI snapshot records.
   */
  private function buildSnapshotTrendDefaults(string $kpiId): array {
    $series = $this->snapshotDataService->getKpiMetricSeries($kpiId);
    if (empty($series)) {
      return [];
    }

    $values = [];
    $annual = [];
    $lastSnapshot = NULL;
    foreach ($series as $record) {
      $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
      if ($value === NULL) {
        continue;
      }
      $values[] = $value;
      $lastSnapshot = $record;

      if ($this->isAnnualSnapshotRecord($record)) {
        $year = NULL;
        if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
          $year = $record['snapshot_date']->format('Y');
        }
        elseif (!empty($record['period_year'])) {
          $year = (string) $record['period_year'];
        }
        if ($year !== NULL) {
          $annual[$year] = $value;
        }
      }
    }

    if (!$values) {
      return [
        'annual' => $annual,
      ];
    }

    $lastUpdated = NULL;
    if ($lastSnapshot) {
      if (!empty($lastSnapshot['snapshot_date']) && $lastSnapshot['snapshot_date'] instanceof \DateTimeImmutable) {
        $lastUpdated = $lastSnapshot['snapshot_date']->format('Y-m-d');
      }
      elseif (!empty($lastSnapshot['period_year'])) {
        $month = (int) ($lastSnapshot['period_month'] ?? 1);
        $lastUpdated = sprintf('%04d-%02d-01', (int) $lastSnapshot['period_year'], $month);
      }
    }

    return [
      'annual' => $annual,
      'trend' => array_slice($values, -12),
      'ttm12' => $this->calculateTrailingAverage($values, 12),
      'ttm3' => $this->calculateTrailingAverage($values, 3),
      'current' => end($values),
      'last_updated' => $lastUpdated,
    ];
  }

  /**
   * Gets the data for the "Total # Active Members" KPI.
   *
   * @param array $kpi_info
   *   The KPI configuration info.
   *
   * @return array
   *   The KPI data.
   */
  private function getKpiTotalActiveMembersData(array $kpi_info): array {
    $kpiSeries = $this->snapshotDataService->getKpiMetricSeries('kpi_total_active_members');
    $annualOverrides = [];
    $kpiResult = NULL;
    $kpiLastDate = NULL;

    if (!empty($kpiSeries)) {
      $values = [];
      $lastSnapshot = NULL;
      $current = NULL;
      foreach ($kpiSeries as $record) {
        $snapshotDate = $record['snapshot_date'] ?? NULL;
        if ($snapshotDate instanceof \DateTimeImmutable) {
          $year = $snapshotDate->format('Y');
        }
        elseif (!empty($record['period_year'])) {
          $year = (string) $record['period_year'];
        }
        else {
          continue;
        }

        $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
        if ($value === NULL) {
          continue;
        }

        if ($this->isAnnualSnapshotRecord($record)) {
          $annualOverrides[$year] = $value;
        }
        $values[] = $value;
        $lastSnapshot = $record;
        $current = $value;
      }

      if ($values) {
        $trend = array_slice($values, -12);
        $ttm12 = $this->calculateTrailingAverage($values, 12);
        $ttm3 = $this->calculateTrailingAverage($values, 3);
        $lastUpdated = NULL;
        if ($lastSnapshot) {
          if (!empty($lastSnapshot['snapshot_date']) && $lastSnapshot['snapshot_date'] instanceof \DateTimeImmutable) {
            $lastUpdated = $lastSnapshot['snapshot_date']->format('Y-m-d');
          }
          elseif (!empty($lastSnapshot['period_year'])) {
            $month = (int) ($lastSnapshot['period_month'] ?? 1);
            $lastUpdated = sprintf('%04d-%02d-01', (int) $lastSnapshot['period_year'], $month);
          }
        }

        if ($lastUpdated) {
          try {
            $kpiLastDate = new \DateTimeImmutable($lastUpdated);
          }
          catch (\Exception $exception) {
            $kpiLastDate = NULL;
          }
        }

        $kpiResult = $this->withDemographicSegments(
          $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'kpi_total_active_members'),
          'kpi_total_active_members'
        );
      }
    }

    $series = $this->snapshotDataService->getMembershipCountSeries('month');
    if (!empty($series)) {
      $values = [];
      $lastSnapshotDate = NULL;
      $current = NULL;
      foreach ($series as $row) {
        $snapshotDate = NULL;
        if (!empty($row['snapshot_date']) && $row['snapshot_date'] instanceof \DateTimeImmutable) {
          $snapshotDate = $row['snapshot_date'];
        }
        elseif (!empty($row['period_date']) && $row['period_date'] instanceof \DateTimeImmutable) {
          $snapshotDate = $row['period_date'];
        }
        if (!$snapshotDate) {
          continue;
        }
        $year = $snapshotDate->format('Y');
        $membersActive = is_numeric($row['members_active']) ? (float) $row['members_active'] : NULL;
        if ($membersActive === NULL) {
          continue;
        }
        $values[] = $membersActive;
        $lastSnapshotDate = $snapshotDate;
        $current = $membersActive;
      }

      if ($values) {
        $trend = array_slice($values, -12);
        $ttm12 = $this->calculateTrailingAverage($values, 12);
        $ttm3 = $this->calculateTrailingAverage($values, 3);
        $lastUpdated = $lastSnapshotDate ? $lastSnapshotDate->format('Y-m-d') : NULL;
        $membershipResult = $this->withDemographicSegments(
          $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'kpi_total_active_members'),
          'kpi_total_active_members'
        );

        // Prefer membership_totals snapshots when they are newer than KPI facts.
        if (!$kpiResult || !$kpiLastDate || ($lastSnapshotDate && $lastSnapshotDate > $kpiLastDate)) {
          return $membershipResult;
        }
      }
    }

    if ($kpiResult) {
      return $kpiResult;
    }

    $membershipSeries = $this->membershipMetricsService->getMonthlyActiveMemberCounts(36);
    if (empty($membershipSeries)) {
      return $this->withDemographicSegments(
        $this->buildKpiResult($kpi_info, $annualOverrides, [], NULL, NULL, NULL, NULL, 'kpi_total_active_members'),
        'kpi_total_active_members'
      );
    }

    $values = [];
    $lastUpdated = NULL;
    $current = NULL;
    foreach ($membershipSeries as $record) {
      $count = isset($record['count']) ? (float) $record['count'] : NULL;
      if ($count === NULL) {
        continue;
      }
      $values[] = $count;
      $current = $count;
      $lastUpdated = $record['date'] ?? ($record['period'] ?? NULL);
    }

    if (!$values) {
      return $this->withDemographicSegments(
        $this->buildKpiResult($kpi_info, $annualOverrides, [], NULL, NULL, NULL, NULL, 'kpi_total_active_members'),
        'kpi_total_active_members'
      );
    }

    $trend = array_slice($values, -12);
    $ttm12 = $this->calculateTrailingAverage($values, 12);
    $ttm3 = $this->calculateTrailingAverage($values, 3);

    return $this->withDemographicSegments(
      $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'kpi_total_active_members', NULL, NULL, 'Last 12 months'),
      'kpi_total_active_members'
    );
  }

  /**
   * Gets the data for the "# of Workshop Attendees" KPI.
   *
   * @param array $kpi_info
   *   The KPI configuration info.
  *
  * @return array
  *   The KPI data.
  */
  private function getKpiWorkshopAttendeesData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $now = new \DateTimeImmutable('first day of this month');
    $start = $now->modify('-35 months');
    $monthlySeries = $this->eventsMembershipDataService->getMonthlyWorkshopAttendanceSeries($start, $now);
    $monthlyItems = $monthlySeries['items'] ?? [];

    if (!empty($monthlyItems)) {
      $monthlyCounts = array_map(static function (array $item): float {
        return isset($item['count']) ? (float) $item['count'] : 0.0;
      }, $monthlyItems);

      $trend = array_slice($monthlyCounts, -12);
      $ttm12 = $this->calculateTrailingAverage($monthlyCounts, 12);
      $ttm3 = $this->calculateTrailingAverage($monthlyCounts, 3);
      $currentSum = $this->calculateTrailingSum($monthlyCounts, 12);
      if ($currentSum !== NULL) {
        $current = (int) round($currentSum);
      }

      $lastItem = $monthlyItems[count($monthlyItems) - 1];
      if (!empty($lastItem['date']) && $lastItem['date'] instanceof \DateTimeImmutable) {
        $lastUpdated = $lastItem['date']->format('Y-m-d');
      }

    }

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('kpi_workshop_attendees');
    if (!empty($snapshotSeries)) {
      $snapshotValues = [];
      $lastSnapshot = NULL;
      foreach ($snapshotSeries as $record) {
        $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
        if ($value === NULL) {
          continue;
        }
        $snapshotValues[] = $value;
        $lastSnapshot = $record;

        if ($this->isAnnualSnapshotRecord($record)) {
          if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
            $year = $record['snapshot_date']->format('Y');
          }
          elseif (!empty($record['period_year'])) {
            $year = (string) $record['period_year'];
          }
          else {
            $year = NULL;
          }
          if ($year !== NULL) {
            $annualOverrides[$year] = $value;
          }
        }
      }

      if (empty($monthlyItems) && $snapshotValues) {
        $trend = array_slice($snapshotValues, -12);
        $ttm12 = $this->calculateTrailingAverage($snapshotValues, 12);
        $ttm3 = $this->calculateTrailingAverage($snapshotValues, 3);
        $currentSum = $this->calculateTrailingSum($snapshotValues, 12);
        if ($currentSum !== NULL) {
          $current = (int) round($currentSum);
        }
      }

      if ($lastUpdated === NULL && $lastSnapshot) {
        if (!empty($lastSnapshot['snapshot_date']) && $lastSnapshot['snapshot_date'] instanceof \DateTimeImmutable) {
          $lastUpdated = $lastSnapshot['snapshot_date']->format('Y-m-d');
        }
        elseif (!empty($lastSnapshot['period_year'])) {
          $month = (int) ($lastSnapshot['period_month'] ?? 1);
          $lastUpdated = sprintf('%04d-%02d-01', (int) $lastSnapshot['period_year'], $month);
        }
      }
    }

    if ($annualOverrides) {
      foreach ($annualOverrides as $year => $value) {
        $annualOverrides[$year] = is_numeric($value) ? (float) $value : $value;
      }
      ksort($annualOverrides, SORT_STRING);
    }

    $result = $this->withDemographicSegments(
      $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'kpi_workshop_attendees', NULL, NULL, 'Last 12 months', 'Trailing 12 months', 1.0),
      'kpi_workshop_attendees'
    );

    $ytdStart = (new \DateTimeImmutable('first day of january ' . date('Y')))->setTime(0, 0, 0);
    $ytdEnd = new \DateTimeImmutable('now');
    $ytd = $this->eventsMembershipDataService->getWorkshopAttendeeCountInRange($ytdStart, $ytdEnd);
    if ($ytd !== NULL) {
      $segments = $result['segments'] ?? [];
      $segments[] = [
        'label' => 'YTD',
        'value' => (float) $ytd,
        'format' => 'integer',
      ];
      $result['segments'] = $segments;
    }

    return $result;
  }

  /**
   * Gets the data for the "Workshop Capacity Utilization %" KPI.
   */
  private function getKpiWorkshopCapacityUtilizationData(array $kpi_info): array {
    $series = $this->buildEducationCapacitySeries(['Ticketed Workshop']);
    return $this->buildCapacityUtilizationResult(
      $kpi_info,
      $series,
      'kpi_workshop_capacity_utilization',
      'CiviCRM: Weighted fill ratio = counted registrations / total capacity for active Ticketed Workshops (capacity > 0). Deactivated/cancelled events are excluded.'
    );
  }

  /**
   * Gets the data for the "Program Capacity Utilization %" KPI.
   */
  private function getKpiProgramCapacityUtilizationData(array $kpi_info): array {
    $series = $this->buildEducationCapacitySeries(['Program']);
    return $this->buildCapacityUtilizationResult(
      $kpi_info,
      $series,
      'kpi_program_capacity_utilization',
      'CiviCRM: Weighted fill ratio = counted registrations / total capacity for active Program events (capacity > 0). Deactivated/cancelled events are excluded.'
    );
  }

  /**
   * Gets the data for the combined Workshop + Program capacity KPI.
   */
  private function getKpiWorkshopProgramCapacityUtilizationData(array $kpi_info): array {
    $combinedSeries = $this->buildEducationCapacitySeries(['Ticketed Workshop', 'Program']);
    $workshopSeries = $this->buildEducationCapacitySeries(['Ticketed Workshop']);
    $programSeries = $this->buildEducationCapacitySeries(['Program']);

    $result = $this->buildCapacityUtilizationResult(
      $kpi_info,
      $combinedSeries,
      'kpi_workshop_program_capacity_utilization',
      'CiviCRM: Weighted fill ratio = counted registrations / total capacity for active Ticketed Workshop + Program events (capacity > 0). Deactivated/cancelled events are excluded.'
    );
    $workshopWeighted = $workshopSeries['summary']['weighted_fill_ratio'] ?? NULL;
    $programWeighted = $programSeries['summary']['weighted_fill_ratio'] ?? NULL;
    $segments = [];
    if (is_numeric($workshopWeighted)) {
      $segments[] = [
        'label' => 'Workshop',
        'value' => (float) $workshopWeighted,
        'format' => 'percent',
      ];
    }
    if (is_numeric($programWeighted)) {
      $segments[] = [
        'label' => 'Program',
        'value' => (float) $programWeighted,
        'format' => 'percent',
      ];
    }
    if ($segments) {
      $result['segments'] = $segments;
    }
    return $result;
  }

  /**
   * Loads monthly capacity series and combines labels when requested.
   */
  private function buildEducationCapacitySeries(array $eventTypeLabels): array {
    $end = new \DateTimeImmutable('now');
    $start = $end->modify('first day of this month')->setTime(0, 0, 0)->modify('-11 months');
    $labels = array_values(array_unique(array_filter(array_map('trim', $eventTypeLabels))));
    if (empty($labels)) {
      $labels = ['Ticketed Workshop'];
    }

    $combinedByMonth = [];
    foreach ($labels as $label) {
      $series = $this->eventsMembershipDataService->getWorkshopCapacityUtilizationSeries($start, $end, $label);
      foreach ((array) ($series['items'] ?? []) as $item) {
        $monthKey = (string) ($item['month_key'] ?? '');
        if ($monthKey === '') {
          continue;
        }
        if (!isset($combinedByMonth[$monthKey])) {
          $combinedByMonth[$monthKey] = [
            'month_key' => $monthKey,
            'label' => (string) ($item['label'] ?? $monthKey),
            'date' => $item['date'] ?? NULL,
            'eligible_events' => 0,
            'near_capacity_events' => 0,
            'full_events' => 0,
            'capacity_total' => 0,
            'counted_total' => 0,
          ];
        }
        $combinedByMonth[$monthKey]['eligible_events'] += (int) ($item['eligible_events'] ?? 0);
        $combinedByMonth[$monthKey]['near_capacity_events'] += (int) ($item['near_capacity_events'] ?? 0);
        $combinedByMonth[$monthKey]['full_events'] += (int) ($item['full_events'] ?? 0);
        $combinedByMonth[$monthKey]['capacity_total'] += (int) ($item['capacity_total'] ?? 0);
        $combinedByMonth[$monthKey]['counted_total'] += (int) ($item['counted_total'] ?? 0);
      }
    }

    ksort($combinedByMonth, SORT_STRING);
    $items = [];
    $labelsOut = [];
    $fillRates = [];
    $summary = [
      'eligible_events' => 0,
      'near_capacity_events' => 0,
      'full_events' => 0,
      'capacity_total' => 0,
      'counted_total' => 0,
      'weighted_fill_ratio' => NULL,
      'near_capacity_rate' => NULL,
      'full_rate' => NULL,
    ];

    foreach ($combinedByMonth as $monthKey => $stats) {
      $capacity = (int) ($stats['capacity_total'] ?? 0);
      $counted = (int) ($stats['counted_total'] ?? 0);
      $events = (int) ($stats['eligible_events'] ?? 0);
      $near = (int) ($stats['near_capacity_events'] ?? 0);
      $full = (int) ($stats['full_events'] ?? 0);
      $ratio = $capacity > 0 ? ($counted / $capacity) : 0.0;
      $nearRate = $events > 0 ? ($near / $events) : 0.0;
      $fullRate = $events > 0 ? ($full / $events) : 0.0;
      $label = (string) ($stats['label'] ?? $monthKey);

      $items[] = [
        'month_key' => $monthKey,
        'label' => $label,
        'date' => $stats['date'],
        'eligible_events' => $events,
        'near_capacity_events' => $near,
        'full_events' => $full,
        'capacity_total' => $capacity,
        'counted_total' => $counted,
        'fill_ratio' => $ratio,
        'near_capacity_rate' => $nearRate,
        'full_rate' => $fullRate,
      ];
      $labelsOut[] = $label;
      $fillRates[] = $ratio;

      $summary['eligible_events'] += $events;
      $summary['near_capacity_events'] += $near;
      $summary['full_events'] += $full;
      $summary['capacity_total'] += $capacity;
      $summary['counted_total'] += $counted;
    }

    if ($summary['capacity_total'] > 0) {
      $summary['weighted_fill_ratio'] = $summary['counted_total'] / $summary['capacity_total'];
    }
    if ($summary['eligible_events'] > 0) {
      $summary['near_capacity_rate'] = $summary['near_capacity_events'] / $summary['eligible_events'];
      $summary['full_rate'] = $summary['full_events'] / $summary['eligible_events'];
    }

    return [
      'items' => $items,
      'labels' => $labelsOut,
      'fill_rates' => $fillRates,
      'summary' => $summary,
    ];
  }

  /**
   * Builds a normalized KPI payload from a capacity-utilization series.
   */
  private function buildCapacityUtilizationResult(array $kpi_info, array $series, string $kpiId, string $sourceNote): array {
    $items = (array) ($series['items'] ?? []);
    $summary = (array) ($series['summary'] ?? []);

    $trend = array_map(static function (array $item): float {
      return isset($item['fill_ratio']) && is_numeric($item['fill_ratio']) ? (float) $item['fill_ratio'] : 0.0;
    }, $items);

    $ttm12 = $this->calculateTrailingAverage($trend, 12);
    $ttm3 = $this->calculateTrailingAverage($trend, 3);
    $current = isset($summary['weighted_fill_ratio']) && is_numeric($summary['weighted_fill_ratio'])
      ? (float) $summary['weighted_fill_ratio']
      : NULL;
    $lastUpdated = NULL;
    if (!empty($items)) {
      $last = $items[count($items) - 1];
      if (!empty($last['date']) && $last['date'] instanceof \DateTimeImmutable) {
        $lastUpdated = $last['date']->format('Y-m-d');
      }
    }

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      $ttm12,
      $ttm3,
      $lastUpdated,
      $current,
      $kpiId,
      'percent',
      $sourceNote,
      'Last 12 months',
      'Average utilization'
    );
  }

  /**
   * Gets the data for the "Total # First Time Workshop Participants" KPI.
   */
  private function getKpiTotalFirstTimeWorkshopParticipantsData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $now = new \DateTimeImmutable('first day of this month');
    $start = $now->modify('-35 months');
    $monthlySeries = $this->eventsMembershipDataService->getMonthlyFirstTimeWorkshopParticipantsSeries($start, $now);
    $monthlyItems = $monthlySeries['items'] ?? [];

    if (!empty($monthlyItems)) {
      $monthlyCounts = array_map(static function (array $item): float {
        return isset($item['count']) ? (float) $item['count'] : 0.0;
      }, $monthlyItems);

      $trend = array_slice($monthlyCounts, -12);
      $ttm12 = $this->calculateTrailingAverage($monthlyCounts, 12);
      $ttm3 = $this->calculateTrailingAverage($monthlyCounts, 3);
      $currentSum = $this->calculateTrailingSum($monthlyCounts, 12);
      if ($currentSum !== NULL) {
        $current = (int) round($currentSum);
      }

      $lastItem = $monthlyItems[count($monthlyItems) - 1];
      if (!empty($lastItem['date']) && $lastItem['date'] instanceof \DateTimeImmutable) {
        $lastUpdated = $lastItem['date']->format('Y-m-d');
      }
    }

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('kpi_total_first_time_workshop_participants');
    if (!empty($snapshotSeries)) {
      $snapshotValues = [];
      $lastSnapshot = NULL;
      foreach ($snapshotSeries as $record) {
        $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
        if ($value === NULL) {
          continue;
        }
        $snapshotValues[] = $value;
        $lastSnapshot = $record;

        if ($this->isAnnualSnapshotRecord($record)) {
          if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
            $year = $record['snapshot_date']->format('Y');
          }
          elseif (!empty($record['period_year'])) {
            $year = (string) $record['period_year'];
          }
          else {
            $year = NULL;
          }
          if ($year !== NULL) {
            $annualOverrides[$year] = $value;
          }
        }
      }

      if (empty($monthlyItems) && $snapshotValues) {
        $trend = array_slice($snapshotValues, -12);
        $ttm12 = $this->calculateTrailingAverage($snapshotValues, 12);
        $ttm3 = $this->calculateTrailingAverage($snapshotValues, 3);
        $currentSum = $this->calculateTrailingSum($snapshotValues, 12);
        if ($currentSum !== NULL) {
          $current = (int) round($currentSum);
        }
      }

      if ($lastUpdated === NULL && $lastSnapshot) {
        if (!empty($lastSnapshot['snapshot_date']) && $lastSnapshot['snapshot_date'] instanceof \DateTimeImmutable) {
          $lastUpdated = $lastSnapshot['snapshot_date']->format('Y-m-d');
        }
        elseif (!empty($lastSnapshot['period_year'])) {
          $month = (int) ($lastSnapshot['period_month'] ?? 1);
          $lastUpdated = sprintf('%04d-%02d-01', (int) $lastSnapshot['period_year'], $month);
        }
      }
    }

    if ($annualOverrides) {
      foreach ($annualOverrides as $year => $value) {
        $annualOverrides[$year] = is_numeric($value) ? (float) $value : $value;
      }
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->withDemographicSegments(
      $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'kpi_total_first_time_workshop_participants', NULL, NULL, 'Last 12 months', 'Trailing 12 months', 1.0),
      'kpi_total_first_time_workshop_participants'
    );
  }

  /**
   * Gets the data for the "Total New Member Signups" KPI.
   */
  private function getKpiTotalNewMemberSignupsData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = date('Y-m-d');

    $recruitmentHistory = $this->membershipMetricsService->getMonthlyRecruitmentHistory();
    
    if (!empty($recruitmentHistory)) {
      // Flatten the year/month nested array into a sequential series.
      $sequentialJoins = [];
      $annualJoins = [];
      
      $endMonth = new DateTimeImmutable('first day of this month');
      $startMonth = $endMonth->modify('-36 months');
      $period = new \DatePeriod($startMonth, new \DateInterval('P1M'), $endMonth);
      
      foreach ($period as $dt) {
        $year = (int) $dt->format('Y');
        $month = (int) $dt->format('n');
        $count = $recruitmentHistory[$year][$month] ?? 0;
        
        $sequentialJoins[] = (float) $count;
        $annualJoins[$year] = ($annualJoins[$year] ?? 0.0) + $count;
      }

      if ($sequentialJoins) {
        $trend = array_slice($sequentialJoins, -12);
        $ttm12 = $this->calculateTrailingAverage($sequentialJoins, 12);
        $ttm3 = $this->calculateTrailingAverage($sequentialJoins, 3);
        $currentSum = $this->calculateTrailingSum($sequentialJoins, 12);
        if ($currentSum !== NULL) {
          $current = (int) round($currentSum);
        }
      }

      foreach ($annualJoins as $year => $value) {
        $annualOverrides[(string) $year] = round($value);
      }
    }

    if ($annualOverrides) {
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->withDemographicSegments($this->buildKpiResult(
      $kpi_info, 
      $annualOverrides, 
      $trend, 
      $ttm12, 
      $ttm3, 
      $lastUpdated, 
      $current, 
      'kpi_total_new_member_signups', 
      'integer', 
      'System: Count of new users with the "member" role created in the period.', 
      'Last 12 months', 
      'Trailing 12 months', 
      1.0
    ), 'kpi_total_new_member_signups');
  }

  /**
   * Gets the data for the "Active Participation %" KPI.
   */
  private function getKpiActiveParticipationData(array $kpi_info): array {
    $annualOverrides = [];
    $annualAggregates = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('kpi_active_participation');
    if (!empty($snapshotSeries)) {
      $snapshotValues = [];
      $lastSnapshot = NULL;
      foreach ($snapshotSeries as $record) {
        $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
        if ($value === NULL) {
          continue;
        }
        $snapshotValues[] = $value;
        $lastSnapshot = $record;

        if ($this->isAnnualSnapshotRecord($record)) {
          if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
            $year = $record['snapshot_date']->format('Y');
          }
          elseif (!empty($record['period_year'])) {
            $year = (string) $record['period_year'];
          }
          else {
            $year = NULL;
          }
          if ($year !== NULL) {
            $annualOverrides[$year] = $value;
          }
        }
        else {
          if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
            $year = $record['snapshot_date']->format('Y');
          }
          elseif (!empty($record['period_year'])) {
            $year = (string) $record['period_year'];
          }
          else {
            $year = NULL;
          }

          if ($year !== NULL) {
            if (!isset($annualAggregates[$year])) {
              $annualAggregates[$year] = [];
            }
            $annualAggregates[$year][] = $value;
          }
        }
      }

      if ($snapshotValues) {
        $trend = array_slice($snapshotValues, -12);
        $ttm12 = $this->calculateTrailingAverage($snapshotValues, 12);
        $ttm3 = $this->calculateTrailingAverage($snapshotValues, 3);
        $current = (float) end($snapshotValues);
      }

      if ($lastSnapshot) {
        if (!empty($lastSnapshot['snapshot_date']) && $lastSnapshot['snapshot_date'] instanceof \DateTimeImmutable) {
          $lastUpdated = $lastSnapshot['snapshot_date']->format('Y-m-d');
        }
        elseif (!empty($lastSnapshot['period_year'])) {
          $month = (int) ($lastSnapshot['period_month'] ?? 1);
          $lastUpdated = sprintf('%04d-%02d-01', (int) $lastSnapshot['period_year'], $month);
        }
      }

      // When annual rows are missing, derive annual values from granular points.
      foreach ($annualAggregates as $year => $values) {
        if (isset($annualOverrides[$year]) || empty($values)) {
          continue;
        }
        $annualOverrides[$year] = array_sum($values) / count($values);
      }
    }

    if ($current === NULL) {
      $end = (new \DateTimeImmutable('now'))->setTime(23, 59, 59);
      $start = $end->modify('-89 days')->setTime(0, 0, 0);
      $buckets = $this->utilizationDataService->getVisitFrequencyBuckets($start->getTimestamp(), $end->getTimestamp());
      $totalMembers = (int) array_sum($buckets);
      $noVisits = (int) ($buckets['no_visits'] ?? 0);
      if ($totalMembers > 0) {
        $current = ($totalMembers - $noVisits) / $totalMembers;
      }
      $lastUpdated = $end->format('Y-m-d');
    }

    if ($annualOverrides) {
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->withDemographicSegments(
      $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'kpi_active_participation', 'percent', NULL, 'Last 12 months', 'Last 90 days', 1.0),
      'kpi_active_participation'
    );
  }

  /**
   * Gets the data for the "Active Participation % (BIPOC)" KPI.
   */
  private function getKpiActiveParticipationBipocData(array $kpi_info): array {
    $trend = $this->getDemographicParticipationTrend('bipoc');
    $current = end($trend) ?: 0.0;
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_active_participation_bipoc',
      'percent',
      'System: % of BIPOC members who visited the space in the last 90 days.',
      '8 Quarters'
    );
  }

  /**
   * Gets the data for the "Active Participation % (Female/Non-binary)" KPI.
   */
  private function getKpiActiveParticipationFemaleNbData(array $kpi_info): array {
    $trend = $this->getDemographicParticipationTrend('female_nb');
    $current = end($trend) ?: 0.0;
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_active_participation_female_nb',
      'percent',
      'System: % of Female/Non-binary members who visited the space in the last 90 days.',
      '8 Quarters'
    );
  }

  /**
   * Helper to calculate participation rate trend for a demographic segment.
   */
  private function getDemographicParticipationTrend(string $segment, int $quarters = 8): array {
    $now = new \DateTimeImmutable('now');
    $month = (int) $now->format('n');
    $year = (int) date('Y');

    // Start from the most recently completed quarter.
    $prevQ = (int) ceil($month / 3) - 1;
    if ($prevQ <= 0) {
      $prevQ = 4;
      $year--;
    }

    $trend = [];
    for ($i = $quarters - 1; $i >= 0; $i--) {
      $targetQ = $prevQ - $i;
      $targetYear = $year;
      while ($targetQ <= 0) {
        $targetQ += 4;
        $targetYear--;
      }

      $startMonth = ($targetQ - 1) * 3 + 1;
      $endMonth = $targetQ * 3;
      $lastDay = (int) (new \DateTimeImmutable(sprintf('%d-%02d-01', $targetYear, $endMonth)))->format('t');
      $start = new \DateTimeImmutable(sprintf('%d-%02d-01 00:00:00', $targetYear, $startMonth));
      $end = new \DateTimeImmutable(sprintf('%d-%02d-%02d 23:59:59', $targetYear, $endMonth, $lastDay));

      $trend[] = $this->getDemographicParticipationRate($segment, $start, $end);
    }

    return $trend;
  }

  /**
   * Helper to calculate participation rate for a demographic segment.
   */
  private function getDemographicParticipationRate(string $segment, ?\DateTimeImmutable $start = NULL, ?\DateTimeImmutable $end = NULL): float {
    if (!$end) {
      $end = (new \DateTimeImmutable('now'))->setTime(23, 59, 59);
    }
    if (!$start) {
      $start = $end->modify('-89 days')->setTime(0, 0, 0);
    }
    
    $db = \Drupal::database();
    
    // 1. Identify all active members in this demographic.
    $rosterQuery = $db->select('civicrm_uf_match', 'ufm');
    $rosterQuery->innerJoin('user__roles', 'ur', 'ur.entity_id = ufm.uf_id');
    $rosterQuery->condition('ur.roles_target_id', 'member');
    $rosterQuery->innerJoin('users_field_data', 'u', 'u.uid = ufm.uf_id AND u.status = 1');
    $rosterQuery->fields('ufm', ['uf_id', 'contact_id']);
    
    if ($segment === 'bipoc') {
      $rosterQuery->innerJoin('civicrm_value_demographics_15', 'demo', 'demo.entity_id = ufm.contact_id');
      $bipocValues = ['asian', 'black', 'middleeast', 'mena', 'hispanic', 'native', 'aian', 'islander', 'nhpi', 'multi', 'other'];
      $or = $rosterQuery->orConditionGroup();
      foreach ($bipocValues as $val) {
        $or->condition('ethnicity_46', '%' . $db->escapeLike($val) . '%', 'LIKE');
      }
      $rosterQuery->condition($or);
    } 
    elseif ($segment === 'female_nb') {
      $rosterQuery->innerJoin('civicrm_contact', 'c', 'c.id = ufm.contact_id');
      $rosterQuery->condition('c.gender_id', [1, 4, 5, 6], 'IN');
    }

    $activeInDemo = $rosterQuery->execute()->fetchAllAssoc('uf_id');
    $totalCount = count($activeInDemo);
    if ($totalCount === 0) {
      return 0.0;
    }

    // 2. Identify how many of them visited in the period.
    $uids = array_keys($activeInDemo);
    $visitQuery = $db->select('access_control_log_field_data', 'a');
    $visitQuery->innerJoin('access_control_log__field_access_request_user', 'u', 'u.entity_id = a.id');
    $visitQuery->condition('u.field_access_request_user_target_id', $uids, 'IN');
    $visitQuery->condition('a.created', [$start->getTimestamp(), $end->getTimestamp()], 'BETWEEN');
    $visitQuery->addExpression('COUNT(DISTINCT u.field_access_request_user_target_id)', 'visitors');
    
    $visitorCount = (int) $visitQuery->execute()->fetchField();

    return $visitorCount / $totalCount;
  }

  /**
   * Gets the data for the "Adherence to Shop Budget" KPI.
   */
  private function getKpiAdherenceToShopBudgetData(array $kpi_info): array {
    $current = $this->financialDataService->getAdherenceToShopBudget();
    $trend = $this->financialDataService->getShopBudgetAdherenceTrend();
    // NULL: data freshness depends on when the Google Sheet was last updated.
    $lastUpdated = NULL;

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_adherence_to_shop_budget',
      'percent',
      'Google Sheets: Comparison of "budgets" vs "Income-Statement" for Shop Operations.'
    );
  }

  /**
   * Gets the data for the "Equipment Uptime Rate %" KPI.
   */
  private function getKpiEquipmentUptimeRateData(array $kpi_info): array {
    $current = $this->infrastructureDataService->getEquipmentUptimeRate();
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_equipment_uptime_rate',
      'percent',
      'Live: Calculated from the status taxonomy of active shop tools.'
    );
  }

  /**
   * Gets the data for the "Active Maintenance Load" KPI.
   */
  private function getKpiActiveMaintenanceLoadData(array $kpi_info): array {
    $current = $this->infrastructureDataService->getActiveMaintenanceLoad();
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_active_maintenance_load',
      'number',
      'Live: Count of open maintenance tasks.'
    );
  }

  /**
   * Gets the data for the "Storage Occupancy %" KPI.
   */
  private function getKpiStorageOccupancyData(array $kpi_info): array {
    $current = $this->infrastructureDataService->getStorageOccupancy();
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_storage_occupancy',
      'percent',
      'Live: Occupancy rate from storage_manager service.'
    );
  }

  /**
   * Gets the data for the "New Member 28-Day Activation %" KPI.
   */
  private function getKpiNewMemberFirstBadge28DaysData(array $kpi_info): array {
    $series = $this->memberSuccessDataService->getMonthlyActivationSeries(18, 28, 30);
    if (empty($series)) {
      $funnel = $this->memberSuccessDataService->getLatestOnboardingFunnel(90);
      $joined = (int) ($funnel['joined_recent'] ?? 0);
      $badgeActive = (int) ($funnel['badge_active'] ?? 0);
      $current = $joined > 0 ? ($badgeActive / $joined) : NULL;
      $lastUpdated = (!empty($funnel['snapshot_date']) && $funnel['snapshot_date'] instanceof \DateTimeImmutable)
        ? $funnel['snapshot_date']->format('Y-m-d')
        : NULL;
      return $this->buildKpiResult(
        $kpi_info,
        [],
        $current !== NULL ? [$current] : [],
        NULL,
        NULL,
        $lastUpdated,
        $current,
        'kpi_new_member_first_badge_28_days',
        'percent',
        'Fallback: Latest onboarding funnel (badge active / recent joins).'
      );
    }

    $trend = [];
    $annualOverrides = [];
    $lastUpdated = NULL;
    foreach ($series as $row) {
      if (!array_key_exists('ratio', $row) || !is_numeric($row['ratio'])) {
        continue;
      }
      $ratio = (float) $row['ratio'];
      $trend[] = $ratio;

      if (!empty($row['snapshot_date']) && $row['snapshot_date'] instanceof \DateTimeImmutable) {
        $year = $row['snapshot_date']->format('Y');
        $annualOverrides[$year] = $ratio;
        $lastUpdated = $row['snapshot_date']->format('Y-m-d');
      }
    }

    if (empty($trend)) {
      $funnel = $this->memberSuccessDataService->getLatestOnboardingFunnel(90);
      $joined = (int) ($funnel['joined_recent'] ?? 0);
      $badgeActive = (int) ($funnel['badge_active'] ?? 0);
      $current = $joined > 0 ? ($badgeActive / $joined) : NULL;
      $lastUpdated = (!empty($funnel['snapshot_date']) && $funnel['snapshot_date'] instanceof \DateTimeImmutable)
        ? $funnel['snapshot_date']->format('Y-m-d')
        : NULL;
      return $this->buildKpiResult(
        $kpi_info,
        [],
        $current !== NULL ? [$current] : [],
        NULL,
        NULL,
        $lastUpdated,
        $current,
        'kpi_new_member_first_badge_28_days',
        'percent',
        'Fallback: Latest onboarding funnel (badge active / recent joins).'
      );
    }

    $current = (float) end($trend);
    ksort($annualOverrides, SORT_STRING);
    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      array_slice($trend, -12),
      $this->calculateTrailingAverage($trend, 12),
      $this->calculateTrailingAverage($trend, 3),
      $lastUpdated,
      $current,
      'kpi_new_member_first_badge_28_days',
      'percent',
      'Automated: Member success daily snapshots (join cohort reaching ~28 days).'
    );
  }

  /**
   * Gets the data for the "Members At-Risk %" KPI.
   */
  private function getKpiMembersAtRiskShareData(array $kpi_info): array {
    $series = $this->memberSuccessDataService->getMonthlyRiskShareSeries(18, 20);
    if (empty($series)) {
      return $this->buildKpiResult(
        $kpi_info,
        [],
        [],
        NULL,
        NULL,
        NULL,
        NULL,
        'kpi_members_at_risk_share',
        'percent'
      );
    }

    $trend = [];
    $annualOverrides = [];
    $lastUpdated = NULL;
    foreach ($series as $row) {
      if (!array_key_exists('ratio', $row) || !is_numeric($row['ratio'])) {
        continue;
      }
      $ratio = (float) $row['ratio'];
      $trend[] = $ratio;

      if (!empty($row['snapshot_date']) && $row['snapshot_date'] instanceof \DateTimeImmutable) {
        $year = $row['snapshot_date']->format('Y');
        $annualOverrides[$year] = $ratio;
        $lastUpdated = $row['snapshot_date']->format('Y-m-d');
      }
    }

    if (empty($trend)) {
      return $this->buildKpiResult(
        $kpi_info,
        [],
        [],
        NULL,
        NULL,
        NULL,
        NULL,
        'kpi_members_at_risk_share',
        'percent'
      );
    }

    $current = (float) end($trend);
    ksort($annualOverrides, SORT_STRING);
    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      array_slice($trend, -12),
      $this->calculateTrailingAverage($trend, 12),
      $this->calculateTrailingAverage($trend, 3),
      $lastUpdated,
      $current,
      'kpi_members_at_risk_share',
      'percent',
      'Automated: Share of members with member-success risk_score >= 20.'
    );
  }

  /**
   * Gets the data for the "Total Tours (12 month)" KPI.
   */
  private function getKpiToursData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('kpi_tours');
    if (!empty($snapshotSeries)) {
      $snapshotValues = [];
      $lastSnapshot = NULL;
      foreach ($snapshotSeries as $record) {
        $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
        if ($value === NULL) {
          continue;
        }
        $snapshotValues[] = $value;
        $lastSnapshot = $record;

        if ($this->isAnnualSnapshotRecord($record)) {
          $year = !empty($record['snapshot_date']) ? $record['snapshot_date']->format('Y') : (string) ($record['period_year'] ?? '');
          if ($year) {
            $annualOverrides[$year] = $value;
          }
        }
      }

      if ($snapshotValues) {
        $trend = array_slice($snapshotValues, -12);
        // For tours we SUM the months instead of averaging.
        $ttm12 = array_sum(array_slice($snapshotValues, -12));
        $ttm3 = array_sum(array_slice($snapshotValues, -3));
        $current = $ttm12;
      }

      if ($lastSnapshot) {
        $lastUpdated = !empty($lastSnapshot['snapshot_date']) ? $lastSnapshot['snapshot_date']->format('Y-m-d') : date('Y-m-d');
      }
    }

    if ($current === NULL) {
      $funnel = $this->funnelDataService->getTourFunnelData();
      $current = (float) ($funnel['participants_total'] ?? 0);
      $lastUpdated = date('Y-m-d');
    }

    if ($annualOverrides) {
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      $trend,
      $ttm12,
      $ttm3,
      $lastUpdated,
      $current,
      'kpi_tours',
      'integer',
      'System: Sum of monthly tour event attendees and tour activities.',
      'Last 12 months',
      'Trailing 12 months',
      1.0
    );
  }

  /**
   * Gets the data for the "Total New Recurring Revenue" KPI.
   */
  private function getKpiTotalNewRecurringRevenueData(array $kpi_info): array {
    $annualOverrides = [];
    $currentYear = (int) date('Y');

    // Pull recent calendar years for the annual table.
    for ($y = $currentYear - 2; $y <= $currentYear; $y++) {
      $annualOverrides[(string) $y] = $this->financialDataService->getAnnualNewRecurringRevenue($y);
    }

    // "Current" = new MRR from members who joined in the trailing 12 months.
    $current = $this->financialDataService->getTrailingNewRecurringRevenue();
    $trend = $this->financialDataService->getNewRecurringRevenueTrend();
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_total_new_recurring_revenue',
      'currency',
      'CiviCRM: Sum of monthly dues for members who joined during the period. Represents the monthly "clip" added to the recurring budget.',
      '8 Quarters',
      'Trailing 12 months',
      1.0
    );
  }

  /**
   * Gets the data for the "Value of Equipment Added" KPI.
   */
  private function getKpiEquipmentInvestmentData(array $kpi_info): array {
    $currentYear = (int) date('Y');
    $annualOverrides = [];
    for ($y = $currentYear - 2; $y <= $currentYear; $y++) {
      $annualOverrides[(string) $y] = $this->infrastructureDataService->getEquipmentInvestment($y);
    }

    $current = $this->infrastructureDataService->getEquipmentInvestment();
    $trend = $this->infrastructureDataService->getEquipmentInvestmentTrend();
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_equipment_investment',
      'currency',
      'Live: Sum of replacement values for new assets and library items.',
      '4 Years',
      'Trailing 12 months'
    );
  }

  /**
   * Gets the data for the "Tours to Member Conversion %" KPI.
   */
  private function getKpiToursToMemberConversionData(array $kpi_info): array {
    $funnel = $this->funnelDataService->getTourFunnelData();
    $participants = (int) ($funnel['participants'] ?? 0);
    $participantsTotal = (int) ($funnel['participants_total'] ?? $participants);
    $alreadyMembers = (int) ($funnel['participants_already_members'] ?? 0);
    $conversions = (int) ($funnel['conversions'] ?? 0);
    $current = $participants > 0 ? ($conversions / $participants) : NULL;

    $annualOverrides = [];
    if ($current !== NULL && !empty($funnel['range']['end']) && $funnel['range']['end'] instanceof \DateTimeInterface) {
      $annualOverrides[$funnel['range']['end']->format('Y')] = $current;
    }

    $lastUpdated = !empty($funnel['range']['end']) && $funnel['range']['end'] instanceof \DateTimeInterface
      ? $funnel['range']['end']->format('Y-m-d')
      : date('Y-m-d');

    $sourceNote = sprintf(
      'Rolling 12 months: %d eligible tour participants (%d total, %d already members), %d converted to membership.',
      $participants,
      $participantsTotal,
      $alreadyMembers,
      $conversions
    );

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_tours_to_member_conversion',
      'percent',
      $sourceNote
    );
  }

  /**
   * Gets the data for the "Event Participant to Member Conversion %" KPI.
   */
  private function getKpiEventParticipantToMemberConversionData(array $kpi_info): array {
    $funnel = $this->funnelDataService->getEventParticipantFunnelData();
    $participants = (int) ($funnel['participants'] ?? 0);
    $participantsTotal = (int) ($funnel['participants_total'] ?? $participants);
    $alreadyMembers = (int) ($funnel['participants_already_members'] ?? 0);
    $conversions = (int) ($funnel['conversions'] ?? 0);
    $current = $participants > 0 ? ($conversions / $participants) : NULL;

    $annualOverrides = [];
    if ($current !== NULL && !empty($funnel['range']['end']) && $funnel['range']['end'] instanceof \DateTimeInterface) {
      $annualOverrides[$funnel['range']['end']->format('Y')] = $current;
    }

    $lastUpdated = !empty($funnel['range']['end']) && $funnel['range']['end'] instanceof \DateTimeInterface
      ? $funnel['range']['end']->format('Y-m-d')
      : date('Y-m-d');

    $sourceNote = sprintf(
      'Rolling 12 months: %d eligible event participants (%d total, %d already members), %d converted to membership.',
      $participants,
      $participantsTotal,
      $alreadyMembers,
      $conversions
    );

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_event_participant_to_member_conversion',
      'percent',
      $sourceNote
    );
  }

  /**
   * Gets the data for the "Guest Waivers Signed (12 month)" KPI.
   */
  private function getGuestWaiversSignedData(array $kpi_info): array {
    $funnel = $this->funnelDataService->getActivityFunnelData('guest waiver');
    $waivers = (int) ($funnel['activities'] ?? 0);
    $conversions = (int) ($funnel['conversions'] ?? 0);

    $annualOverrides = [];
    if (!empty($funnel['range']['end']) && $funnel['range']['end'] instanceof \DateTimeInterface) {
      $annualOverrides[$funnel['range']['end']->format('Y')] = $waivers;
    }

    $lastUpdated = !empty($funnel['range']['end']) && $funnel['range']['end'] instanceof \DateTimeInterface
      ? $funnel['range']['end']->format('Y-m-d')
      : date('Y-m-d');

    $sourceNote = sprintf('Rolling 12 months: %d guest waivers signed, %d later converted to membership.', $waivers, $conversions);

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      [],
      NULL,
      NULL,
      $lastUpdated,
      $waivers,
      'guest_waivers_signed',
      NULL,
      $sourceNote
    );
  }

  /**
   * Gets the data for the "Guest Waiver to Member Conversion %" KPI.
   */
  private function getKpiGuestWaiverToMemberConversionData(array $kpi_info): array {
    $funnel = $this->funnelDataService->getActivityFunnelData('guest waiver');
    $waivers = (int) ($funnel['activities'] ?? 0);
    $waiversTotal = (int) ($funnel['activities_total'] ?? $waivers);
    $alreadyMembers = (int) ($funnel['activities_already_members'] ?? 0);
    $conversions = (int) ($funnel['conversions'] ?? 0);
    $current = $waivers > 0 ? ($conversions / $waivers) : NULL;

    $annualOverrides = [];
    if ($current !== NULL && !empty($funnel['range']['end']) && $funnel['range']['end'] instanceof \DateTimeInterface) {
      $annualOverrides[$funnel['range']['end']->format('Y')] = $current;
    }

    $lastUpdated = !empty($funnel['range']['end']) && $funnel['range']['end'] instanceof \DateTimeInterface
      ? $funnel['range']['end']->format('Y-m-d')
      : date('Y-m-d');

    $sourceNote = sprintf(
      'Rolling 12 months: %d eligible guest waiver contacts (%d total, %d already members), %d conversions to membership.',
      $waivers,
      $waiversTotal,
      $alreadyMembers,
      $conversions
    );

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_guest_waiver_to_member_conversion',
      'percent',
      $sourceNote
    );
  }

  /**
   * Gets the data for the "Discovery Meetings Logged (12 month)" KPI.
   */
  private function getDiscoveryMeetingsLoggedData(array $kpi_info): array {
    $funnel = $this->funnelDataService->getActivityFunnelData('meeting');
    $meetings = (int) ($funnel['activities'] ?? 0);
    $conversions = (int) ($funnel['conversions'] ?? 0);

    $annualOverrides = [];
    if (!empty($funnel['range']['end']) && $funnel['range']['end'] instanceof \DateTimeInterface) {
      $annualOverrides[$funnel['range']['end']->format('Y')] = $meetings;
    }

    $lastUpdated = !empty($funnel['range']['end']) && $funnel['range']['end'] instanceof \DateTimeInterface
      ? $funnel['range']['end']->format('Y-m-d')
      : date('Y-m-d');

    $sourceNote = sprintf('Rolling 12 months: %d meetings logged, %d converted to membership.', $meetings, $conversions);

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      [],
      NULL,
      NULL,
      $lastUpdated,
      $meetings,
      'discovery_meetings_logged',
      NULL,
      $sourceNote
    );
  }

  /**
   * Gets the data for the "Meeting to Member Conversion %" KPI.
   */
  private function getMeetingToMemberConversionData(array $kpi_info): array {
    $funnel = $this->funnelDataService->getActivityFunnelData('meeting');
    $meetings = (int) ($funnel['activities'] ?? 0);
    $conversions = (int) ($funnel['conversions'] ?? 0);
    $current = $meetings > 0 ? ($conversions / $meetings) : NULL;

    $annualOverrides = [];
    if ($current !== NULL && !empty($funnel['range']['end']) && $funnel['range']['end'] instanceof \DateTimeInterface) {
      $annualOverrides[$funnel['range']['end']->format('Y')] = $current;
    }

    $lastUpdated = !empty($funnel['range']['end']) && $funnel['range']['end'] instanceof \DateTimeInterface
      ? $funnel['range']['end']->format('Y-m-d')
      : date('Y-m-d');

    $sourceNote = sprintf('Rolling 12 months: %d meetings, %d conversions to membership.', $meetings, $conversions);

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'meeting_to_member_conversion',
      'percent',
      $sourceNote
    );
  }

  /**
   * Gets the data for the "Education Net Promoter Score (NPS)" KPI.
   */
  private function getKpiEducationNpsData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('kpi_education_nps');
    if (!empty($snapshotSeries)) {
      $snapshotValues = [];
      $lastSnapshot = NULL;
      foreach ($snapshotSeries as $record) {
        $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
        if ($value === NULL) {
          continue;
        }
        $snapshotValues[] = $value;
        $lastSnapshot = $record;

        if ($this->isAnnualSnapshotRecord($record)) {
          if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
            $year = $record['snapshot_date']->format('Y');
          }
          elseif (!empty($record['period_year'])) {
            $year = (string) $record['period_year'];
          }
          else {
            $year = NULL;
          }
          if ($year !== NULL) {
            $annualOverrides[$year] = $value;
          }
        }
      }

      if ($snapshotValues) {
        $trend = array_slice($snapshotValues, -12);
        $ttm12 = $this->calculateTrailingAverage($snapshotValues, 12);
        $ttm3 = $this->calculateTrailingAverage($snapshotValues, 3);
        $current = (float) end($snapshotValues);
      }

      if ($lastSnapshot) {
        if (!empty($lastSnapshot['snapshot_date']) && $lastSnapshot['snapshot_date'] instanceof \DateTimeImmutable) {
          $lastUpdated = $lastSnapshot['snapshot_date']->format('Y-m-d');
        }
        elseif (!empty($lastSnapshot['period_year'])) {
          $month = (int) ($lastSnapshot['period_month'] ?? 1);
          $lastUpdated = sprintf('%04d-%02d-01', (int) $lastSnapshot['period_year'], $month);
        }
      }
    }

    if ($current === NULL) {
      $end = new \DateTimeImmutable('last day of this month 23:59:59');
      $start = $end->modify('first day of this month')->modify('-11 months')->setTime(0, 0, 0);
      $npsSeries = $this->educationEvaluationDataService->getNetPromoterSeries($start, $end);
      $points = array_values(array_filter($npsSeries['nps'] ?? [], 'is_numeric'));
      if ($points) {
        $trend = array_slice($points, -12);
        $ttm12 = $this->calculateTrailingAverage($points, 12);
        $ttm3 = $this->calculateTrailingAverage($points, 3);
        $current = isset($npsSeries['overall']['nps']) && is_numeric($npsSeries['overall']['nps'])
          ? (float) $npsSeries['overall']['nps']
          : (float) end($points);
      }
      $lastUpdated = $end->format('Y-m-d');
    }

    if ($annualOverrides) {
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'kpi_education_nps');
  }

  /**
   * Gets the data for the "% Workshop Participants (BIPOC)" KPI.
   */
  private function getKpiWorkshopParticipantsBipocData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('kpi_workshop_participants_bipoc');
    if (!empty($snapshotSeries)) {
      $snapshotValues = [];
      $lastSnapshot = NULL;
      foreach ($snapshotSeries as $record) {
        $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
        if ($value === NULL) {
          continue;
        }
        $snapshotValues[] = $value;
        $lastSnapshot = $record;

        if ($this->isAnnualSnapshotRecord($record)) {
          if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
            $year = $record['snapshot_date']->format('Y');
          }
          elseif (!empty($record['period_year'])) {
            $year = (string) $record['period_year'];
          }
          else {
            $year = NULL;
          }
          if ($year !== NULL) {
            $annualOverrides[$year] = $value;
          }
        }
      }

      if ($snapshotValues) {
        $trend = array_slice($snapshotValues, -12);
        $ttm12 = $this->calculateTrailingAverage($snapshotValues, 12);
        $ttm3 = $this->calculateTrailingAverage($snapshotValues, 3);
        $current = (float) end($snapshotValues);
      }

      if ($lastSnapshot) {
        if (!empty($lastSnapshot['snapshot_date']) && $lastSnapshot['snapshot_date'] instanceof \DateTimeImmutable) {
          $lastUpdated = $lastSnapshot['snapshot_date']->format('Y-m-d');
        }
        elseif (!empty($lastSnapshot['period_year'])) {
          $month = (int) ($lastSnapshot['period_month'] ?? 1);
          $lastUpdated = sprintf('%04d-%02d-01', (int) $lastSnapshot['period_year'], $month);
        }
      }
    }

    if ($current === NULL) {
      $end = new \DateTimeImmutable('last day of this month 23:59:59');
      $start = $end->modify('first day of this month')->modify('-11 months')->setTime(0, 0, 0);
      $demographics = $this->eventsMembershipDataService->getParticipantDemographics($start, $end);

      $labels = $demographics['ethnicity']['labels'] ?? [];
      $workshop = $demographics['ethnicity']['workshop'] ?? [];
      $bipocCount = 0.0;
      $knownCount = 0.0;

      foreach ($labels as $index => $label) {
        $count = isset($workshop[$index]) && is_numeric($workshop[$index]) ? (float) $workshop[$index] : 0.0;
        if ($count <= 0) {
          continue;
        }
        if ($this->isUnspecifiedEthnicity((string) $label)) {
          continue;
        }
        $knownCount += $count;
        if ($this->isBipocEthnicityLabel((string) $label)) {
          $bipocCount += $count;
        }
      }

      if ($knownCount > 0) {
        $current = $bipocCount / $knownCount;
      }
      $lastUpdated = $end->format('Y-m-d');
    }

    if ($annualOverrides) {
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      $trend,
      $ttm12,
      $ttm3,
      $lastUpdated,
      $current,
      'kpi_workshop_participants_bipoc',
      'percent',
      'System: % of unique workshop participants identifying as BIPOC.'
    );
  }

  /**
   * Gets the data for the "% Active Instructors (BIPOC)" KPI.
   */
  private function getKpiActiveInstructorsBipocData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('kpi_active_instructors_bipoc');
    if (!empty($snapshotSeries)) {
      $snapshotValues = [];
      $lastSnapshot = NULL;
      foreach ($snapshotSeries as $record) {
        $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
        if ($value === NULL) {
          continue;
        }
        $snapshotValues[] = $value;
        $lastSnapshot = $record;

        if ($this->isAnnualSnapshotRecord($record)) {
          if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
            $year = $record['snapshot_date']->format('Y');
          }
          elseif (!empty($record['period_year'])) {
            $year = (string) $record['period_year'];
          }
          else {
            $year = NULL;
          }
          if ($year !== NULL) {
            $annualOverrides[$year] = $value;
          }
        }
      }

      if ($snapshotValues) {
        $trend = array_slice($snapshotValues, -12);
        $ttm12 = $this->calculateTrailingAverage($snapshotValues, 12);
        $ttm3 = $this->calculateTrailingAverage($snapshotValues, 3);
        $current = (float) end($snapshotValues);
      }

      if ($lastSnapshot) {
        if (!empty($lastSnapshot['snapshot_date']) && $lastSnapshot['snapshot_date'] instanceof \DateTimeImmutable) {
          $lastUpdated = $lastSnapshot['snapshot_date']->format('Y-m-d');
        }
        elseif (!empty($lastSnapshot['period_year'])) {
          $month = (int) ($lastSnapshot['period_month'] ?? 1);
          $lastUpdated = sprintf('%04d-%02d-01', (int) $lastSnapshot['period_year'], $month);
        }
      }
    }

    if ($current === NULL) {
      $end = new \DateTimeImmutable('last day of this month 23:59:59');
      $start = $end->modify('first day of this month')->modify('-11 months')->setTime(0, 0, 0);
      $demographics = $this->eventsMembershipDataService->getActiveInstructorDemographics($start, $end);
      $instructors = $demographics['instructors'] ?? [];
      $knownCount = 0;
      $bipocCount = 0;

      foreach ($instructors as $instructor) {
        $ethnicities = array_unique(array_filter(array_map('strval', (array) ($instructor['ethnicity'] ?? []))));
        if (!$ethnicities) {
          continue;
        }

        $hasKnownEthnicity = FALSE;
        $hasBipocEthnicity = FALSE;
        foreach ($ethnicities as $label) {
          if ($this->isUnspecifiedEthnicity($label)) {
            continue;
          }
          $hasKnownEthnicity = TRUE;
          if ($this->isBipocEthnicityLabel($label)) {
            $hasBipocEthnicity = TRUE;
          }
        }

        if (!$hasKnownEthnicity) {
          continue;
        }
        $knownCount++;
        if ($hasBipocEthnicity) {
          $bipocCount++;
        }
      }

      if ($knownCount > 0) {
        $current = $bipocCount / $knownCount;
      }
      $lastUpdated = $end->format('Y-m-d');
    }

    if ($annualOverrides) {
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'kpi_active_instructors_bipoc', 'percent');
  }

  /**
   * Gets the data for the "First Year Member Retention %" KPI.
   */
  private function getKpiFirstYearMemberRetentionData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $retentionSeries = $this->membershipMetricsService->getMonthlyFirstYearRetentionSeries(36);
    if (!empty($retentionSeries)) {
      $values = [];
      foreach ($retentionSeries as $entry) {
        $value = isset($entry['retention_percent']) ? (float) $entry['retention_percent'] : NULL;
        if ($value === NULL) {
          continue;
        }
        $values[] = $value;
      }

      if ($values) {
        $trend = array_slice($values, -12);
        $ttm12 = $this->calculateTrailingAverage($values, 12);
        $ttm3 = $this->calculateTrailingAverage($values, 3);
        $current = $values[count($values) - 1];
      }

      $lastEntry = end($retentionSeries);
      if ($lastEntry) {
        $lastUpdated = $lastEntry['evaluation_date'] ?? $lastEntry['period'] ?? NULL;
      }
      reset($retentionSeries);
    }

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('kpi_first_year_member_retention');
    if (!empty($snapshotSeries)) {
      $snapshotValues = [];
      $lastSnapshot = NULL;
      foreach ($snapshotSeries as $record) {
        $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
        if ($value === NULL) {
          continue;
        }
        $snapshotValues[] = $value;
        $lastSnapshot = $record;

        if ($this->isAnnualSnapshotRecord($record)) {
          if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
            $year = $record['snapshot_date']->format('Y');
          }
          elseif (!empty($record['period_year'])) {
            $year = (string) $record['period_year'];
          }
          else {
            $year = NULL;
          }
          if ($year !== NULL) {
            $annualOverrides[$year] = $value;
          }
        }
      }

      if (empty($retentionSeries) && $snapshotValues) {
        $trend = array_slice($snapshotValues, -12);
        $ttm12 = $this->calculateTrailingAverage($snapshotValues, 12);
        $ttm3 = $this->calculateTrailingAverage($snapshotValues, 3);
        $current = (float) end($snapshotValues);
      }

      if ($lastUpdated === NULL && $lastSnapshot) {
        if (!empty($lastSnapshot['snapshot_date']) && $lastSnapshot['snapshot_date'] instanceof \DateTimeImmutable) {
          $lastUpdated = $lastSnapshot['snapshot_date']->format('Y-m-d');
        }
        elseif (!empty($lastSnapshot['period_year'])) {
          $month = (int) ($lastSnapshot['period_month'] ?? 1);
          $lastUpdated = sprintf('%04d-%02d-01', (int) $lastSnapshot['period_year'], $month);
        }
      }
    }

    if ($annualOverrides) {
      foreach ($annualOverrides as $year => $value) {
        $annualOverrides[$year] = is_numeric($value) ? (float) $value : $value;
      }
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->withDemographicSegments($this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      $trend,
      $ttm12,
      $ttm3,
      $lastUpdated,
      $current,
      'kpi_first_year_member_retention',
      'percent'
    ), 'kpi_first_year_member_retention');
  }

  /**
   * Gets the data for the "Member (Post-12mo) Retention %" KPI.
   */
  private function getKpiMemberPost12MonthRetentionData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $cohortMatrix = $this->membershipMetricsService->getMonthlyCohortRetentionMatrix(48);
    $retentionSeries = [];
    foreach ($cohortMatrix as $row) {
      if (
        !isset($row['retention'][12], $row['retention'][24]) ||
        !is_numeric($row['retention'][12]) ||
        !is_numeric($row['retention'][24])
      ) {
        continue;
      }
      $retentionAt12 = (float) $row['retention'][12] / 100;
      $retentionAt24 = (float) $row['retention'][24] / 100;
      if ($retentionAt12 <= 0) {
        continue;
      }
      $retentionRatio = $retentionAt24 / $retentionAt12;
      $retentionRatio = max(0.0, min(1.0, $retentionRatio));
      $retentionSeries[] = $retentionRatio;
    }

    if ($retentionSeries) {
      $trend = array_slice($retentionSeries, -12);
      $ttm12 = $this->calculateTrailingAverage($retentionSeries, 12);
      $ttm3 = $this->calculateTrailingAverage($retentionSeries, 3);
      $current = $retentionSeries[count($retentionSeries) - 1];
      $lastUpdated = date('Y-m-d');

      $now = new \DateTimeImmutable('first day of this month');
      $monthsCount = count($retentionSeries);
      foreach ($retentionSeries as $index => $value) {
        $monthsAgo = $monthsCount - $index - 1;
        $cohortMonth = $now->modify(sprintf('-%d months', $monthsAgo + 12));
        if ((int) $cohortMonth->format('n') === 12) {
          $annualOverrides[$cohortMonth->format('Y')] = $value;
        }
      }
    }

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('kpi_member_post_12_month_retention');
    if (!empty($snapshotSeries)) {
      $snapshotValues = [];
      $lastSnapshot = NULL;
      foreach ($snapshotSeries as $record) {
        $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
        if ($value === NULL) {
          continue;
        }
        if (abs($value) > 1.5 && abs($value) <= 100) {
          $value = $value / 100;
        }
        $value = max(0.0, min(1.0, $value));
        $snapshotValues[] = $value;
        $lastSnapshot = $record;

        if ($this->isAnnualSnapshotRecord($record)) {
          if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
            $year = $record['snapshot_date']->format('Y');
          }
          elseif (!empty($record['period_year'])) {
            $year = (string) $record['period_year'];
          }
          else {
            $year = NULL;
          }
          if ($year !== NULL) {
            $annualOverrides[$year] = $value;
          }
        }
      }

      if (empty($retentionSeries) && $snapshotValues) {
        $trend = array_slice($snapshotValues, -12);
        $ttm12 = $this->calculateTrailingAverage($snapshotValues, 12);
        $ttm3 = $this->calculateTrailingAverage($snapshotValues, 3);
        $current = (float) end($snapshotValues);
      }

      if ($lastUpdated === NULL && $lastSnapshot) {
        if (!empty($lastSnapshot['snapshot_date']) && $lastSnapshot['snapshot_date'] instanceof \DateTimeImmutable) {
          $lastUpdated = $lastSnapshot['snapshot_date']->format('Y-m-d');
        }
        elseif (!empty($lastSnapshot['period_year'])) {
          $month = (int) ($lastSnapshot['period_month'] ?? 1);
          $lastUpdated = sprintf('%04d-%02d-01', (int) $lastSnapshot['period_year'], $month);
        }
      }
    }

    if ($annualOverrides) {
      foreach ($annualOverrides as $year => $value) {
        $annualOverrides[$year] = is_numeric($value) ? (float) $value : $value;
      }
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->withDemographicSegments($this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      $trend,
      $ttm12,
      $ttm3,
      $lastUpdated,
      $current,
      'kpi_member_post_12_month_retention',
      'percent'
    ), 'kpi_member_post_12_month_retention');
  }

  /**
   * Gets the data for the "Member Net Promoter Score (NPS)" KPI.
   */
  private function getKpiMemberNpsData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('kpi_member_nps');
    if (!empty($snapshotSeries)) {
      $snapshotValues = [];
      $lastSnapshot = NULL;
      foreach ($snapshotSeries as $record) {
        $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
        if ($value === NULL) {
          continue;
        }
        $snapshotValues[] = $value;
        $lastSnapshot = $record;

        if ($this->isAnnualSnapshotRecord($record)) {
          if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
            $year = $record['snapshot_date']->format('Y');
          }
          elseif (!empty($record['period_year'])) {
            $year = (string) $record['period_year'];
          }
          else {
            $year = NULL;
          }
          if ($year !== NULL) {
            $annualOverrides[$year] = $value;
          }
        }
      }

      if ($snapshotValues) {
        $trend = array_slice($snapshotValues, -12);
        $ttm12 = $this->calculateTrailingAverage($snapshotValues, 12);
        $ttm3 = $this->calculateTrailingAverage($snapshotValues, 3);
        $current = (float) end($snapshotValues);
      }

      if ($lastSnapshot) {
        if (!empty($lastSnapshot['snapshot_date']) && $lastSnapshot['snapshot_date'] instanceof \DateTimeImmutable) {
          $lastUpdated = $lastSnapshot['snapshot_date']->format('Y-m-d');
        }
        elseif (!empty($lastSnapshot['period_year'])) {
          $month = (int) ($lastSnapshot['period_month'] ?? 1);
          $lastUpdated = sprintf('%04d-%02d-01', (int) $lastSnapshot['period_year'], $month);
        }
      }
    }

    if ($current === NULL) {
      $current = (float) $this->membershipMetricsService->getAnnualMemberNps();
      $annualOverrides[(string) date('Y')] = $current;
      $lastUpdated = date('Y-m-d');
      $trend = $trend ?: [$current];
      $ttm12 = $ttm12 ?? $current;
      $ttm3 = $ttm3 ?? $current;
    }

    if ($annualOverrides) {
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->withDemographicSegments(
      $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'kpi_member_nps'),
      'kpi_member_nps'
    );
  }

  /**
   * Gets the data for the "$ Annual Individual Giving" KPI.
   */
  /**
   * Gets the data for the "$ Annual Corporate Sponsorships" KPI.
Process Group PGID: 1032535   *
   * Uses non-member donor monthly amounts as an automated sponsorship proxy.
   */
  private function getKpiAnnualCorporateSponsorshipsData(array $kpi_info): array {
    // Use trailing 12 months (last 4 quarters) so the figure is always a full
    // year of data regardless of where we are in the calendar year.
    $current = $this->financialDataService->getMetricTtmSum('income_corporate_donations');
    $trend = $this->financialDataService->getMetricTrend('income_corporate_donations');
    // NULL: data freshness depends on when the Google Sheet was last updated.
    $lastUpdated = NULL;

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_annual_corporate_sponsorships',
      'currency',
      'Google Sheets: Trailing 12-month (last 4 quarters) total of Corporate Donations.',
      '12 Quarters',
      'Trailing 12 months',
      1.0
    );
  }

  /**
   * Gets the data for the "# of Active Grants in Pipeline" KPI.
   */
  private function getKpiGrantPipelineCountData(array $kpi_info): array {
    $summary = $this->developmentDataService->getGrantsSummary();
    $currentYear = (int) date('Y');
    $current = (int) $this->developmentDataService->getGrantSubmittedYtdCount($currentYear);
    $trend = $this->developmentDataService->getGrantSubmittedTrend(12);
    $lastUpdated = date('Y-m-d');

    $result = $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_grant_pipeline_count',
      'integer',
      'CiviCRM Funding: YTD count of grants with submitted documentation/link populated (period keyed by due date because no submitted timestamp exists).',
      'Last 12 months',
      'YTD'
    );

    $activePipeline = (int) ($summary['pipeline_count'] ?? 0);
    $segments = $result['segments'] ?? [];
    $segments[] = [
      'label' => 'Active pipeline now',
      'value' => (float) $activePipeline,
      'format' => 'integer',
    ];
    $result['segments'] = $segments;
    return $result;
  }

  /**
   * Gets the data for the "Net Income (Program Lines)" KPI.
   */
  private function getKpiNetIncomeProgramLinesData(array $kpi_info): array {
    $current = $this->financialDataService->getNetIncomeProgramLinesTtm();
    $trend = $this->financialDataService->getNetIncomeProgramLinesTrend();
    // NULL: data freshness depends on when the Google Sheet was last updated.
    $lastUpdated = NULL;

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_net_income_program_lines',
      'currency',
      'Google Sheets: Trailing 12-month (last 4 quarters) net income from workspaces, storage, and media.',
      '12 Quarters',
      'Trailing 12 months',
      1.0
    );
  }

  /**
   * Gets the data for the "Reserve Funds" KPI.
   */
  private function getKpiReserveFundsMonthsData(array $kpi_info): array {
    $current = $this->financialDataService->getReserveFundsMonths();
    $trend = $this->financialDataService->getReserveFundsMonthsTrend(18);
    // NULL: data freshness depends on when the Google Sheet was last updated.
    $lastUpdated = NULL;

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_reserve_funds_months',
      'number',
      'Google Sheets: Cash and Cash Equivalents / Average Monthly Operating Expense.',
      '18 Months'
    );
  }

  /**
   * Gets the data for the "Earned Income Sustaining Core %" KPI.
   */
  private function getKpiEarnedIncomeSustainingCoreData(array $kpi_info): array {
    $current = $this->financialDataService->getEarnedIncomeSustainingCoreRate();
    $trend = $this->financialDataService->getEarnedIncomeSustainingCoreTrend();
    // Derive freshness from the Income-Statement sheet column header so the
    // display reflects the actual period of the data, not today's date.
    $lastUpdated = NULL;
    $headers = $this->getIncomeStatementHeaders();
    if ($headers) {
      $expenseRow = $this->getIncomeStatementRowValuesByMetricKey('expense_total', ['Total Expense', 'Total Expenses', 'Total Expenses (All)']);
      if ($expenseRow !== NULL) {
        $latest = $this->extractLatestSheetValue($expenseRow, $headers);
        if (!empty($latest['column_label'])) {
          $lastUpdated = $latest['column_label'];
        }
      }
    }

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_earned_income_sustaining_core',
      'percent',
      'Google Sheets: (Total Income - Grants/Donations) / (Total Expense).',
      '8 Quarters'
    );
  }

  /**
   * Gets the data for the "Member Revenue (Quarterly)" KPI.
   */
  private function getKpiMemberRevenueQuarterlyData(array $kpi_info): array {
    [$prevYear, $prevQ] = $this->financialDataService->getPreviousQuarterLabel();
    $colLabel = $this->financialDataService->quarterToColumnLabel($prevYear, $prevQ);
    $current = $this->financialDataService->getPreviousQuarterMemberRevenue();
    $trend = $this->financialDataService->getMetricTrend('income_membership');
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_member_revenue_quarterly',
      'currency',
      "Google Sheets: Membership income for $colLabel (most recently completed quarter).",
      '12 Quarters',
      "Prior quarter ($colLabel)",
      1.0
    );
  }

  /**
   * Gets the data for the "Active Incubator Ventures" KPI.
   */
  private function getKpiActiveIncubatorVenturesData(array $kpi_info): array {
    // Flag as in-development by returning NULL current.
    return $this->buildKpiResult($kpi_info, [], [], NULL, NULL, NULL, NULL, 'kpi_active_incubator_ventures');
  }

  /**
   * Gets the data for the "Incubator Workspace Occupancy" KPI.
   */
  private function getKpiIncubatorWorkspaceOccupancyData(array $kpi_info): array {
    // Flag as in-development by returning NULL current.
    return $this->buildKpiResult($kpi_info, [], [], NULL, NULL, NULL, NULL, 'kpi_incubator_workspace_occupancy', 'percent');
  }

  /**
   * Gets the data for the "Monthly Revenue at Risk" KPI.
   */
  private function getKpiMonthlyRevenueAtRiskData(array $kpi_info): array {
    // User requested in-development tag.
    return $this->buildKpiResult($kpi_info, [], [], NULL, NULL, NULL, NULL, 'kpi_monthly_revenue_at_risk', 'currency');
  }

  /**
   * Gets the data for the "Payment Resolution Rate" KPI.
   */
  private function getKpiPaymentResolutionRateData(array $kpi_info): array {
    // User requested in-development tag.
    return $this->buildKpiResult($kpi_info, [], [], NULL, NULL, NULL, NULL, 'kpi_payment_resolution_rate', 'percent');
  }

  /**
   * Gets the data for the "Member Lifetime Value (Projected)" KPI.
   */
  private function getKpiMemberLifetimeValueProjectedData(array $kpi_info): array {
    $ltvData = $this->financialDataService->getLifetimeValueByTenure();
    // Use the "0 Years" (New Member) projection as the conservative baseline.
    $current = $ltvData['0 Years']['total'] ?? NULL;
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_member_lifetime_value_projected',
      'currency',
      'Automated: Projected LTV for members in their first year (Year 0).'
    );
  }

  /**
   * Gets the data for the "Revenue vs Expense Index (per member)" KPI.
   */
  private function getKpiRevenuePerMemberIndexData(array $kpi_info): array {
    $current = $this->financialDataService->getRevenuePerMemberIndex();
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_revenue_per_member_index',
      'decimal',
      'Calculated: (Monthly Dues Revenue per Head) / (Monthly Operating Expense per Head).'
    );
  }

  /**
   * Gets the data for the "Net Income (Education Program)" KPI.
   */
  private function getKpiNetIncomeEducationData(array $kpi_info): array {
    // Use full-year actuals: sums all quarters labelled with the target year
    // (2025 when in Q1 of the following year). This avoids the TTM approach
    // picking up Q4 of the prior year instead of the most recent Q4.
    $current = $this->financialDataService->getNetIncomeEducationProgram();
    $trend = $this->financialDataService->getNetIncomeEducationTrend();
    // NULL: data freshness depends on when the Google Sheet was last updated.
    $lastUpdated = NULL;

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_net_income_education',
      'currency',
      'Google Sheets: Full-year net income from Education program.',
      '12 Quarters',
      'Full Year 2025',
      1.0
    );
  }

  /**
   * Gets the data for the "Entrepreneurship Joins Rate" KPI.
   */
  private function getKpiEntrepreneurshipJoinsRateData(array $kpi_info): array {
    $end = new DateTimeImmutable('now');
    $start = $end->modify('-12 months');
    $stats = $this->entrepreneurshipDataService->getJoinRate($start, $end);
    $trend = $this->entrepreneurshipDataService->getJoinRateTrend();
    
    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      date('Y-m-d'),
      $stats['value'],
      'kpi_entrepreneurship_joins_rate',
      'percent',
      'Profile: % of new members in trailing 12mo who identified as inventors, entrepreneurs, or sellers during signup.',
      '8 Quarters'
    );
  }

  /**
   * Gets the data for the "Entrepreneurship Retention" KPI.
   */
  private function getKpiEntrepreneurshipRetentionData(array $kpi_info): array {
    $stats = $this->entrepreneurshipDataService->getRetentionRate();
    $trend = $this->entrepreneurshipDataService->getRetentionRateTrend();

    return $this->withDemographicSegments($this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      date('Y-m-d'),
      $stats['value'],
      'kpi_entrepreneurship_retention',
      'percent',
      'Profile: 12-month retention rate for members who joined with entrepreneurial goals or experience.',
      '4 Years'
    ), 'kpi_entrepreneurship_retention');
  }

  /**
   * Adds BIPOC/Female-NB segment chips when demographic data is available.
   */
  private function withDemographicSegments(array $result, string $kpiId): array {
    $segments = [];
    $baseFormat = isset($result['format']) ? (string) $result['format'] : NULL;
    $normalizedId = trim($kpiId);

    foreach (['bipoc' => 'BIPOC', 'female_nb' => 'Female/NB'] as $segmentKey => $segmentLabel) {
      $segmentFormat = $baseFormat;
      $segmentValue = $this->resolveDemographicSegmentValue($normalizedId, $segmentKey, $segmentFormat);
      if (!is_numeric($segmentValue)) {
        continue;
      }
      $segments[] = [
        'label' => $segmentLabel,
        'value' => (float) $segmentValue,
        'format' => $segmentFormat,
      ];
    }

    if (!empty($segments)) {
      $result['segments'] = $segments;
    }

    return $result;
  }

  /**
   * Resolves a demographic segment value for a KPI from KPI-specific sources.
   */
  private function resolveDemographicSegmentValue(string $kpiId, string $segment, ?string &$resolvedFormat = NULL): ?float {
    $segment = strtolower(trim($segment));
    if (!in_array($segment, ['bipoc', 'female_nb'], TRUE)) {
      return NULL;
    }

    // First preference: exact segmented snapshot metric for this KPI.
    $snapshotValue = $this->getLatestSnapshotMetricValue($kpiId . '_' . $segment);
    if (is_numeric($snapshotValue)) {
      $resolvedFormat = 'percent';
      return (float) $snapshotValue;
    }

    // A legacy alias exists for this KPI ID in some datasets.
    if ($kpiId === 'kpi_total_first_time_workshop_participants') {
      $legacyValue = $this->getLatestSnapshotMetricValue('total_first_time_workshop_participants_' . $segment);
      if (is_numeric($legacyValue)) {
        $resolvedFormat = 'percent';
        return (float) $legacyValue;
      }
    }

    switch ($kpiId) {
      case 'kpi_active_participation':
        $end = (new \DateTimeImmutable('now'))->setTime(23, 59, 59);
        $start = $end->modify('-89 days')->setTime(0, 0, 0);
        $resolvedFormat = 'percent';
        return $this->getDemographicParticipationRate($segment, $start, $end);

      case 'kpi_first_year_member_retention':
        $retention = $this->getSegmentedRetentionMetrics($segment);
        if (isset($retention['first_year']) && is_numeric($retention['first_year'])) {
          $resolvedFormat = 'percent';
          return (float) $retention['first_year'];
        }
        return NULL;

      case 'kpi_member_post_12_month_retention':
        $retention = $this->getSegmentedRetentionMetrics($segment);
        if (isset($retention['post_12_month']) && is_numeric($retention['post_12_month'])) {
          $resolvedFormat = 'percent';
          return (float) $retention['post_12_month'];
        }
        return NULL;

      case 'kpi_workshop_attendees':
        $resolvedFormat = 'percent';
        if ($segment === 'bipoc') {
          return $this->getWorkshopBipocParticipationRate();
        }
        return $this->getWorkshopFemaleNbParticipationRate();

      case 'kpi_total_first_time_workshop_participants':
        $resolvedFormat = 'percent';
        $rates = $this->getFirstTimeWorkshopDemographicRates();
        if ($segment === 'bipoc') {
          return isset($rates['bipoc_rate']) && is_numeric($rates['bipoc_rate']) ? (float) $rates['bipoc_rate'] : NULL;
        }
        return isset($rates['female_nb_rate']) && is_numeric($rates['female_nb_rate']) ? (float) $rates['female_nb_rate'] : NULL;

      case 'kpi_total_new_member_signups':
        $resolvedFormat = 'percent';
        $rates = $this->getNewMemberSignupDemographicRates();
        if ($segment === 'bipoc') {
          return isset($rates['bipoc_rate']) && is_numeric($rates['bipoc_rate']) ? (float) $rates['bipoc_rate'] : NULL;
        }
        return isset($rates['female_nb_rate']) && is_numeric($rates['female_nb_rate']) ? (float) $rates['female_nb_rate'] : NULL;

      case 'kpi_total_active_members':
        // Do not infer segments from global demographic shares here; they may
        // use a different population/timeslice than this KPI's active count.
        // Segments are shown only when explicit KPI-specific metrics exist.
        return NULL;
    }

    return NULL;
  }

  /**
   * Returns the workshop female/non-binary participation rate.
   */
  private function getWorkshopFemaleNbParticipationRate(): ?float {
    $end = (new \DateTimeImmutable('last day of this month'))->setTime(23, 59, 59);
    $start = $end->modify('first day of this month')->modify('-11 months')->setTime(0, 0, 0);
    $demographics = $this->eventsMembershipDataService->getParticipantDemographics($start, $end);
    if (empty($demographics['gender']['labels']) || empty($demographics['gender']['workshop'])) {
      return NULL;
    }

    $labels = $demographics['gender']['labels'];
    $workshop = $demographics['gender']['workshop'];
    $knownCount = 0.0;
    $femaleNbCount = 0.0;

    foreach ($labels as $index => $label) {
      $count = isset($workshop[$index]) && is_numeric($workshop[$index]) ? (float) $workshop[$index] : 0.0;
      if ($count <= 0) {
        continue;
      }
      if ($this->isUnspecifiedGenderLabel((string) $label)) {
        continue;
      }
      $knownCount += $count;
      if ($this->isFemaleNbGenderLabel((string) $label)) {
        $femaleNbCount += $count;
      }
    }

    return $knownCount > 0 ? ($femaleNbCount / $knownCount) : NULL;
  }

  /**
   * Returns the workshop BIPOC participation rate from known ethnicity values.
   */
  private function getWorkshopBipocParticipationRate(): ?float {
    $end = new \DateTimeImmutable('last day of this month 23:59:59');
    $start = $end->modify('first day of this month')->modify('-11 months')->setTime(0, 0, 0);
    $demographics = $this->eventsMembershipDataService->getParticipantDemographics($start, $end);

    $labels = $demographics['ethnicity']['labels'] ?? [];
    $workshop = $demographics['ethnicity']['workshop'] ?? [];
    $bipocCount = 0.0;
    $knownCount = 0.0;

    foreach ($labels as $index => $label) {
      $count = isset($workshop[$index]) && is_numeric($workshop[$index]) ? (float) $workshop[$index] : 0.0;
      if ($count <= 0) {
        continue;
      }
      if ($this->isUnspecifiedEthnicity((string) $label)) {
        continue;
      }
      $knownCount += $count;
      if ($this->isBipocEthnicityLabel((string) $label)) {
        $bipocCount += $count;
      }
    }

    return $knownCount > 0 ? ($bipocCount / $knownCount) : NULL;
  }

  /**
   * Returns first-time workshop demographic rates from the trailing 12 months.
   */
  private function getFirstTimeWorkshopDemographicRates(): array {
    static $cache = NULL;
    if (is_array($cache)) {
      return $cache;
    }

    $end = new \DateTimeImmutable('last day of this month 23:59:59');
    $start = $end->modify('first day of this month')->modify('-11 months')->setTime(0, 0, 0);
    $rates = $this->eventsMembershipDataService->getFirstTimeWorkshopDemographicRates($start, $end, 'Workshop');

    $cache = is_array($rates) ? $rates : [];
    return $cache;
  }

  /**
   * Returns new-member signup demographic rates from the trailing 12 months.
   */
  private function getNewMemberSignupDemographicRates(): array {
    static $cache = NULL;
    if (is_array($cache)) {
      return $cache;
    }

    $db = \Drupal::database();
    $end = new \DateTimeImmutable('now');
    $start = $end->modify('-12 months');

    $startTs = $start->getTimestamp();
    $endTs = $end->getTimestamp();

    $query = $db->select('profile', 'p');
    $query->addField('p', 'uid');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->condition('p.created', $startTs, '>=');
    $query->condition('p.created', $endTs, '<=');
    $query->leftJoin('profile__field_member_gender', 'gender', 'gender.entity_id = p.profile_id AND gender.deleted = 0');
    $query->addField('gender', 'field_member_gender_value', 'gender_value');
    $query->leftJoin('profile__field_member_ethnicity', 'eth', 'eth.entity_id = p.profile_id AND eth.deleted = 0');
    $query->addField('eth', 'field_member_ethnicity_value', 'ethnicity_value');

    $byUid = [];
    foreach ($query->execute() as $record) {
      $uid = (int) ($record->uid ?? 0);
      if ($uid <= 0) {
        continue;
      }
      if (!isset($byUid[$uid])) {
        $byUid[$uid] = [
          'gender' => '',
          'ethnicity' => [],
        ];
      }
      $gender = trim((string) ($record->gender_value ?? ''));
      if ($gender !== '') {
        $byUid[$uid]['gender'] = strtolower($gender);
      }
      $ethnicity = trim((string) ($record->ethnicity_value ?? ''));
      if ($ethnicity !== '') {
        $byUid[$uid]['ethnicity'][] = $ethnicity;
      }
    }

    $genderKnown = 0;
    $femaleNb = 0;
    $ethnicityKnown = 0;
    $bipoc = 0;

    foreach ($byUid as $entry) {
      $gender = $entry['gender'] ?? '';
      if ($gender !== '' && $gender !== 'decline') {
        $genderKnown++;
        if (in_array($gender, ['female', 'other', 'transgender', 'self_describe'], TRUE)) {
          $femaleNb++;
        }
      }

      $reported = FALSE;
      $isBipoc = FALSE;
      foreach ($entry['ethnicity'] as $ethnicity) {
        $label = strtolower(trim((string) $ethnicity));
        if ($this->isUnspecifiedEthnicity($label)) {
          continue;
        }
        $reported = TRUE;
        if ($this->isBipocEthnicityLabel($label)) {
          $isBipoc = TRUE;
        }
      }
      if ($reported) {
        $ethnicityKnown++;
        if ($isBipoc) {
          $bipoc++;
        }
      }
    }

    $cache = [
      'female_nb_rate' => $genderKnown > 0 ? ($femaleNb / $genderKnown) : NULL,
      'bipoc_rate' => $ethnicityKnown > 0 ? ($bipoc / $ethnicityKnown) : NULL,
      'reported_gender' => $genderKnown,
      'reported_ethnicity' => $ethnicityKnown,
    ];
    return $cache;
  }

  /**
   * Calculates workshop attendee YTD value from monthly items.
   */
  private function calculateWorkshopAttendeesYtd(array $monthlyItems): ?int {
    if (empty($monthlyItems)) {
      return NULL;
    }
    $year = (int) (new \DateTimeImmutable('now'))->format('Y');
    $sum = 0;
    $found = FALSE;
    foreach ($monthlyItems as $item) {
      if (empty($item['date']) || !$item['date'] instanceof \DateTimeImmutable) {
        continue;
      }
      if ((int) $item['date']->format('Y') !== $year) {
        continue;
      }
      $sum += isset($item['count']) && is_numeric($item['count']) ? (int) $item['count'] : 0;
      $found = TRUE;
    }

    return $found ? $sum : NULL;
  }

  /**
   * Determines whether a gender label should be excluded from known totals.
   */
  private function isUnspecifiedGenderLabel(string $label): bool {
    $normalized = strtolower(trim($label));
    return $normalized === ''
      || str_contains($normalized, 'unspecified')
      || str_contains($normalized, 'unknown')
      || str_contains($normalized, 'prefer not');
  }

  /**
   * Determines whether a gender label counts toward the Female/NB segment.
   */
  private function isFemaleNbGenderLabel(string $label): bool {
    $normalized = strtolower(trim($label));
    if ($normalized === '') {
      return FALSE;
    }

    if (str_contains($normalized, 'female') || str_contains($normalized, 'woman')) {
      return TRUE;
    }

    return str_contains($normalized, 'non')
      || str_contains($normalized, 'nb')
      || str_contains($normalized, 'gender');
  }

  /**
   * Returns segmented retention values for first-year and post-12mo KPIs.
   */
  private function getSegmentedRetentionMetrics(string $segment): array {
    static $cache = [];
    $segment = strtolower(trim($segment));
    if (!in_array($segment, ['bipoc', 'female_nb'], TRUE)) {
      return [];
    }

    if (isset($cache[$segment])) {
      return $cache[$segment];
    }

    $matrix = $this->buildSegmentedMonthlyCohortRetentionMatrix($segment, 48);
    if (empty($matrix)) {
      $cache[$segment] = [];
      return $cache[$segment];
    }

    $firstYearSeries = [];
    $post12Series = [];
    foreach ($matrix as $row) {
      $ret12 = $row['retention'][12] ?? NULL;
      if (is_numeric($ret12)) {
        $ret12Decimal = (float) $ret12 / 100;
        $firstYearSeries[] = $ret12Decimal;

        $ret24 = $row['retention'][24] ?? NULL;
        if (is_numeric($ret24) && $ret12Decimal > 0) {
          $ret24Decimal = (float) $ret24 / 100;
          $ratio = $ret24Decimal / $ret12Decimal;
          $post12Series[] = max(0.0, min(1.0, $ratio));
        }
      }
    }

    $cache[$segment] = [
      'first_year' => $firstYearSeries ? (float) end($firstYearSeries) : NULL,
      'post_12_month' => $post12Series ? (float) end($post12Series) : NULL,
    ];
    return $cache[$segment];
  }

  /**
   * Builds a monthly cohort retention matrix filtered by demographic segment.
   */
  private function buildSegmentedMonthlyCohortRetentionMatrix(string $segment, int $monthsBack = 48): array {
    $segment = strtolower(trim($segment));
    if (!in_array($segment, ['bipoc', 'female_nb'], TRUE)) {
      return [];
    }

    $db = \Drupal::database();
    $schema = $db->schema();
    if (
      !$schema->tableExists('profile') ||
      !$schema->tableExists('user__roles') ||
      !$schema->tableExists('profile__field_member_end_date') ||
      !$schema->tableExists('civicrm_uf_match') ||
      !$schema->tableExists('civicrm_contact')
    ) {
      return [];
    }

    $monthsBack = max(24, $monthsBack);
    $tz = new \DateTimeZone(date_default_timezone_get());
    $nowDate = (new \DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
    $cutoffDate = $nowDate
      ->modify('first day of this month')
      ->modify(sprintf('-%d months', $monthsBack))
      ->setTime(0, 0, 0);
    $cutoffTs = $cutoffDate->getTimestamp();

    $query = $db->select('profile', 'p');
    $query->fields('p', ['uid', 'created']);
    $query->leftJoin('profile__field_member_end_date', 'end_date', 'end_date.entity_id = p.profile_id AND end_date.deleted = 0');
    $query->addField('end_date', 'field_member_end_date_value', 'end_value');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->innerJoin('civicrm_uf_match', 'ufm', 'ufm.uf_id = p.uid');
    $query->innerJoin('civicrm_contact', 'c', 'c.id = ufm.contact_id');
    $query->condition('c.is_deleted', 0);

    if ($segment === 'female_nb') {
      $query->condition('c.gender_id', [1, 4, 5, 6], 'IN');
    }
    else {
      if (!$schema->tableExists('civicrm_value_demographics_15')) {
        return [];
      }
      $query->innerJoin('civicrm_value_demographics_15', 'demo', 'demo.entity_id = c.id');
      $bipocValues = ['asian', 'black', 'middleeast', 'mena', 'hispanic', 'native', 'aian', 'islander', 'nhpi', 'multi', 'other'];
      $or = $query->orConditionGroup();
      foreach ($bipocValues as $value) {
        $or->condition('demo.ethnicity_46', '%' . $db->escapeLike($value) . '%', 'LIKE');
      }
      $query->condition($or);
    }

    $records = [];
    foreach ($query->execute() as $row) {
      $uid = (int) ($row->uid ?? 0);
      $joinTs = (int) ($row->created ?? 0);
      if ($uid <= 0 || $joinTs <= 0) {
        continue;
      }
      $endTs = NULL;
      $endValue = trim((string) ($row->end_value ?? ''));
      if ($endValue !== '') {
        $parsed = strtotime($endValue . ' 23:59:59');
        if ($parsed !== FALSE) {
          $endTs = $parsed;
        }
      }
      $records[$uid] = [
        'join' => $joinTs,
        'end' => $endTs,
        'uid' => $uid,
        'has_member_role' => FALSE,
      ];
    }

    if (empty($records)) {
      return [];
    }

    $roleQuery = $db->select('user__roles', 'ur');
    $roleQuery->fields('ur', ['entity_id']);
    $roleQuery->condition('ur.entity_id', array_keys($records), 'IN');
    $roleQuery->condition('ur.roles_target_id', ['current_member', 'member'], 'IN');
    $roleQuery->distinct();
    $activeRoleUids = array_flip(array_map('intval', $roleQuery->execute()->fetchCol()));
    foreach ($records as $uid => &$record) {
      $record['has_member_role'] = isset($activeRoleUids[$uid]);
    }
    unset($record);

    $cohorts = [];
    foreach ($records as $record) {
      if ($record['end'] === NULL && !$record['has_member_role']) {
        continue;
      }
      $joinTs = (int) $record['join'];
      if ($joinTs < $cutoffTs) {
        continue;
      }

      $joinDate = (new \DateTimeImmutable('@' . $joinTs))->setTimezone($tz);
      $key = $joinDate->format('Y-m');
      $cohortStart = $joinDate->modify('first day of this month')->setTime(0, 0, 0);
      if (!isset($cohorts[$key])) {
        $cohorts[$key] = [
          'cohort_start' => $cohortStart,
          'joined' => 0,
          'survived_counts' => array_fill(0, $monthsBack + 1, 0),
        ];
      }

      $cohorts[$key]['joined']++;
      $endTs = $record['end'];
      for ($m = 0; $m <= $monthsBack; $m++) {
        $milestone = $m === 0
          ? $cohortStart
          : $cohortStart->modify(sprintf('+%d months', $m))->modify('last day of this month')->setTime(23, 59, 59);
        if ($milestone > $nowDate) {
          continue;
        }
        if ($endTs === NULL || $endTs >= $milestone->getTimestamp()) {
          $cohorts[$key]['survived_counts'][$m]++;
        }
      }
    }

    if (empty($cohorts)) {
      return [];
    }

    ksort($cohorts);
    $matrix = [];
    foreach ($cohorts as $data) {
      $row = [
        'joined' => $data['joined'],
        'retention' => [],
      ];
      foreach ($data['survived_counts'] as $m => $count) {
        $milestone = $m === 0
          ? $data['cohort_start']
          : $data['cohort_start']->modify(sprintf('+%d months', $m))->modify('last day of this month')->setTime(23, 59, 59);
        if ($milestone > $nowDate) {
          $row['retention'][$m] = NULL;
          continue;
        }
        $row['retention'][$m] = $data['joined'] > 0 ? round(($count / $data['joined']) * 100, 1) : 0.0;
      }
      $matrix[] = $row;
    }

    return $matrix;
  }

  /**
   * Returns the most recent numeric value for a snapshot KPI metric.
   */
  private function getLatestSnapshotMetricValue(string $kpiId): ?float {
    $series = $this->snapshotDataService->getKpiMetricSeries($kpiId);
    if (empty($series)) {
      return NULL;
    }

    for ($index = count($series) - 1; $index >= 0; $index--) {
      $value = $series[$index]['value'] ?? NULL;
      if (!is_numeric($value)) {
        continue;
      }
      return (float) $value;
    }

    return NULL;
  }

  /**
   * Gets the data for the "Grant Win Ratio %" KPI.
   */
  private function getKpiGrantWinRatioData(array $kpi_info): array {
    $summary = $this->developmentDataService->getGrantsSummary();
    $current = (float) ($summary['win_ratio'] ?? 0.0);
    $trend = $this->developmentDataService->getGrantWinRatioTrend();
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_grant_win_ratio',
      'percent',
      'CiviCRM: (Grants Won) / (Grants Won + Lost + Abandoned).',
      '8 Quarters'
    );
  }

  /**
   * Gets the data for the "Donor Retention Rate %" KPI.
   */
  private function getKpiDonorRetentionRateData(array $kpi_info): array {
    $stats = $this->developmentDataService->getDonorStats();
    $current = (float) ($stats['retention_rate'] ?? 0.0);
    $trend = $this->developmentDataService->getDonorRetentionRateTrend();
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_donor_retention_rate',
      'percent',
      'CiviCRM: (Donors in current 12mo who also gave in previous 12mo) / (Previous 12mo donors).',
      '6 Quarters'
    );
  }

  /**
   * Gets the data for the "# of Recurring Donors" KPI.
   */
  private function getKpiRecurringDonorsCountData(array $kpi_info): array {
    $current = (float) $this->developmentDataService->getRecurringDonorsCount();
    $trend = $this->developmentDataService->getRecurringDonorsCountTrend();
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_recurring_donors_count',
      'integer',
      'CiviCRM: Count of contacts with active recurring setup OR tagged "Recurring Donor" (tagged contacts included in trend when they donated that quarter).',
      '8 Quarters'
    );
  }

  /**
   * Gets the data for the "# of Donor Upgrades" KPI.
   */
  private function getKpiDonorUpgradesCountData(array $kpi_info): array {
    $stats = $this->developmentDataService->getDonorStats();
    $current = (float) ($stats['upgrades_count'] ?? 0);
    $trend = $this->developmentDataService->getDonorUpgradesCountTrend();
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_donor_upgrades_count',
      'integer',
      'CiviCRM: Donors who increased total giving in the last 12 months vs previous 12 months.',
      '6 Quarters'
    );
  }

  /**
   * Gets the data for the "# of Entrepreneurship Events Held" KPI.
   *
   * Uses entrepreneurship-goal activity as an automated engagement proxy.
   */
  private function getKpiEntrepreneurshipEventParticipationData(array $kpi_info): array {
    $currentYear = (int) date('Y');

    // "Current" = trailing 12 months — always a full year of data regardless
    // of calendar position, consistent with the other outreach/education KPIs.
    $current = (float) $this->eventsMembershipDataService->getEntrepreneurshipEventParticipantsTrailing();
    $trend = $this->eventsMembershipDataService->getEntrepreneurshipEventParticipantsTrend();
    $lastUpdated = date('Y-m-d');

    // Populate the annual table with prior complete years.
    $annualOverrides = [];
    $annualOverrides[(string) ($currentYear - 1)] = (float) $this->eventsMembershipDataService->getEntrepreneurshipEventParticipants($currentYear - 1);
    if ($currentYear > 2025) {
      $annualOverrides['2025'] = (float) $this->eventsMembershipDataService->getEntrepreneurshipEventParticipants(2025);
    }

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      $trend,
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_entrepreneurship_event_participation',
      'number',
      'CiviCRM: Unique participants in events with interest "Prototyping & Invention" or "Entrepreneurship, Startups & Business".',
      '8 Quarters',
      'Trailing 12 months',
      1.0
    );
  }

  /**
   * Gets the data for the "Member Satisfaction (Equipment)" KPI.
   */
  private function getKpiMemberSatisfactionEquipmentData(array $kpi_info): array {
    return $this->buildMemberSatisfactionProxyData($kpi_info, 'kpi_member_satisfaction_equipment');
  }

  /**
   * Builds satisfaction KPI data from snapshots, then member NPS fallback.
   */
  private function buildMemberSatisfactionProxyData(array $kpi_info, string $kpiId): array {
    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries($kpiId);
    if (!empty($snapshotSeries)) {
      $values = [];
      $annualOverrides = [];
      $lastUpdated = NULL;

      foreach ($snapshotSeries as $record) {
        $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
        if ($value === NULL) {
          continue;
        }
        $values[] = $value;

        if ($this->isAnnualSnapshotRecord($record)) {
          $year = NULL;
          if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
            $year = $record['snapshot_date']->format('Y');
          }
          elseif (!empty($record['period_year'])) {
            $year = (string) $record['period_year'];
          }
          if ($year !== NULL) {
            $annualOverrides[$year] = $value;
          }
        }

        if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
          $lastUpdated = $record['snapshot_date']->format('Y-m-d');
        }
      }

      if ($values) {
        if ($annualOverrides) {
          ksort($annualOverrides, SORT_STRING);
        }
        return $this->buildKpiResult(
          $kpi_info,
          $annualOverrides,
          array_slice($values, -12),
          $this->calculateTrailingAverage($values, 12),
          $this->calculateTrailingAverage($values, 3),
          $lastUpdated,
          (float) end($values),
          $kpiId,
          NULL,
          'Direct: KPI snapshot series.'
        );
      }
    }

    $memberNps = (float) $this->membershipMetricsService->getAnnualMemberNps();
    $current = $this->mapNpsToFivePointScale($memberNps);
    $year = (string) date('Y');
    $annualOverrides = [$year => $current];

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      [$current],
      $current,
      $current,
      date('Y-m-d'),
      $current,
      $kpiId,
      NULL,
      'Proxy: Member survey NPS mapped to 1-5 satisfaction scale.'
    );
  }

  /**
   * Gets the data for the "Retention POC %" KPI.
   */
  private function getKpiRetentionPocData(array $kpi_info): array {
    $annualOverrides = [];
    $currentYear = (int) date('Y');

    $ethnicityOptions = $this->membershipMetricsService->getCohortFilterOptions('ethnicity', 100);
    $pocValues = [];
    foreach ($ethnicityOptions as $option) {
      $label = (string) ($option['label'] ?? $option['value'] ?? '');
      if ($label !== '' && $this->isBipocEthnicityLabel($label)) {
        $pocValues[] = (string) $option['value'];
      }
    }
    $pocValues = array_values(array_unique($pocValues));

    if ($pocValues) {
      for ($year = $currentYear - 5; $year <= $currentYear - 1; $year++) {
        $joined = 0;
        $active = 0;

        foreach ($pocValues as $ethnicityValue) {
          $cohort = $this->membershipMetricsService->getAnnualCohorts($year, $year, [
            'type' => 'ethnicity',
            'value' => $ethnicityValue,
          ]);
          if (empty($cohort[0])) {
            continue;
          }
          $joined += (int) ($cohort[0]['joined'] ?? 0);
          $active += (int) ($cohort[0]['active'] ?? 0);
        }

        if ($joined > 0) {
          $annualOverrides[(string) $year] = $active / $joined;
        }
      }
    }

    if (empty($annualOverrides)) {
      $fallback = (float) $this->membershipMetricsService->getAnnualRetentionPoc();
      if ($fallback > 1.5 && $fallback <= 100) {
        $fallback = $fallback / 100;
      }
      return $this->buildKpiResult(
        $kpi_info,
        [(string) ($currentYear - 1) => $fallback],
        [$fallback],
        $fallback,
        $fallback,
        date('Y-m-d'),
        $fallback,
        'kpi_retention_poc',
        'percent',
        'Fallback: Membership metrics POC retention estimate.'
      );
    }

    ksort($annualOverrides, SORT_STRING);
    $trend = array_values($annualOverrides);
    $current = end($trend);
    $latestYear = (int) array_key_last($annualOverrides);
    $lastUpdated = sprintf('%04d-12-31', $latestYear);

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      $trend,
      $this->calculateTrailingAverage($trend, 12),
      $this->calculateTrailingAverage($trend, 3),
      $lastUpdated,
      $current,
      'kpi_retention_poc',
      'percent',
      'Automated: Cohort retention filtered by BIPOC ethnicity.'
    );
  }

  /**
   * Gets the data for the "Shop Utilization (Active Participation %)" KPI.
   */
  private function getKpiShopUtilizationData(array $kpi_info): array {
    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('kpi_shop_utilization');
    if (!empty($snapshotSeries)) {
      $values = [];
      $annual = [];
      $lastUpdated = NULL;
      foreach ($snapshotSeries as $record) {
        $value = is_numeric($record['value']) ? (float) $record['value'] : NULL;
        if ($value === NULL) {
          continue;
        }
        $values[] = $value;
        if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
          $lastUpdated = $record['snapshot_date']->format('Y-m-d');
        }
        if ($this->isAnnualSnapshotRecord($record)) {
          $year = NULL;
          if (!empty($record['snapshot_date']) && $record['snapshot_date'] instanceof \DateTimeImmutable) {
            $year = $record['snapshot_date']->format('Y');
          }
          elseif (!empty($record['period_year'])) {
            $year = (string) $record['period_year'];
          }
          if ($year !== NULL) {
            $annual[$year] = $value;
          }
        }
      }

      if (!empty($values)) {
        if ($annual) {
          ksort($annual, SORT_STRING);
        }
        $current = (float) end($values);
        return $this->buildKpiResult(
          $kpi_info,
          $annual,
          array_slice($values, -12),
          $this->calculateTrailingAverage($values, 12),
          $this->calculateTrailingAverage($values, 3),
          $lastUpdated,
          $current,
          'kpi_shop_utilization',
          'percent',
          'Snapshot-backed: Door-access participation ratio.'
        );
      }
    }

    $annualOverrides = [];
    $trend = [];
    $lastUpdated = date('Y-m-d');

    $endOfCurrentMonth = (new \DateTimeImmutable('last day of this month'))->setTime(23, 59, 59);
    for ($i = 11; $i >= 0; $i--) {
      $windowEnd = $endOfCurrentMonth->modify("-{$i} months");
      $windowStart = $windowEnd->modify('-89 days')->setTime(0, 0, 0);
      $buckets = $this->utilizationDataService->getVisitFrequencyBuckets($windowStart->getTimestamp(), $windowEnd->getTimestamp());
      $ratio = $this->deriveParticipationRatioFromBuckets($buckets);
      if ($ratio === NULL) {
        continue;
      }
      $trend[] = $ratio;
      $year = $windowEnd->format('Y');
      if (!isset($annualOverrides[$year])) {
        $annualOverrides[$year] = [];
      }
      $annualOverrides[$year][] = $ratio;
    }

    $annualAverages = [];
    foreach ($annualOverrides as $year => $values) {
      $annualAverages[$year] = $this->calculateTrailingAverage($values, count($values));
    }
    if ($annualAverages) {
      ksort($annualAverages, SORT_STRING);
    }

    $current = !empty($trend) ? (float) end($trend) : NULL;
    if ($current === NULL) {
      $fallback = (float) $this->utilizationDataService->getAnnualActiveParticipation();
      if ($fallback > 1.5 && $fallback <= 100) {
        $fallback = $fallback / 100;
      }
      $current = $fallback;
      $annualAverages[(string) date('Y')] = $fallback;
      $trend = [$fallback];
    }

    return $this->buildKpiResult(
      $kpi_info,
      $annualAverages,
      $trend,
      $this->calculateTrailingAverage($trend, 12),
      $this->calculateTrailingAverage($trend, 3),
      $lastUpdated,
      $current,
      'kpi_shop_utilization',
      'percent',
      'Automated: Door-access participation ratio over rolling windows.'
    );
  }

  /**
   * Gets the data for the "Board Governance" KPI.
   */
  private function getKpiBoardGovernanceData(array $kpi_info): array {
    $stats = $this->governanceBoardDataService->getBoardGovernanceKpi();
    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $stats['last_updated'],
      $stats['value'],
      'kpi_board_governance',
      'percent',
      'Survey: Annual self-assessment of Board effectiveness. Value is % of responses scored 4 or 5.'
    );
  }

  /**
   * Gets the data for the "Committee Effectiveness" KPI.
   */
  private function getKpiCommitteeEffectivenessData(array $kpi_info): array {
    $stats = $this->governanceBoardDataService->getCommitteeEffectivenessKpi();
    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $stats['last_updated'],
      $stats['value'],
      'kpi_committee_effectiveness',
      'percent',
      'Survey: Annual self-assessment of Committee effectiveness. Value is % of responses scored 4 or 5.'
    );
  }

  /**
   * Gets the data for the "Reserve Funds (as Months of Operating Expense)" KPI.
   *
   * @param array $kpi_info
   *   The KPI configuration info.
   *
   * @return array
   *   The KPI data.
   */
  private function getReserveFundsMonthsData(array $kpi_info): array {
    $series = $this->buildReserveFundsSeries();
    if (!$series) {
      return $this->buildKpiResult($kpi_info, [], [], NULL, NULL, NULL, 'TBD', 'reserve_funds_months');
    }

    return $this->buildKpiResult(
      $kpi_info,
      $series['annual'] ?? [],
      $series['trend'] ?? [],
      $series['ttm12'] ?? NULL,
      $series['ttm3'] ?? NULL,
      $series['last_updated'] ?? NULL,
      $series['current'] ?? NULL,
      'reserve_funds_months'
    );
  }

  /**
   * Gets the data for the "Membership Diversity (% BIPOC)" KPI.
   *
   * @param array $kpi_info
   *   The KPI configuration info.
   *
   * @return array
   *   The KPI data.
   */
  private function getKpiMembershipDiversityBipocData(array $kpi_info): array {
    $summary = $this->demographicsDataService->getMembershipEthnicitySummary();
    $current = isset($summary['percentage']) ? (float) $summary['percentage'] : NULL;
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_membership_diversity_bipoc',
      'percent'
    );
  }

  /**
   * Gets the data for the "Board Gender Diversity" KPI.
   */
  private function getKpiBoardGenderDiversityData(array $kpi_info): array {
    try {
      $composition = $this->governanceBoardDataService->getBoardComposition();
    }
    catch (\Throwable $exception) {
      return $this->getPlaceholderData($kpi_info, 'kpi_board_gender_diversity');
    }
    $gender = $composition['gender']['actual_pct'] ?? [];
    $maleShare = isset($gender['Male']) ? (float) $gender['Male'] : 0.0;
    $current = max(0.0, 1.0 - $maleShare);
    // NULL: board roster is manually maintained in Google Sheets; freshness unknown.
    $lastUpdated = NULL;

    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_board_gender_diversity',
      'percent'
    );
  }

  /**
   * Gets the data for the "Board Ethnic Diversity" KPI.
   */
  private function getKpiBoardEthnicDiversityData(array $kpi_info): array {
    try {
      $composition = $this->governanceBoardDataService->getBoardComposition();
    }
    catch (\Throwable $exception) {
      return $this->getPlaceholderData($kpi_info, 'kpi_board_ethnic_diversity');
    }
    $ethnicity = $composition['ethnicity']['actual_pct'] ?? [];
    $current = $this->sumPercentages($ethnicity, $this->getBoardBipocLabels());
    // NULL: board roster is manually maintained in Google Sheets; freshness unknown.
    $lastUpdated = NULL;

    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'kpi_board_ethnic_diversity',
      'percent'
    );
  }

  /**
   * Maps a standard NPS score (-100..100) to a 1..5 satisfaction scale.
   */
  private function mapNpsToFivePointScale(float $nps): float {
    $normalized = max(-100, min(100, $nps));
    return round(1 + (($normalized + 100) / 200) * 4, 2);
  }

  /**
   * Converts utilization frequency buckets into participation ratio.
   */
  private function deriveParticipationRatioFromBuckets(array $buckets): ?float {
    $total = 0;
    foreach ($buckets as $count) {
      if (is_numeric($count)) {
        $total += (int) $count;
      }
    }
    if ($total <= 0) {
      return NULL;
    }
    $noVisits = isset($buckets['no_visits']) && is_numeric($buckets['no_visits']) ? (int) $buckets['no_visits'] : 0;
    $participants = max(0, $total - $noVisits);
    return $participants / $total;
  }

  /**
   * Determines whether an ethnicity label should be treated as unspecified.
   */
  private function isUnspecifiedEthnicity(string $label): bool {
    $normalized = strtolower(trim($label));
    if ($normalized === '') {
      return TRUE;
    }

    $unspecifiedTokens = [
      'unspecified',
      'unknown',
      'prefer not',
      'decline',
      'not provided',
      'n/a',
    ];
    foreach ($unspecifiedTokens as $token) {
      if (str_contains($normalized, $token)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Determines whether an ethnicity label should be counted as BIPOC.
   */
  private function isBipocEthnicityLabel(string $label): bool {
    $normalized = strtolower(trim($label));
    if ($this->isUnspecifiedEthnicity($normalized)) {
      return FALSE;
    }

    return !str_contains($normalized, 'white');
  }

  /**
   * Sums the provided percentage labels, ignoring missing entries.
   */
  private function sumPercentages(array $values, array $labels): float {
    $total = 0.0;
    foreach ($labels as $label) {
      $total += isset($values[$label]) ? (float) $values[$label] : 0.0;
    }
    return $total;
  }

  /**
   * Returns the BIPOC-focused labels for board composition summaries.
   */
  private function getBoardBipocLabels(): array {
    return [
      'Asian',
      'Black or African American',
      'Middle Eastern or North African',
      'Native Hawaiian or Pacific Islander',
      'Hispanic or Latino',
      'American Indian or Alaska Native',
      'Other / Multi',
    ];
  }

  /**
   * Calculates a trailing average for the supplied window.
   *
   * @param array $values
   *   Ordered numeric values.
   * @param int $window
   *   Number of points to include in the trailing window.
   *
   * @return float|null
   *   The trailing average, or NULL if there is insufficient data.
   */
  private function calculateTrailingAverage(array $values, int $window): ?float {
    if ($window <= 0 || empty($values)) {
      return NULL;
    }

    $numeric = array_values(array_filter($values, static function ($value) {
      return is_numeric($value);
    }));
    if (!$numeric) {
      return NULL;
    }

    $slice = array_slice($numeric, -1 * min($window, count($numeric)));
    if (!$slice) {
      return NULL;
    }

    return array_sum($slice) / count($slice);
  }

  /**
   * Calculates a trailing sum for the supplied window.
   *
   * @param array $values
   *   Ordered numeric values.
   * @param int $window
   *   Number of points to include in the trailing window.
   *
   * @return float|null
   *   The trailing sum, or NULL if there is insufficient data.
   */
  private function calculateTrailingSum(array $values, int $window): ?float {
    if ($window <= 0 || empty($values)) {
      return NULL;
    }

    $numeric = array_values(array_filter($values, 'is_numeric'));
    if (!$numeric) {
      return NULL;
    }

    $slice = array_slice($numeric, -1 * min($window, count($numeric)));
    if (!$slice) {
      return NULL;
    }

    return array_sum($slice);
  }

  /**
   * Returns period metadata for a calendar-year YTD KPI.
   *
   * When the supplied $targetYear is the current year, the period is
   * accumulating and the fraction of the year elapsed is returned so the
   * goal can be scaled proportionally when evaluating performance.
   *
   * When $targetYear is a prior year (common in Q1 when services fall back
   * to the previous year to avoid sparse data), the period is complete and
   * fraction = 1.0.
   *
   * @param int $targetYear
   *   The year the KPI's current value represents.
   *
   * @return array{label: string, fraction: float}
   *   'label'    – human-readable period description.
   *   'fraction' – 0 < fraction ≤ 1.0; use 1.0 for completed periods.
   */
  private function getYtdPeriodInfo(int $targetYear): array {
    $currentYear = (int) date('Y');
    if ($targetYear < $currentYear) {
      return [
        'label' => (string) $targetYear . ' (full year)',
        'fraction' => 1.0,
      ];
    }

    // Fraction based on completed months (January = 1/12 etc.).
    $completedMonths = max(1, (int) date('n') - 1);
    $fraction = $completedMonths / 12;

    // Quarter label for the period elapsed.
    if ($completedMonths <= 3) {
      $periodLabel = 'Q1 YTD';
    }
    elseif ($completedMonths <= 6) {
      $periodLabel = 'Q1–Q2 YTD';
    }
    elseif ($completedMonths <= 9) {
      $periodLabel = 'Q1–Q3 YTD';
    }
    else {
      $periodLabel = 'YTD';
    }

    return [
      'label' => $periodLabel,
      'fraction' => $fraction,
    ];
  }

  /**
   * Calculates the average monthly expense from the Income-Statement sheet.
   */
  private function calculateAverageMonthlyExpense(): ?float {
    $table = $this->getIncomeStatementTable();
    if (!$table) {
      return NULL;
    }

    $headers = $table['headers'] ?? [];
    $targetRow = $this->getIncomeStatementRowValuesByMetricKey('expense_total', ['Total Expense']);
    if ($targetRow === NULL) {
      return NULL;
    }

    $dateColumns = $this->getDateColumnIndexes($headers, 2);
    if (!$dateColumns) {
      return NULL;
    }

    $expensesByIndex = [];
    foreach (array_keys($dateColumns) as $i) {
      $rawValue = $targetRow[$i] ?? '';
      $numericValue = $this->normalizeSheetNumber($rawValue);
      if ($numericValue === NULL) {
        continue;
      }
      $expensesByIndex[$i] = abs($numericValue);
    }

    if (!$expensesByIndex) {
      return NULL;
    }

    ksort($expensesByIndex);
    $latestQuarters = array_slice($expensesByIndex, -4, NULL, TRUE);
    if (!$latestQuarters) {
      return NULL;
    }

    $quarterCount = count($latestQuarters);
    $totalExpense = array_sum($latestQuarters);
    $months = $quarterCount * 3;

    if ($months <= 0) {
      return NULL;
    }

    return $totalExpense / $months;
  }

  /**
   * Loads and caches the Income-Statement tab.
   */
  private function getIncomeStatementTable(): array {
    if ($this->incomeStatementTable !== NULL) {
      return $this->incomeStatementTable;
    }

    $sheetData = $this->googleSheetClientService->getSheetData('Income-Statement');
    if (empty($sheetData) || count($sheetData) < 2) {
      $this->incomeStatementTable = [];
      return $this->incomeStatementTable;
    }

    $headers = array_map('trim', array_shift($sheetData));
    $accountLabelIndex = array_search('account', array_map('strtolower', $headers), TRUE);
    if ($accountLabelIndex === FALSE) {
      $accountLabelIndex = 0;
    }
    $metricKeyIndex = $this->locateColumnIndex($headers, ['metric_key']);

    $rows = [];
    $rowsByMetricKey = [];
    foreach ($sheetData as $row) {
      $label = isset($row[$accountLabelIndex]) ? trim((string) $row[$accountLabelIndex]) : '';
      if ($label === '' && $accountLabelIndex !== 0) {
        $label = isset($row[0]) ? trim((string) $row[0]) : '';
      }
      if ($label === '') {
        continue;
      }
      $rows[$this->normalizeSheetLabel($label)] = $row;

      if ($metricKeyIndex !== NULL) {
        $metricKey = isset($row[$metricKeyIndex]) ? trim((string) $row[$metricKeyIndex]) : '';
        if ($metricKey !== '') {
          $rowsByMetricKey[$this->normalizeSheetLabel($metricKey)] = $row;
        }
      }
    }

    $this->incomeStatementTable = [
      'headers' => $headers,
      'rows' => $rows,
      'rows_by_metric_key' => $rowsByMetricKey,
      'metric_key_index' => $metricKeyIndex,
    ];
    return $this->incomeStatementTable;
  }

  /**
   * Returns the Income-Statement headers.
   */
  private function getIncomeStatementHeaders(): array {
    $table = $this->getIncomeStatementTable();
    return $table['headers'] ?? [];
  }

  /**
   * Returns a row from the Income-Statement tab.
   */
  private function getIncomeStatementRowValues(string $label, array $aliases = []): ?array {
    $table = $this->getIncomeStatementTable();
    if (!$table || empty($table['rows'])) {
      return NULL;
    }

    $candidates = array_merge([$label], $aliases);
    foreach ($candidates as $candidate) {
      $key = $this->normalizeSheetLabel($candidate);
      if ($key === '') {
        continue;
      }
      if (isset($table['rows'][$key])) {
        return $table['rows'][$key];
      }
    }

    foreach ($candidates as $candidate) {
      $key = $this->normalizeSheetLabel($candidate);
      if ($key === '') {
        continue;
      }
      foreach ($table['rows'] as $rowKey => $row) {
        if (str_contains($rowKey, $key)) {
          return $row;
        }
      }
    }

    return NULL;
  }

  /**
   * Returns an Income-Statement row by metric key with label fallback.
   */
  private function getIncomeStatementRowValuesByMetricKey(string $metricKey, array $labelFallbacks = []): ?array {
    $table = $this->getIncomeStatementTable();
    if (!$table) {
      return NULL;
    }

    $normalizedMetricKey = $this->normalizeSheetLabel($metricKey);
    if (
      $normalizedMetricKey !== ''
      && !empty($table['rows_by_metric_key'])
      && isset($table['rows_by_metric_key'][$normalizedMetricKey])
    ) {
      return $table['rows_by_metric_key'][$normalizedMetricKey];
    }

    if (!$labelFallbacks) {
      return NULL;
    }

    $primary = array_shift($labelFallbacks);
    return $this->getIncomeStatementRowValues((string) $primary, $labelFallbacks);
  }

  /**
   * Attempts to find an Income-Statement row by keyword fragments.
   */
  private function findIncomeStatementRowByKeywords(array $keywords, array $exclusions = []): ?array {
    $table = $this->getIncomeStatementTable();
    if (!$table || empty($table['rows'])) {
      return NULL;
    }

    $normalizedKeywords = array_values(array_filter(array_map([$this, 'normalizeSheetLabel'], $keywords)));
    if (!$normalizedKeywords) {
      return NULL;
    }
    $normalizedExclusions = array_filter(array_map([$this, 'normalizeSheetLabel'], $exclusions));

    foreach ($table['rows'] as $rowKey => $row) {
      $match = TRUE;
      foreach ($normalizedKeywords as $keyword) {
        if (!str_contains($rowKey, $keyword)) {
          $match = FALSE;
          break;
        }
      }
      if (!$match) {
        continue;
      }

      $excluded = FALSE;
      foreach ($normalizedExclusions as $exclusion) {
        if ($exclusion !== '' && str_contains($rowKey, $exclusion)) {
          $excluded = TRUE;
          break;
        }
      }
      if ($excluded) {
        continue;
      }

      return $row;
    }

    return NULL;
  }

  /**
   * Normalizes Income-Statement row labels for lookup.
   */
  private function normalizeSheetLabel(string $label): string {
    $trimmed = strtolower(trim($label));
    if ($trimmed === '') {
      return '';
    }
    return preg_replace('/[^a-z0-9]+/', '', $trimmed);
  }

  /**
   * Extracts the latest numeric value from an Income-Statement row.
   */
  private function extractLatestSheetValue(array $row, array $headers): ?array {
    $dateColumns = $this->getDateColumnIndexes($headers, 2);
    if (!$dateColumns) {
      return NULL;
    }

    $latest = NULL;
    foreach ($dateColumns as $i => $parsedDate) {
      $rawValue = $row[$i] ?? '';
      $numericValue = $this->normalizeSheetNumber($rawValue);
      if ($numericValue === NULL) {
        continue;
      }

      $headerLabel = $headers[$i] ?? NULL;
      $columnLabel = NULL;
      if ($parsedDate) {
        $columnLabel = $parsedDate->format('Y-m-d');
      }
      elseif (is_string($headerLabel) && trim($headerLabel) !== '') {
        $columnLabel = trim($headerLabel);
      }

      $candidate = [
        'value' => (float) $numericValue,
        'column_index' => $i,
        'column_label' => $columnLabel,
        'column_timestamp' => $parsedDate ? $parsedDate->getTimestamp() : NULL,
      ];

      if ($latest === NULL) {
        $latest = $candidate;
        continue;
      }

      $latestTs = $latest['column_timestamp'];
      $candidateTs = $candidate['column_timestamp'];

      if ($candidateTs !== NULL && ($latestTs === NULL || $candidateTs > $latestTs)) {
        $latest = $candidate;
        continue;
      }

      if ($candidateTs === NULL && $latestTs === NULL && $candidate['column_index'] < $latest['column_index']) {
        $latest = $candidate;
      }
    }

    if ($latest === NULL) {
      return NULL;
    }

    unset($latest['column_timestamp']);
    return $latest;
  }

  /**
   * Returns a numeric value from a specific Income-Statement column.
   */
  private function getSheetValueAtColumn(array $row, int $columnIndex): ?float {
    if ($columnIndex < 0) {
      return NULL;
    }
    $rawValue = $row[$columnIndex] ?? '';
    return $this->normalizeSheetNumber($rawValue);
  }

  /**
   * Attempts to parse a numeric value from a Google Sheet cell.
   *
   * @param mixed $value
   *   The raw cell value.
   *
   * @return float|null
   *   A numeric representation, or NULL when parsing fails.
   */
  private function normalizeSheetNumber($value): ?float {
    if (is_numeric($value)) {
      return (float) $value;
    }
    if (!is_string($value)) {
      return NULL;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
      return NULL;
    }

    // Detect a trailing % before stripping non-numeric characters.
    // Sheet goals for percent KPIs are stored as e.g. "26%" meaning 26%;
    // KPI values are decimal fractions (0.26), so divide by 100.
    $isPercent = str_ends_with($trimmed, '%');

    $negative = FALSE;
    if (str_starts_with($trimmed, '(') && str_ends_with($trimmed, ')')) {
      $negative = TRUE;
      $trimmed = substr($trimmed, 1, -1);
    }

    $cleaned = preg_replace('/[^\d\.\-]/', '', $trimmed);
    if ($cleaned === '' || !is_numeric($cleaned)) {
      return NULL;
    }

    $number = (float) $cleaned;
    if ($isPercent) {
      $number = $number / 100;
    }
    return $negative ? $number * -1 : $number;
  }

  /**
   * Attempts to parse a date from a Google Sheet header value.
   *
   * @param mixed $value
   *   The raw header value.
   *
   * @return \DateTimeImmutable|null
   *   A DateTime object when parsing succeeds, otherwise NULL.
   */
  private function parseSheetDate($value): ?\DateTimeImmutable {
    if (is_numeric($value)) {
      $value = (string) $value;
    }
    if (!is_string($value)) {
      return NULL;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
      return NULL;
    }

    $normalized = trim($trimmed, "'\"");
    if ($normalized === '') {
      return NULL;
    }
    $normalized = preg_replace('/\s+/', ' ', $normalized);

    if (preg_match('/^\d{8}$/', $normalized)) {
      $year = (int) substr($normalized, 0, 4);
      $month = (int) substr($normalized, 4, 2);
      $day = (int) substr($normalized, 6, 2);
      return $this->createDateFromParts($year, $month, $day);
    }

    if (preg_match('/^\d{6}$/', $normalized)) {
      $year = (int) substr($normalized, 0, 4);
      $month = (int) substr($normalized, 4, 2);
      return $this->createMonthEndDate($year, $month);
    }

    if (preg_match('/^\d{4}$/', $normalized)) {
      $year = (int) $normalized;
      return $this->createMonthEndDate($year, 12);
    }

    if ($monthYearDate = $this->parseMonthYearLabel($normalized)) {
      return $monthYearDate;
    }

    if (is_numeric($normalized)) {
      $serial = (float) $normalized;
      if ($serial > 20000) {
        if ($excelDate = $this->convertExcelSerialToDate($serial)) {
          return $excelDate;
        }
      }
    }

    try {
      return new \DateTimeImmutable($normalized);
    }
    catch (\Exception $e) {
      return $this->parseQuarterLabel($normalized);
    }
  }

  /**
   * Converts Excel serial date numbers into DateTime objects.
   */
  private function convertExcelSerialToDate(float $serial): ?\DateTimeImmutable {
    if ($serial <= 0) {
      return NULL;
    }

    $days = (int) floor($serial);
    $seconds = (int) round(($serial - $days) * 86400);

    try {
      $date = (new \DateTimeImmutable('1899-12-30'))->modify(sprintf('+%d days', $days));
      if ($seconds) {
        $date = $date->modify(sprintf('+%d seconds', $seconds));
      }
      return $date;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Creates a date from discrete components.
   */
  private function createDateFromParts(int $year, int $month, int $day): ?\DateTimeImmutable {
    if ($month < 1 || $month > 12) {
      return NULL;
    }
    if ($day < 1 || $day > 31) {
      return NULL;
    }
    try {
      return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Builds a month-end date for the supplied year/month combination.
   */
  private function createMonthEndDate(int $year, int $month): ?\DateTimeImmutable {
    if ($month < 1 || $month > 12) {
      return NULL;
    }

    try {
      $firstOfMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    }
    catch (\Exception $e) {
      return NULL;
    }
    $lastDay = (int) $firstOfMonth->format('t');
    return $this->createDateFromParts($year, $month, $lastDay);
  }

  /**
   * Parses month-year labels such as "Jan-23" or "January 2024".
   */
  private function parseMonthYearLabel(string $value): ?\DateTimeImmutable {
    if (!preg_match('/^([A-Za-z]+)[\\s\\-\\/]+(\\d{2,4})$/', $value, $matches)) {
      return NULL;
    }

    $month = $this->monthNameToNumber($matches[1]);
    if ($month === NULL) {
      return NULL;
    }

    $year = (int) $matches[2];
    if ($year < 100) {
      $year += ($year >= 70) ? 1900 : 2000;
    }

    return $this->createMonthEndDate($year, $month);
  }

  /**
   * Parses a quarter label (e.g., "Jan-Mar 2023") into a DateTimeImmutable.
   */
  private function parseQuarterLabel(string $label): ?\DateTimeImmutable {
    if (!preg_match('/^([A-Za-z]+)\s*-\s*([A-Za-z]+)\s+(\d{4})$/', $label, $matches)) {
      return NULL;
    }

    $endMonth = $this->monthNameToNumber($matches[2]);
    if ($endMonth === NULL) {
      return NULL;
    }

    $year = (int) $matches[3];
    $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))->format('t');

    try {
      return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $endMonth, $lastDay));
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Maps a month name or abbreviation to its numeric representation.
   */
  private function monthNameToNumber(string $name): ?int {
    $map = [
      'jan' => 1,
      'january' => 1,
      'feb' => 2,
      'february' => 2,
      'mar' => 3,
      'march' => 3,
      'apr' => 4,
      'april' => 4,
      'may' => 5,
      'jun' => 6,
      'june' => 6,
      'jul' => 7,
      'july' => 7,
      'aug' => 8,
      'august' => 8,
      'sep' => 9,
      'sept' => 9,
      'september' => 9,
      'oct' => 10,
      'october' => 10,
      'nov' => 11,
      'november' => 11,
      'dec' => 12,
      'december' => 12,
    ];

    $normalized = strtolower(trim($name));
    return $map[$normalized] ?? NULL;
  }

  /**
   * Returns KPI goal overrides sourced from Google Sheets.
   */
  private function getSheetGoalOverrides(): array {
    $this->computeSheetGoalData();
    return $this->sheetGoalCache ?? [];
  }

  /**
   * Returns sheet-defined annual KPI targets keyed by KPI ID.
   */
  private function getSheetAnnualTargets(): array {
    $this->computeSheetGoalData();
    return $this->sheetAnnualTargets ?? [];
  }

  /**
   * Loads and caches goal overrides and annual targets from Google Sheets.
   */
  private function computeSheetGoalData(): void {
    if ($this->sheetGoalCache !== NULL && $this->sheetAnnualTargets !== NULL) {
      return;
    }

    $goalOverrides = [];
    $annualTargets = [];

    foreach (['Goals-Percent', 'Goals-Count'] as $tabName) {
      $rows = $this->googleSheetClientService->getSheetData($tabName);
      if (empty($rows) || count($rows) < 2) {
        continue;
      }
      $parsed = $this->parseGoalSheet($rows);
      if (!empty($parsed['goals'])) {
        $goalOverrides = array_merge($goalOverrides, $parsed['goals']);
      }
      if (!empty($parsed['annual'])) {
        foreach ($parsed['annual'] as $kpiId => $yearValues) {
          if (!isset($annualTargets[$kpiId])) {
            $annualTargets[$kpiId] = [];
          }
          // Use union operator to preserve numeric year keys.
          $annualTargets[$kpiId] = $annualTargets[$kpiId] + $yearValues;
        }
      }
    }

    $this->sheetGoalCache = $goalOverrides;
    $this->sheetAnnualTargets = $annualTargets;
  }

  /**
   * Parses a goal sheet into KPI goal overrides.
   *
   * @param array $rows
   *   Raw sheet rows.
   *
   * @return array
   *   Associative array of KPI ID => goal value.
   */
  private function parseGoalSheet(array $rows): array {
    $header = array_map('trim', array_shift($rows));
    if (!$header) {
      return ['goals' => [], 'annual' => []];
    }
    $goalIdIndex = $this->locateColumnIndex($header, ['goal_id', 'kpi_id']);
    if ($goalIdIndex === NULL) {
      return ['goals' => [], 'annual' => []];
    }

    $yearColumns = [];
    foreach ($header as $index => $value) {
      if ($index === $goalIdIndex) {
        continue;
      }
      $normalized = preg_replace('/[^0-9]/', '', (string) $value);
      if (strlen($normalized) === 4 && ctype_digit($normalized)) {
        $year = (int) $normalized;
        $yearColumns[$index] = $year;
      }
    }

    if (!$yearColumns) {
      return ['goals' => [], 'annual' => []];
    }

    $goals = [];
    $annual = [];
    $currentYear = (int) date('Y');

    foreach ($rows as $row) {
      $goalId = isset($row[$goalIdIndex]) ? trim((string) $row[$goalIdIndex]) : '';
      if ($goalId === '' || stripos($goalId, 'kpi_') !== 0) {
        continue;
      }
      $normalizedGoalId = $goalId;
      
      // Track the best candidate for the "Current Goal" (closest to current year or 2030).
      $bestGoalValue = NULL;
      $bestGoalYear = 0;

      foreach ($yearColumns as $index => $year) {
        $valueRaw = $row[$index] ?? '';
        $value = $this->normalizeSheetNumber($valueRaw);
        if ($value === NULL) {
          continue;
        }
        
        // Strategy: 2030 is the ultimate goal, but if missing, use the latest year provided.
        if ($year === 2030) {
          $bestGoalValue = $value;
          $bestGoalYear = 2030;
        }
        elseif ($bestGoalYear !== 2030 && $year >= $bestGoalYear) {
          $bestGoalValue = $value;
          $bestGoalYear = $year;
        }

        $annual[$normalizedGoalId][(string) $year] = $value;
      }

      if ($bestGoalValue !== NULL) {
        $goals[$normalizedGoalId] = $bestGoalValue;
      }
    }

    return [
      'goals' => $goals,
      'annual' => $annual,
    ];
  }

  /**
   * Locates the first matching header column.
   */
  private function locateColumnIndex(array $header, array $candidates): ?int {
    $normalized = array_map(static function ($value) {
      return strtolower(trim((string) $value));
    }, $header);
    foreach ($candidates as $candidate) {
      $candidate = strtolower($candidate);
      $index = array_search($candidate, $normalized, TRUE);
      if ($index !== FALSE) {
        return $index;
      }
    }
    return NULL;
  }

  /**
   * Determines whether a KPI snapshot record represents an annual value.
   */
  private function isAnnualSnapshotRecord(array $record): bool {
    if (!empty($record['snapshot_type'])) {
      $type = strtolower((string) $record['snapshot_type']);
      if (str_contains($type, 'annual')) {
        return TRUE;
      }
    }
    if (!empty($record['meta']) && is_array($record['meta'])) {
      $meta = array_change_key_case($record['meta'], CASE_LOWER);
      if (($meta['snapshot_scope'] ?? '') === 'annual') {
        return TRUE;
      }
    }
    if (!empty($record['period_year']) && empty($record['period_month'])) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Determines the most relevant goal year to display.
   */
  private function determineGoalYear(array $annual, array $sheetAnnual, int $referenceYear): int {
    if (isset($annual[(string) $referenceYear]) || isset($sheetAnnual[(string) $referenceYear])) {
      return $referenceYear;
    }

    $actualYears = array_map('intval', array_keys($annual));
    $sheetYears = array_map('intval', array_keys($sheetAnnual));
    $allYears = array_values(array_unique(array_merge($actualYears, $sheetYears)));
    if (!$allYears) {
      return $referenceYear;
    }

    sort($allYears);

    // 1. Try to find the closest year in the past (including current).
    $pastFallback = NULL;
    foreach ($allYears as $year) {
      if ($year <= $referenceYear) {
        $pastFallback = $year;
      }
    }
    if ($pastFallback !== NULL) {
      return $pastFallback;
    }

    // 2. If no past goals exist, pick the earliest future goal.
    return $allYears[0];
  }

  /**
   * Provides the reserve funds months-of-coverage monthly time series.
   *
   * @return array
   *   An array with keys:
   *   - labels: Month labels.
   *   - values: Numeric month-of-coverage values.
   *   - last_updated: ISO date string or NULL.
   *   - items: Detailed item arrays with keys 'date', 'label', 'months'.
   */
  public function getReserveFundsMonthlySeries(): array {
    $series = $this->buildReserveFundsSeries();
    if (!$series || empty($series['items'])) {
      return [
        'labels' => [],
        'values' => [],
        'last_updated' => NULL,
        'items' => [],
      ];
    }

    $labels = [];
    $values = [];
    foreach ($series['items'] as $item) {
      $labels[] = $item['label'];
      $values[] = $item['months'];
    }

    return [
      'labels' => $labels,
      'values' => $values,
      'last_updated' => $series['last_updated'] ?? NULL,
      'items' => $series['items'],
    ];
  }

  /**
   * Builds the reserve funds time series and derived metrics.
   */
  private function buildReserveFundsSeries(): array {
    $sheetData = $this->googleSheetClientService->getSheetData('Balance-Sheet');
    if (empty($sheetData) || count($sheetData) < 2) {
      return [];
    }

    $headers = array_map('trim', array_shift($sheetData));
    $accountIndex = $this->locateColumnIndex($headers, ['account']);
    if ($accountIndex === NULL) {
      $accountIndex = 0;
    }
    $dateColumns = $this->getDateColumnIndexes($headers, $accountIndex + 1);
    if (!$dateColumns) {
      return [];
    }

    $targetRow = NULL;
    foreach ($sheetData as $row) {
      $label = isset($row[$accountIndex]) ? trim((string) $row[$accountIndex]) : '';
      if ($label === '' && $accountIndex !== 0) {
        $label = isset($row[0]) ? trim((string) $row[0]) : '';
      }
      if ($label !== '' && strcasecmp($label, 'Cash and Cash Equivalents') === 0) {
        $targetRow = $row;
        break;
      }
    }

    if ($targetRow === NULL) {
      return [];
    }

    $entries = [];
    foreach ($dateColumns as $i => $date) {
      $rawValue = $targetRow[$i] ?? '';
      $numericValue = $this->normalizeSheetNumber($rawValue);
      if ($numericValue === NULL) {
        continue;
      }
      $monthKey = $date->format('Y-m');
      $entries[$monthKey] = [
        'index' => $i,
        'date' => $date,
        'cash' => $numericValue,
        'label' => $date->format('M Y'),
      ];
    }

    if (!$entries) {
      return [];
    }

    $averageMonthlyExpense = $this->calculateAverageMonthlyExpense();
    if ($averageMonthlyExpense === NULL || $averageMonthlyExpense <= 0) {
      return [];
    }

    ksort($entries, SORT_STRING);

    // Exclude future or in-progress months so historical charts remain stable.
    $now = new \DateTimeImmutable('first day of this month');
    $entries = array_filter($entries, static function (array $entry) use ($now): bool {
      return isset($entry['date']) && $entry['date'] instanceof \DateTimeImmutable && $entry['date'] < $now;
    });
    if (!$entries) {
      return [];
    }

    $items = [];
    $monthsValues = [];
    foreach ($entries as $monthKey => $entry) {
      $months = $averageMonthlyExpense > 0 ? ($entry['cash'] / $averageMonthlyExpense) : 0.0;
      $date = $entry['date'];
      $label = $entry['label'];
      $items[] = [
        'index' => $entry['index'],
        'date' => $date,
        'month_key' => $monthKey,
        'label' => $label,
        'months' => $months,
      ];
      $monthsValues[] = $months;
    }

    if (!$items) {
      return [];
    }

    $trend = array_slice($monthsValues, -12);
    $ttm12 = $this->calculateTrailingAverage($monthsValues, 12);
    $ttm3 = $this->calculateTrailingAverage($monthsValues, 3);
    $current = end($monthsValues);
    if ($current === FALSE) {
      $current = NULL;
    }

    $lastItem = end($items);
    $lastDate = $lastItem && !empty($lastItem['date']) && $lastItem['date'] instanceof \DateTimeImmutable
      ? $lastItem['date']
      : NULL;
    $lastLabel = $lastItem['label'] ?? NULL;
    $lastUpdated = $lastDate
      ? $lastDate->format('Y-m-d')
      : ($lastLabel !== '' ? $lastLabel : NULL);
    reset($items);

    return [
      'items' => $items,
      'trend' => $trend,
      'ttm12' => $ttm12,
      'ttm3' => $ttm3,
      'current' => $current,
      'annual' => [],
      'last_updated' => $lastUpdated,
    ];
  }

  /**
   * Finds date-like columns in a tab header and returns index => parsed date.
   */
  private function getDateColumnIndexes(array $headers, int $startIndex = 0): array {
    $dateColumns = [];
    foreach ($headers as $index => $headerValue) {
      if ($index < $startIndex) {
        continue;
      }
      $date = $this->parseSheetDate($headerValue);
      if ($date) {
        $dateColumns[(int) $index] = $date;
      }
    }
    return $dateColumns;
  }

  /**
   * Calculates the whole-month difference between two dates.
   */
  private function diffInMonths(\DateTimeImmutable $from, \DateTimeImmutable $to): int {
    $years = (int) $to->format('Y') - (int) $from->format('Y');
    $months = (int) $to->format('n') - (int) $from->format('n');
    return ($years * 12) + $months;
  }

  /**
   * Static KPI metadata leveraged to document calculations.
   *
   * @param string $section_id
   *   The ID of the section.
   *
   * @return array
   *   Metadata keyed by KPI machine name.
   */
  private function getStaticKpiMetadata(?string $section_id = NULL): array {
    $config = [
      'overview' => [
        'kpi_total_active_members' => [
          'label' => 'Total # Active Members',
          'base_2025' => 1000,
          'goal_2030' => 1500,
          'description' => 'The count of all members currently in active status across all membership categories.',
          'source_note' => 'System: active members from ms_fact_org_snapshot (December monthly snapshot used for annual totals).',
        ],
        'kpi_workshop_attendees' => [
          'label' => '# of Workshop Attendees',
          'base_2025' => 1200,
          'goal_2030' => 2000,
          'description' => 'The total number of registrations for ticketed workshops held during the period.',
          'source_note' => 'CiviCRM: Sum of monthly registrations for event type "Ticketed Workshop".',
        ],
        'reserve_funds_months' => [
          'label' => 'Reserve Funds (as Months of Operating Expense)',
          'base_2025' => 3,
          'goal_2030' => 6,
          'description' => 'Organization cash reserves measured by how many months of average operating expenses they could cover.',
          'source_note' => 'Finance: (Cash Equivalents from Balance Sheet) / (Trailing 12-month average monthly expense).',
        ],
        'kpi_board_governance' => [
          'label' => 'Board Governance KPI',
          'base_2025' => 0.80,
          'goal_2030' => 0.90,
          'description' => 'Composite score of Board performance across 7 key governance categories.',
          'source_note' => 'Survey: Annual Board self-assessment (% of responses scoring 4 or 5).',
        ],
        'kpi_committee_effectiveness' => [
          'label' => 'Committee Effectiveness KPI',
          'base_2025' => 0.80,
          'goal_2030' => 0.90,
          'description' => 'Composite score of effectiveness across 5 key categories for all active committees.',
          'source_note' => 'Survey: Annual Committee member self-assessment (% of responses scoring 4 or 5).',
        ],
        'kpi_entrepreneurship_joins_rate' => [
          'label' => 'Entrepreneurship Joins Focus (%)',
          'base_2025' => 0.25,
          'goal_2030' => 0.35,
          'description' => 'Percentage of new members joining with existing entrepreneurial experience or specific goals to create/sell products.',
          'source_note' => 'Profile: % of new members selecting inventor, entrepreneur, or seller goals.',
        ],
        'kpi_entrepreneurship_retention' => [
          'label' => 'Entrepreneurship Retention %',
          'base_2025' => 0.60,
          'goal_2030' => 0.75,
          'description' => 'The 12-month survival rate for members who joined with entrepreneurial goals or experience.',
          'source_note' => 'Profile: 12-month retention tracked for the entrepreneurial member segment.',
        ],
      ],
      'governance' => [
        'kpi_board_ethnic_diversity' => [
          'label' => 'Board Ethnic Diversity (% BIPOC)',
          'base_2025' => 0.20,
          'goal_2030' => 0.50,
          'description' => 'Percentage of the Board of Directors identifying as Black, Indigenous, or People of Color.',
          'source_note' => 'CiviCRM: Live demographics from the Current Board Members group.',
        ],
        'kpi_board_gender_diversity' => [
          'label' => 'Board Gender Diversity (% Female/Non-binary)',
          'base_2025' => 0.40,
          'goal_2030' => 0.50,
          'description' => 'Percentage of the Board of Directors identifying as Female or Non-binary.',
          'source_note' => 'CiviCRM: Live demographics from the Current Board Members group.',
        ],
        'kpi_board_governance' => [
          'label' => 'Board Governance KPI',
          'base_2025' => 0.80,
          'goal_2030' => 0.90,
          'description' => 'Composite score of Board performance across 7 key governance categories.',
          'source_note' => 'Survey: Annual Board self-assessment (% of responses scoring 4 or 5).',
        ],
        'kpi_committee_effectiveness' => [
          'label' => 'Committee Effectiveness KPI',
          'base_2025' => 0.80,
          'goal_2030' => 0.90,
          'description' => 'Composite score of effectiveness across 5 key categories for all active committees.',
          'source_note' => 'Survey: Annual Committee member self-assessment (% of responses scoring 4 or 5).',
        ],
      ],
      'finance' => [
        'kpi_reserve_funds_months' => [
          'label' => 'Reserve Funds (as Months of Operating Expense)',
          'base_2025' => 3,
          'goal_2030' => 6,
          'description' => 'Organization cash reserves measured by how many months of average operating expenses they could cover.',
          'source_note' => 'Finance: (Cash Equivalents from Balance Sheet) / (Trailing 12-month average monthly expense).',
        ],
        'kpi_earned_income_sustaining_core' => [
          'label' => 'Earned Income Sustaining Core %',
          'base_2025' => 0.80,
          'goal_2030' => 1.00,
          'description' => 'Measure of how much our core recurring revenue (dues, etc.) covers our core operating expenses.',
          'source_note' => 'Finance: (Earned Income - Grants/Donations) / (Total Expense - Program/Capital Investment).',
        ],
        'kpi_member_revenue_quarterly' => [
          'label' => 'Member Revenue (Quarterly)',
          'base_2025' => 100000,
          'goal_2030' => 150000,
          'description' => 'Total revenue received from individual recurring membership dues.',
          'source_note' => 'Xero: Quarterly sum of Membership - Individual Recurring account.',
        ],
        'kpi_member_lifetime_value_projected' => [
          'label' => 'Member Lifetime Value (Projected)',
          'base_2025' => 1752,
          'goal_2030' => 3000,
          'description' => 'Projected total revenue a new member will contribute over their entire tenure, based on current retention rates.',
          'source_note' => 'Model: (Avg Monthly Dues) * (Projected Tenure in Months) for new joiners.',
        ],
        'kpi_revenue_per_member_index' => [
          'label' => 'Revenue vs Expense Index (per member)',
          'base_2025' => 0.62,
          'goal_2030' => 1.0,
          'description' => 'Ratio showing how close individual member dues are to covering the total cost per member of operating the space.',
          'source_note' => 'Finance: (Avg Monthly Dues per Head) / (Avg Monthly Operating Expense per Head).',
        ],
        'kpi_monthly_revenue_at_risk' => [
          'label' => 'Monthly Revenue at Risk ($)',
          'base_2025' => 0,
          'goal_2030' => 0,
          'goal_direction' => 'lower',
          'status' => 'draft',
          'description' => 'Sum of monthly dues for active members currently experiencing failed payments.',
          'source_note' => 'System: Active members with failed payment flags on their profile.',
        ],
        'kpi_payment_resolution_rate' => [
          'label' => 'Payment Resolution Rate %',
          'base_2025' => 0.50,
          'goal_2030' => 0.85,
          'status' => 'draft',
          'description' => 'Percentage of members with payment issues who successfully update their payment and remain active.',
          'source_note' => 'CRM: Count of payment outreach logs with successful recovery outcomes.',
        ],
      ],
      'infrastructure' => [
        'kpi_equipment_uptime_rate' => [
          'label' => 'Equipment Uptime Rate %',
          'base_2025' => 0.90,
          'goal_2030' => 0.98,
          'description' => 'Percentage of primary workshop equipment available for use during operating hours.',
          'source_note' => 'Shop: (Operational Tools) / (Operational + Down Tools) from live inventory status.',
        ],
        'kpi_active_maintenance_load' => [
          'label' => 'Active Maintenance Load',
          'base_2025' => 50,
          'goal_2030' => 20,
          'goal_direction' => 'lower',
          'status' => 'draft',
          'description' => 'The current number of open, uncompleted maintenance tasks and tool repair requests.',
          'source_note' => 'System: Count of published "task" nodes that are not flagged as completed.',
        ],
        'kpi_storage_occupancy' => [
          'label' => 'Storage Occupancy %',
          'base_2025' => 0.85,
          'goal_2030' => 0.95,
          'status' => 'draft',
          'description' => 'Percentage of member storage units (lockers, shelves, etc.) currently occupied.',
          'source_note' => 'System: Occupancy rate calculated by the storage manager service.',
        ],
        'kpi_equipment_investment' => [
          'label' => 'Value of Equipment Added ($)',
          'base_2025' => 20000,
          'goal_2030' => 50000,
          'description' => 'Total replacement value of new tools, assets, and library items added to the space.',
          'source_note' => 'System: Sum of replacement values for new "item" and "library_item" nodes.',
        ],
        'kpi_adherence_to_shop_budget' => [
          'label' => 'Adherence to Shop Budget',
          'base_2025' => 0.92,
          'goal_2030' => 1.0,
          'goal_direction' => 'lower',
          'status' => 'draft',
          'description' => 'Comparison of actual shop operating expenses against the approved organizational budget.',
          'source_note' => 'Finance: (Actual Shop Operations Expense) / (Budgeted Shop Operations).',
        ],
      ],
      'outreach' => [
        'kpi_total_new_member_signups' => [
          'label' => 'Total New Member Signups',
          'base_2025' => 300,
          'goal_2030' => 500,
          'description' => 'The total number of unique individuals who created a new membership profile during the period.',
          'source_note' => 'System: Count of new profiles with the "member" role.',
        ],
        'kpi_total_first_time_workshop_participants' => [
          'label' => 'Total # First Time Workshop Participants',
          'base_2025' => 400,
          'goal_2030' => 600,
          'description' => 'The count of individuals whose first-ever interaction with MakeHaven was attending a workshop this year.',
          'source_note' => 'CiviCRM: Count of event participants with no prior registration history.',
        ],
        'kpi_total_new_recurring_revenue' => [
          'label' => 'New Recurring Membership Revenue (Monthly)',
          'base_2025' => 25000,
          'goal_2030' => 35000,
          'description' => 'The total monthly recurring dues added by new members joining during the period. (Note: $2,000/mo added equals $24,000 in annual budget impact).',
          'source_note' => 'Model: Sum of starting monthly dues for all new member joins.',
        ],
        'kpi_tours' => [
          'label' => 'Total Tours (12 month)',
          'base_2025' => 400,
          'goal_2030' => 1000,
          'description' => 'Count of unique individuals who participated in a facility tour.',
          'source_note' => 'CiviCRM: Unique contacts with a Tour registration in the last 12 months.',
        ],
        'kpi_tours_to_member_conversion' => [
          'label' => 'Tours to Member Conversion %',
          'base_2025' => 0.20,
          'goal_2030' => 0.35,
          'description' => 'Percentage of tour participants who subsequently signed up for a membership.',
          'source_note' => 'CRM: (Tour participants who became members) / (Total unique tour participants).',
        ],
        'kpi_event_participant_to_member_conversion' => [
          'label' => 'Event Participant to Member Conversion %',
          'base_2025' => 0.10,
          'goal_2030' => 0.20,
          'description' => 'Percentage of workshop or event participants who subsequently signed up for a membership.',
          'source_note' => 'CRM: (Event participants who became members) / (Total unique event participants).',
        ],
        'guest_waivers_signed' => [
          'label' => 'Guest Waivers Signed (12 month)',
          'base_2025' => 200,
          'goal_2030' => 450,
          'description' => 'Total number of non-members who signed a guest waiver to visit the space.',
          'source_note' => 'CRM: Count of unique contacts with Guest Waiver activity.',
        ],
        'kpi_guest_waiver_to_member_conversion' => [
          'label' => 'Guest Waiver to Member Conversion %',
          'base_2025' => 0.20,
          'goal_2030' => 0.35,
          'description' => 'Percentage of guests who subsequently signed up for a membership.',
          'source_note' => 'CRM: (Guests who became members) / (Total unique guests).',
        ],
        'discovery_meetings_logged' => [
          'label' => 'Discovery Meetings Logged (12 month)',
          'base_2025' => 50,
          'goal_2030' => 180,
          'description' => 'Count of introductory discovery or recruitment meetings held with potential members/partners.',
          'source_note' => 'CRM: Count of unique contacts with Meeting activity.',
        ],
        'meeting_to_member_conversion' => [
          'label' => 'Meeting to Member Conversion %',
          'base_2025' => 0.20,
          'goal_2030' => 0.40,
          'description' => 'Percentage of discovery meeting participants who subsequently signed up for a membership.',
          'source_note' => 'CRM: (Meeting participants who became members) / (Total unique meeting participants).',
        ],
      ],
      'retention' => [
        'kpi_total_active_members' => [
          'label' => 'Total # Active Members',
          'base_2025' => 1000,
          'goal_2030' => 1500,
          'description' => 'The count of all members currently in active status across all membership categories.',
          'source_note' => 'System: active members from ms_fact_org_snapshot (December monthly snapshot used for annual totals).',
        ],
        'kpi_first_year_member_retention' => [
          'label' => 'First Year Member Retention %',
          'base_2025' => 0.70,
          'goal_2030' => 0.85,
          'description' => 'Percentage of new members who remain active for at least 12 months after joining.',
          'source_note' => 'System: 12-month survival rate for the previous year\'s join cohort (excludes unpreventable ends).',
        ],
        'kpi_member_post_12_month_retention' => [
          'label' => 'Member (Post-12mo) Retention %',
          'base_2025' => 0.85,
          'goal_2030' => 0.92,
          'description' => 'Retention from month 12 to month 24 among members who made it through their first year.',
          'source_note' => 'System: Cohort month-24 retention divided by month-12 retention.',
        ],
        'kpi_retention_poc' => [
          'label' => 'Retention POC %',
          'base_2025' => 0.45,
          'goal_2030' => 0.80,
          'description' => 'The 12-month survival rate specifically for members identifying as Black, Indigenous, or People of Color.',
          'source_note' => 'System: 12-month survival rate filtered by BIPOC ethnicity.',
        ],
        'kpi_member_nps' => [
          'label' => 'Member Net Promoter Score (NPS)',
          'base_2025' => 50,
          'goal_2030' => 75,
          'description' => 'Overall member satisfaction and likelihood to recommend MakeHaven to others.',
          'source_note' => 'Survey: Annual member survey (Promoters % - Detractors %).',
        ],
        'kpi_active_participation' => [
          'label' => 'Active Participation %',
          'base_2025' => 0.60,
          'goal_2030' => 0.80,
          'description' => 'Percentage of active members who have utilized the space (recorded a card read) in the last quarter.',
          'source_note' => 'System: Unique member UIDs with door access logs divided by total active members.',
        ],
        'kpi_active_participation_bipoc' => [
          'label' => 'Active Participation % (BIPOC)',
          'base_2025' => 0.60,
          'goal_2030' => 0.80,
          'description' => 'Percentage of active BIPOC members who have utilized the space in the last quarter.',
          'source_note' => 'System: Unique BIPOC UIDs with door access logs divided by total active BIPOC members.',
        ],
        'kpi_active_participation_female_nb' => [
          'label' => 'Active Participation % (Female/Non-binary)',
          'base_2025' => 0.60,
          'goal_2030' => 0.80,
          'description' => 'Percentage of active Female and Non-binary members who have utilized the space in the last quarter.',
          'source_note' => 'System: Unique Female/NB UIDs with door access logs divided by total active members in that segment.',
        ],
        'kpi_new_member_first_badge_28_days' => [
          'label' => 'New Member First Badge (28 days) %',
          'base_2025' => 0.55,
          'goal_2030' => 0.80,
          'description' => 'Share of new members who successfully complete their first badge/orientation within 28 days of joining.',
          'source_note' => 'System: New members with badge_count >= 1 within their first month.',
        ],
        'kpi_members_at_risk_share' => [
          'label' => 'Members At-Risk %',
          'base_2025' => 0.40,
          'goal_2030' => 0.15,
          'goal_direction' => 'lower',
          'status' => 'draft',
          'description' => 'Share of members whose recent inactivity indicates they are at higher risk of cancelling.',
          'source_note' => 'Model: Share of members with risk_score >= 20 based on visit frequency.',
        ],
        'kpi_membership_diversity_bipoc' => [
          'label' => 'Membership Diversity (% BIPOC)',
          'base_2025' => 0.15,
          'goal_2030' => 0.30,
          'description' => 'Percentage of the overall membership identifying as Black, Indigenous, or People of Color.',
          'source_note' => 'Profile: % of members with non-white self-identity submitted.',
        ],
      ],
      'education' => [
        'kpi_workshop_attendees' => [
          'label' => '# of Workshop Attendees',
          'base_2025' => 1200,
          'goal_2030' => 2000,
          'description' => 'The total number of registrations for ticketed workshops held during the period.',
          'source_note' => 'CiviCRM: Sum of monthly registrations for event type "Ticketed Workshop".',
        ],
        'kpi_workshop_capacity_utilization' => [
          'label' => 'Workshop Capacity Utilization %',
          'base_2025' => 0.70,
          'goal_2030' => 0.90,
          'description' => 'Weighted ratio of counted registrations to total available seats for active, ticketed workshops.',
          'source_note' => 'CiviCRM: Counted registrations / max participant capacity for active Ticketed Workshops (capacity > 0).',
        ],
        'kpi_program_capacity_utilization' => [
          'label' => 'Program Capacity Utilization %',
          'base_2025' => 0.70,
          'goal_2030' => 0.90,
          'description' => 'Weighted ratio of counted registrations to total available seats for active program events.',
          'source_note' => 'CiviCRM: Counted registrations / max participant capacity for active Program events (capacity > 0).',
        ],
        'kpi_workshop_program_capacity_utilization' => [
          'label' => 'Workshop + Program Capacity Utilization %',
          'base_2025' => 0.70,
          'goal_2030' => 0.90,
          'description' => 'Combined weighted fill ratio across active workshop and program events.',
          'source_note' => 'CiviCRM: Counted registrations / max participant capacity for active Ticketed Workshop + Program events (capacity > 0).',
        ],
        'kpi_education_nps' => [
          'label' => 'Education Net Promoter Score (NPS)',
          'base_2025' => 60,
          'goal_2030' => 80,
          'description' => 'Student satisfaction score for educational programs and workshops.',
          'source_note' => 'Survey: Post-workshop evaluations (Promoters % - Detractors %).',
        ],
        'kpi_workshop_participants_bipoc' => [
          'label' => '% Workshop Participants (BIPOC)',
          'base_2025' => 0.17,
          'goal_2030' => 0.30,
          'description' => 'Percentage of unique workshop attendees identifying as Black, Indigenous, or People of Color.',
          'source_note' => 'CRM: % of unique participants with non-white self-identity.',
        ],
        'kpi_active_instructors_bipoc' => [
          'label' => '% Active Instructors (BIPOC)',
          'base_2025' => 0.37,
          'goal_2030' => 0.50,
          'description' => 'Percentage of active workshop instructors identifying as Black, Indigenous, or People of Color.',
          'source_note' => 'CRM: % of instructors with non-white self-identity who taught in the period.',
        ],
        'kpi_net_income_education' => [
          'label' => 'Net Income (Education Program)',
          'base_2025' => 38922,
          'goal_2030' => 60000,
          'description' => 'Direct financial contribution of the education program (Revenue - Instructor Pay - Materials).',
          'source_note' => 'Finance: Education Income - Education Expense from Income Statement.',
        ],
      ],
      'entrepreneurship' => [
        'kpi_incubator_workspace_occupancy' => [
          'label' => 'Incubator Workspace Occupancy %',
          'base_2025' => 0.75,
          'goal_2030' => 0.95,
          'description' => 'Percentage of rentable incubator desk and shop floor space currently under contract.',
          'source_note' => 'System: (Occupied SqFt) / (Total Rentable SqFt) for professional workspaces.',
        ],
        'kpi_active_incubator_ventures' => [
          'label' => '# of Active Incubator Ventures',
          'base_2025' => 10,
          'goal_2030' => 20,
          'description' => 'Total count of distinct startup or product ventures utilizing the incubator infrastructure.',
          'source_note' => 'Manual: Tracking of distinct ventures utilizing professional workspace/mentorship.',
        ],
        'kpi_entrepreneurship_event_participation' => [
          'label' => '# of Entrepreneurship Events Participants',
          'base_2025' => 49,
          'goal_2030' => 150,
          'description' => 'Annual unique participants in events focused on prototyping, invention, or business startup skills.',
          'source_note' => 'CRM: Participants in events tagged with Entrepreneurship or Prototyping interests.',
        ],
        'kpi_entrepreneurship_joins_rate' => [
          'label' => 'Entrepreneurship Joins Focus (%)',
          'base_2025' => 0.25,
          'goal_2030' => 0.35,
          'description' => 'Percentage of new members joining with existing entrepreneurial experience or specific goals to create/sell products.',
          'source_note' => 'Profile: % of new members selecting inventor, entrepreneur, or seller goals.',
        ],
        'kpi_entrepreneurship_retention' => [
          'label' => 'Entrepreneurship Retention %',
          'base_2025' => 0.60,
          'goal_2030' => 0.75,
          'description' => 'The 12-month survival rate for members who joined with entrepreneurial goals or experience.',
          'source_note' => 'Profile: 12-month retention tracked for the entrepreneurial member segment.',
        ],
      ],
      'development' => [
        'kpi_recurring_donors_count' => [
          'label' => '# of Recurring Donors',
          'base_2025' => 18,
          'goal_2030' => 50,
          'description' => 'Count of unique individuals with active monthly or annual recurring contribution plans.',
          'source_note' => 'CiviCRM: Donors with "In Progress" recurring contribution records.',
        ],
        'kpi_annual_corporate_sponsorships' => [
          'label' => '$ Annual Corporate Sponsorships',
          'base_2025' => 7916,
          'goal_2030' => 75000,
          'description' => 'Total annual revenue received from corporate donations and organizational sponsorships.',
          'source_note' => 'Finance: Sum of corporate donation accounts from Income Statement.',
        ],
        'kpi_grant_pipeline_count' => [
          'label' => '# of Grants Submitted (YTD)',
          'base_2025' => 9,
          'goal_2030' => 15,
          'description' => 'Count of grant opportunities with submitted documentation recorded during the year-to-date period.',
          'source_note' => 'CiviCRM Funding: Submitted link present; period assignment currently uses due date (submitted timestamp not available).',
        ],
        'kpi_grant_win_ratio' => [
          'label' => 'Grant Win Ratio %',
          'base_2025' => 0.32,
          'goal_2030' => 0.50,
          'description' => 'The percentage of submitted grant applications that resulted in an award.',
          'source_note' => 'CRM: (Won Grants) / (Won + Lost + Abandoned Grants).',
        ],
        'kpi_donor_retention_rate' => [
          'label' => 'Donor Retention Rate %',
          'base_2025' => 0.737,
          'goal_2030' => 0.85,
          'description' => 'Percentage of individual donors from the previous year who contributed again this year.',
          'source_note' => 'CRM: % of donors in current 12mo who also gave in the previous 12mo.',
        ],
        'kpi_donor_upgrades_count' => [
          'label' => '# of Donor Upgrades',
          'base_2025' => 33,
          'goal_2030' => 100,
          'status' => 'draft',
          'description' => 'Count of individual donors who increased their total giving compared to the prior year.',
          'source_note' => 'CRM: Donors whose total 12mo giving > prior 12mo giving.',
        ],
      ],
      'dei' => [
        // Note: Specific DEI retention and participation KPIs have been 
        // moved to the 'retention' section for better side-by-side comparison.
      ],
    ];

    if ($section_id === NULL) {
      return $config;
    }

    return $config[$section_id] ?? [];
  }
}
