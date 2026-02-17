<?php

namespace Drupal\makerspace_dashboard\Commands;

use Drupal\Core\Database\Connection;
use Drupal\makerspace_dashboard\Service\EducationEvaluationDataService;
use Drupal\makerspace_dashboard\Service\EventsMembershipDataService;
use Drush\Commands\DrushCommands;

/**
 * Backfills snapshot-backed KPI facts for annual dashboard tables.
 */
class KpiSnapshotBackfillCommands extends DrushCommands {

  /**
   * KPI IDs considered safe to backfill historically.
   */
  private const SAFE_KPIS = [
    'total_new_member_signups',
    'workshop_attendees',
    'total_first_time_workshop_participants',
    'education_nps',
    'workshop_participants_bipoc',
  ];

  /**
   * KPI IDs that can drift due to changing role/state assumptions.
   */
  private const RISKY_KPIS = [
    'active_instructors_bipoc',
  ];

  /**
   * Constructs command handler.
   */
  public function __construct(
    protected Connection $database,
    protected EventsMembershipDataService $eventsMembershipDataService,
    protected EducationEvaluationDataService $educationEvaluationDataService,
  ) {
    parent::__construct();
  }

  /**
   * Backfills annual KPI facts into existing annual snapshots.
   *
   * By default this only processes KPIs that are historically stable and safe.
   * Use --include-risky to add KPI calculations that may drift as member roles
   * or taxonomy/state semantics change over time.
   *
   * @command makerspace-dashboard:backfill-kpi-snapshots
   * @aliases msd:backfill-kpi-snapshots
   * @option from-year First year to process (inclusive). Defaults to previous year.
   * @option to-year Last year to process (inclusive). Defaults to from-year.
   * @option snapshot-types Comma list of snapshot types to target. Default: annually,annual
   * @option kpis Comma list of KPI IDs to process. Default: conservative safe set.
   * @option include-risky Include risky KPI IDs in default set.
   * @option apply Persist results. Omit for dry-run preview.
   *
   * @usage drush msd:backfill-kpi-snapshots --from-year=2024 --to-year=2025
   * @usage drush msd:backfill-kpi-snapshots --from-year=2024 --to-year=2025 --apply
   * @usage drush msd:backfill-kpi-snapshots --kpis=workshop_attendees,education_nps --apply
   */
  public function backfill(array $options = [
    'from-year' => NULL,
    'to-year' => NULL,
    'snapshot-types' => 'annually,annual',
    'kpis' => '',
    'include-risky' => FALSE,
    'apply' => FALSE,
  ]): void {
    $fromYear = (int) ($options['from-year'] ?: (date('Y') - 1));
    $toYear = (int) ($options['to-year'] ?: $fromYear);
    if ($toYear < $fromYear) {
      [$fromYear, $toYear] = [$toYear, $fromYear];
    }

    $snapshotTypes = array_values(array_filter(array_map('trim', explode(',', (string) ($options['snapshot-types'] ?? '')))));
    if (empty($snapshotTypes)) {
      $snapshotTypes = ['annually', 'annual'];
    }

    $includeRisky = !empty($options['include-risky']);
    $apply = !empty($options['apply']);
    $requestedKpis = $this->resolveRequestedKpis((string) ($options['kpis'] ?? ''), $includeRisky);
    if (empty($requestedKpis)) {
      $this->io()->warning('No KPI IDs selected for backfill.');
      return;
    }

    $snapshots = $this->loadTargetSnapshots($fromYear, $toYear, $snapshotTypes);
    if (empty($snapshots)) {
      $this->io()->warning('No annual snapshots matched the requested range/types.');
      return;
    }

    $summary = [];
    $writes = [];
    foreach ($snapshots as $snapshot) {
      $snapshotId = (int) $snapshot['id'];
      $snapshotDate = (string) $snapshot['snapshot_date'];
      $snapshotYear = (int) substr($snapshotDate, 0, 4);
      $periodStart = new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $snapshotYear));
      $periodEnd = new \DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $snapshotYear));
      $periodMonth = (int) substr($snapshotDate, 5, 2);

      $kpiValues = $this->computeKpiValues($requestedKpis, $snapshotYear, $periodStart, $periodEnd);
      $summary[$snapshotYear] = $summary[$snapshotYear] ?? [
        'snapshots' => 0,
        'kpis' => 0,
      ];
      $summary[$snapshotYear]['snapshots']++;

      foreach ($kpiValues as $kpiId => $value) {
        if (!is_numeric($value)) {
          continue;
        }
        $writes[] = [
          'snapshot_id' => $snapshotId,
          'kpi_id' => $kpiId,
          'metric_value' => (float) $value,
          'period_year' => $snapshotYear,
          'period_month' => $periodMonth,
          'meta' => [
            'source' => 'makerspace_dashboard_backfill',
            'computed_for_year' => $snapshotYear,
          ],
        ];
        $summary[$snapshotYear]['kpis']++;
      }
    }

    $this->io()->title('KPI Snapshot Backfill');
    $this->io()->text(sprintf('Range: %d-%d', $fromYear, $toYear));
    $this->io()->text('Snapshot types: ' . implode(', ', $snapshotTypes));
    $this->io()->text('KPIs: ' . implode(', ', $requestedKpis));
    foreach ($summary as $year => $row) {
      $this->io()->text(sprintf(' - %d: %d snapshots, %d KPI rows', $year, $row['snapshots'], $row['kpis']));
    }
    $this->io()->text(sprintf('Total KPI rows: %d', count($writes)));

    if (!$apply) {
      $this->io()->warning('Dry-run only. Re-run with --apply to persist rows.');
      return;
    }

    foreach ($writes as $row) {
      $this->database->merge('ms_fact_kpi_snapshot')
        ->keys([
          'snapshot_id' => $row['snapshot_id'],
          'kpi_id' => $row['kpi_id'],
        ])
        ->fields([
          'metric_value' => $row['metric_value'],
          'period_year' => $row['period_year'],
          'period_month' => $row['period_month'],
          'meta' => $row['meta'],
        ])
        ->execute();
    }

    $this->io()->success(sprintf('Backfilled %d KPI snapshot rows.', count($writes)));
  }

  /**
   * Resolves KPI list from options.
   */
  protected function resolveRequestedKpis(string $kpisOption, bool $includeRisky): array {
    $requested = array_values(array_filter(array_map('trim', explode(',', $kpisOption))));
    if (!empty($requested)) {
      return array_values(array_unique($requested));
    }

    $defaults = self::SAFE_KPIS;
    if ($includeRisky) {
      $defaults = array_merge($defaults, self::RISKY_KPIS);
    }
    return array_values(array_unique($defaults));
  }

  /**
   * Loads target annual snapshots for the selected year range.
   */
  protected function loadTargetSnapshots(int $fromYear, int $toYear, array $snapshotTypes): array {
    $startDate = sprintf('%04d-01-01', $fromYear);
    $endDate = sprintf('%04d-12-31', $toYear);

    $query = $this->database->select('ms_snapshot', 's')
      ->fields('s', ['id', 'snapshot_date', 'snapshot_type'])
      ->condition('s.definition', 'membership_totals')
      ->condition('s.snapshot_type', $snapshotTypes, 'IN')
      ->condition('s.snapshot_date', [$startDate, $endDate], 'BETWEEN')
      ->orderBy('s.snapshot_date', 'ASC')
      ->orderBy('s.id', 'ASC');

    return array_map(static function ($row): array {
      return [
        'id' => (int) $row->id,
        'snapshot_date' => (string) $row->snapshot_date,
        'snapshot_type' => (string) $row->snapshot_type,
      ];
    }, $query->execute()->fetchAll());
  }

  /**
   * Computes KPI values for a given annual window.
   */
  protected function computeKpiValues(array $kpiIds, int $year, \DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $values = [];
    foreach ($kpiIds as $kpiId) {
      $value = match ($kpiId) {
        'total_new_member_signups' => $this->computeAnnualJoins($year),
        'workshop_attendees' => $this->computeWorkshopAttendees($start, $end),
        'total_first_time_workshop_participants' => $this->computeFirstTimeWorkshopParticipants($start, $end),
        'education_nps' => $this->computeEducationNps($start, $end),
        'workshop_participants_bipoc' => $this->computeWorkshopParticipantsBipoc($start, $end),
        'active_instructors_bipoc' => $this->computeActiveInstructorsBipoc($start, $end),
        default => NULL,
      };
      if ($value !== NULL && is_numeric($value)) {
        $values[$kpiId] = (float) $value;
      }
    }
    return $values;
  }

  /**
   * Computes annual new-member signups from monthly org snapshots.
   */
  protected function computeAnnualJoins(int $year): ?float {
    $query = $this->database->select('ms_snapshot', 's');
    $query->innerJoin('ms_fact_org_snapshot', 'o', 'o.snapshot_id = s.id');
    $query->addExpression('SUM(o.joins)', 'joins_total');
    $query->condition('s.definition', 'membership_totals');
    $query->condition('s.snapshot_type', 'monthly');
    $query->condition('s.snapshot_date', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)], 'BETWEEN');
    $value = $query->execute()->fetchField();
    return is_numeric($value) ? (float) $value : NULL;
  }

  /**
   * Computes annual workshop attendees from monthly series.
   */
  protected function computeWorkshopAttendees(\DateTimeImmutable $start, \DateTimeImmutable $end): ?float {
    $series = $this->eventsMembershipDataService->getMonthlyWorkshopAttendanceSeries($start, $end);
    $items = (array) ($series['items'] ?? []);
    if (empty($items)) {
      return NULL;
    }
    return (float) makerspace_dashboard_sum_count_items($items);
  }

  /**
   * Computes annual first-time workshop participants from monthly series.
   */
  protected function computeFirstTimeWorkshopParticipants(\DateTimeImmutable $start, \DateTimeImmutable $end): ?float {
    $series = $this->eventsMembershipDataService->getMonthlyFirstTimeWorkshopParticipantsSeries($start, $end);
    $items = (array) ($series['items'] ?? []);
    if (empty($items)) {
      return NULL;
    }
    return (float) makerspace_dashboard_sum_count_items($items);
  }

  /**
   * Computes annual education NPS.
   */
  protected function computeEducationNps(\DateTimeImmutable $start, \DateTimeImmutable $end): ?float {
    $series = $this->educationEvaluationDataService->getNetPromoterSeries($start, $end);
    $value = $series['overall']['nps'] ?? NULL;
    return is_numeric($value) ? (float) $value : NULL;
  }

  /**
   * Computes annual workshop participant BIPOC rate.
   */
  protected function computeWorkshopParticipantsBipoc(\DateTimeImmutable $start, \DateTimeImmutable $end): ?float {
    $participantDemo = $this->eventsMembershipDataService->getParticipantDemographics($start, $end);
    $rate = makerspace_dashboard_calculate_workshop_bipoc_rate((array) $participantDemo);
    return is_numeric($rate) ? (float) $rate : NULL;
  }

  /**
   * Computes annual active instructor BIPOC rate (risky historical metric).
   */
  protected function computeActiveInstructorsBipoc(\DateTimeImmutable $start, \DateTimeImmutable $end): ?float {
    $instructorDemo = $this->eventsMembershipDataService->getActiveInstructorDemographics($start, $end);
    $rate = makerspace_dashboard_calculate_instructor_bipoc_rate((array) $instructorDemo);
    return is_numeric($rate) ? (float) $rate : NULL;
  }

}

