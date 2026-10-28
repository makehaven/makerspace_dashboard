<?php

declare(strict_types=1);

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * The Monday staff briefing: last week's numbers, by role, by email.
 *
 * Why this exists: the Tuesday staff meeting had no shared picture of the
 * week. /kpi answers board questions (12-month and year-to-date measures that
 * barely move in a week), and the dashboards need someone to go looking.
 * This sends one email on Monday morning so staff can read it before the
 * meeting.
 *
 * How a number earns attention: every weekly figure is compared with the
 * eight weeks before it. A week above all eight, or below all eight, is
 * flagged ("highest in 9 weeks"); anything inside that range is shown without
 * comment. Stock figures (members, failed payments) are judged on their
 * weekly change, not their level: a slowly growing membership would
 * otherwise be "highest in 9 weeks" every week. "Usual" is the median, not
 * the mean, so one freak week (the 31k-row door-log flood of 2026-08) does not
 * drag the comparison. Weekly counts are small (about five new members a week), so
 * flagging every change would be noise that staff learn to skip. The flagged
 * lines, plus anything that is plainly a to-do (a safety report, a class
 * under half full, a tool down a long time), are pulled up into "Worth
 * discussing".
 *
 * Off until a recipient list is set:
 *   drush state:set makerspace_dashboard.staff_briefing.recipients "a@x.org,b@x.org"
 * Preview without sending: drush makerspace-dashboard:staff-briefing.
 */
class StaffBriefingService {

  /**
   * Comma-separated recipient emails. Empty = the cron send is off.
   */
  public const STATE_RECIPIENTS = 'makerspace_dashboard.staff_briefing.recipients';

  /**
   * Y-m-d of the Monday whose briefing was last sent by cron.
   */
  public const STATE_LAST_SENT = 'makerspace_dashboard.staff_briefing.last_sent';

  /**
   * Hour (site timezone) on Monday after which cron may send.
   *
   * Late enough that the nightly member-success snapshot has run.
   */
  public const SEND_AFTER_HOUR = 7;

  /**
   * Weeks of history each weekly number is compared against.
   */
  public const COMPARE_WEEKS = 8;

  /**
   * Upcoming classes under this share of capacity are flagged.
   */
  public const LOW_FILL = 0.5;

  /**
   * CiviCRM event types that are classes (Ticketed Workshop, Ticketed Member
   * Only).
   */
  protected const CLASS_EVENT_TYPES = [6, 16];

  /**
   * Webforms counted by the briefing.
   */
  protected const WEBFORM_BROKEN_EQUIPMENT = 'webform_9252';
  protected const WEBFORM_SAFETY_REPORT = 'webform_5199';
  protected const WEBFORM_GUEST_WAIVERS = ['webform_2670', 'guest_waiver'];

  /**
   * Feedback statuses that mean staff still owe a decision or a reply.
   */
  protected const FEEDBACK_OPEN = ['received', 'reviewing', 'forwarded', 'needs_info'];

