<?php

declare(strict_types=1);

namespace Drupal\makerspace_dashboard\Support;

/**
 * Where a KPI stands against its goal, and how its value is written.
 *
 * One copy of the rule, shared by the /kpi stoplight colors and the Monday
 * staff briefing, so the email can never call a KPI "on track" that the
 * dashboard shows red.
 */
final class KpiStatus {

  public const GOOD = 'good';
  public const WARNING = 'warning';
  public const POOR = 'poor';

  /**
   * Plain-language names for each status.
   */
  public const LABELS = [
    self::GOOD => 'On track',
    self::WARNING => 'Close',
    self::POOR => 'Behind',
  ];

  /**
   * Compares a KPI value with its goal.
   *
   * @param mixed $current
   *   The KPI's value.
   * @param mixed $goal
   *   The goal for the current year.
   * @param string|null $format
   *   The KPI's display format ('percent', 'currency', 'integer', …).
   * @param string $goalDirection
   *   'higher' (default) or 'lower' when less is better.
   * @param float $periodFraction
   *   For accumulating (YTD) KPIs, the fraction of the goal period elapsed
   *   (e.g. 0.25 for Q1). The annual goal is scaled proportionally so that a
   *   KPI on pace for the full year never shows "poor" early in the year.
   *   Pass 1.0 (default) for point-in-time or trailing-period KPIs.
   *
   * @return string|null
   *   GOOD (goal met), WARNING (within 8 points for percentages, 10% for
   *   amounts), POOR, or NULL when there is nothing to compare.
   */
  public static function status($current, $goal, ?string $format = NULL, string $goalDirection = 'higher', float $periodFraction = 1.0): ?string {
    if ($goal === NULL || $current === NULL) {
      return NULL;
    }
    $goalValue = self::normalize($goal, $format);
    $currentValue = self::normalize($current, $format);
    if ($goalValue === NULL || $currentValue === NULL || $goalValue == 0.0) {
      return NULL;
    }

    // Scale the goal proportionally for accumulating YTD KPIs.
    if ($periodFraction > 0.0 && $periodFraction < 1.0) {
      $goalValue = $goalValue * $periodFraction;
    }

    $isPercent = ($format === 'percent') || abs($goalValue) <= 1.5;
    $goalDirection = strtolower(trim($goalDirection));
    $lowerIsBetter = in_array($goalDirection, ['lower', 'down', 'decrease'], TRUE);

    if ($lowerIsBetter) {
      if ($currentValue <= $goalValue) {
        return self::GOOD;
      }
      if ($isPercent) {
        if ($currentValue <= $goalValue + 0.08) {
          return self::WARNING;
        }
      }
      elseif ($currentValue <= ($goalValue * 1.1)) {
        return self::WARNING;
      }
      return self::POOR;
    }

    if ($currentValue >= $goalValue) {
      return self::GOOD;
    }
    if ($isPercent) {
      if ($currentValue >= $goalValue - 0.08) {
        return self::WARNING;
      }
    }
    elseif ($currentValue >= ($goalValue * 0.9)) {
      return self::WARNING;
    }
    return self::POOR;
  }

  /**
   * Normalizes values that may be stored as percentages or raw numbers.
   */
  public static function normalize($value, ?string $format = NULL): ?float {
    if (!is_numeric($value)) {
      return NULL;
    }
    $numeric = (float) $value;
    if ($format === 'percent' && abs($numeric) > 1.5 && abs($numeric) <= 100) {
      return $numeric / 100;
    }
    return $numeric;
  }

  /**
   * Writes a KPI value for display; non-numeric values pass through.
   *
   * @return string|null
   *   NULL for an empty value (callers choose their own "n/a").
   */
  public static function format($value, ?string $format = NULL): ?string {
    if ($value === NULL || $value === '' || $value === 'n/a') {
      return NULL;
    }
    if ($format === 'percent' && is_numeric($value)) {
      // Values with abs > 1.5 are percentage points; anything at or below is
      // a 0-1 ratio. The 1.5 threshold matches normalize() (which drives the
      // stoplight color), so a ratio above 1.0 (e.g. 1.04 = 104%) displays as
      // 104%, not 1.0%.
      $normalized = (float) $value;
      if (abs($normalized) > 1.5) {
        $normalized = $normalized / 100;
      }
      $percentage = $normalized * 100;
      // Near 100% one decimal matters: 99.78% rounded to "100%" read as
      // perfect uptime.
      $decimals = (abs($percentage) < 10 || (abs($percentage) >= 99.5 && abs($percentage) < 100)) ? 1 : 0;
      return number_format($percentage, $decimals) . '%';
    }
    if ($format === 'integer' && is_numeric($value)) {
      return number_format((float) $value, 0);
    }
    if ($format === 'currency' && is_numeric($value)) {
      $float = (float) $value;
      $decimals = abs($float) < 100 ? 2 : 0;
      return ($float < 0 ? '-$' : '$') . number_format(abs($float), $decimals);
    }
    if (is_numeric($value)) {
      $float = (float) $value;
      $abs = abs($float);
      $precision = 0;
      if ($abs < 10) {
        $precision = 2;
      }
      elseif ($abs < 100) {
        $precision = 1;
      }
      return number_format($float, $precision);
    }
    return (string) $value;
  }

}
