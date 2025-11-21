<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Provides aggregated metrics for the store/material inventory system.
 */
class StoreInventoryData {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected TimeInterface $time;

  protected \DateTimeZone $timezone;

  /**
   * Constructs the service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, TimeInterface $time) {
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
   * Builds a month-over-month sales vs. consumption velocity dataset.
   */
  public function getSalesVelocitySeries(int $months = 12): array {
    if ($months <= 0 || !$this->entityTypeManager->hasDefinition('material_inventory')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('material_inventory');
    if (!$storage instanceof EntityStorageInterface) {
      return [];
    }

    $window = $this->getMonthSeriesWindow($months);
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'inventory_adjustment')
      ->condition('created', $window['start']->getTimestamp(), '>=')
      ->sort('created', 'ASC');
    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    $series = [];
    for ($i = 0; $i < $months; $i++) {
      $month_start = $window['start']->modify("+$i months");
      $key = $month_start->format('Y-m');
      $series[$key] = [
        'label' => $month_start->format('M Y'),
        'restock' => 0,
        'consumption' => 0,
      ];
    }

    $end = $window['end'];
    foreach ($storage->loadMultiple($ids) as $entity) {
      $created = $entity->getCreatedTime();
      $created_date = (new \DateTimeImmutable('@' . $created))->setTimezone($this->timezone);
      if ($created_date < $window['start'] || $created_date >= $end) {
        continue;
      }
      $key = $created_date->format('Y-m');
      if (!isset($series[$key])) {
        continue;
      }

      $quantity = 0;
      if ($entity->hasField('field_inventory_quantity_change') && !$entity->get('field_inventory_quantity_change')->isEmpty()) {
        $quantity = (int) $entity->get('field_inventory_quantity_change')->value;
      }
      if ($quantity === 0) {
        continue;
      }

      if ($quantity > 0) {
        $series[$key]['restock'] += $quantity;
      }
      else {
        $series[$key]['consumption'] += abs($quantity);
      }
    }

    return array_values($series);
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
