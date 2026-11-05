<?php

declare(strict_types=1);

namespace Drupal\makerspace_dashboard\Support;

/**
 * Turns member coordinates into area totals that are safe to publish.
 *
 * The location feeds are public. Each point here is a member's home, so a feed
 * may only say "at least N members live around here". Points snap to the centre
 * of a grid square, squares under the minimum roll up into a larger square, and
 * what is still under the minimum is withheld. Nothing derived from an
 * individual's coordinates is ever published.
 *
 * Do not add jitter instead: the earlier version seeded it from the coordinate,
 * so anyone could reverse it to a cell of about 110 m.
 */
final class PublicLocationGrid {

  /**
   * Fewest members a published square may represent.
   */
  public const MINIMUM_COUNT = 5;

  /**
   * Fine square, in degrees: about 2.2 km by 1.7 km in Connecticut.
   */
  public const FINE_DEGREES = 0.02;

  /**
   * Roll-up square, in degrees: about 11 km by 8 km in Connecticut.
   */
  public const COARSE_DEGREES = 0.1;

  /**
   * Aggregates points into publishable squares.
   *
   * @param array<int, array{lat: float, lon: float, count?: int}> $points
   *   Raw points. These must never leave this method.
   *
   * @return array{locations: array<int, array{lat: float, lon: float, count: int}>, shown: int, withheld: int}
   *   Squares to publish, and how many members they do and do not cover.
   */
  public static function aggregate(array $points): array {
    $fine = [];
    foreach ($points as $point) {
      $lat = $point['lat'] ?? NULL;
      $lon = $point['lon'] ?? NULL;
      if (!is_numeric($lat) || !is_numeric($lon) || !is_finite((float) $lat) || !is_finite((float) $lon)) {
        continue;
      }
      $key = self::cellKey((float) $lat, (float) $lon, self::FINE_DEGREES);
      $fine[$key] ??= ['count' => 0, 'points' => []];
      $count = (int) ($point['count'] ?? 1);
      $fine[$key]['count'] += $count;
      $fine[$key]['points'][] = [(float) $lat, (float) $lon, $count];
    }

    $locations = [];
    $shown = 0;
    $coarse = [];
    foreach ($fine as $key => $cell) {
      if ($cell['count'] >= self::MINIMUM_COUNT) {
        $locations[] = self::cell($key, self::FINE_DEGREES, $cell['count']);
        $shown += $cell['count'];
        continue;
      }
      foreach ($cell['points'] as [$lat, $lon, $count]) {
        $coarseKey = self::cellKey($lat, $lon, self::COARSE_DEGREES);
        $coarse[$coarseKey] = ($coarse[$coarseKey] ?? 0) + $count;
      }
    }

    $withheld = 0;
    foreach ($coarse as $key => $count) {
      if ($count >= self::MINIMUM_COUNT) {
        $locations[] = self::cell($key, self::COARSE_DEGREES, $count);
        $shown += $count;
      }
      else {
        $withheld += $count;
      }
    }

    return ['locations' => $locations, 'shown' => $shown, 'withheld' => $withheld];
  }

  /**
   * Builds the key of the square that holds a coordinate.
   */
  private static function cellKey(float $lat, float $lon, float $size): string {
    return (int) floor($lat / $size) . '|' . (int) floor($lon / $size);
  }

  /**
   * Builds a published square, located at its centre.
   */
  private static function cell(string $key, float $size, int $count): array {
    [$latIndex, $lonIndex] = array_map('intval', explode('|', $key));
    return [
      'lat' => round(($latIndex + 0.5) * $size, 3),
      'lon' => round(($lonIndex + 0.5) * $size, 3),
      'count' => $count,
    ];
  }

}
