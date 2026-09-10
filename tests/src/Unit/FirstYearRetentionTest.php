<?php

namespace Drupal\Tests\makerspace_dashboard\Unit;

use Drupal\makerspace_dashboard\Support\FirstYearRetention;
use PHPUnit\Framework\TestCase;

/**
 * Historical cohorts must not depend on login status or calendar-month shortcuts.
 */
class FirstYearRetentionTest extends TestCase {

  public function testEvidenceAnniversaryAndExclusions(): void {
    $rows = [
      $this->member(1, '2025-08-25', '2026-08-10'),
      $this->member(2, '2025-08-25', '2026-08-25'),
      $this->member(3, '2025-08-25', '', FALSE),
      $this->member(4, '2025-09-01'),
      $this->member(5, '2025-08-01', '2026-08-01', FALSE),
      $this->member(6, '2025-08-01', '2026-07-01', FALSE, 'relocation'),
      $this->member(7, '2025-08-01', '', TRUE, '', 842),
      $this->member(8, '2025-08-01', 'invalid'),
    ];
    $rows[] = $rows[0];
    $result = FirstYearRetention::calculate($rows, new \DateTimeImmutable('2026-09-09'), 12, ['relocation']);
    $this->assertCount(1, $result);
    $this->assertSame(3, $result[0]['total']);
    $this->assertSame(2, $result[0]['retained']);
    $this->assertSame(66.67, $result[0]['retention_percent']);
    $this->assertSame('2026-08-31', $result[0]['evaluation_date']);
    $this->assertSame(3, $result[0]['standard_total']);
  }

  public function testLeapAnniversaryClampsAndPooledRateWeightsMembers(): void {
    $this->assertSame('2025-02-28', FirstYearRetention::anniversary(new \DateTimeImmutable('2024-02-29'))->format('Y-m-d'));
    $this->assertSame(10.0, FirstYearRetention::pooledRate([
      ['total' => 1, 'retained' => 1], ['total' => 9, 'retained' => 0],
    ]));
    $this->assertNull(FirstYearRetention::pooledRate([]));
  }

  private function member(int $uid, string $join, string $end = '', bool $role = TRUE, string $reason = '', int $type = 716): object {
    return (object) ['uid' => $uid, 'created' => strtotime($join), 'end_date_value' => $end, 'has_member_role' => $role, 'end_reason_value' => $reason, 'membership_type_id' => $type];
  }

}
