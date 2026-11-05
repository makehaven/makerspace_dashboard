<?php

declare(strict_types=1);

namespace Drupal\makerspace_dashboard\Support;

/**
 * Small-count rules for charts on the public dashboard.
 *
 * A small count matters when the group is known or the count is crossed with
 * something else: instructors are named on the site, and "the one member of
 * this group in this town" points at a person. A small count in an anonymous
 * crowd, such as registrations by age band, stays as it is.
 */
final class PublicCounts {

  /**
   * Fewest people a published category may represent.
   */
  public const MINIMUM = 5;

  /**
   * Moves categories under the minimum into one combined category.
   *
   * @param array<string, int> $counts
   *   Counts keyed by label.
   * @param string $combinedLabel
   *   Label for the combined category.
   *
   * @return array<string, int>
   *   Counts with small categories combined, combined category last.
   */
  public static function rollUp(array $counts, string $combinedLabel): array {
    $kept = [];
    $combined = 0;
    foreach ($counts as $label => $count) {
      if ($count > 0 && $count < self::MINIMUM) {
        $combined += $count;
      }
      else {
        $kept[$label] = $count;
      }
    }
    if ($combined > 0) {
      $kept[$combinedLabel] = ($kept[$combinedLabel] ?? 0) + $combined;
    }
    return $kept;
  }

  /**
   * Applies the minimum to a stacked chart, one column at a time.
   *
   * @param array<string, array<int, int>> $series
   *   Values per column, keyed by series label.
   *
   * @return array{series: array<string, array<int, int>>, combined: array<int, int>}
   *   Series with small cells zeroed and emptied series dropped, plus the
   *   combined total of the removed cells per column.
   */
  public static function rollUpColumns(array $series): array {
    $combined = [];
    foreach ($series as $label => $values) {
      foreach ($values as $column => $value) {
        $combined[$column] ??= 0;
        if ($value > 0 && $value < self::MINIMUM) {
          $combined[$column] += $value;
          $series[$label][$column] = 0;
        }
      }
      if (!array_filter($series[$label])) {
        unset($series[$label]);
      }
    }
    return ['series' => $series, 'combined' => $combined];
  }

  /**
   * Whether an end reason would single someone out in the community.
   *
   * Members know who was removed, so this is never shown under its own name,
   * whatever the count. Takes either the stored key or the label of
   * field_member_end_reason ("removed" / "Disciplinary Removal").
   */
  public static function isSensitiveEndReason(string $reason): bool {
    $reason = mb_strtolower(trim($reason));
    return $reason === 'removed' || str_contains($reason, 'disciplin');
  }

}
