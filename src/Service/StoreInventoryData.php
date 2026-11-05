<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\makerspace_dashboard\Support\StoreTrends;
use Drupal\material_inventory_totals\InventoryOddities;
use Drupal\node\NodeInterface;

/**
 * Provides aggregated metrics for the store/material inventory system.
 */
class StoreInventoryData {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected TimeInterface $time;

  protected \DateTimeZone $timezone;

  /**
   * Trends already worked out this request, by number of months.
   *
   * Several charts read them and each scan covers the whole history.
   */
  protected array $trends = [];

  /**
   * Constructs the service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    TimeInterface $time,
    protected Connection $database,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->time = $time;
    $this->timezone = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
  }

  /**
   * Provides snapshot totals for inventory counts and value.
   */
  public function getInventorySnapshot(): array {
    $materials = $this->loadMaterials();
    $totals = [
      'item_count' => count($materials),
      'unit_count' => 0,
      'inventory_value' => 0.0,
    ];

    foreach ($materials as $material) {
      $totals['unit_count'] += $this->getInventoryCount($material);
      $totals['inventory_value'] += $this->getInventoryValue($material);
    }

    $totals['inventory_value'] = round($totals['inventory_value'], 2);
    return $totals;
  }

  /**
   * Builds a dataset describing top categories by inventory value.
   */
  public function getCategoryContribution(int $limit = 8): array {
    $materials = $this->loadMaterials();
    if (!$materials) {
      return [];
    }

    $totals = [];
    foreach ($materials as $material) {
      $value = $this->getInventoryValue($material);
      if ($value <= 0) {
        continue;
      }

      $terms = [];
      if ($material->hasField('field_material_categories') && !$material->get('field_material_categories')->isEmpty()) {
        foreach ($material->get('field_material_categories') as $item) {
          $tid = (int) $item->target_id;
          if ($tid) {
            $terms[] = $tid;
          }
        }
      }

      if ($terms) {
        $share = $value / count($terms);
        foreach ($terms as $tid) {
          $totals[$tid] = ($totals[$tid] ?? 0) + $share;
        }
      }
      else {
        $totals['_uncategorized'] = ($totals['_uncategorized'] ?? 0) + $value;
      }
    }

    $overall = array_sum($totals);
    if ($overall <= 0) {
      return [];
    }

    arsort($totals);
    $term_ids = array_filter(array_keys($totals), static fn($key) => is_int($key));
    $terms = $term_ids ? $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($term_ids) : [];

    $dataset = [];
    foreach ($totals as $key => $value) {
      $label = $key === '_uncategorized'
        ? 'Uncategorized'
        : (isset($terms[$key]) ? $terms[$key]->label() : 'Unknown');

      $dataset[] = [
        'label' => (string) $label,
        'value' => round($value, 2),
        'share' => round($value / $overall, 4),
        'is_uncategorized' => $key === '_uncategorized',
      ];

      if (count($dataset) >= $limit) {
        break;
      }
    }

    return $dataset;
  }

  /**
   * Monthly store dollars, buyers and top sellers for the Store tab.
   *
   * @return array
   *   What StoreTrends::summarize() returns, plus labels: month labels keyed by
   *   Y-m, and titles: item titles keyed by material ID. Empty when the
   *   inventory module is missing or nothing has been recorded.
   */
  public function getTrends(int $months = 12): array {
    if (isset($this->trends[$months])) {
      return $this->trends[$months];
    }
    $this->trends[$months] = [];
    if (!class_exists(InventoryOddities::class) || !$this->entityTypeManager->hasDefinition('material_inventory')) {
      return [];
    }
    $rows = $this->historyRows();
    if (!$rows) {
      return [];
    }

    $window = $this->getMonthSeriesWindow($months);
    $labels = [];
    for ($i = 0; $i < $months; $i++) {
      $month = $window['start']->modify("+$i months");
      $labels[$month->format('Y-m')] = $month->format('M Y');
    }
    $trends = StoreTrends::summarize($rows, array_keys($labels), $this->materialAreas());
    $trends['labels'] = $labels;
    $trends['titles'] = $this->materialTitles(array_keys($trends['items']));
    return $this->trends[$months] = $trends;
  }

