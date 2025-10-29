<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

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
   * Cache lifetime in seconds.
   */
  protected int $ttl;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, int $ttl = 600) {
    $this->database = $database;
    $this->cache = $cache;
    $this->ttl = $ttl;
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

