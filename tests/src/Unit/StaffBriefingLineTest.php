<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_dashboard\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\makerspace_dashboard\Service\KpiDataService;
use Drupal\makerspace_dashboard\Service\StaffBriefingService;
use Drupal\makerspace_dashboard\Support\KpiStatus;
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
  protected function service(?StateInterface $state = NULL, ?KpiDataService $kpi = NULL): object {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(ImmutableConfig::class));
    return new class(
      $this->createMock(Connection::class),
      $factory,
      $state ?? $this->createMock(StateInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(MailManagerInterface::class),
      $this->createMock(LoggerInterface::class),
      NULL,
      $kpi,
    ) extends StaffBriefingService {

      public function lineFor(array $metric, array $series): array {
        return $this->line($metric, $series);
      }

      public function goalsFor(array $map, \DateTimeImmutable $weekEnd): array {
        return $this->goals($map, $weekEnd);
      }

    };
  }

  /**
   * Goals use the /kpi rule, and last week's recorded value rides along.
   */
  public function testGoalsCompareWithLastWeek(): void {
    $kpi = $this->createMock(KpiDataService::class);
    $kpi->method('getKpiData')->willReturn([
      'kpi_total_active_members' => ['label' => 'Total # Active Members', 'current' => 794, 'goal_current_year' => 845, 'goal_current_year_label' => '2026', 'period_basis' => 'point', 'value_source' => 'calculated', 'refresh_status' => 'current'],
      'kpi_tours' => ['label' => 'Tours', 'current' => 'TBD', 'value_source' => 'unavailable', 'refresh_status' => 'current'],
    ]);
    $kpi->expects($this->never())->method('warmSection');
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn([
      '2026-10-26' => ['kpi_total_active_members' => ['value' => 850.0, 'status' => KpiStatus::GOOD]],
    ]);

    $goals = $this->service($state, $kpi)->goalsFor(['overview' => ['kpi_total_active_members', 'kpi_tours']], new \DateTimeImmutable('2026-11-02'));
    $this->assertSame(['kpi_total_active_members'], array_keys($goals), 'A KPI with no number is left out.');
    $members = $goals['kpi_total_active_members'];
    $this->assertSame(KpiStatus::WARNING, $members['status']);
    $this->assertSame(KpiStatus::GOOD, $members['was_status']);
    $this->assertSame('850', $members['was_text']);
    $this->assertSame('now', $members['period']);
    $this->assertSame('now', $members['group']);
    $this->assertSame('full_year', StaffBriefingService::goalGroup('projected'), 'A full-year projection reads with the 12-month figures.');
    $this->assertSame('slow', StaffBriefingService::goalGroup('cohort'));
    $this->assertSame('so_far', StaffBriefingService::goalGroup('ytd'));
  }

  /**
   * An expired stored KPI is recomputed rather than shown without a status.
   */
  public function testExpiredKpisAreRecomputed(): void {
    $stale = ['kpi_x' => ['label' => 'X', 'current' => 5, 'goal_current_year' => 10, 'value_source' => 'calculated', 'refresh_status' => 'stale']];
    $fresh = ['kpi_x' => ['refresh_status' => 'current'] + $stale['kpi_x']];
    $kpi = $this->createMock(KpiDataService::class);
    $kpi->method('getKpiData')->willReturnOnConsecutiveCalls($stale, $fresh);
    $kpi->expects($this->once())->method('warmSection')->with('infrastructure');
    $goals = $this->service(NULL, $kpi)->goalsFor(['infrastructure' => ['kpi_x']], new \DateTimeImmutable('2026-11-02'));
    $this->assertSame(KpiStatus::POOR, $goals['kpi_x']['status']);
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
   * A count clearly outside all 8 prior weeks is flagged, median as usual.
   */
  public function testCountOutsideRangeIsFlagged(): void {
    $low = $this->service()->lineFor(['title' => 'Store sales', 'type' => 'window'], [2, 20, 25, 30, 22, 28, 25, 32, 19, 99]);
    $this->assertSame('lowest in 9 weeks', $low['flag']);
    $this->assertSame('25', $low['avg_text']);
    $high = $this->service()->lineFor(['title' => 'Appointments', 'type' => 'window'], [57, 39, 42, 29, 34, 20, 32, 31, 30, 0]);
    $this->assertSame('highest in 9 weeks', $high['flag']);
  }

  /**
   * Just past the old high or low is chance, not news.
   *
   * The 2026-11-09 send flagged ten numbers this way, among them broken
   * equipment reports (4, after eight weeks of 0 to 3).
   */
  public function testBarelyOutsideRangeIsNotFlagged(): void {
    $broken = $this->service()->lineFor(['title' => 'Broken equipment reports', 'type' => 'window'], [4, 3, 0, 3, 3, 2, 3, 1, 3, 2]);
    $this->assertNull($broken['flag']);
    $visitors = $this->service()->lineFor(['title' => 'Members who came in', 'type' => 'window'], [220, 211, 219, 198, 201, 182, 196, 190, 199, 180]);
    $this->assertNull($visitors['flag']);
    $ends = $this->service()->lineFor(['title' => 'Memberships ended', 'type' => 'window'], [4, 7, 8, 19, 8, 9, 5, 8, 10, 8]);
    $this->assertNull($ends['flag'], 'One below the old low, on a spread of 14.');
  }

  /**
   * A note gives a number its denominator, and is dropped without one.
   */
  public function testNoteText(): void {
    $this->assertSame('of 12 seats offered (75% full).', StaffBriefingService::noteText('of {n} seats offered ({pct}% full).', 12.0, 9.0));
    $this->assertNull(StaffBriefingService::noteText('of {n} seats offered ({pct}% full).', 0.0, 0.0), 'No classes, no share.');
    $this->assertSame('7 gifts.', StaffBriefingService::noteText('{n} gifts.', 7.0, 95.0));
  }

  /**
   * Each role's items are ranked on their own; a long one shortens for others.
   */
  public function testByRoleAndBrief(): void {
    $item = static fn ($role, $rank, $text) => ['text' => $text, 'role' => $role, 'rank' => $rank];
    $by = StaffBriefingService::byRole([
      $item('operations', StaffBriefingService::RANK_NOTABLE, 'tasks ready'),
      $item('director', StaffBriefingService::RANK_STUCK, 'unpaid requests'),
      $item('operations', StaffBriefingService::RANK_URGENT, 'safety'),
      $item('director', StaffBriefingService::RANK_URGENT, 'alarm'),
    ]);
    $this->assertSame(['safety', 'tasks ready'], array_column($by['operations'], 'text'));
    $this->assertSame(['alarm', 'unpaid requests'], array_column($by['director'], 'text'));

    $this->assertSame('Facilitator appointments booked: 57 — highest in 9 weeks; usual 32', StaffBriefingService::brief(['text' => 'Facilitator appointments booked: 57 — highest in 9 weeks; usual 32.']));
    $this->assertSame('Classes under half full in the next two weeks', StaffBriefingService::brief(['text' => 'Classes under half full in the next two weeks: Introduction to Induction: Forging & Blacksmithing Basics (Nov 15, 2 of 6); Build & Badge: Laser Cutters (Nov 18, 4 of 9).']));
  }

  /**
   * Board actions and website alarms stay in the director's copy.
   *
   * Governance Dance is the board's list, not the staff's to-do list.
   */
  public function testBoardActionsOnlyReachTheDirector(): void {
    $role = static fn ($title, array $items = []) => ['title' => $title, 'link' => '/d', 'lines' => [], 'goals' => [], 'lists' => [], 'followup' => [], 'items' => $items];
    $briefing = [
      'week_start' => new \DateTimeImmutable('2026-11-02'),
      'week_end' => new \DateTimeImmutable('2026-11-09'),
      'discuss' => [],
      'changes' => [],
      'roles' => [
        'director' => $role('Director', [
          ['text' => '2 board/committee actions past due: Laurel 2.', 'role' => 'director', 'rank' => 2, 'links' => [], 'private' => TRUE],
          ['text' => 'Payment requests approved but unpaid: 9.', 'role' => 'director', 'rank' => 2, 'links' => []],
        ]),
        'operations' => $role('Operations'),
      ],
      'focus' => [
        'board' => [], 'transitions' => [], 'broken' => [],
        'meetings' => [['title' => 'Outreach Committee', 'when' => 'Mon Nov 9, 1:30pm', 'url' => '']],
        'actions' => [['text' => 'Mail the postcards', 'owner' => 'Laurel', 'committee' => 'development', 'url' => '', 'due' => '2026-09-30', 'overdue' => TRUE]],
      ],
    ];
    $service = $this->service();
    $kate = $service->renderHtml($briefing, 'operations');
    $this->assertStringNotContainsString('Mail the postcards', $kate);
    $this->assertStringNotContainsString('board/committee', $kate);
    $this->assertStringContainsString('Outreach Committee', $kate, 'Meetings this week are for everyone.');
    $this->assertStringContainsString('Payment requests approved but unpaid', $kate);
    $jr = $service->renderHtml($briefing, 'director');
    $this->assertStringContainsString('Mail the postcards', $jr);
    $this->assertStringContainsString('2 board/committee actions past due', $jr);
  }

  /**
   * The month's running net reads as a sentence.
   */
  public function testNetText(): void {
    $this->assertSame('November so far: 9 members joined, 12 ended, net −3', StaffBriefingService::netText('November so far', 9, 12));
    $this->assertSame('All of October: 1 member joined, 0 ended, net +1', StaffBriefingService::netText('All of October', 1, 0));
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

    $jump = $this->service()->lineFor(['title' => 'Onboarding', 'type' => 'snapshot'], [32, 24, 24, 23, 25, 22, 24, 23, 24, 23]);
    $this->assertSame('biggest weekly rise in 9 weeks', $jump['flag']);
    $this->assertStringContainsString('(+8 this week)', $jump['discuss']);
  }

  /**
   * A biased history (booked fees) shows its sparkline but is never flagged.
   */
  public function testNoFlagMetricIsNeverFlagged(): void {
    $line = $this->service()->lineFor(['title' => 'Booked', 'type' => 'window', 'format' => 'money', 'no_flag' => TRUE], [5614, 4166, 3000, 2650, 2500, 2700, 2400, 2600, 2650, 2000]);
    $this->assertNull($line['flag']);
    $this->assertCount(9, $line['spark']);
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
   * Arrows are neutral words; the sparkline starts at zero and marks a flag.
   */
  public function testArrowAndSparkline(): void {
    $this->assertSame('▲ 4', StaffBriefingService::arrow(4.0));
    $this->assertSame('▼ $25', StaffBriefingService::arrow(-25.0, TRUE));
    $this->assertSame('same', StaffBriefingService::arrow(0.0));
    $this->assertSame('', StaffBriefingService::arrow(NULL));

    $line = $this->service()->lineFor(['title' => 'Visitors', 'type' => 'window'], [198, 198, 197, 194, 195, 190, 185, 180, 175, 170]);
    $this->assertSame('same', $line['arrow_text']);
    $this->assertSame([175.0, 180.0, 185.0, 190.0, 195.0, 194.0, 197.0, 198.0, 198.0], array_map('floatval', $line['spark']), 'Nine weeks, oldest first.');

    $html = StaffBriefingService::sparkHtml([10, 0, NULL, 20], TRUE, '#8b1919');
    $this->assertSame(4, substr_count($html, '<td '));
    $this->assertStringContainsString('height:9px', $html, 'Half the max is half the height: bars start at zero.');
    $this->assertStringContainsString('height:18px;background:#8b1919', $html, 'The flagged latest week wears the brand color.');
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

  /**
   * Only three items reach Tuesday: safety and stuck things first.
   */
  public function testRankDiscussKeepsThreeForTuesday(): void {
    $item = static fn ($role, $rank, $text) => ['text' => $text, 'role' => $role, 'rank' => $rank];
    [$tuesday, $rest] = StaffBriefingService::rankDiscuss([
      $item('education', StaffBriefingService::RANK_NOTABLE, 'waitlist'),
      $item('facility', StaffBriefingService::RANK_STUCK, 'stale tool'),
      $item('operations', StaffBriefingService::RANK_NOTABLE, 'failed payments'),
      $item('facility', StaffBriefingService::RANK_URGENT, 'safety report'),
      $item('education', StaffBriefingService::RANK_NOTABLE, 'low fill'),
    ], 3);
    $this->assertSame(['safety report', 'stale tool', 'waitlist'], array_column($tuesday, 'text'));
    $this->assertSame(['failed payments'], array_column($rest['operations'], 'text'));
    $this->assertSame(['low fill'], array_column($rest['education'], 'text'));
    $this->assertArrayNotHasKey('facility', $rest);
  }

  /**
   * Recipients may carry a role; bad addresses and unknown roles are dropped.
   */
  public function testParseRecipients(): void {
    $parsed = StaffBriefingService::parseRecipients('jr@x.org=Director, kate@x.org=operations ash@x.org=chef nope', ['director', 'operations']);
    $this->assertSame(['jr@x.org' => 'director', 'kate@x.org' => 'operations', 'ash@x.org' => NULL], $parsed);
  }

  /**
   * A Tuesday item's details link to the tool, class or action they name.
   */
  public function testHeadlineLinksDetails(): void {
    $headline = new \ReflectionMethod(StaffBriefingService::class, 'headline');
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
    $html = $headline->invoke(NULL, [
      'text' => '1 tool issue with no update past the follow-up window: Laser (reported concern, 20 days).',
      'links' => [['Laser (reported concern, 20 days)', '/node/7']],
    ], $e, 'https://www.makehaven.org');
    $this->assertStringContainsString('<b>1 tool issue with no update past the follow-up window</b>', $html);
    $this->assertStringContainsString('<a href="https://www.makehaven.org/node/7"', $html);
    $this->assertStringContainsString('>Laser (reported concern, 20 days)</a>.', $html);
  }

  /**
   * What's New entries get an area from the page they link to.
   */
  public function testAreaFor(): void {
    $this->assertSame('Membership', StaffBriefingService::areaFor('/membership/address/confirm'));
    $this->assertSame('Classes & instructors', StaffBriefingService::areaFor('/become-instructor'));
    $this->assertSame('Shop & tools', StaffBriefingService::areaFor('/library/borrowed'));
    $this->assertSame('Member businesses', StaffBriefingService::areaFor('https://www.makehaven.org/commission?x=1'));
    $this->assertSame('Website', StaffBriefingService::areaFor('/whats-new'));
  }

}