  /**
   * Tool statuses that are not "down" (parked on purpose, or fine).
   */
  protected const TOOL_STATUS_OK = ['Operational', 'Storage', 'Gone', 'Offline - Initial Setup', 'Setup / Training Only', 'Setup'];

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected MailManagerInterface $mailManager,
    protected LoggerInterface $logger,
    protected ?FunnelDataService $funnelData = NULL,
  ) {}

  /**
   * Cron entry point: sends once per Monday after SEND_AFTER_HOUR.
   */
  public function sendIfDue(): void {
    $recipients = $this->recipients();
    if (!$recipients) {
      return;
    }
    $now = $this->now();
    if ($now->format('N') !== '1' || (int) $now->format('G') < self::SEND_AFTER_HOUR) {
      return;
    }
    $monday = $now->format('Y-m-d');
    if ($this->state->get(self::STATE_LAST_SENT) === $monday) {
      return;
    }
    // Recorded before sending: a send that dies half way must not repeat on
    // every cron tick for the rest of the day.
    $this->state->set(self::STATE_LAST_SENT, $monday);
    $this->send($this->build(), $recipients);
  }

  /**
   * The recipient list from state.
   *
   * @return string[]
   *   Valid email addresses.
   */
  public function recipients(): array {
    $raw = (string) $this->state->get(self::STATE_RECIPIENTS, '');
    $list = array_filter(array_map('trim', preg_split('/[,\s]+/', $raw) ?: []));
    return array_values(array_filter($list, static fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL)));
  }

  /**
   * Builds the briefing for the week ending at $weekEnd (a Monday, 00:00).
   *
   * @param \DateTimeImmutable|null $weekEnd
   *   Defaults to the most recent Monday 00:00 in the site timezone.
   *
   * @return array
   *   ['week_start', 'week_end' (DateTimeImmutable, exclusive), 'roles' =>
   *   [role => ['title', 'link', 'lines' => [...], 'lists' => [...]]],
   *   'discuss' => [string, ...]].
   */
  public function build(?\DateTimeImmutable $weekEnd = NULL): array {
    $weekEnd = $weekEnd ?? $this->lastMonday();
    $weeks = [];
    // One extra week, so stock figures have COMPARE_WEEKS changes to compare.
    for ($i = 0; $i <= self::COMPARE_WEEKS + 1; $i++) {
      $end = $weekEnd->modify('-' . (7 * $i) . ' days');
      $weeks[] = [$end->modify('-7 days'), $end];
    }

    $roles = [];
    foreach ($this->definitions() as $role => $def) {
      $lines = [];
      foreach ($def['metrics'] as $key => $metric) {
        $series = $this->series($metric, $weeks);
        if ($series === NULL) {
          continue;
        }
        $lines[$key] = $this->line($metric, $series);
      }
      $roles[$role] = [
        'title' => $def['title'],
        'link' => $def['link'],
        'lines' => $lines,
        'lists' => [],
      ];
    }

    $discuss = [];
    $this->addLists($roles, $discuss, $weekEnd);
    foreach ($roles as $role) {
      foreach ($role['lines'] as $line) {
        if ($line['flag'] !== NULL) {
          $discuss[] = $line['discuss'];
        }
      }
    }

    return [
      'week_start' => $weekEnd->modify('-7 days'),
      'week_end' => $weekEnd,
      'roles' => $roles,
      'discuss' => $discuss,
    ];
  }

  /**
   * The metrics, grouped by role.
   *
   * Each metric is either 'window' (counted between :s and :e — unix
   * timestamps — or :sd and :ed — Eastern datetime strings, which is how
   * CiviCRM stores register and receive dates) or 'snapshot' (read from the
   * member-success daily snapshot taken the day before the week ends).
   * 'requires' names a table; when it is missing the line is left out.
   */
  protected function definitions(): array {
    $classTypes = implode(',', self::CLASS_EVENT_TYPES);
    $counted = "p.is_test = 0 AND p.status_id IN (SELECT id FROM civicrm_participant_status_type WHERE is_counted = 1)";
    $granted = "FROM access_control_log_field_data l JOIN access_control_log__field_access_request_result r ON r.entity_id = l.id WHERE l.type = 'access_control_request' AND l.created >= :s AND l.created < :e";
    $webform = static fn (array $ids) => "SELECT COUNT(*) FROM webform_submission WHERE webform_id IN ('" . implode("','", $ids) . "') AND created >= :s AND created < :e";

    return [
      'director' => [
        'title' => 'Director',
        'link' => '/makerspace-dashboard/overview',
        'metrics' => [
          'members' => ['title' => 'Members (not paused)', 'type' => 'snapshot', 'sql' => "COUNT(*) - SUM(stage = 'paused')"],
          'joins' => ['title' => 'New members', 'type' => 'window', 'requires' => 'profile', 'sql' => "SELECT COUNT(*) FROM profile WHERE type = 'main' AND created >= :s AND created < :e"],
          'ends' => ['title' => 'Memberships ended', 'type' => 'window', 'requires' => 'profile__field_member_end_date', 'sql' => "SELECT COUNT(*) FROM profile__field_member_end_date WHERE deleted = 0 AND field_member_end_date_value >= :sday AND field_member_end_date_value < :eday"],
          'paused' => ['title' => 'Paused members', 'type' => 'snapshot', 'sql' => "SUM(stage = 'paused')"],
          'tours' => ['title' => 'People who toured', 'type' => 'tours'],
          'donations' => ['title' => 'Donations, campaign gifts and sponsorships', 'type' => 'window', 'format' => 'money', 'requires' => 'civicrm_contribution', 'sql' => "SELECT IFNULL(SUM(total_amount), 0) FROM civicrm_contribution WHERE is_test = 0 AND contribution_status_id = 1 AND financial_type_id IN (1, 3, 5) AND receive_date >= :sd AND receive_date < :ed"],
        ],
      ],
      'operations' => [
        'title' => 'Operations',
        'link' => '/makerspace-dashboard/operations',
        'metrics' => [
          'payment_failed' => ['title' => 'Members with a failed payment', 'type' => 'snapshot', 'sql' => 'SUM(payment_failed)'],
          'recovery' => ['title' => 'Members in the recovery queue', 'type' => 'snapshot', 'sql' => "SUM(stage = 'recovery')"],
          'onboarding' => ['title' => 'New members still onboarding', 'type' => 'snapshot', 'sql' => "SUM(stage = 'onboarding')"],
          'join_form' => ['title' => 'Join form submissions', 'type' => 'window', 'requires' => 'webform_submission', 'sql' => "SELECT COUNT(*) FROM webform_submission WHERE uri LIKE '%join-makehaven%' AND created >= :s AND created < :e"],
          'feedback' => ['title' => 'Website feedback reports', 'type' => 'window', 'sql' => "SELECT COUNT(*) FROM node_field_data WHERE type = 'feedback_ledger' AND created >= :s AND created < :e"],
          'store' => ['title' => 'Store sales (line items)', 'type' => 'window', 'requires' => 'material_transaction_field_data', 'sql' => "SELECT COUNT(*) FROM material_transaction_field_data WHERE created >= :s AND created < :e"],
          'lending' => ['title' => 'Lending library checkouts', 'type' => 'window', 'requires' => 'library_transaction__field_library_action', 'sql' => "SELECT COUNT(*) FROM library_transaction_field_data t JOIN library_transaction__field_library_action a ON a.entity_id = t.id WHERE a.field_library_action_value = 'withdraw' AND t.created >= :s AND t.created < :e"],
        ],
      ],
      'education' => [
        'title' => 'Education',
        'link' => '/makerspace-dashboard/education',
        'metrics' => [
          'classes_held' => ['title' => 'Classes held', 'type' => 'window', 'requires' => 'civicrm_event', 'sql' => "SELECT COUNT(*) FROM civicrm_event WHERE is_active = 1 AND is_template = 0 AND event_type_id IN ($classTypes) AND start_date >= :sd AND start_date < :ed"],
          'class_seats' => ['title' => 'Class seats filled', 'type' => 'window', 'requires' => 'civicrm_event', 'sql' => "SELECT COUNT(*) FROM civicrm_participant p JOIN civicrm_event e ON e.id = p.event_id WHERE $counted AND e.is_active = 1 AND e.is_template = 0 AND e.event_type_id IN ($classTypes) AND e.start_date >= :sd AND e.start_date < :ed"],
          'registrations' => ['title' => 'Event registrations taken', 'type' => 'window', 'requires' => 'civicrm_participant', 'sql' => "SELECT COUNT(*) FROM civicrm_participant p WHERE $counted AND p.register_date >= :sd AND p.register_date < :ed"],
          'event_income' => ['title' => 'Event fee income', 'type' => 'window', 'format' => 'money', 'requires' => 'civicrm_contribution', 'sql' => "SELECT IFNULL(SUM(total_amount), 0) FROM civicrm_contribution WHERE is_test = 0 AND contribution_status_id = 1 AND financial_type_id = 4 AND receive_date >= :sd AND receive_date < :ed"],
          'quizzes' => ['title' => 'Badge quizzes completed', 'type' => 'window', 'requires' => 'quiz_result', 'sql' => "SELECT COUNT(*) FROM quiz_result WHERE is_invalid = 0 AND time_end >= :s AND time_end < :e"],
          'badges' => ['title' => 'Badges earned', 'type' => 'window', 'requires' => 'node__field_badge_status', 'sql' => "SELECT COUNT(*) FROM node_field_data n JOIN node__field_badge_status s ON s.entity_id = n.nid WHERE n.type = 'badge_request' AND s.field_badge_status_value = 'active' AND n.created >= :s AND n.created < :e"],
          'appointments' => ['title' => 'Facilitator appointments booked', 'type' => 'window', 'sql' => "SELECT COUNT(*) FROM node_field_data WHERE type = 'appointment' AND created >= :s AND created < :e"],
        ],
      ],
      'facility' => [
        'title' => 'Facility & safety',
        'link' => '/makerspace-dashboard/infrastructure',
        'metrics' => [
          'visitors' => ['title' => 'Members who came in', 'type' => 'window', 'requires' => 'access_control_log__field_access_request_user', 'sql' => "SELECT COUNT(DISTINCT u.field_access_request_user_target_id) FROM access_control_log_field_data l JOIN access_control_log__field_access_request_result r ON r.entity_id = l.id JOIN access_control_log__field_access_request_user u ON u.entity_id = l.id WHERE l.type = 'access_control_request' AND l.created >= :s AND l.created < :e AND r.field_access_request_result_value = 1"],
          'swipes' => ['title' => 'Door and tool unlocks', 'type' => 'window', 'requires' => 'access_control_log__field_access_request_result', 'sql' => "SELECT COUNT(*) $granted AND r.field_access_request_result_value = 1"],
          'denied' => ['title' => 'Denied swipes', 'type' => 'window', 'requires' => 'access_control_log__field_access_request_result', 'sql' => "SELECT COUNT(*) $granted AND r.field_access_request_result_value = 0"],
          'reservations' => ['title' => 'Equipment reservations made', 'type' => 'window', 'sql' => "SELECT COUNT(*) FROM node_field_data WHERE type = 'reservation' AND created >= :s AND created < :e"],
          'tool_changes' => ['title' => 'Tool status log entries', 'type' => 'window', 'requires' => 'asset_log_entry', 'sql' => "SELECT COUNT(*) FROM asset_log_entry WHERE created >= :s AND created < :e"],
          'broken_reports' => ['title' => 'Broken equipment reports', 'type' => 'window', 'requires' => 'webform_submission', 'sql' => $webform([self::WEBFORM_BROKEN_EQUIPMENT])],
          'safety_reports' => ['title' => 'Safety concern and accident reports', 'type' => 'window', 'requires' => 'webform_submission', 'sql' => $webform([self::WEBFORM_SAFETY_REPORT]), 'always_discuss' => TRUE],
          'guests' => ['title' => 'Guest waivers signed', 'type' => 'window', 'requires' => 'webform_submission', 'sql' => $webform(self::WEBFORM_GUEST_WAIVERS)],
        ],
      ],
    ];
  }

  /**
   * One value per week, newest first. NULL when the source is missing.
   */
  protected function series(array $metric, array $weeks): ?array {
    $schema = $this->database->schema();
    if ($metric['type'] === 'snapshot') {
      if (!$schema->tableExists('ms_member_success_snapshot')) {
        return NULL;
      }
      $out = [];
      foreach ($weeks as [, $end]) {
        $date = $this->database->query("SELECT MAX(snapshot_date) FROM {ms_member_success_snapshot} WHERE snapshot_type = 'daily' AND snapshot_date < :d", [':d' => $end->format('Y-m-d')])->fetchField();
        $out[] = $date ? (float) $this->database->query("SELECT IFNULL({$metric['sql']}, 0) FROM {ms_member_success_snapshot} WHERE snapshot_type = 'daily' AND snapshot_date = :d", [':d' => $date])->fetchField() : NULL;
      }
      return $out[0] === NULL ? NULL : $out;
    }
    if ($metric['type'] === 'tours') {
      if (!$this->funnelData || !$schema->tableExists('civicrm_activity')) {
        return NULL;
      }
      $out = [];
      foreach ($weeks as [$start, $end]) {
        try {
          $out[] = (float) $this->funnelData->getTourParticipantCount($start, $end->modify('-1 second'));
        }
        catch (\Throwable $e) {
          return NULL;
        }
      }
      return $out;
    }
    if (!empty($metric['requires']) && !$schema->tableExists($metric['requires'])) {
      return NULL;
    }
    $sql = $metric['sql'];
    $out = [];
    foreach ($weeks as [$start, $end]) {
      $args = [];
      foreach ([':s' => $start->getTimestamp(), ':e' => $end->getTimestamp(), ':sd' => $start->format('Y-m-d H:i:s'), ':ed' => $end->format('Y-m-d H:i:s'), ':sday' => $start->format('Y-m-d'), ':eday' => $end->format('Y-m-d')] as $ph => $v) {
        if (preg_match('/' . preg_quote($ph, '/') . '\b/', $sql)) {
          $args[$ph] = $v;
        }
      }
      try {
        $out[] = (float) $this->database->query($sql, $args)->fetchField();
      }
      catch (\Throwable $e) {
        $this->logger->error('Staff briefing metric failed: @msg', ['@msg' => $e->getMessage()]);
        return NULL;
      }
    }
    return $out;
  }

  /**
   * Turns a series (newest first) into a display line with its flag.
   */
  protected function line(array $metric, array $series): array {
    $money = ($metric['format'] ?? '') === 'money';
    $stock = $metric['type'] === 'snapshot';
    $value = $series[0];
    $last = $series[1] ?? NULL;

    // What is compared: the weekly count, or for a stock its weekly change.
    $measure = [];
    for ($i = 0; $i <= self::COMPARE_WEEKS; $i++) {
      if ($stock) {
        $measure[] = (isset($series[$i], $series[$i + 1])) ? $series[$i] - $series[$i + 1] : NULL;
      }
      else {
        $measure[] = $series[$i] ?? NULL;
      }
    }
    $now = $measure[0];
    $history = array_values(array_filter(array_slice($measure, 1), static fn ($v) => $v !== NULL));
    $usual = self::median($history);

    $flag = NULL;
    if ($now !== NULL && count($history) >= self::COMPARE_WEEKS) {
      $span = count($history) + 1;
      if ($now > max($history)) {
        $flag = $stock ? "biggest weekly rise in $span weeks" : "highest in $span weeks";
      }
      elseif ($now < min($history)) {
        $flag = $stock ? "biggest weekly drop in $span weeks" : "lowest in $span weeks";
      }
    }
    if (!empty($metric['always_discuss']) && $value > 0) {
      $flag = 'read each one';
    }

    $valueText = self::format($value, $money);
    $changeText = $now === NULL ? '' : self::signed($now, $money);
    $discuss = NULL;
    if ($flag !== NULL) {
      $discuss = $stock
        ? sprintf('%s: %s (%s this week) — %s; usual weekly change %s.', $metric['title'], $valueText, $changeText, $flag, self::signed(round($usual ?? 0.0), $money))
        : sprintf('%s: %s — %s; usual %s.', $metric['title'], $valueText, $flag, self::format($usual === NULL ? NULL : round($usual), $money));
    }

    return [
      'title' => $metric['title'],
      'value' => $value,
      'value_text' => $valueText,
      'last_text' => $stock ? $changeText : self::format($last, $money),
      // A median of counts reads as a count: "107", not "106.5".
      'avg_text' => $stock ? self::signed(round($usual ?? 0.0), $money) : self::format($usual === NULL ? NULL : round($usual), $money),
      'stock' => $stock,
      'flag' => $flag,
      'discuss' => $discuss,
    ];
  }

  /**
   * Median of a list, or NULL when empty.
   */
  public static function median(array $values): ?float {
    if (!$values) {
      return NULL;
    }
    sort($values);
    $n = count($values);
    return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
  }

  /**
   * A change with its sign: +3, −2, ±0.
   */
  public static function signed(float $value, bool $money = FALSE): string {
    if (abs($value) < 0.05) {
      return '±0';
    }
    return ($value > 0 ? '+' : '−') . self::format(abs($value), $money);
  }

  /**
   * Point-in-time lists that have no weekly history: tools down, classes in
   * the next two weeks, feedback waiting, early-warning trips.
   */
  protected function addLists(array &$roles, array &$discuss, \DateTimeImmutable $weekEnd): void {
    $schema = $this->database->schema();
    $now = $weekEnd->getTimestamp();

    // Director: website alarms that tripped during the week.
    if ($schema->tableExists('ms_early_warning_reading')) {
      $rows = $this->database->query("SELECT metric, MIN(reading_date) first_day, COUNT(*) days FROM {ms_early_warning_reading} WHERE tripped = 1 AND reading_date >= :s AND reading_date < :e GROUP BY metric", [
        ':s' => $weekEnd->modify('-7 days')->format('Y-m-d'),
        ':e' => $weekEnd->format('Y-m-d'),
      ])->fetchAll();
      $items = [];
      foreach ($rows as $row) {
        $title = class_exists('\Drupal\makerspace_snapshot\EarlyWarning\EarlyWarningMonitor')
          ? (\Drupal\makerspace_snapshot\EarlyWarning\EarlyWarningMonitor::TRIPWIRES[$row->metric]['title'] ?? $row->metric)
          : $row->metric;
        $items[] = sprintf('%s — tripped on %d %s from %s', $title, $row->days, $row->days == 1 ? 'day' : 'days', date('D M j', strtotime($row->first_day)));
      }
      $roles['director']['lists'][] = [
        'title' => 'Website early-warning alarms',
        'items' => $items,
        'empty' => 'None tripped this week.',
      ];
      if ($items) {
        $discuss[] = 'Website early-warning alarms tripped: ' . count($items) . ' (see Director).';
      }
    }

    // Operations: feedback waiting on staff.
    if ($schema->tableExists('node__field_fb_status')) {
      $open = $this->database->query("SELECT n.nid, n.title, n.created, s.field_fb_status_value status FROM {node_field_data} n JOIN {node__field_fb_status} s ON s.entity_id = n.nid WHERE n.type = 'feedback_ledger' AND s.field_fb_status_value IN (:st[]) ORDER BY n.created ASC", [':st[]' => self::FEEDBACK_OPEN])->fetchAll();
      $old = array_filter($open, static fn ($r) => $now - (int) $r->created > 14 * 86400);
      $items = array_map(static fn ($r) => sprintf('%s — %s, %d days', $r->title, str_replace('_', ' ', $r->status), floor(($now - (int) $r->created) / 86400)), array_slice($old, 0, 8));
      $roles['operations']['lists'][] = [
        'title' => sprintf('Feedback waiting on staff: %d open, %d older than two weeks', count($open), count($old)),
        'items' => $items,
        'more' => count($old) > 8 ? sprintf('…and %d more on the feedback board.', count($old) - 8) : NULL,
        'empty' => 'Nothing older than two weeks.',
      ];
    }

    // Education: recent badge requests still waiting for a checkout. Only
    // the last 30 days: thousands of older requests were never closed and
    // are not a queue anyone works.
    if ($schema->tableExists('node__field_badge_status')) {
      $pending = (int) $this->database->query("SELECT COUNT(*) FROM {node_field_data} n JOIN {node__field_badge_status} s ON s.entity_id = n.nid WHERE n.type = 'badge_request' AND s.field_badge_status_value = 'pending' AND n.created >= :since AND n.created < :now", [':since' => $now - 30 * 86400, ':now' => $now])->fetchField();
      $roles['education']['lists'][] = [
        'title' => sprintf('Badge requests from the last 30 days still waiting for a checkout: %d', $pending),
        'items' => [],
        'empty' => '',
      ];
    }

    // Education: classes in the next two weeks, low fill flagged.
    if ($schema->tableExists('civicrm_event')) {
      $rows = $this->database->query("SELECT e.id, e.title, e.start_date, e.max_participants cap, (SELECT COUNT(*) FROM civicrm_participant p WHERE p.event_id = e.id AND p.is_test = 0 AND p.status_id IN (SELECT id FROM civicrm_participant_status_type WHERE is_counted = 1)) n FROM civicrm_event e WHERE e.is_active = 1 AND e.is_template = 0 AND e.event_type_id IN (" . implode(',', self::CLASS_EVENT_TYPES) . ") AND e.start_date >= :s AND e.start_date < :e ORDER BY e.start_date", [
        ':s' => $weekEnd->format('Y-m-d H:i:s'),
        ':e' => $weekEnd->modify('+14 days')->format('Y-m-d H:i:s'),
      ])->fetchAll();
      $items = [];
      $low = [];
      foreach ($rows as $r) {
        $cap = (int) $r->cap;
        $isLow = $cap > 0 && ((int) $r->n / $cap) < self::LOW_FILL;
        $items[] = ['text' => sprintf('%s — %s: %d%s', date('D M j', strtotime($r->start_date)), $r->title, (int) $r->n, $cap ? " of $cap" : ' registered'), 'warn' => $isLow];
        if ($isLow) {
          $low[] = sprintf('%s (%s, %d of %d)', $r->title, date('M j', strtotime($r->start_date)), (int) $r->n, $cap);
        }
      }
      $roles['education']['lists'][] = [
        'title' => sprintf('Classes in the next two weeks: %d, %d under half full', count($rows), count($low)),
        'items' => $items,
        'empty' => 'No classes scheduled.',
      ];
      if ($low) {
        $discuss[] = 'Classes under half full in the next two weeks: ' . implode('; ', $low) . '.';
      }
    }

    // Facility: tools that are not usable, and for how long.
    if ($schema->tableExists('node__field_item_status')) {
      $rows = $this->database->query("SELECT n.nid, n.title, n.changed, t.name status, (SELECT MAX(l.created) FROM {asset_log_entry} l WHERE l.asset = n.nid) logged FROM {node_field_data} n JOIN {node__field_item_status} s ON s.entity_id = n.nid AND s.deleted = 0 JOIN {taxonomy_term_field_data} t ON t.tid = s.field_item_status_target_id WHERE n.type = 'item' AND n.status = 1 AND t.name NOT IN (:ok[])", [':ok[]' => self::TOOL_STATUS_OK])->fetchAll();
      $tools = [];
      foreach ($rows as $r) {
        $since = (int) ($r->logged ?: $r->changed);
        $tools[] = ['days' => (int) floor(($now - $since) / 86400), 'text' => $r->title, 'status' => $r->status, 'new' => $since >= $now - 7 * 86400];
      }
      usort($tools, static fn ($a, $b) => $b['days'] <=> $a['days']);
      $items = array_map(static fn ($t) => ['text' => sprintf('%s — %s, %s', $t['text'], $t['status'], $t['new'] ? 'new this week' : $t['days'] . ' days'), 'warn' => $t['days'] >= 30], $tools);
      $roles['facility']['lists'][] = [
        'title' => sprintf('Tools not fully working: %d', count($tools)),
        'items' => $items,
        'empty' => 'Every tool is operational.',
      ];
      $long = array_filter($tools, static fn ($t) => $t['days'] >= 30);
      if ($long) {
        $discuss[] = sprintf('%d %s down 30+ days: %s.', count($long), count($long) === 1 ? 'tool' : 'tools', implode('; ', array_map(static fn ($t) => $t['text'] . ' (' . $t['days'] . ' d)', $long)));
      }
    }
  }

  /**
   * Renders the email body (inline styles: mail clients drop <style>).
   */
  public function renderHtml(array $briefing): string {
    $base = rtrim((string) ($this->configFactory->get('makerspace_dashboard.settings')->get('briefing_base_url') ?: 'https://www.makehaven.org'), '/');
    $e = static fn ($s) => Html::escape((string) $s);
    $brand = '#8b1919';
    $range = $briefing['week_start']->format('M j') . ' – ' . $briefing['week_end']->modify('-1 day')->format('M j, Y');

    $h = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;max-width:680px;margin:0 auto;line-height:1.45">';
    $h .= '<h1 style="font-size:20px;color:' . $brand . ';margin:0 0 4px">Staff briefing: week of ' . $e($range) . '</h1>';
    $h .= '<p style="margin:0 0 16px;color:#555">Last week\'s numbers, for Tuesday\'s meeting. "Usual" is the middle of the 8 weeks before; a number is flagged only when it is outside all 8.</p>';

    $h .= '<h2 style="font-size:16px;margin:16px 0 6px">Worth discussing</h2>';
    if ($briefing['discuss']) {
      $h .= '<ul style="margin:0 0 8px;padding-left:20px">';
      foreach ($briefing['discuss'] as $item) {
        $h .= '<li style="margin:0 0 4px">' . $e($item) . '</li>';
      }
      $h .= '</ul>';
    }
    else {
      $h .= '<p style="margin:0 0 8px">Nothing outside its normal range this week.</p>';
    }

    foreach ($briefing['roles'] as $role) {
      $h .= '<h2 style="font-size:16px;margin:22px 0 6px;padding-top:10px;border-top:2px solid ' . $brand . '">' . $e($role['title']) . '</h2>';
      $groups = [
        'Where things stand' => ['cols' => ['Now', 'Change', 'Usual change'], 'lines' => array_filter($role['lines'], static fn ($l) => $l['stock'])],
        'Last week' => ['cols' => ['Last week', 'Week before', 'Usual'], 'lines' => array_filter($role['lines'], static fn ($l) => !$l['stock'])],
      ];
      foreach ($groups as $label => $group) {
        if (!$group['lines']) {
          continue;
        }
        $h .= '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:6px 0 0">';
        $h .= '<tr style="color:#666;font-size:12px"><td style="padding:3px 6px 3px 0;font-weight:bold">' . $e($label) . '</td>';
        foreach ($group['cols'] as $i => $col) {
          $h .= '<td style="padding:3px ' . ($i === 2 ? '0' : '6px') . ' 3px 6px;text-align:right;width:84px">' . $e($col) . '</td>';
        }
        $h .= '</tr>';
        foreach ($group['lines'] as $line) {
          $style = $line['flag'] ? 'font-weight:bold;color:' . $brand : '';
          $h .= '<tr style="border-top:1px solid #eee"><td style="padding:4px 6px 4px 0">' . $e($line['title']) . ($line['flag'] ? '<br><span style="font-size:12px;font-weight:normal;color:' . $brand . '">' . $e(ucfirst($line['flag'])) . '</span>' : '') . '</td>'
            . '<td style="padding:4px 6px;text-align:right;' . $style . '">' . $e($line['value_text']) . '</td>'
            . '<td style="padding:4px 6px;text-align:right;color:#555">' . $e($line['last_text']) . '</td>'
            . '<td style="padding:4px 0 4px 6px;text-align:right;color:#555">' . $e($line['avg_text']) . '</td></tr>';
        }
        $h .= '</table>';
      }
      foreach ($role['lists'] as $list) {
        $h .= '<p style="margin:12px 0 4px;font-weight:bold">' . $e($list['title']) . '</p>';
        if ($list['items']) {
          $h .= '<ul style="margin:0;padding-left:20px">';
          foreach ($list['items'] as $item) {
            $text = is_array($item) ? $item['text'] : $item;
            $warn = is_array($item) && !empty($item['warn']);
            $h .= '<li style="margin:0 0 2px' . ($warn ? ';color:' . $brand : '') . '">' . $e($text) . '</li>';
          }
          $h .= '</ul>';
          if (!empty($list['more'])) {
            $h .= '<p style="margin:2px 0 0;color:#555">' . $e($list['more']) . '</p>';
          }
        }
        elseif ($list['empty'] !== '') {
          $h .= '<p style="margin:0;color:#555">' . $e($list['empty']) . '</p>';
        }
      }
      $h .= '<p style="margin:8px 0 0;font-size:12px"><a href="' . $e($base . $role['link']) . '" style="color:' . $brand . '">More on the ' . $e($role['title']) . ' dashboard</a></p>';
    }

    $h .= '<p style="margin:24px 0 0;font-size:12px;color:#777">Sent every Monday morning by the website. Weeks run Monday to Sunday. Tell JR what you would add or drop.</p>';
    $h .= '</div>';
    return $h;
  }

  /**
   * Sends the briefing to each recipient.
   *
   * @return int
   *   How many messages the mail system accepted.
   */
  public function send(array $briefing, array $recipients): int {
    $range = $briefing['week_start']->format('M j') . '–' . $briefing['week_end']->modify('-1 day')->format('M j');
    $params = [
      'subject' => 'Staff briefing: week of ' . $range,
      'html' => $this->renderHtml($briefing),
    ];
    $sent = 0;
    foreach ($recipients as $to) {
      $result = $this->mailManager->mail('makerspace_dashboard', 'staff_briefing', $to, 'en', $params);
      if (!empty($result['result'])) {
        $sent++;
      }
      else {
        $this->logger->error('Staff briefing to @to was not accepted by the mail system.', ['@to' => $to]);
      }
    }
    $this->logger->info('Staff briefing for @range sent to @n of @total recipients.', ['@range' => $range, '@n' => $sent, '@total' => count($recipients)]);
    return $sent;
  }

  /**
   * Formats a number for the email.
   */
  public static function format(?float $value, bool $money = FALSE): string {
    if ($value === NULL) {
      return '–';
    }
    if ($money) {
      return '$' . number_format($value, 0);
    }
    return abs($value - round($value)) < 0.05 ? number_format($value, 0) : number_format($value, 1);
  }

  /**
   * Now, in the site timezone.
   */
  protected function now(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($this->timezone());
  }

  /**
   * The most recent Monday 00:00 (today, if today is Monday).
   */
  public function lastMonday(): \DateTimeImmutable {
    $now = $this->now();
    $monday = $now->setTime(0, 0);
    return $now->format('N') === '1' ? $monday : $monday->modify('last monday');
  }

  /**
   * The site timezone.
   */
  public function timezone(): \DateTimeZone {
    return new \DateTimeZone((string) ($this->configFactory->get('system.date')->get('timezone.default') ?: 'America/New_York'));
  }

}
