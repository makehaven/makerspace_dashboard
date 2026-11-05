<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_dashboard\Unit;

use Drupal\makerspace_dashboard\Support\StoreTrends;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\makerspace_dashboard\Support\StoreTrends
 * @group makerspace_dashboard
 */
class StoreTrendsTest extends UnitTestCase {

  /**
   * The months in these tests.
   */
  private const MONTHS = ['2026-03', '2026-04'];

  /**
   * Builds one history row.
   */
  private function row(string $date, float $qty, string $reason, string $memo = '', float $cost = 1.0, int $nid = 1, int $uid = 0): array {
    return [
      'created' => strtotime($date . ' 12:00:00'),
      'nid' => $nid,
      'uid' => $uid,
      'qty' => $qty,
      'reason' => $reason,
      'memo' => $memo,
      'unit_cost' => $cost,
    ];
  }

  /**
   * Sales split by how they were paid, in dollars at store price.
   */
  public function testSalesSplitByHowPaid(): void {
    $found = StoreTrends::summarize([
      $this->row('2026-03-01', 100, 'restock', 'Initial inventory', 2.5),
      $this->row('2026-03-02', -4, 'sale', '', 2.5),
      $this->row('2026-03-03', -2, 'unpaid_tab', '', 2.5),
      $this->row('2026-04-03', -1, 'sale', '', 2.5),
    ], self::MONTHS);
    $this->assertEqualsWithDelta(10.0, $found['months']['2026-03']['paid'], 0.001);
    $this->assertEqualsWithDelta(5.0, $found['months']['2026-03']['tab'], 0.001);
    $this->assertEqualsWithDelta(2.5, $found['months']['2026-04']['paid'], 0.001);
    $this->assertEqualsWithDelta(17.5, $found['items'][1], 0.001);
  }

  /**
   * Months outside the window are left out of everything.
   */
  public function testOutsideTheWindow(): void {
    $found = StoreTrends::summarize([
      $this->row('2026-01-01', 100, 'restock', 'Initial inventory'),
      $this->row('2026-02-02', -4, 'sale', '[UID:7] Sold to Someone - x'),
      $this->row('2026-02-03', -5, 'lossage'),
    ], self::MONTHS);
    $this->assertSame([], $found['items']);
    $this->assertSame(0, $found['buyers']['total']);
    $this->assertSame(0.0, $found['months']['2026-03']['lost']);
  }

  /**
   * A tab line cleared months later leaves the month it was added in.
   *
   * Stranded tab lines were cleared in bulk in April 2026. Taking them off the
   * month of the clearing drove that month's tab sales below zero.
   */
  public function testReturnCancelsTheOriginalSale(): void {
    $found = StoreTrends::summarize([
      $this->row('2026-03-01', 100, 'restock', 'Initial inventory'),
      $this->row('2026-03-02', -5, 'unpaid_tab', 'Added to tab by user 9', 1.0, 1, 9),
      $this->row('2026-04-29', 5, 'other', 'Not a restock: stock returned when a stranded tab line was cleared'),
    ], self::MONTHS);
    $this->assertSame(0.0, $found['months']['2026-03']['tab']);
    $this->assertSame(0.0, $found['months']['2026-04']['tab']);
    $this->assertSame([], $found['items']);
    $this->assertSame(0, $found['buyers']['total']);
  }

  /**
   * Counts add up short and high; zero-outs are neither.
   */
  public function testLostAndFound(): void {
    $found = StoreTrends::summarize([
      $this->row('2026-03-01', 100, 'restock', 'Initial inventory', 2.0),
      $this->row('2026-03-02', -3, 'lossage', '', 2.0),
      $this->row('2026-03-03', 4, 'count_found', '', 2.0),
      $this->row('2026-03-04', 251, 'other', 'Zeroing out following inventory', 2.0),
      $this->row('2026-03-05', 0, 'verification', '', 2.0),
    ], self::MONTHS);
    $this->assertEqualsWithDelta(6.0, $found['months']['2026-03']['lost'], 0.001);
    $this->assertEqualsWithDelta(8.0, $found['months']['2026-03']['found'], 0.001);
  }

  /**
   * An item in several areas is split, and one with none is under ''.
   */
  public function testAreas(): void {
    $found = StoreTrends::summarize([
      $this->row('2026-03-01', 100, 'restock', 'Initial inventory', 1.0, 1),
      $this->row('2026-03-02', -10, 'sale', '', 1.0, 1),
      $this->row('2026-03-01', 100, 'restock', 'Initial inventory', 1.0, 2),
      $this->row('2026-03-02', -3, 'sale', '', 1.0, 2),
    ], self::MONTHS, [1 => ['Wood', 'Metal']]);
    $this->assertEqualsWithDelta(5.0, $found['areas']['Wood'], 0.001);
    $this->assertEqualsWithDelta(5.0, $found['areas']['Metal'], 0.001);
    $this->assertEqualsWithDelta(3.0, $found['areas'][''], 0.001);
  }

  /**
   * People are counted once a month, and returning means 2+ months.
   */
  public function testBuyers(): void {
    $found = StoreTrends::summarize([
      $this->row('2026-03-01', 100, 'restock', 'Initial inventory'),
      $this->row('2026-03-02', -1, 'unpaid_tab', 'Added to tab by user 9', 1.0, 1, 9),
      $this->row('2026-03-03', -1, 'unpaid_tab', 'Added to tab by user 9', 1.0, 1, 9),
      $this->row('2026-04-03', -1, 'sale', '[UID:9] Sold to Pat Doe - paypal'),
      $this->row('2026-04-04', -1, 'sale', 'Sold to Lee Roe - paypal'),
    ], self::MONTHS);
    $this->assertSame(1, $found['months']['2026-03']['buyers']);
    $this->assertSame(2, $found['months']['2026-04']['buyers']);
    $this->assertSame(['total' => 2, 'returning' => 1], $found['buyers']);
  }

  /**
   * An older name-only sale joins the same person's later sales.
   */
  public function testNameOnlySaleJoinsTheUser(): void {
    $found = StoreTrends::summarize([
      $this->row('2026-03-01', 100, 'restock', 'Initial inventory'),
      $this->row('2026-03-02', -1, 'sale', 'Sold to Pat Doe - paypal'),
      $this->row('2026-04-03', -1, 'sale', '[UID:9] Sold to Pat Doe - paypal'),
    ], self::MONTHS);
    $this->assertSame(['total' => 1, 'returning' => 1], $found['buyers']);
  }

  /**
   * A cash box entry belongs to whoever typed it in, not a buyer.
   */
  public function testCashBoxEntryHasNoBuyer(): void {
    $this->assertNull(StoreTrends::buyerKey($this->row('2026-03-02', -1, 'sale', 'Dec cashbox', 1.0, 1, 33)));
    $this->assertSame('uid:33', StoreTrends::buyerKey($this->row('2026-03-02', -1, 'unpaid_tab', 'Added to tab by user 33', 1.0, 1, 33)));
  }

}
