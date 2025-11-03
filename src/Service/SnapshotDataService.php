<?php

namespace Drupal\makerspace_dashboard\Service;

use DateTimeImmutable;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use InvalidArgumentException;

/**
 * Provides reusable access to makerspace snapshot aggregates.
 */
class SnapshotDataService {

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Time service.
   */
  protected TimeInterface $time;

  /**
   * Cache lifetime.
   */
  protected int $ttl;

  /**
   * Cached indicator for the snapshot is_test flag availability.
   */
  protected ?bool $snapshotHasTestFlag = NULL;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, TimeInterface $time, int $ttl = 900) {
    $this->database = $database;
    $this->cache = $cache;
    $this->time = $time;
    $this->ttl = $ttl;
  }

  /**
   * Returns active membership counts grouped by the requested granularity.
   *
   * @param string $granularity
   *   One of day, month, or year.
   * @param bool $includeTests
   *   Whether to include rows flagged as test snapshots.
   * @param array|null $snapshotTypes
   *   Optional array of snapshot_type values to include. Defaults to all
   *   makerspace_snapshot membership_totals schedules.
   *
   * @return array
   *   Ordered list of associative arrays with keys:
   *   - period_key: Canonical string key for the grouping period.
   *   - period_date: DateTimeImmutable representing the period anchor (start of
   *     day/month/year).
   *   - snapshot_date: DateTimeImmutable of the source snapshot.
   *   - snapshot_type: The snapshot type string.
   *   - members_active: Integer active membership count.
   */
  public function getMembershipCountSeries(string $granularity = 'day', bool $includeTests = FALSE, ?array $snapshotTypes = NULL): array {
    $granularity = $this->normalizeGranularity($granularity);

    $cacheIdParts = [
      'makerspace_dashboard:snapshot:membership',
      $granularity,
      $includeTests ? 'with_tests' : 'production',
    ];
    if ($snapshotTypes) {
      $cacheIdParts[] = md5(implode('|', $snapshotTypes));
    }
    $cacheId = implode(':', $cacheIdParts);

    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $schema = $this->database->schema();
    if (!$schema->tableExists('ms_snapshot') || !$schema->tableExists('ms_fact_org_snapshot')) {
      return [];
    }

    $query = $this->database->select('ms_snapshot', 's');
    $hasTestFlag = $this->snapshotHasTestFlag();
    $query->innerJoin('ms_fact_org_snapshot', 'o', 'o.snapshot_id = s.id');
    $snapshotFields = ['snapshot_date', 'snapshot_type'];
    if ($hasTestFlag) {
      $snapshotFields[] = 'is_test';
    }
    $query->fields('s', $snapshotFields);
    $query->fields('o', ['members_active']);

    if ($snapshotTypes) {
      $query->condition('s.snapshot_type', $snapshotTypes, 'IN');
    }
    else {
      // Default to the core snapshot cadence types when none are specified.
      $query->condition('s.snapshot_type', [
        'monthly',
        'quarterly',
        'annually',
        'manual',
      ], 'IN');
    }

    if (!$includeTests && $hasTestFlag) {
      $query->condition('s.is_test', 0);
    }

    $query->orderBy('s.snapshot_date', 'ASC');
    $records = $query->execute()->fetchAll();

    if (!$records) {
      return [];
    }

    $series = [];
    foreach ($records as $record) {
      $snapshotDate = DateTimeImmutable::createFromFormat('Y-m-d', $record->snapshot_date);
      if (!$snapshotDate) {
        continue;
      }

      $periodDate = $this->normalizeDateForGranularity($snapshotDate, $granularity);
      $periodKey = $periodDate->format($this->periodFormat($granularity));
      $snapshotTimestamp = $snapshotDate->getTimestamp();

      if (!isset($series[$periodKey]) || $snapshotTimestamp >= $series[$periodKey]['snapshot_timestamp']) {
        $series[$periodKey] = [
          'period_key' => $periodKey,
          'period_date' => $periodDate,
          'snapshot_date' => $snapshotDate,
          'snapshot_type' => $record->snapshot_type,
          'members_active' => (int) $record->members_active,
          'snapshot_timestamp' => $snapshotTimestamp,
        ];
      }
    }

    if (!$series) {
      return [];
    }

    $ordered = array_values($series);
    usort($ordered, static function (array $a, array $b) {
      return $a['period_date'] <=> $b['period_date'];
    });

    $clean = array_map(static function (array $row): array {
      unset($row['snapshot_timestamp']);
      return $row;
    }, $ordered);

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $clean, $expire, ['makerspace_snapshot:membership_totals']);

    return $clean;
  }

  /**
   * Returns the stored KPI metric series for a given KPI ID.
   */
  public function getKpiMetricSeries(string $kpiId, bool $includeTests = FALSE, ?array $snapshotTypes = NULL, ?int $limit = NULL): array {
    $kpiId = trim($kpiId);
    if ($kpiId === '') {
      return [];
    }

    $cacheIdParts = [
      'makerspace_dashboard:snapshot:kpi',
      $kpiId,
      $includeTests ? 'with_tests' : 'production',
    ];
    if ($snapshotTypes) {
      $cacheIdParts[] = md5(implode('|', $snapshotTypes));
    }
    if ($limit) {
      $cacheIdParts[] = 'limit:' . $limit;
    }
    $cacheId = implode(':', $cacheIdParts);

    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $schema = $this->database->schema();
    if (!$schema->tableExists('ms_fact_kpi_snapshot') || !$schema->tableExists('ms_snapshot')) {
      return [];
    }

    $query = $this->database->select('ms_fact_kpi_snapshot', 'k');
    $hasTestFlag = $this->snapshotHasTestFlag();
    $query->innerJoin('ms_snapshot', 's', 's.id = k.snapshot_id');
    $query->fields('k', ['metric_value', 'period_year', 'period_month', 'meta']);
    $snapshotFields = ['snapshot_date', 'snapshot_type'];
    if ($hasTestFlag) {
      $snapshotFields[] = 'is_test';
    }
    $query->fields('s', $snapshotFields);
    $query->condition('k.kpi_id', $kpiId);

    if ($snapshotTypes) {
      $query->condition('s.snapshot_type', $snapshotTypes, 'IN');
    }

    if (!$includeTests && $hasTestFlag) {
      $query->condition('s.is_test', 0);
    }

    $query->orderBy('s.snapshot_date', 'DESC');
    if ($limit !== NULL) {
      $query->range(0, max(0, $limit));
    }

    $records = $query->execute()->fetchAll();
    if (!$records) {
      return [];
    }

    $series = [];
    foreach ($records as $record) {
      $snapshotDate = \DateTimeImmutable::createFromFormat('Y-m-d', $record->snapshot_date);
      $value = is_numeric($record->metric_value) ? (float) $record->metric_value : $record->metric_value;
      $series[] = [
        'snapshot_date' => $snapshotDate ?: NULL,
        'snapshot_type' => $record->snapshot_type,
        'is_test' => $hasTestFlag ? (bool) $record->is_test : FALSE,
        'value' => $value,
        'period_year' => $record->period_year !== NULL ? (int) $record->period_year : NULL,
        'period_month' => $record->period_month !== NULL ? (int) $record->period_month : NULL,
        'meta' => is_array($record->meta) ? $record->meta : [],
      ];
    }

    // Reverse to chronological order because the query sorted descending.
    $series = array_reverse($series);

    $expire = $this->time->getRequestTime() + $this->ttl;
    $this->cache->set($cacheId, $series, $expire, [
      'makerspace_snapshot:kpi',
      'makerspace_snapshot:kpi:' . $kpiId,
    ]);

    return $series;
  }

  /**
   * Ensures we only accept supported granularities.
   */
  protected function normalizeGranularity(string $granularity): string {
    $granularity = strtolower($granularity);
    $allowed = ['day', 'month', 'year'];
    if (!in_array($granularity, $allowed, TRUE)) {
      throw new InvalidArgumentException(sprintf('Unsupported granularity "%s".', $granularity));
    }
    return $granularity;
  }

  /**
   * Determines whether the ms_snapshot table still exposes the is_test flag.
   */
  protected function snapshotHasTestFlag(): bool {
    if ($this->snapshotHasTestFlag === NULL) {
      $this->snapshotHasTestFlag = $this->database->schema()->fieldExists('ms_snapshot', 'is_test');
    }
    return $this->snapshotHasTestFlag;
  }

  /**
   * Returns the canonical period format string per granularity.
   */
  protected function periodFormat(string $granularity): string {
    return match ($granularity) {
      'day' => 'Y-m-d',
      'month' => 'Y-m',
      'year' => 'Y',
      default => throw new InvalidArgumentException(sprintf('Unsupported granularity "%s".', $granularity)),
    };
  }

  /**
   * Normalizes a snapshot date to the anchor for the requested granularity.
   */
  protected function normalizeDateForGranularity(DateTimeImmutable $date, string $granularity): DateTimeImmutable {
    return match ($granularity) {
      'day' => $date,
      'month' => $date->setDate((int) $date->format('Y'), (int) $date->format('m'), 1),
      'year' => $date->setDate((int) $date->format('Y'), 1, 1),
      default => $date,
    };
  }

  /**
   * Gets the total number of new member signups for the year.
   *
   * @return int
   *   The total number of new member signups.
   */
  public function getAnnualNewMemberSignups(): int {
    // @todo: Implement logic to SUM() the 12 monthly `joins` values from the
    // `ms_fact_org_snapshot` table. This will be called by the 'annual'
    // snapshot in the makerspace_snapshot module.
    return 350;
  }

  /**
   * Gets the total dollar value of new recurring revenue for the year.
   *
   * @return float
   *   The total new recurring revenue.
   */
  public function getAnnualNewRecurringRevenue(): float {
    // @todo: Implement logic to SUM(plan_amount) for all new joins in the
    // period. This will require modifying the `takeSnapshot()` method in the
    // `makerspace_snapshot` module. This will be called by the 'annual'
    // snapshot.
    return 60000.00;
  }

}
