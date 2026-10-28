<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_dashboard\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\makerspace_dashboard\Service\StaffBriefingService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * When a staff-briefing number is flagged, and when it is left alone.
 *
 * @group makerspace_dashboard
 * @coversDefaultClass \Drupal\makerspace_dashboard\Service\StaffBriefingService
 */
class StaffBriefingLineTest extends UnitTestCase {

  /**
   * A service whose protected line() can be called directly.
   */
  protected function service(): object {
    return new class(
      $this->createMock(Connection::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(StateInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(MailManagerInterface::class),
      $this->createMock(LoggerInterface::class),
    ) extends StaffBriefingService {

      public function lineFor(array $metric, array $series): array {
        return $this->line($metric, $series);
      }

    };
  }

  /**
   * A count inside its 8-week range is shown without a flag.
   */
  public function testCountInsideRangeIsNotFlagged(): void {
    $line = $this->service()->lineFor(['title' => 'New members', 'type' => 'window'], [9, 13, 8, 10, 7, 12, 9, 11, 6, 14]);
    $this->assertNull($line['flag']);
    $this->assertNull($line['discuss']);
    $this->assertSame('13', $line['last_text']);
  }

  /**
   * A count outside all 8 prior weeks is flagged, with the median as usual.
   */
  public function testCountOutsideRangeIsFlagged(): void {
    $low = $this->service()->lineFor(['title' => 'Store sales', 'type' => 'window'], [12, 14, 25, 30, 22, 28, 25, 40, 19, 99]);
    $this->assertSame('lowest in 9 weeks', $low['flag']);
    $this->assertSame('25', $low['avg_text']);
    $high = $this->service()->lineFor(['title' => 'Appointments', 'type' => 'window'], [42, 30, 32, 31, 35, 29, 33, 38, 30, 0]);
    $this->assertSame('highest in 9 weeks', $high['flag']);
  }

  /**
   * One freak week (the 2026-08 door-log flood) does not move "usual".
   */
  public function testMedianIgnoresOneFreakWeek(): void {
    $line = $this->service()->lineFor(['title' => 'Unlocks', 'type' => 'window'], [1301, 1320, 1323, 1349, 1071, 1116, 1134, 1138, 37681, 31855]);
    $this->assertNull($line['flag']);
    $this->assertSame('1,229', $line['avg_text']);
  }

  /**
   * A steadily growing stock is not "highest in 9 weeks" every week.
   */
  public function testGrowingStockIsJudgedOnChange(): void {
    $steady = $this->service()->lineFor(['title' => 'Members', 'type' => 'snapshot'], [810, 805, 800, 795, 790, 785, 780, 775, 770, 765]);
    $this->assertNull($steady['flag']);
    $this->assertSame('+5', $steady['last_text']);

    $jump = $this->service()->lineFor(['title' => 'Onboarding', 'type' => 'snapshot'], [30, 24, 24, 23, 25, 22, 24, 23, 24, 23]);
    $this->assertSame('biggest weekly rise in 9 weeks', $jump['flag']);
    $this->assertStringContainsString('(+6 this week)', $jump['discuss']);
  }

  /**
   * Safety reports always reach "Worth discussing", and only when present.
   */
  public function testAlwaysDiscussOnlyWhenNonZero(): void {
    $metric = ['title' => 'Safety reports', 'type' => 'window', 'always_discuss' => TRUE];
    $this->assertNotNull($this->service()->lineFor($metric, [1, 0, 0, 0, 0, 0, 0, 0, 0, 0])['discuss']);
    $this->assertNull($this->service()->lineFor($metric, [0, 0, 0, 0, 0, 0, 0, 0, 0, 0])['flag']);
  }

  /**
   * Money and signed formatting.
   */
  public function testFormatting(): void {
    $this->assertSame('$2,176', StaffBriefingService::format(2176.4, TRUE));
    $this->assertSame('−2', StaffBriefingService::signed(-2.0));
    $this->assertSame('±0', StaffBriefingService::signed(0.0));
    $this->assertSame('–', StaffBriefingService::format(NULL));
  }

}
