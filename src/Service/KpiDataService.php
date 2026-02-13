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
   */
  public function __construct(ConfigFactoryInterface $config_factory, FinancialDataService $financial_data_service, GoogleSheetClientService $google_sheet_client_service, EventsMembershipDataService $events_membership_data_service, DemographicsDataService $demographics_data_service, SnapshotDataService $snapshot_data_service, MembershipMetricsService $membership_metrics_service, UtilizationDataService $utilization_data_service, GovernanceBoardDataService $governance_board_data_service, EducationEvaluationDataService $education_evaluation_data_service, DevelopmentDataService $development_data_service, EntrepreneurshipDataService $entrepreneurship_data_service) {
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
    foreach ($kpi_config as $kpi_id => $kpi_info) {
      $method_name = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $kpi_id))) . 'Data';
      if (method_exists($this, $method_name)) {
        $kpi_data[$kpi_id] = $this->{$method_name}($kpi_info);
      }
      else {
        // Fallback for KPIs that don't have a dedicated method yet.
        $kpi_data[$kpi_id] = $this->getPlaceholderData($kpi_info, $kpi_id);
      }
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

      $definitions[$kpi_id] = $definition;
    }

    return $definitions;
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
  private function buildKpiResult(array $kpi_info, array $annualOverrides = [], array $trend = [], ?float $ttm12 = NULL, ?float $ttm3 = NULL, ?string $lastUpdated = NULL, $current = NULL, ?string $kpiId = NULL, ?string $displayFormat = NULL, ?string $sourceNote = NULL): array {
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
    $goalCurrentYear = $annual[$goalKey] ?? $sheetTargets[$goalKey] ?? NULL;

    return [
      'label' => $kpi_info['label'] ?? '',
      'base_2025' => $kpi_info['base_2025'] ?? NULL,
      'goal_2030' => $kpi_info['goal_2030'] ?? NULL,
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
  private function getTotalActiveMembersData(array $kpi_info): array {
    $kpiSeries = $this->snapshotDataService->getKpiMetricSeries('total_active_members');
    $annualOverrides = [];

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

        return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'total_active_members');
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

        return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'total_active_members');
      }
    }

    $membershipSeries = $this->membershipMetricsService->getMonthlyActiveMemberCounts(36);
    if (empty($membershipSeries)) {
      return $this->buildKpiResult($kpi_info, $annualOverrides, [], NULL, NULL, NULL, NULL, 'total_active_members');
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
      return $this->buildKpiResult($kpi_info, $annualOverrides, [], NULL, NULL, NULL, NULL, 'total_active_members');
    }

    $trend = array_slice($values, -12);
    $ttm12 = $this->calculateTrailingAverage($values, 12);
    $ttm3 = $this->calculateTrailingAverage($values, 3);

    return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'total_active_members');
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

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('workshop_attendees');
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

    return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'workshop_attendees');
  }

  /**
   * Gets the data for the "Total # First Time Workshop Participants" KPI.
   */
  private function getTotalFirstTimeWorkshopParticipantsData(array $kpi_info): array {
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

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('total_first_time_workshop_participants');
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

    return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'total_first_time_workshop_participants');
  }

  /**
   * Gets the data for the "Total New Member Signups" KPI.
   */
  private function getTotalNewMemberSignupsData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('total_new_member_signups');
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

    $membershipSeries = $this->snapshotDataService->getMembershipCountSeries('month');
    if (!empty($membershipSeries)) {
      $monthlyJoins = [];
      $annualJoins = [];
      $latestDate = NULL;

      foreach ($membershipSeries as $row) {
        $joins = isset($row['joins']) ? (float) $row['joins'] : NULL;
        if ($joins === NULL) {
          continue;
        }

        $monthlyJoins[] = $joins;
        $snapshotDate = $row['snapshot_date'] ?? NULL;
        if ($snapshotDate instanceof \DateTimeImmutable) {
          $year = $snapshotDate->format('Y');
          $annualJoins[$year] = ($annualJoins[$year] ?? 0.0) + $joins;
          $latestDate = $snapshotDate->format('Y-m-d');
        }
      }

      if ($monthlyJoins) {
        $trend = array_slice($monthlyJoins, -12);
        $ttm12 = $this->calculateTrailingAverage($monthlyJoins, 12);
        $ttm3 = $this->calculateTrailingAverage($monthlyJoins, 3);
        $currentSum = $this->calculateTrailingSum($monthlyJoins, 12);
        if ($currentSum !== NULL) {
          $current = (int) round($currentSum);
        }
      }

      if ($latestDate !== NULL) {
        $lastUpdated = $latestDate;
      }

      foreach ($annualJoins as $year => $value) {
        if (!isset($annualOverrides[$year])) {
          $annualOverrides[$year] = round($value);
        }
      }
    }

    if ($annualOverrides) {
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'total_new_member_signups');
  }

  /**
   * Gets the data for the "Active Participation %" KPI.
   */
  private function getActiveParticipationData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('active_participation');
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

    return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'active_participation', 'percent');
  }

  /**
   * Gets the data for the "Member Referral Rate" KPI.
   */
  private function getMemberReferralRateData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('member_referral_rate');
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
      $distribution = $this->demographicsDataService->getDiscoveryDistribution();
      if (!empty($distribution)) {
        $total = 0;
        $referrals = 0;
        foreach ($distribution as $row) {
          $count = (int) ($row['count'] ?? 0);
          $label = strtolower(trim((string) ($row['label'] ?? '')));
          $total += $count;
          if ($label !== '' && (str_contains($label, 'referral') || str_contains($label, 'friend'))) {
            $referrals += $count;
          }
        }
        if ($total > 0) {
          $current = $referrals / $total;
        }
      }
      $lastUpdated = date('Y-m-d');
    }

    if ($annualOverrides) {
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'member_referral_rate', 'percent');
  }

  /**
   * Gets the data for the "Education Net Promoter Score (NPS)" KPI.
   */
  private function getEducationNpsData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('education_nps');
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

    return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'education_nps');
  }

  /**
   * Gets the data for the "% Workshop Participants (BIPOC)" KPI.
   */
  private function getWorkshopParticipantsBipocData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('workshop_participants_bipoc');
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

    return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'workshop_participants_bipoc', 'percent');
  }

  /**
   * Gets the data for the "% Active Instructors (BIPOC)" KPI.
   */
  private function getActiveInstructorsBipocData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('active_instructors_bipoc');
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

    return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'active_instructors_bipoc', 'percent');
  }

  /**
   * Gets the data for the "First Year Member Retention %" KPI.
   */
  private function getFirstYearMemberRetentionData(array $kpi_info): array {
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

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('first_year_member_retention');
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

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      $trend,
      $ttm12,
      $ttm3,
      $lastUpdated,
      $current,
      'first_year_member_retention',
      'percent'
    );
  }

  /**
   * Gets the data for the "Member Net Promoter Score (NPS)" KPI.
   */
  private function getMemberNpsData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $ttm12 = NULL;
    $ttm3 = NULL;
    $current = NULL;
    $lastUpdated = NULL;

    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('member_nps');
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

    return $this->buildKpiResult($kpi_info, $annualOverrides, $trend, $ttm12, $ttm3, $lastUpdated, $current, 'member_nps');
  }

  /**
   * Gets the data for the "$ Annual Corporate Sponsorships" KPI.
   *
   * Uses non-member donor monthly amounts as an automated sponsorship proxy.
   */
  private function getAnnualCorporateSponsorshipsData(array $kpi_info): array {
    $series = $this->developmentDataService->getMemberDonorTrend(36);
    $labels = array_values((array) ($series['labels'] ?? []));
    $amounts = array_values((array) ($series['non_member']['amounts'] ?? []));

    $trend = [];
    $annualOverrides = [];
    $lastUpdated = NULL;
    $current = NULL;

    $count = min(count($labels), count($amounts));
    for ($i = 0; $i < $count; $i++) {
      if (!is_numeric($amounts[$i])) {
        continue;
      }
      $amount = (float) $amounts[$i];
      $trend[] = $amount;

      $monthDate = \DateTimeImmutable::createFromFormat('M Y', (string) $labels[$i]);
      if ($monthDate) {
        $year = $monthDate->format('Y');
        if (!isset($annualOverrides[$year])) {
          $annualOverrides[$year] = 0.0;
        }
        $annualOverrides[$year] += $amount;
        $lastUpdated = $monthDate->format('Y-m-t');
      }
    }

    if (!empty($trend)) {
      $current = $this->calculateTrailingSum($trend, 12);
      if ($current !== NULL) {
        $current = round($current, 2);
      }
    }

    foreach ($annualOverrides as $year => $value) {
      $annualOverrides[$year] = round((float) $value, 2);
    }
    if ($annualOverrides) {
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      array_slice($trend, -12),
      $this->calculateTrailingAverage($trend, 12),
      $this->calculateTrailingAverage($trend, 3),
      $lastUpdated ?? date('Y-m-d'),
      $current,
      'annual_corporate_sponsorships',
      NULL,
      'Proxy: Non-member donor contribution amount trend.'
    );
  }

  /**
   * Gets the data for the "Donor Retention Rate %" KPI.
   *
   * Uses returning-donor share ((donors - first_time_donors) / donors).
   */
  private function getDonorRetentionRateData(array $kpi_info): array {
    $annual = $this->developmentDataService->getAnnualGivingSummary(8);
    if (empty($annual)) {
      return $this->buildKpiResult($kpi_info, [], [], NULL, NULL, NULL, NULL, 'donor_retention_rate', 'percent');
    }

    $annualOverrides = [];
    foreach ($annual as $row) {
      $year = (string) ($row['year'] ?? '');
      $donors = (int) ($row['donors'] ?? 0);
      $firstTime = (int) ($row['first_time_donors'] ?? 0);
      if ($year === '' || $donors <= 0) {
        continue;
      }

      $returning = max(0, $donors - max(0, $firstTime));
      $annualOverrides[$year] = $returning / $donors;
    }

    if (!$annualOverrides) {
      return $this->buildKpiResult($kpi_info, [], [], NULL, NULL, NULL, NULL, 'donor_retention_rate', 'percent');
    }

    ksort($annualOverrides, SORT_STRING);
    $trend = array_values($annualOverrides);
    $current = end($trend);
    $latestYear = (int) array_key_last($annualOverrides);
    $lastUpdated = $latestYear > 0 ? sprintf('%04d-12-31', $latestYear) : date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      $trend,
      $this->calculateTrailingAverage($trend, 12),
      $this->calculateTrailingAverage($trend, 3),
      $lastUpdated,
      $current,
      'donor_retention_rate',
      'percent',
      'Automated: Returning donor share from annual giving summary.'
    );
  }

  /**
   * Gets the data for the "# of Entrepreneurship Events Held" KPI.
   *
   * Uses entrepreneurship-goal activity as an automated engagement proxy.
   */
  private function getEntrepreneurshipEventsData(array $kpi_info): array {
    $annualOverrides = [];
    $trend = [];
    $current = NULL;
    $lastUpdated = date('Y-m-d');

    $now = new \DateTimeImmutable('first day of this month');
    $start = $now->modify('-36 months');
    $goalTrend = $this->entrepreneurshipDataService->getEntrepreneurGoalTrend($start, $now);
    $labels = array_values((array) ($goalTrend['labels'] ?? []));
    $series = (array) ($goalTrend['series'] ?? []);

    if (!empty($labels) && !empty($series)) {
      foreach ($labels as $index => $label) {
        $entrepreneur = (float) ($series['entrepreneur'][$index] ?? 0);
        $seller = (float) ($series['seller'][$index] ?? 0);
        $inventor = (float) ($series['inventor'][$index] ?? 0);
        $quarterTotal = $entrepreneur + $seller + $inventor;
        $trend[] = $quarterTotal;

        if (preg_match('/^(\d{4})-Q[1-4]$/', (string) $label, $matches)) {
          $year = $matches[1];
          $annualOverrides[$year] = ($annualOverrides[$year] ?? 0) + $quarterTotal;
        }
      }
    }

    $snapshot = $this->entrepreneurshipDataService->getActiveEntrepreneurSnapshot();
    if (!empty($snapshot['totals']['goal_any'])) {
      $current = (float) $snapshot['totals']['goal_any'];
    }
    elseif (!empty($trend)) {
      $current = (float) end($trend);
    }

    if ($annualOverrides) {
      foreach ($annualOverrides as $year => $value) {
        $annualOverrides[$year] = (float) $value;
      }
      ksort($annualOverrides, SORT_STRING);
    }

    return $this->buildKpiResult(
      $kpi_info,
      $annualOverrides,
      array_slice($trend, -12),
      $this->calculateTrailingAverage($trend, 12),
      $this->calculateTrailingAverage($trend, 3),
      $lastUpdated,
      $current,
      'entrepreneurship_events',
      NULL,
      'Proxy: Entrepreneurship goal activity and active entrepreneur snapshot.'
    );
  }

  /**
   * Gets the data for the "Member Satisfaction (Equipment)" KPI.
   */
  private function getMemberSatisfactionEquipmentData(array $kpi_info): array {
    return $this->buildMemberSatisfactionProxyData($kpi_info, 'member_satisfaction_equipment');
  }

  /**
   * Gets the data for the "Member Satisfaction (Facility/Vibe)" KPI.
   */
  private function getMemberSatisfactionFacilityVibeData(array $kpi_info): array {
    return $this->buildMemberSatisfactionProxyData($kpi_info, 'member_satisfaction_facility_vibe');
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
  private function getRetentionPocData(array $kpi_info): array {
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
        'retention_poc',
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
      'retention_poc',
      'percent',
      'Automated: Cohort retention filtered by BIPOC ethnicity.'
    );
  }

  /**
   * Gets the data for the "Shop Utilization (Active Participation %)" KPI.
   */
  private function getShopUtilizationData(array $kpi_info): array {
    $snapshotSeries = $this->snapshotDataService->getKpiMetricSeries('shop_utilization');
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
          'shop_utilization',
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
      'shop_utilization',
      'percent',
      'Automated: Door-access participation ratio over rolling windows.'
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
   * Gets the data for the "Earned Income Sustaining Core %" KPI.
   */
  private function getEarnedIncomeSustainingCoreData(array $kpi_info): array {
    $buildEmpty = fn() => $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      NULL,
      NULL,
      'earned_income_sustaining_core',
      'percent'
    );

    if (!$this->getIncomeStatementTable()) {
      return $buildEmpty();
    }

    $headers = $this->getIncomeStatementHeaders();
    $totalExpenseRow = $this->getIncomeStatementRowValues('Total Expense', ['Total Expenses', 'Total Expenses (All)']);
    if ($totalExpenseRow === NULL) {
      return $buildEmpty();
    }

    $latestExpense = $this->extractLatestSheetValue($totalExpenseRow, $headers);
    if ($latestExpense === NULL) {
      return $buildEmpty();
    }

    $columnIndex = $latestExpense['column_index'];
    $lastUpdated = $latestExpense['column_label'] ?? NULL;
    $totalExpense = abs($latestExpense['value']);
    if ($totalExpense <= 0) {
      return $buildEmpty();
    }

    $incomeSources = [
      [
        'label' => 'Interest, Investment and Reward Income',
        'aliases' => ['Interest Income', 'Investment Income', 'Reward Income'],
        'keywords' => ['interest', 'investment', 'income'],
      ],
      [
        'label' => 'Store',
        'aliases' => ['Store Income', 'Store Revenue', 'Retail Income', 'Retail'],
        'keywords' => ['store'],
      ],
      [
        'label' => 'OtherMembership',
        'aliases' => ['Other Membership', 'Other Membership Income', 'Membership Other'],
        'keywords' => ['other', 'membership'],
      ],
      [
        'label' => 'Storage',
        'aliases' => ['Storage Income', 'Storage Revenue', 'Storage Rentals'],
        'keywords' => ['storage'],
      ],
      [
        'label' => 'Education',
        'aliases' => ['Education Income', 'Education Revenue'],
        'keywords' => ['education'],
      ],
      [
        'label' => 'Workspaces',
        'aliases' => ['Workspace Income', 'Workspaces Income'],
        'keywords' => ['workspace'],
      ],
      [
        'label' => 'Media Income',
        'aliases' => ['Media Revenue', 'Media'],
        'keywords' => ['media'],
      ],
    ];

    $earnedIncome = 0.0;
    foreach ($incomeSources as $source) {
      $row = $this->getIncomeStatementRowValues($source['label'], $source['aliases'] ?? []);
      if (!$row && !empty($source['keywords'])) {
        $row = $this->findIncomeStatementRowByKeywords($source['keywords'], ['expense']);
      }
      if (!$row) {
        continue;
      }
      $value = $this->getSheetValueAtColumn($row, $columnIndex);
      if ($value === NULL) {
        continue;
      }
      $earnedIncome += abs($value);
    }

    if ($earnedIncome <= 0) {
      return $buildEmpty();
    }

    $fundraisingRow = $this->getIncomeStatementRowValues('Fundraising', ['Fundraising Expense']);
    $fundraisingExpense = 0.0;
    if ($fundraisingRow) {
      $fundraisingValue = $this->getSheetValueAtColumn($fundraisingRow, $columnIndex);
      if ($fundraisingValue !== NULL) {
        $fundraisingExpense = abs($fundraisingValue);
      }
    }

    $adjustedExpenses = $totalExpense - $fundraisingExpense;
    if ($adjustedExpenses <= 0) {
      return $buildEmpty();
    }

    $current = $earnedIncome / $adjustedExpenses;

    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'earned_income_sustaining_core',
      'percent'
    );
  }

  /**
   * Gets the data for the "Member Revenue (Quarterly)" KPI.
   *
   * Calculates the current value by multiplying the active member count and the
   * average `field_member_payment_monthly` value recorded on active profiles.
   */
  private function getMemberRevenueQuarterlyData(array $kpi_info): array {
    $paymentStats = $this->financialDataService->getAverageMonthlyPaymentByType();
    $activeMembers = isset($paymentStats['total_members']) ? (int) $paymentStats['total_members'] : 0;
    $averagePayment = isset($paymentStats['overall_average']) ? (float) $paymentStats['overall_average'] : NULL;

    if ($activeMembers <= 0 || $averagePayment === NULL) {
      return $this->getPlaceholderData($kpi_info, 'member_revenue_quarterly');
    }

    $current = round($activeMembers * $averagePayment, 2);
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'member_revenue_quarterly'
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
  private function getMembershipDiversityBipocData(array $kpi_info): array {
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
      'membership_diversity_bipoc',
      'percent'
    );
  }

  /**
   * Gets the data for the "Board Gender Diversity" KPI.
   */
  private function getBoardGenderDiversityData(array $kpi_info): array {
    try {
      $composition = $this->governanceBoardDataService->getBoardComposition();
    }
    catch (\Throwable $exception) {
      return $this->getPlaceholderData($kpi_info, 'board_gender_diversity');
    }
    $gender = $composition['gender']['actual_pct'] ?? [];
    $maleShare = isset($gender['Male']) ? (float) $gender['Male'] : 0.0;
    $current = max(0.0, 1.0 - $maleShare);
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'board_gender_diversity',
      'percent'
    );
  }

  /**
   * Gets the data for the "Board Ethnic Diversity" KPI.
   */
  private function getBoardEthnicDiversityData(array $kpi_info): array {
    try {
      $composition = $this->governanceBoardDataService->getBoardComposition();
    }
    catch (\Throwable $exception) {
      return $this->getPlaceholderData($kpi_info, 'board_ethnic_diversity');
    }
    $ethnicity = $composition['ethnicity']['actual_pct'] ?? [];
    $current = $this->sumPercentages($ethnicity, $this->getBoardBipocLabels());
    $lastUpdated = date('Y-m-d');

    return $this->buildKpiResult(
      $kpi_info,
      [],
      [],
      NULL,
      NULL,
      $lastUpdated,
      $current,
      'board_ethnic_diversity',
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
   * Calculates the average monthly expense from the Income-Statement sheet.
   */
  private function calculateAverageMonthlyExpense(): ?float {
    $table = $this->getIncomeStatementTable();
    if (!$table) {
      return NULL;
    }

    $headers = $table['headers'] ?? [];
    $targetRow = $this->getIncomeStatementRowValues('Total Expense');
    if ($targetRow === NULL) {
      return NULL;
    }

    $expensesByIndex = [];
    $maxColumns = max(count($headers), count($targetRow));
    for ($i = 2; $i < $maxColumns; $i++) {
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
    $rows = [];
    foreach ($sheetData as $row) {
      $label = isset($row[0]) ? trim((string) $row[0]) : '';
      if ($label === '') {
        continue;
      }
      $rows[$this->normalizeSheetLabel($label)] = $row;
    }

    $this->incomeStatementTable = [
      'headers' => $headers,
      'rows' => $rows,
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
    $maxColumns = max(count($headers), count($row));
    if ($maxColumns <= 2) {
      return NULL;
    }

    for ($i = $maxColumns - 1; $i >= 2; $i--) {
      $rawValue = $row[$i] ?? '';
      $numericValue = $this->normalizeSheetNumber($rawValue);
      if ($numericValue === NULL) {
        continue;
      }

      $headerLabel = $headers[$i] ?? NULL;
      $parsedDate = $this->parseSheetDate($headerLabel);
      $columnLabel = NULL;
      if ($parsedDate) {
        $columnLabel = $parsedDate->format('Y-m-d');
      }
      elseif (is_string($headerLabel) && trim($headerLabel) !== '') {
        $columnLabel = trim($headerLabel);
      }

      return [
        'value' => (float) $numericValue,
        'column_index' => $i,
        'column_label' => $columnLabel,
      ];
    }

    return NULL;
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
          $annualTargets[$kpiId] = array_merge($annualTargets[$kpiId], $yearValues);
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
    foreach ($rows as $row) {
      $goalId = isset($row[$goalIdIndex]) ? trim((string) $row[$goalIdIndex]) : '';
      if ($goalId === '' || stripos($goalId, 'kpi_') !== 0) {
        continue;
      }
      $normalizedGoalId = substr($goalId, 4);
      if ($normalizedGoalId === '') {
        continue;
      }
      foreach ($yearColumns as $index => $year) {
        $valueRaw = $row[$index] ?? '';
        $value = $this->normalizeSheetNumber($valueRaw);
        if ($value === NULL) {
          continue;
        }
        if ($year === 2030) {
          $goals[$normalizedGoalId] = $value;
        }
        $annual[$normalizedGoalId][(string) $year] = $value;
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

    $fallback = NULL;
    foreach ($allYears as $year) {
      if ($year <= $referenceYear && (isset($annual[(string) $year]) || isset($sheetAnnual[(string) $year]))) {
        $fallback = $year;
      }
    }

    if ($fallback !== NULL) {
      return $fallback;
    }

    // Avoid jumping ahead to a future goal year when the current year has not
    // been configured yet. Showing the reference year keeps the UI aligned with
    // the current reporting window even if values are still "n/a".
    return $referenceYear;
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
    $targetRow = NULL;
    foreach ($sheetData as $row) {
      $label = isset($row[0]) ? trim((string) $row[0]) : '';
      if ($label !== '' && strcasecmp($label, 'Cash and Cash Equivalents') === 0) {
        $targetRow = $row;
        break;
      }
    }

    if ($targetRow === NULL) {
      return [];
    }

    $entries = [];
    $maxColumns = max(count($headers), count($targetRow));
    for ($i = 1; $i < $maxColumns; $i++) {
      $rawValue = $targetRow[$i] ?? '';
      $numericValue = $this->normalizeSheetNumber($rawValue);
      if ($numericValue === NULL) {
        continue;
      }
      $headerValue = $headers[$i] ?? '';
      $date = $this->parseSheetDate($headerValue);
      if (!$date) {
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

    if ($section_id === NULL) {
      return $config;
    }

    return $config[$section_id] ?? [];
  }
}
