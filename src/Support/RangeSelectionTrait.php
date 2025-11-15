<?php

namespace Drupal\makerspace_dashboard\Support;

/**
 * Provides reusable helpers for charts with selectable date ranges.
 */
trait RangeSelectionTrait {

  /**
   * Returns translated range presets restricted to allowed keys.
   */
  protected function getRangePresets(?array $allowedKeys = NULL): array {
    $presets = [];
    foreach (RangeConfig::definitions() as $key => $info) {
      if ($allowedKeys !== NULL && !in_array($key, $allowedKeys, TRUE)) {
        continue;
      }
      $presets[$key] = [
        'label' => $this->t($info['label']),
        'modifier' => $info['modifier'],
      ];
    }
    return $presets;
  }

  /**
   * Resolves a selected range key from request filters.
   */
  protected function resolveSelectedRange(array $filters, string $chartId, string $defaultRange, array $allowedRanges): string {
    $definitions = RangeConfig::definitions();
    $allowed = array_values(array_filter($allowedRanges, static fn($key) => isset($definitions[$key])));
    if (!$allowed) {
      $allowed = array_keys($definitions);
    }

    $default = in_array($defaultRange, $allowed, TRUE) ? $defaultRange : reset($allowed);
    $requested = $filters['ranges'][$chartId] ?? ($filters['range'] ?? NULL);
    if (is_string($requested) && in_array($requested, $allowed, TRUE)) {
      return $requested;
    }
    return $default;
  }

  /**
   * Calculates range boundaries for the supplied preset key.
   */
  protected function calculateRangeBounds(string $rangeKey, \DateTimeImmutable $endDate): array {
    $definitions = RangeConfig::definitions();
    $definition = $definitions[$rangeKey] ?? $definitions['1y'];
    $modifier = $definition['modifier'];

    $end = $endDate;
    $start = $modifier ? $end->modify($modifier) : NULL;

    return [
      'start' => $start,
      'end' => $end,
    ];
  }

}
