<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_dashboard\Unit;

use Drupal\makerspace_dashboard\Support\KpiStatus;
use PHPUnit\Framework\TestCase;

/**
 * The on track / close / behind rule shared by /kpi and the staff briefing.
 *
 * @coversDefaultClass \Drupal\makerspace_dashboard\Support\KpiStatus
 */
class KpiStatusTest extends TestCase {

  /**
   * @dataProvider cases
   */
  public function testStatus($current, $goal, ?string $format, string $direction, float $fraction, ?string $expected): void {
    $this->assertSame($expected, KpiStatus::status($current, $goal, $format, $direction, $fraction));
  }

  /**
   * Values taken from live /kpi on 2026-10-28.
   */
  public static function cases(): array {
    return [
      'members 794 of 845 is within 10%' => [794, 845, NULL, 'higher', 1.0, KpiStatus::WARNING],
      'sponsorships 8334 of 15000' => [8334, 15000, 'currency', 'higher', 1.0, KpiStatus::POOR],
      'uptime above goal' => [0.9978, 0.95, 'percent', 'higher', 1.0, KpiStatus::GOOD],
      'tour conversion 29% vs 35% is within 8 points' => [0.2896, 0.35, 'percent', 'higher', 1.0, KpiStatus::WARNING],
      'percent given as points' => [57, 0.6, 'percent', 'higher', 1.0, KpiStatus::WARNING],
      'ytd goal is pro-rated' => [30000, 58000, 'currency', 'higher', 0.5, KpiStatus::GOOD],
      'lower is better' => [12, 10, NULL, 'lower', 1.0, KpiStatus::POOR],
      'no goal' => [10, NULL, NULL, 'higher', 1.0, NULL],
      'not numeric' => ['TBD', 10, NULL, 'higher', 1.0, NULL],
    ];
  }

  /**
   * Display formats.
   */
  public function testFormat(): void {
    $this->assertSame('29%', KpiStatus::format(0.2896, 'percent'));
    $this->assertSame('104%', KpiStatus::format(1.04, 'percent'));
    $this->assertSame('$15,000', KpiStatus::format(15000, 'currency'));
    $this->assertSame('845', KpiStatus::format(845, NULL));
    $this->assertNull(KpiStatus::format(NULL));
  }

}
