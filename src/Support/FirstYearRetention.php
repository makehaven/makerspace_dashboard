<?php

namespace Drupal\makerspace_dashboard\Support;

/**
 * Evaluates completed join cohorts at each member's calendar anniversary.
 */
final class FirstYearRetention {

  /**
   * Returns a calendar anniversary, clamping leap day to February's last day.
   */
  public static function anniversary(\DateTimeImmutable $join): \DateTimeImmutable {
    $year = (int) $join->format('Y') + 1;
    $month = (int) $join->format('n');
    $first = $join->setDate($year, $month, 1)->setTime(0, 0);
    return $first->setDate($year, $month, min((int) $join->format('j'), (int) $first->format('t')));
  }

  /**
   * Builds counts shared by the blended and membership-type charts.
   */
  public static function calculate(array $rows, \DateTimeImmutable $now, int $months, array $excludedReasons): array {
    $last = $now->modify('first day of this month')->setTime(0, 0)->modify('-13 months');
    $first = $last->modify('-' . (max(1, $months) - 1) . ' months');
    $buckets = [];
    $seen = [];
    foreach ($rows as $row) {
      $uid = (int) $row->uid;
      if (isset($seen[$uid]) || (int) $row->created <= 0) {
        continue;
      }
      $seen[$uid] = TRUE;
      $join = (new \DateTimeImmutable('@' . $row->created))->setTimezone($now->getTimezone());
      if ($join < $first || $join >= $last->modify('+1 month')) {
        continue;
      }
      $endValue = trim((string) ($row->end_date_value ?? ''));
      // A profile alone does not establish that membership ever began.
      if (empty($row->has_member_role) && $endValue === '') {
        continue;
      }
      $end = NULL;
      if ($endValue !== '') {
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $endValue, $now->getTimezone());
        if (!$end || $end->format('Y-m-d') !== $endValue || $end < $join->setTime(0, 0)) {
          continue;
        }
      }
      $anniversary = self::anniversary($join);
      $reason = strtolower(trim((string) ($row->end_reason_value ?? '')));
      $type = (int) ($row->membership_type_id ?? 0);
      if ($type === 842 || ($end && $end < $anniversary && in_array($reason, $excludedReasons, TRUE))) {
        continue;
      }
      $key = $join->format('Y-m-01');
      $buckets[$key] ??= [
        'period' => $key,
        'label' => $join->format('M Y'),
        'evaluation_date' => $join->modify('first day of this month')->modify('+1 year')->modify('last day of this month')->format('Y-m-d'),
        'total' => 0,
        'retained' => 0,
        'standard_total' => 0,
        'standard_retained' => 0,
        'sliding_total' => 0,
        'sliding_retained' => 0,
      ];
      // End dates are inclusive: membership lasts through that calendar day.
      $retained = $end === NULL || $end >= $anniversary;
      $buckets[$key]['total']++;
      $buckets[$key]['retained'] += (int) $retained;
      $prefix = match ($type) {716 => 'standard', 718 => 'sliding', default => NULL
      };
      if ($prefix !== NULL) {
        $buckets[$key][$prefix . '_total']++;
        $buckets[$key][$prefix . '_retained'] += (int) $retained;
      }
    }
    ksort($buckets);
    foreach ($buckets as &$bucket) {
      $bucket['retention_percent'] = round(100 * $bucket['retained'] / $bucket['total'], 2);
      foreach (['standard', 'sliding'] as $prefix) {
        $bucket[$prefix . '_percent'] = $bucket[$prefix . '_total'] > 0
          ? round(100 * $bucket[$prefix . '_retained'] / $bucket[$prefix . '_total'], 2) : NULL;
      }
    }
    return array_values($buckets);
  }

  /**
   * Pools member counts instead of giving small and large cohorts equal weight.
   */
  public static function pooledRate(array $rows): ?float {
    $total = array_sum(array_column($rows, 'total'));
    return $total > 0 ? round(100 * array_sum(array_column($rows, 'retained')) / $total, 2) : NULL;
  }

}
