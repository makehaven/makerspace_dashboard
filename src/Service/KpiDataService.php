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
  private function buildKpiResult(array $kpi_info, array $annualOverrides = [], array $trend = [], ?float $ttm12 = NULL, ?float $ttm3 = NULL, ?string $lastUpdated = NULL, $current = NULL, ?string $kpiId = NULL): array {
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
    if (empty($series)) {
      return $this->buildKpiResult($kpi_info, $annualOverrides, [], NULL, NULL, NULL, NULL, 'total_active_members');
    }

    $values = [];
    $lastSnapshot = NULL;
    $current = NULL;
    foreach ($series as $row) {
      if (empty($row['snapshot_date']) || !$row['snapshot_date'] instanceof \DateTimeImmutable) {
        continue;
      }
      $year = $row['snapshot_date']->format('Y');
      $membersActive = is_numeric($row['members_active']) ? (float) $row['members_active'] : NULL;
      if ($membersActive === NULL) {
        continue;
      }
      $values[] = $membersActive;
      $lastSnapshot = $row;
      $current = $membersActive;
    }

    $trend = array_slice($values, -12);
    $ttm12 = $this->calculateTrailingAverage($values, 12);
    $ttm3 = $this->calculateTrailingAverage($values, 3);
    $lastUpdated = $lastSnapshot && $lastSnapshot['snapshot_date'] instanceof \DateTimeImmutable
      ? $lastSnapshot['snapshot_date']->format('Y-m-d')
      : NULL;

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
    $sheetData = $this->googleSheetClientService->getSheetData('Income-Statement');
    if (empty($sheetData) || count($sheetData) < 2) {
      return NULL;
    }

    $headers = array_map('trim', array_shift($sheetData));
    $targetRow = NULL;
    foreach ($sheetData as $row) {
      $label = isset($row[0]) ? trim((string) $row[0]) : '';
      if ($label !== '' && strcasecmp($label, 'Total Expense') === 0) {
        $targetRow = $row;
        break;
      }
    }

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
    if (!is_string($value)) {
      return NULL;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
      return NULL;
    }

    try {
      return new \DateTimeImmutable($trimmed);
    }
    catch (\Exception $e) {
      return $this->parseQuarterLabel($trimmed);
    }
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

    foreach ($allYears as $year) {
      if ($year > $referenceYear && (isset($annual[(string) $year]) || isset($sheetAnnual[(string) $year]))) {
        return $year;
      }
    }

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

    // Ensure we only include a contiguous monthly run. If the sheet contains
    // future placeholders that skip one or more months, truncate at the first
    // detected gap so the chart reflects only the populated data.
    $contiguousEntries = [];
    $previousDate = NULL;
    foreach ($entries as $monthKey => $entry) {
      if ($previousDate !== NULL) {
        $diff = $this->diffInMonths($previousDate, $entry['date']);
        if ($diff > 1) {
          break;
        }
      }
      $contiguousEntries[$monthKey] = $entry;
      $previousDate = $entry['date'];
    }

    if ($contiguousEntries) {
      $entries = $contiguousEntries;
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

    if ($section_id === NULL) {
      return $config;
    }

    return $config[$section_id] ?? [];
  }
}
