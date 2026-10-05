<?php

namespace Drupal\Tests\makerspace_dashboard\Unit;

use Drupal\makerspace_dashboard\Controller\WorkBoardFeedController;
use PHPUnit\Framework\TestCase;

/**
 * The raw signals the What's next board ranks on.
 */
class WorkBoardFeedTest extends TestCase {

  /**
   * Report counts come from the provenance string the ledger script writes.
   *
   * @dataProvider sources
   */
  public function testReportCount(string $source, int $expected): void {
    $this->assertSame($expected, WorkBoardFeedController::reportCount($source));
  }

  /**
   * Provenance strings in the shapes found on live, and their report counts.
   */
  public static function sources(): array {
    return [
      'one sid' => ['website_feedback sid 19572', 1],
      'sids with spaces' => ['website_feedback sids 19557, 20355', 2],
      'sids without spaces' => ['website_feedback sid 19512,19528,19560', 3],
      'repeated sid counts once' => ['website_feedback sids 19557, 19557', 1],
      'chat id' => ['chat id 12', 1],
      // Born outside the form: the date must not be read as three reports.
      'staff report with a date' => ['staff report 2026-07-13 onboarding next-steps', 1],
      'cycle review theme' => ['cycle-review 2026-05-19 theme-A badge-video-hidden', 1],
      'empty' => ['', 1],
    ];
  }

  /**
   * Only process.makehaven.org links yield a registry process id.
   */
  public function testProcessId(): void {
    $this->assertSame('finance-xero-bills', WorkBoardFeedController::processId('https://process.makehaven.org/?p=finance-xero-bills'));
    $this->assertNull(WorkBoardFeedController::processId('https://process.makehaven.org/'));
    $this->assertNull(WorkBoardFeedController::processId('https://www.makehaven.org/?p=finance-xero-bills'));
    $this->assertNull(WorkBoardFeedController::processId(''));
  }

}
