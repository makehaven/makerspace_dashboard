<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_dashboard\Unit;

use Drupal\makerspace_dashboard\Support\PublicCounts;
use Drupal\makerspace_dashboard\Support\PublicLocationGrid;
use PHPUnit\Framework\TestCase;

/**
 * The rules that keep the public dashboard from pointing at a person.
 *
 * @coversDefaultClass \Drupal\makerspace_dashboard\Support\PublicLocationGrid
 */
class PublicPrivacyTest extends TestCase {

  /**
   * A lone member is never published, however precise the input.
   */
  public function testLoneMemberIsWithheld(): void {
    $grid = PublicLocationGrid::aggregate([['lat' => 41.30712, 'lon' => -72.92345]]);
    $this->assertSame([], $grid['locations']);
    $this->assertSame(0, $grid['shown']);
    $this->assertSame(1, $grid['withheld']);
  }

  /**
   * Published squares are centred on the grid, whatever the inputs were.
   */
  public function testOutputDoesNotDependOnExactCoordinates(): void {
    $a = [];
    $b = [];
    for ($i = 0; $i < 5; $i++) {
      $a[] = ['lat' => 41.3010 + ($i / 1000), 'lon' => -72.9310 + ($i / 1000)];
      $b[] = ['lat' => 41.3150 - ($i / 1000), 'lon' => -72.9220 - ($i / 1000)];
    }
    $first = PublicLocationGrid::aggregate($a);
    $this->assertSame($first['locations'], PublicLocationGrid::aggregate($b)['locations']);
    $this->assertCount(1, $first['locations']);
    $this->assertSame(5, $first['locations'][0]['count']);
    $this->assertEqualsWithDelta(41.31, $first['locations'][0]['lat'], 0.0001);
    $this->assertEqualsWithDelta(-72.93, $first['locations'][0]['lon'], 0.0001);
  }

  /**
   * Small squares roll up; every published figure meets the minimum.
   */
  public function testSmallSquaresRollUp(): void {
    $points = [];
    // Five members spread over different fine squares of one coarse square.
    foreach ([[41.31, -72.91], [41.33, -72.93], [41.35, -72.95], [41.37, -72.97], [41.39, -72.99]] as [$lat, $lon]) {
      $points[] = ['lat' => $lat, 'lon' => $lon];
    }
    $grid = PublicLocationGrid::aggregate($points);
    $this->assertCount(1, $grid['locations']);
    $this->assertSame(5, $grid['locations'][0]['count']);
    $this->assertSame(0, $grid['withheld']);
    $this->assertEqualsWithDelta(41.35, $grid['locations'][0]['lat'], 0.0001);
  }

  /**
   * A geocoded town group counts by its size and bad points are ignored.
   */
  public function testWeightedAndInvalidPoints(): void {
    $grid = PublicLocationGrid::aggregate([
      ['lat' => 41.31, 'lon' => -72.92, 'count' => 7],
      ['lat' => NAN, 'lon' => -72.92],
      ['lat' => NULL, 'lon' => NULL],
    ]);
    $this->assertSame(7, $grid['shown']);
    $this->assertSame(7, $grid['locations'][0]['count']);
  }

  /**
   * @covers \Drupal\makerspace_dashboard\Support\PublicCounts::rollUp
   */
  public function testRollUp(): void {
    $this->assertSame(
      ['White' => 22, 'Unspecified' => 6, 'Combined' => 9],
      PublicCounts::rollUp(['White' => 22, 'Unspecified' => 6, 'Asian' => 4, 'Black' => 3, 'Hispanic' => 2], 'Combined')
    );
    $this->assertSame(['A' => 5, 'B' => 0], PublicCounts::rollUp(['A' => 5, 'B' => 0], 'Combined'));
  }

  /**
   * @covers \Drupal\makerspace_dashboard\Support\PublicCounts::rollUpColumns
   */
  public function testRollUpColumns(): void {
    $result = PublicCounts::rollUpColumns([
      'White' => [224, 10],
      'Black' => [27, 1],
      'Pacific' => [2, 1],
    ]);
    $this->assertSame(['White' => [224, 10], 'Black' => [27, 0]], $result['series']);
    $this->assertSame([2, 2], $result['combined']);
  }

  /**
   * @covers \Drupal\makerspace_dashboard\Support\PublicCounts::isSensitiveEndReason
   */
  public function testSensitiveEndReason(): void {
    $this->assertTrue(PublicCounts::isSensitiveEndReason('removed'));
    $this->assertTrue(PublicCounts::isSensitiveEndReason('Disciplinary Removal'));
    $this->assertFalse(PublicCounts::isSensitiveEndReason('Relocation'));
    $this->assertFalse(PublicCounts::isSensitiveEndReason('other'));
  }

}