  /**
   * Loads the adjustment history, oldest first, with today's store price.
   *
   * Long field names have hashed table names, so these go through the table
   * mapping. Loading entities would be far too slow on a public page.
   */
  protected function historyRows(): array {
    $mapping = $this->entityTypeManager->getStorage('material_inventory')->getTableMapping();
    $definitions = $this->entityFieldManager->getFieldStorageDefinitions('material_inventory');
    $query = $this->database->select('material_inventory_field_data', 'a')
      ->condition('a.type', 'inventory_adjustment')
      ->fields('a', ['id', 'created', 'uid'])
      ->orderBy('a.id');
    $join = function (string $method, string $field, string $property, string $alias, string $as) use ($query, $mapping, $definitions): void {
      $query->{$method}($mapping->getFieldTableName($field), $alias, "$alias.entity_id = a.id");
      $query->addField($alias, $mapping->getFieldColumnName($definitions[$field], $property), $as);
    };
    $join('join', 'field_inventory_ref_material', 'target_id', 'material', 'nid');
    $join('join', 'field_inventory_quantity_change', 'value', 'quantity', 'qty');
    $join('leftJoin', 'field_inventory_change_reason', 'value', 'reason', 'reason');
    $join('leftJoin', 'field_inventory_change_memo', 'value', 'memo', 'memo');
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $prices = $this->database->select('node__field_material_unit_cost', 'c')
      ->fields('c', ['entity_id', 'field_material_unit_cost_value'])
      ->execute()
      ->fetchAllKeyed();
    foreach ($rows as &$row) {
      $row['unit_cost'] = (float) ($prices[$row['nid']] ?? 0);
    }
    return $rows;
  }

  /**
   * The top-level areas each material is tagged with.
   *
   * Items are often tagged with an area and its parent ("Woodworking" and
   * "Wood, Metal & Fabrication"). Rolling up counts those once.
   *
   * @return array
   *   Area names per material ID.
   */
  protected function materialAreas(): array {
    $query = $this->database->select('taxonomy_term__parent', 'p');
    $query->join('taxonomy_term_field_data', 't', 't.tid = p.entity_id');
    $query->fields('p', ['entity_id', 'parent_target_id'])->fields('t', ['name']);
    $terms = $query->execute()->fetchAllAssoc('entity_id', \PDO::FETCH_ASSOC);

    $areas = [];
    $tagged = $this->database->select('node__field_material_categories', 'c')
      ->fields('c', ['entity_id', 'field_material_categories_target_id'])
      ->execute();
    foreach ($tagged as $row) {
      $tid = (int) $row->field_material_categories_target_id;
      for ($depth = 0; $depth < 10 && !empty($terms[$tid]['parent_target_id']); $depth++) {
        $tid = (int) $terms[$tid]['parent_target_id'];
      }
      if (isset($terms[$tid])) {
        $areas[(int) $row->entity_id][$terms[$tid]['name']] = $terms[$tid]['name'];
      }
    }
    return array_map('array_values', $areas);
  }

  /**
   * Titles of materials, keyed by ID.
   */
  protected function materialTitles(array $nids): array {
    if (!$nids) {
      return [];
    }
    return $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid', 'title'])
      ->condition('n.nid', $nids, 'IN')
      ->execute()
      ->fetchAllKeyed();
  }

  /**
   * Loads published material nodes keyed by id.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Loaded materials.
   */
  protected function loadMaterials(): array {
    if (!$this->entityTypeManager->hasDefinition('node')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'material')
      ->condition('status', 1);
    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    $nodes = $storage->loadMultiple($ids);
    return array_filter($nodes, static fn($node) => $node instanceof NodeInterface);
  }

  /**
   * Gets the cached inventory count for a material.
   */
  protected function getInventoryCount(NodeInterface $material): int {
    if ($material->hasField('field_material_inventory_count') && !$material->get('field_material_inventory_count')->isEmpty()) {
      $raw = $material->get('field_material_inventory_count')->value;
      return (int) $raw;
    }
    return 0;
  }

  /**
   * Gets the cached inventory value for a material.
   */
  protected function getInventoryValue(NodeInterface $material): float {
    if ($material->hasField('field_material_inventory_value') && !$material->get('field_material_inventory_value')->isEmpty()) {
      $raw = $material->get('field_material_inventory_value')->value;
      return is_numeric($raw) ? (float) $raw : 0.0;
    }
    return 0.0;
  }

  /**
   * Determines the rolling window used for month-based series.
   */
  protected function getMonthSeriesWindow(int $months): array {
    $end_of_month = $this->now()->modify('first day of this month')->setTime(0, 0);
    $start = $end_of_month->modify('-' . ($months - 1) . ' months');
    $end = $start->modify("+$months months");
    return [
      'start' => $start,
      'end' => $end,
    ];
  }

  /**
   * Returns the current timestamp with timezone applied.
   */
  protected function now(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($this->timezone);
  }

}
