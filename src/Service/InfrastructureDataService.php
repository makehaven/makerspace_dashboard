<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\storage_manager\Service\StatisticsService;

/**
 * Provides infrastructure-related aggregates for dashboard visualizations.
 */
class InfrastructureDataService {

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Storage statistics service.
   */
  protected ?StatisticsService $storageStats;

  /**
   * Cache lifetime in seconds.
   */
  protected int $ttl;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, ?StatisticsService $storage_stats = NULL, int $ttl = 600) {
    $this->database = $database;
    $this->cache = $cache;
    $this->storageStats = $storage_stats;
    $this->ttl = $ttl;
  }

  /**
   * Returns the count of active (published, not completed) maintenance tasks.
   */
  public function getActiveMaintenanceLoad(): int {
    $cid = 'makerspace_dashboard:infrastructure:active_maintenance_load';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('node_field_data', 'n');
    $query->leftJoin('flagging', 'f', "f.entity_id = n.nid AND f.flag_id = 'task_completed'");
    $query->condition('n.type', 'task');
    $query->condition('n.status', 1);
    $query->isNull('f.id');
    
    $count = (int) $query->countQuery()->execute()->fetchField();

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $count, $expire, ['node_list:task', 'flagging_list']);

    return $count;
  }

  /**
   * Calculates the equipment uptime rate based on live tool status.
   */
  public function getEquipmentUptimeRate(): ?float {
    $cid = 'makerspace_dashboard:infrastructure:equipment_uptime_rate';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    // Get distribution of statuses for published 'item' nodes.
    $query = $this->database->select('node__field_item_status', 's');
    $query->innerJoin('node_field_data', 'n', 'n.nid = s.entity_id');
    $query->innerJoin('taxonomy_term_field_data', 't', 't.tid = s.field_item_status_target_id');
    $query->fields('t', ['name']);
    $query->addExpression('COUNT(n.nid)', 'count');
    $query->condition('n.type', 'item');
    $query->condition('n.status', 1);
    $query->condition('s.deleted', 0);
    $query->groupBy('t.name');

    $results = $query->execute()->fetchAllKeyed();
    
    $operational = 0;
    $total = 0;

    foreach ($results as $status => $count) {
      $status = mb_strtolower($status);
      // Exclude tools that are 'Gone' or in 'Storage' from the active fleet.
      if (str_contains($status, 'gone') || str_contains($status, 'storage')) {
        continue;
      }

      if ($this->isOperationalStatus($status)) {
        $operational += $count;
      }
      $total += $count;
    }

    $rate = ($total > 0) ? ($operational / $total) : NULL;

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $rate, $expire, ['node_list:item', 'taxonomy_term_list']);

    return $rate;
  }

  /**
   * Calculates the total replacement value of equipment added during a period.
   *
   * @param int|null $year
   *   Optional calendar year. If NULL, returns the trailing 12 months.
   */
  public function getEquipmentInvestment(?int $year = NULL): float {
    $cid = 'makerspace_dashboard:infrastructure:equipment_investment:' . ($year ?? 'trailing');
    if ($cache = $this->cache->get($cid)) {
      return (float) $cache->data;
    }

    if ($year) {
      $start = strtotime($year . '-01-01 00:00:00');
      $end = strtotime($year . '-12-31 23:59:59');
    }
    else {
      $start = strtotime('-12 months');
      $end = time();
    }

    $total = 0.0;

    // 1. Assets (item bundle)
    $query = $this->database->select('node_field_data', 'n');
    $query->innerJoin('node__field_item_value', 'v', 'v.entity_id = n.nid AND v.deleted = 0');
    $query->addExpression('SUM(v.field_item_value_value)', 'val');
    $query->condition('n.type', 'item');
    $query->condition('n.created', [$start, $end], 'BETWEEN');
    $res = $query->execute()->fetchField();
    $total += (float) $res;

    // 2. Lending Library (library_item bundle)
    $query = $this->database->select('node_field_data', 'n');
    $query->innerJoin('node__field_library_item_replacement_v', 'v', 'v.entity_id = n.nid AND v.deleted = 0');
    $query->addExpression('SUM(v.field_library_item_replacement_v_value)', 'val');
    $query->condition('n.type', 'library_item');
    $query->condition('n.created', [$start, $end], 'BETWEEN');
    $res = $query->execute()->fetchField();
    $total += (float) $res;

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $total, $expire, ['node_list:item', 'node_list:library_item']);

    return $total;
  }

  /**
   * Returns a trend series for equipment investment.
   */
  public function getEquipmentInvestmentTrend(int $years = 4): array {
    $currentYear = (int) date('Y');
    $trend = [];
    for ($i = $years - 1; $i >= 0; $i--) {
      $trend[] = $this->getEquipmentInvestment($currentYear - $i);
    }
    return $trend;
  }

  /**
   * Returns the current storage occupancy percentage.
   */
  public function getStorageOccupancy(): ?float {
    if (!$this->storageStats) {
      return NULL;
    }

    $stats = $this->storageStats->getStatistics();
    if (isset($stats['overall']['vacancy_rate'])) {
      // Return occupancy rate (1 - vacancy).
      return (100 - (float) $stats['overall']['vacancy_rate']) / 100;
    }

    return NULL;
  }

  /**
   * Returns counts of published tools grouped by their status taxonomy term.
   */
  public function getToolStatusCounts(): array {
    $cid = 'makerspace_dashboard:infrastructure:status_counts';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('node_field_data', 'n');
    $query->addExpression("COALESCE(NULLIF(term.name, ''), 'Unspecified')", 'status_label');
    $query->addExpression('COUNT(DISTINCT n.nid)', 'tool_count');
    $query->condition('n.type', 'item');
    $query->condition('n.status', 1);

    $query->innerJoin('node__field_item_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $query->leftJoin('taxonomy_term_field_data', 'term', 'term.tid = status.field_item_status_target_id');

    $query->groupBy('status_label');
    $query->orderBy('tool_count', 'DESC');

    $data = [];
    foreach ($query->execute() as $record) {
      $data[$record->status_label] = (int) $record->tool_count;
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $data, $expire, ['node_list:item', 'taxonomy_term_list']);

    return $data;
  }

  /**
   * Returns a list of tools that have non-operational statuses.
   */
  public function getToolsNeedingAttention(int $limit = 12): array {
    $limit = max(1, $limit);
    $cid = sprintf('makerspace_dashboard:infrastructure:attention:%d', $limit);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid', 'title', 'changed']);
    $query->condition('n.type', 'item');
    $query->condition('n.status', 1);

    $query->innerJoin('node__field_item_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $query->leftJoin('taxonomy_term_field_data', 'term', 'term.tid = status.field_item_status_target_id');
    $query->addField('term', 'name', 'status_label');

    $query->orderBy('n.changed', 'DESC');
    $query->range(0, $limit * 4);

    $attention = [];
    foreach ($query->execute() as $record) {
      $label = trim((string) $record->status_label) ?: 'Unspecified';
      if ($this->isOperationalStatus($label)) {
        continue;
      }
      $attention[] = [
        'nid' => (int) $record->nid,
        'title' => $record->title,
        'status' => $label,
        'changed' => (int) $record->changed,
      ];
      if (count($attention) >= $limit) {
        break;
      }
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $attention, $expire, ['node_list:item', 'taxonomy_term_list']);

    return $attention;
  }

  /**
   * Heuristic to determine whether a status is considered operational.
   */
  protected function isOperationalStatus(string $label): bool {
    $normalized = mb_strtolower($label);
    $operationalIndicators = ['operat', 'available', 'ok', 'ready', 'up', 'active', 'online'];
    foreach ($operationalIndicators as $indicator) {
      if (str_contains($normalized, $indicator)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}

