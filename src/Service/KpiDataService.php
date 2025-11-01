<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

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
   */
  public function __construct(ConfigFactoryInterface $config_factory, FinancialDataService $financial_data_service, GoogleSheetClientService $google_sheet_client_service, EventsMembershipDataService $events_membership_data_service, DemographicsDataService $demographics_data_service, SnapshotDataService $snapshot_data_service, MembershipMetricsService $membership_metrics_service, UtilizationDataService $utilization_data_service) {
    $this->configFactory = $config_factory;
    $this->financialDataService = $financial_data_service;
    $this->googleSheetClientService = $google_sheet_client_service;
    $this->eventsMembershipDataService = $events_membership_data_service;
    $this->demographicsDataService = $demographics_data_service;
    $this->snapshotDataService = $snapshot_data_service;
    $this->membershipMetricsService = $membership_metrics_service;
    $this->utilizationDataService = $utilization_data_service;
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
    // @todo: This is a temporary workaround to ensure the KPI tables render for
    // UI review. This should be reverted to use the `makerspace_dashboard.kpis.yml`
    // configuration file once the live data is implemented.
    $kpi_config = $this->getHardcodedKpiConfig($section_id);

    if (!$kpi_config) {
      return [];
    }

    $kpi_data = [];
    foreach ($kpi_config as $kpi_id => $kpi_info) {
      $method_name = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $kpi_id))) . 'Data';
      if (method_exists($this, $method_name)) {
        $kpi_data[$kpi_id] = $this->{$method_name}($kpi_info);
      }
      else {
        // Fallback for KPIs that don't have a dedicated method yet.
        $kpi_data[$kpi_id] = $this->getPlaceholderData($kpi_info);
      }
    }

    return $kpi_data;
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
  private function getPlaceholderData(array $kpi_info): array {
    // @todo: This is a placeholder. The actual data should be fetched from the
    // makerspace_snapshot module's `ms_fact_org_snapshot` table. This will
    // require modifying the makerspace_snapshot module to add new columns for
    // each KPI and implementing the logic to calculate and save the annual
    // snapshot data. The `trend` data should be fetched from the existing
    // monthly snapshots.
    return [
      'label' => $kpi_info['label'],
      'base_2025' => $kpi_info['base_2025'],
      'goal_2030' => $kpi_info['goal_2030'],
      '2026' => 'n/a',
      '2027' => 'n/a',
      '2028' => 'n/a',
      '2029' => 'n/a',
      '2030' => 'n/a',
      'trend' => [],
      'description' => $kpi_info['description'] ?? '',
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
  private function getTotalActiveMembersData(array $kpi_info): array {
    // @todo: Replace placeholder data with actual data from the
    // makerspace_snapshot module.
    // - The yearly "Actual" values (2026-2030) should be fetched from the
    //   `ms_fact_org_snapshot` table, from a new `kpi_total_active_members`
    //   column, for snapshots with `snapshot_type = 'annual'`.
    // - The `trend` data should be an array of the last 12 `members_active`
    //   values from snapshots with `snapshot_type = 'monthly'`.
    return [
      'label' => $kpi_info['label'],
      'base_2025' => $kpi_info['base_2025'],
      'goal_2030' => $kpi_info['goal_2030'],
      '2026' => 1100,
      '2027' => 1200,
      '2028' => 1300,
      '2029' => 1400,
      '2030' => 1500,
      'trend' => [10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120],
      'description' => $kpi_info['description'],
    ];
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
  private function getWorkshopAttendeesData(array $kpi_info): array {
    // @todo: Replace placeholder data with actual data from the
    // makerspace_snapshot module.
    // - The yearly "Actual" values (2026-2030) should be fetched from the
    //   `ms_fact_org_snapshot` table, from a new `kpi_workshop_attendees`
    //   column, for snapshots with `snapshot_type = 'annual'`.
    // - The `trend` data should be an array of the last 12 monthly values
    //   from `EventsMembershipDataService::getMonthlyRegistrationsByType()`.
    return [
      'label' => $kpi_info['label'],
      'base_2025' => $kpi_info['base_2025'],
      'goal_2030' => $kpi_info['goal_2030'],
      '2026' => $this->eventsMembershipDataService->getAnnualWorkshopAttendees(),
      '2027' => 1500,
      '2028' => 1600,
      '2029' => 1800,
      '2030' => 2000,
      'trend' => [100, 110, 120, 130, 140, 150, 160, 170, 180, 190, 200, 210],
      'description' => $kpi_info['description'],
    ];
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
    // @todo: Replace placeholder data with actual data from the
    // makerspace_snapshot module.
    // - The yearly "Actual" values (2026-2030) should be fetched from the
    //   `ms_fact_org_snapshot` table, from a new `kpi_reserve_funds_months`
    //   column, for snapshots with `snapshot_type = 'annual'`.
    // - The `trend` data should be calculated monthly.
    return [
      'label' => $kpi_info['label'],
      'base_2025' => $kpi_info['base_2025'],
      'goal_2030' => $kpi_info['goal_2030'],
      '2026' => 3.5,
      '2027' => 4,
      '2028' => 4.5,
      '2029' => 5,
      '2030' => 6,
      'trend' => [3, 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 4, 4.1],
      'description' => $kpi_info['description'],
    ];
  }

  /**
   * Temporary hardcoded KPI configuration.
   *
   * @param string $section_id
   *   The ID of the section.
   *
   * @return array
   *   The hardcoded KPI configuration for the section.
   */
  private function getHardcodedKpiConfig(string $section_id): array {
    $config = [
      'overview' => [
        'total_active_members' => [
          'label' => 'Total # Active Members',
          'base_2025' => 1000,
          'goal_2030' => 1500,
          'description' => 'Calculation: "The count of all members from all categories (not paused)". Implementation Note: This is `members_active` from `ms_fact_org_snapshot`, which `SnapshotService` already calculates. The annual snapshot will use the value from the December monthly snapshot.',
        ],
        'workshop_attendees' => [
          'label' => '# of Workshop Attendees',
          'base_2025' => 1200,
          'goal_2030' => 2000,
          'description' => 'Calculation: "From Civicrm \'Event Registration Report\' Event end date. Is \'Ticketed Workshop\'". Implementation Note: The annual `SnapshotService` will `SUM()` the 12 monthly values provided by `EventsMembershipDataService::getMonthlyRegistrationsByType()`.',
        ],
        'reserve_funds_months' => [
          'label' => 'Reserve Funds (as Months of Operating Expense)',
          'base_2025' => 3,
          'goal_2030' => 6,
          'description' => 'Calculation: "(Cash and Cash Equivalents) / (Average Monthly Operating Expense (12 month trailing))". Implementation Note: This is a two-part calculation for the annual snapshot: 1. Cash and Cash Equivalents: Call `GoogleSheetClientService` to read the financial export sheet. Find the row `Cash and Cash Equivalents` (Column A) and get the value from the column for the end of that year (e.g., `2026-12-31`). 2. Average Monthly Operating Expense: Call `FinancialDataService` to get this value from a separate Xero report.',
        ],
      ],
      'governance' => [
        'board_ethnic_diversity' => [
          'label' => 'Board Ethnic Diversity (% BIPOC)',
          'base_2025' => 0.20,
          'goal_2030' => 0.50,
          'description' => 'Calculation: "50% (+-10%)BIPOC". Implementation Note: This is not in any system. The annual snapshot value must be read from a manual entry in the `makerspace_dashboard.kpis.yml` config file.',
        ],
        'board_gender_diversity' => [
          'label' => 'Board Gender Diversity (% Female/Non-binary)',
          'base_2025' => 0.40,
          'goal_2030' => 0.50,
          'description' => 'Calculation: "Equitable (+-20%)Gender split". Implementation Note: Manual entry, same as above.',
        ],
        'active_titled_recurring_volunteers' => [
          'label' => 'Count of Active Titled Recurring Volunteers',
          'base_2025' => 50,
          'goal_2030' => 100,
          'description' => 'Calculation: "Count of individuals who has a titled volunteer position with a job description for 3+ months...". Implementation Note: Manual entry, same as above.',
        ],
        'active_committee_participation' => [
          'label' => 'Active Committee Participation',
          'base_2025' => 30,
          'goal_2030' => 60,
          'description' => 'Calculation: The total number of unique volunteers participating in committees. Implementation Note: The annual `SnapshotService` will call `GoogleSheetClientService` to read the "MakeHaven Key KPIs 2025" spreadsheet. It will navigate to the "Committees" tab, read the "Volunteers Participating" column (Column B), and `SUM()` the values starting from row 2.',
        ],
      ],
      'finance' => [
        'reserve_funds_months' => [
          'label' => 'Reserve Funds (as Months of Operating Expense)',
          'base_2025' => 3,
          'goal_2030' => 6,
          'description' => 'Calculation: "(Cash and Cash Equivalents) / (Average Monthly Operating Expense (12 month trailing))". Implementation Note: (See Overview section for the two-part calculation).',
        ],
        'earned_income_sustaining_core' => [
          'label' => 'Earned Income Sustaining Core %',
          'base_2025' => 0.80,
          'goal_2030' => 1.00,
          'description' => 'Calculation: "(Income - (grants+donations)) / (expenses- (grant program expense +capital investment))". Implementation Note: This is not on the balance sheet export. The annual `SnapshotService` will call a new method in `FinancialDataService` to calculate this from Xero.',
        ],
        'member_revenue_quarterly' => [
          'label' => 'Member Revenue (Quarterly)',
          'base_2025' => 100000,
          'goal_2030' => 150000,
          'description' => 'Calculation: "From Xero \'Membership - Individual Recuring\'". Implementation Note: Not on the balance sheet. The annual `SnapshotService` will call `FinancialDataService` to get the sum of the four quarters for the year.',
        ],
        'net_income_program_lines' => [
          'label' => 'Net Income (Program Lines)',
          'base_2025' => 20000,
          'goal_2030' => 50000,
          'description' => 'Calculation: "Net income from lines of business include: desk rental, storage, room rental and equipment usage fees.". Implementation Note: Not on the balance sheet. The annual `SnapshotService` will call `FinancialDataService` to get this from Xero.',
        ],
      ],
      'infrastructure' => [
        'member_satisfaction_equipment' => [
          'label' => 'Member Satisfaction (Equipment)',
          'base_2025' => 4.0,
          'goal_2030' => 4.5,
          'description' => 'Calculation: Value from annual "Survey". Implementation Note: This value will be pulled from the annual member survey (system TBD) and saved in the annual snapshot.',
        ],
        'member_satisfaction_facility_vibe' => [
          'label' => 'Member Satisfaction (Facility/Vibe)',
          'base_2025' => 4.2,
          'goal_2030' => 4.7,
          'description' => 'Calculation: Value from annual "Survey". Implementation Note: Pulled from the annual member survey and saved in the annual snapshot.',
        ],
        'shop_utilization' => [
          'label' => 'Shop Utilization (Active Participation %)',
          'base_2025' => 0.60,
          'goal_2030' => 0.80,
          'description' => 'Calculation: "What count of members enter space per quarter as measured by door access". Implementation Note: See *Retention: Active Participation %* calculation.',
        ],
        'adherence_to_shop_budget' => [
          'label' => 'Adherence to Shop Budget',
          'base_2025' => 1.0,
          'goal_2030' => 1.0,
          'description' => 'Calculation: "Budget vs Shop Expense Line". Implementation Note: This (e.g., a variance percentage) will be pulled from Xero via `FinancialDataService` and saved in the annual snapshot.',
        ],
      ],
      'outreach' => [
        'total_new_member_signups' => [
          'label' => 'Total New Member Signups',
          'base_2025' => 300,
          'goal_2030' => 500,
          'description' => 'Calculation: "The count of all members from all categories.". Implementation Note: This is the `joins_count` already tracked by `SnapshotService`. The annual snapshot will be the `SUM()` of the 12 monthly `joins` values.',
        ],
        'total_first_time_workshop_participants' => [
          'label' => 'Total # First Time Workshop Participants',
          'base_2025' => 400,
          'goal_2030' => 600,
          'description' => 'Calculation: "The count of all members from all categories." Implementation Note: The annual `SnapshotService` will call a new method in `EventsMembershipDataService` to get a *unique count* of participants who attended their first-ever event in that year.',
        ],
        'total_new_recurring_revenue' => [
          'label' => 'Total $ of New Recurring Revenue',
          'base_2025' => 50000,
          'goal_2030' => 80000,
          'description' => 'Calculation: (Inferred) The total dollar value of new membership plans. Implementation Note: The `SnapshotService`\'s `sql_joins` query (in `takeSnapshot()`) must be modified to `SUM(plan_amount)` for all new joins in the period.',
        ],
        'member_referral_rate' => [
          'label' => 'Member Referral Rate',
          'base_2025' => 0.30,
          'goal_2030' => 0.50,
          'description' => 'Calculation: (Inferred) % of new members who selected \'Referral\'. Implementation Note: The annual `SnapshotService` will call `DemographicsDataService` to query new member profiles for `field_member_discovery_value` = \'Referral\' and divide by the total number of new members in that year.',
        ],
      ],
      'retention' => [
        'total_active_members' => [
          'label' => 'Total # Active Members',
          'base_2025' => 1000,
          'goal_2030' => 1500,
          'description' => 'Calculation: "The count of all members from all categories (not paused)". Implementation Note: This is `members_active` from `ms_fact_org_snapshot`. The annual snapshot will use the value from the December monthly snapshot.',
        ],
        'first_year_member_retention' => [
          'label' => 'First Year Member Retention %',
          'base_2025' => 0.70,
          'goal_2030' => 0.85,
          'description' => 'Calculation: "Percent of individual new members retained after 12 months... Excluding Unpreventable end reasons". Implementation Note: The annual `SnapshotService` will call `MembershipMetricsService::getAnnualCohorts()` to get the 12-month retention for the *previous* year\'s join cohort.',
        ],
        'member_nps' => [
          'label' => 'Member Net Promoter Score (NPS)',
          'base_2025' => 50,
          'goal_2030' => 75,
          'description' => 'Calculation: "Member survey... ((Promoters − Detractors) / Total Responses) × 100". Implementation Note: Pulled from the annual member survey and saved in the annual snapshot.',
        ],
        'active_participation' => [
          'label' => 'Active Participation %',
          'base_2025' => 0.60,
          'goal_2030' => 0.80,
          'description' => 'Calculation: "Percent of all currently active members who have at least one card read/entry in the previous quarter.". Implementation Note: The annual `SnapshotService` will call a new method (likely in `UtilizationDataService`) to count unique UIDs with door access logs in Q4 and divide by `members_active` in the December snapshot.',
        ],
        'membership_diversity_bipoc' => [
          'label' => 'Membership Diversity (% BIPOC)',
          'base_2025' => 0.15,
          'goal_2030' => 0.30,
          'description' => 'Calculation: "Percent of membership whose self identities include black, indigenous, and other people of color... Counting only those who have an identity submitted". Implementation Note: The annual `SnapshotService` will call `DemographicsDataService::getEthnicityDistribution()`, sum the counts for all non-white identities, and divide by the total responses.',
        ],
      ],
      'education' => [
        'workshop_attendees' => [
          'label' => '# of Workshop Attendees',
          'base_2025' => 1200,
          'goal_2030' => 2000,
          'description' => 'Calculation: "From Civicrm \'Event Registration Report\' Event end date. Is \'Ticketed Workshop\'". Implementation Note: The annual `SnapshotService` will `SUM()` the monthly values provided by `EventsMembershipDataService::getMonthlyRegistrationsByType()`.',
        ],
        'education_nps' => [
          'label' => 'Education Net Promoter Score (NPS)',
          'base_2025' => 60,
          'goal_2030' => 80,
          'description' => 'Calculation: "Calculate NPS using the standard formula... Promoters: 5, Passives: 4, Detractors: 1-3". Implementation Note: The annual `SnapshotService` will call a new method in `EventsMembershipDataService` to query CiviCRM evaluations for the year.',
        ],
        'workshop_participants_bipoc' => [
          'label' => '% Workshop Participants (BIPOC)',
          'base_2025' => 0.15,
          'goal_2030' => 0.30,
          'description' => 'Calculation: "Report from Civicrm on demographics selected at registration, all not white". Implementation Note: The annual `SnapshotService` will call `EventsMembershipDataService::getParticipantDemographics()` and perform the % calculation.',
        ],
        'active_instructors_bipoc' => [
          'label' => '% Active Instructors (BIPOC)',
          'base_2025' => 0.10,
          'goal_2030' => 0.25,
          'description' => 'Calculation: "Ashley to count the number of BIPOC instructors who taught vs non. Use past 12 months". Implementation Note: The annual `SnapshotService` will call a new method in `EventsMembershipDataService` to get this count from CiviCRM.',
        ],
        'net_income_education' => [
          'label' => 'Net Income (Education Program)',
          'base_2025' => 10000,
          'goal_2030' => 30000,
          'description' => 'Calculation: "Xero accrual activity for: Education ... - Education Expense ...". Implementation Note: The annual `SnapshotService` will call `FinancialDataService` to get this value from Xero.',
        ],
      ],
      'entrepreneurship' => [
        'incubator_workspace_occupancy' => [
          'label' => 'Incubator Workspace Occupancy %',
          'base_2025' => 0.75,
          'goal_2030' => 0.95,
          'description' => 'Calculation: TBD. Implementation Note: Data sources for this section are TBD. For now, the annual snapshot can pull from manual entries in the `kpis.yml` file.',
        ],
        'active_incubator_ventures' => [
          'label' => '# of Active Incubator Ventures',
          'base_2025' => 10,
          'goal_2030' => 20,
          'description' => 'Calculation: TBD. Implementation Note: Data sources for this section are TBD. For now, the annual snapshot can pull from manual entries in the `kpis.yml` file.',
        ],
        'entrepreneurship_events' => [
          'label' => '# of Entrepreneurship Events Held',
          'base_2025' => 5,
          'goal_2030' => 15,
          'description' => 'Calculation: TBD. Implementation Note: Data sources for this section are TBD. For now, the annual snapshot can pull from manual entries in the `kpis.yml` file.',
        ],
        'milestones_achieved' => [
          'label' => '# of Milestones Achieved by Ventures',
          'base_2025' => 20,
          'goal_2030' => 50,
          'description' => 'Calculation: TBD. Implementation Note: Data sources for this section are TBD. For now, the annual snapshot can pull from manual entries in the `kpis.yml` file.',
        ],
      ],
      'development' => [
        'annual_individual_giving' => [
          'label' => '$ Annual Individual Giving',
          'base_2025' => 50000,
          'goal_2030' => 100000,
          'description' => 'Calculation: TBD. Implementation Note: Data sources for this section are TBD but should come from finance systems. Implement these in the annual snapshot by calling `FinancialDataService`.',
        ],
        'annual_corporate_sponsorships' => [
          'label' => '$ Annual Corporate Sponsorships',
          'base_2025' => 25000,
          'goal_2030' => 75000,
          'description' => 'Calculation: TBD. Implementation Note: Data sources for this section are TBD but should come from finance systems. Implement these in the annual snapshot by calling `FinancialDataService`.',
        ],
        'non_government_grants' => [
          'label' => '# of Non-Government Grants Secured',
          'base_2025' => 2,
          'goal_2030' => 5,
          'description' => 'Calculation: TBD. Implementation Note: Data sources for this section are TBD but should come from finance systems. Implement these in the annual snapshot by calling `FinancialDataService`.',
        ],
        'donor_retention_rate' => [
          'label' => 'Donor Retention Rate %',
          'base_2025' => 0.60,
          'goal_2030' => 0.75,
          'description' => 'Calculation: TBD. Implementation Note: Data sources for this section are TBD but should come from finance systems. Implement these in the annual snapshot by calling `FinancialDataService`.',
        ],
      ],
      'dei' => [
        'membership_diversity_bipoc' => [
          'label' => 'Membership Diversity (% BIPOC)',
          'base_2025' => 0.15,
          'goal_2030' => 0.30,
          'description' => 'Calculation: "Percent of membership whose self identities include black, indigenous, and other people of color... Counting only those who have an identity submitted". Implementation Note: The annual `SnapshotService` will call `DemographicsDataService::getEthnicityDistribution()`, sum the counts for all non-white identities, and divide by the total responses.',
        ],
        'workshop_participants_bipoc' => [
          'label' => '% Workshop Participants (BIPOC)',
          'base_2025' => 0.15,
          'goal_2030' => 0.30,
          'description' => 'Calculation: "Report from Civicrm on demographics selected at registration, all not white". Implementation Note: The annual `SnapshotService` will call `EventsMembershipDataService::getParticipantDemographics()` and perform the % calculation.',
        ],
        'active_instructors_bipoc' => [
          'label' => '% Active Instructors (BIPOC)',
          'base_2025' => 0.10,
          'goal_2030' => 0.25,
          'description' => 'Calculation: "Ashley to count the number of BIPOC instructors who taught vs non. Use past 12 months". Implementation Note: The annual `SnapshotService` will call a new method in `EventsMembershipDataService` to get this count from CiviCRM.',
        ],
        'board_ethnic_diversity' => [
          'label' => 'Board Ethnic diversity (% BIPOC)',
          'base_2025' => 0.20,
          'goal_2030' => 0.50,
          'description' => 'Calculation: "50% (+-10%)BIPOC". Implementation Note: This is not in any system. The annual snapshot value must be read from a manual entry in the `makerspace_dashboard.kpis.yml` config file.',
        ],
        'retention_poc' => [
          'label' => 'Retention POC %',
          'base_2025' => 0.65,
          'goal_2030' => 0.80,
          'description' => 'Calculation: (Inferred from other KPIs). Implementation Note: All KPIs in this section are shared from other sections. The table-building logic can simply pull the data for these KPIs from the snapshot data already gathered for the other sections.',
        ],
      ],
    ];

    return $config[$section_id] ?? [];
  }
}
