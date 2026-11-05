<?php

namespace Drupal\makerspace_dashboard\Support;

use Drupal\material_inventory_totals\InventoryOddities;

/**
 * Adds up the store's inventory history into monthly dollars and buyers.
 *
 * Everything is in dollars at today's store price, because units cannot be
 * added across items: a month's "units" would mix cents of machine time, grams
 * of resin and single blades.
 *
 * Works on plain rows, oldest first, keyed by: created, nid, uid, qty, reason,
 * memo, unit_cost. The classifier needs the whole history, even though only
 * the months asked for are added up.
 */
final class StoreTrends {

  /**
   * Adds up the history.
   *
   * @param array $rows
   *   The whole history, oldest first.
   * @param string[] $months
   *   The months wanted, as Y-m, oldest first.
   * @param array $areas
   *   Area names per material ID. An item in several areas is split evenly.
   *
   * @return array
   *   Keyed by:
   *   - months: per Y-m: paid, tab, lost, found (dollars) and buyers (people).
   *   - items: dollars sold per material ID, largest first.
   *   - areas: dollars sold per area name, largest first. Items with no area
   *     are under the empty string.
   *   - buyers: total people, returning (bought in 2+ months).
   */
  public static function summarize(array $rows, array $months, array $areas = []): array {
    $series = array_fill_keys($months, ['paid' => 0.0, 'tab' => 0.0, 'lost' => 0.0, 'found' => 0.0, 'buyers' => 0]);
    $seen = $sales = [];

    foreach ($rows as $row) {
      $nid = (int) $row['nid'];
      $class = InventoryOddities::classify($row, !isset($seen[$nid]));
      $seen[$nid] = TRUE;
      $qty = (float) $row['qty'];

      if ($class === 'sale' && $qty < 0) {
        $sales[$nid][] = $row;
      }
      elseif ($class === 'tab_return') {
        self::cancelSale($sales[$nid], $qty);
      }
      elseif ($class === 'short' || $class === 'over') {
        $month = date('Y-m', (int) $row['created']);
        if (isset($series[$month])) {
          $series[$month][$class === 'short' ? 'lost' : 'found'] += abs($qty) * (float) $row['unit_cost'];
        }
      }
    }

    $names = self::uidsByName($rows);
    $items = $by_area = $bought = [];
    foreach ($sales as $nid => $list) {
      foreach ($list as $row) {
        $month = date('Y-m', (int) $row['created']);
        if (!isset($series[$month])) {
          continue;
        }
        $value = abs((float) $row['qty']) * (float) $row['unit_cost'];
        $series[$month][$row['reason'] === 'unpaid_tab' ? 'tab' : 'paid'] += $value;
        $items[$nid] = ($items[$nid] ?? 0.0) + $value;
        $item_areas = $areas[$nid] ?? [''];
        foreach ($item_areas as $name) {
          $by_area[$name] = ($by_area[$name] ?? 0.0) + $value / count($item_areas);
        }
        $buyer = self::buyerKey($row, $names);
        if ($buyer !== NULL) {
          $bought[$buyer][$month] = TRUE;
        }
      }
    }

    foreach ($bought as $buyer_months) {
      foreach (array_keys($buyer_months) as $month) {
        $series[$month]['buyers']++;
      }
    }
    arsort($items);
    arsort($by_area);
    return [
      'months' => $series,
      'items' => $items,
      'areas' => $by_area,
      'buyers' => [
        'total' => count($bought),
        'returning' => count(array_filter($bought, fn($buyer_months) => count($buyer_months) > 1)),
      ],
    ];
  }

  /**
   * Identifies who made a sale, or NULL when the entry does not say.
   *
   * A store-page sale gives its buyer in the memo, as a user ID or in older
   * ones only "Sold to Name". Its owner is not the buyer: cash box sales are
   * saved under the staff member who entered them. A tab line's owner is.
   */
  public static function buyerKey(array $row, array $names = []): ?string {
    $uid = self::memoUid($row);
    if (!$uid && $row['reason'] === 'unpaid_tab') {
      $uid = (int) $row['uid'];
    }
    if ($uid) {
      return 'uid:' . $uid;
    }
    $name = self::soldToName((string) $row['memo']);
    if ($name === NULL) {
      return NULL;
    }
    return isset($names[$name]) ? 'uid:' . $names[$name] : 'name:' . $name;
  }

  /**
   * The user ID a memo names, or 0.
   */
  private static function memoUid(array $row): int {
    return preg_match('/UID:(\d+)/', (string) $row['memo'], $match) ? (int) $match[1] : 0;
  }

  /**
   * Maps names to users, from memos that give both.
   *
   * Without this, someone counts twice if older sales have only their name.
   */
  private static function uidsByName(array $rows): array {
    $names = [];
    foreach ($rows as $row) {
      $uid = self::memoUid($row);
      $name = self::soldToName((string) $row['memo']);
      if ($uid && $name !== NULL) {
        $names[$name] = $uid;
      }
    }
    return $names;
  }

  /**
   * The buyer's name from a "Sold to Name - ..." memo, in lower case.
   */
  private static function soldToName(string $memo): ?string {
    return preg_match('/Sold to (.+?)\s+[-(]/', $memo, $match) ? mb_strtolower(trim($match[1])) : NULL;
  }

  /**
   * Drops the latest sale a return undoes, so it leaves the month it was in.
   *
   * Stranded tab lines were cleared in bulk months later (April 2026).
   */
  private static function cancelSale(?array &$sales, float $returned): void {
    foreach (array_reverse(array_keys($sales ?? [])) as $key) {
      if (abs(abs((float) $sales[$key]['qty']) - abs($returned)) < 0.0001) {
        unset($sales[$key]);
        return;
      }
    }
  }

}
