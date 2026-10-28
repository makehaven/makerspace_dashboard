<?php

declare(strict_types=1);

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\makerspace_dashboard\Support\KpiFreshness;
use Drupal\makerspace_dashboard\Support\KpiStatus;
use GuzzleHttp\ClientInterface;
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
 * under half full, a tool down a long time), are ranked for discussion.
 *
 * Each role also gets a one-line count of its /kpi goals (on track / close /
 * behind, using the same rule as the /kpi colors); the full goal table is for
 * a monthly roundup, since goals barely move in a week. Only DISCUSS_TOP
 * items go to "For Tuesday"; the rest land in each role's "For a 1-on-1"
 * box. Every cron send records the KPI values, so next week's email can show what moved and flag
 * a KPI whose status changed.
 *
 * Off until a recipient list is set. A role after "=" puts that person's
 * own section first:
 *   drush state:set makerspace_dashboard.staff_briefing.recipients "a@x.org=director,b@x.org=operations"
 * Preview without sending: drush makerspace-dashboard:staff-briefing.
 */
class StaffBriefingService {

  /**
   * Comma-separated recipients, each "email" or "email=role". Empty = off.
   */
  public const STATE_RECIPIENTS = 'makerspace_dashboard.staff_briefing.recipients';

  /**
   * Y-m-d of the Monday whose briefing was last sent by cron.
   */
  public const STATE_LAST_SENT = 'makerspace_dashboard.staff_briefing.last_sent';

  /**
   * KPI values at each send: [Y-m-d Monday => [kpi id => [value, status]]].
   */
  public const STATE_KPI_HISTORY = 'makerspace_dashboard.staff_briefing.kpi_history';

  /**
   * FALSE once staff have agreed to the format; until then it says "test".
   */
  public const STATE_TEST = 'makerspace_dashboard.staff_briefing.test';

  /**
   * Items shown under "For Tuesday". The staff meeting is short; the rest go
   * to each person's section for a 1-on-1.
   */
  public const DISCUSS_TOP = 3;

  /**
   * Where feedback goes: the banner link, the footer buttons and Reply-To.
   */
  public const FEEDBACK_TO = 'jrlogan@makehaven.org';

  /**
   * Every feedback email's subject starts with this, so the replies can be
   * found with one search: subject:"Monday briefing feedback".
   */
  public const FEEDBACK_SUBJECT = 'Monday briefing feedback';

  /**
   * Discussion ranks: lower comes first. Safety and broken things lead.
   */
  public const RANK_URGENT = 1;
  public const RANK_STUCK = 2;
  public const RANK_NOTABLE = 3;

  /**
   * The Process Registry's machine-readable feed (built with its page).
   *
   * Override with makerspace_dashboard.settings:briefing_registry_url.
   */
  public const REGISTRY_URL = 'https://process.makehaven.org/registry.json';

  /**
   * Governance Dance's assistant endpoint (MCP over HTTP, stateless).
   *
   * Read with a connection token made in Governance Dance ("Connect an
   * assistant"), stored as the Pantheon secret GOVERNANCE_DANCE_BRIEFING_TOKEN
   * and mapped by settings.php to makerspace_dashboard.settings:
   * briefing_governance_token. Only the read tools are ever called.
   */
  public const GOVERNANCE_URL = 'https://governance.makehaven.org/mcp';

  /**
   * How the goals table is grouped, so each KPI is read the right way.
   *
   * KPIs keep their natural period (retention only works by join cohort, NPS
   * only by survey); grouping them with a one-line reading note stops a
   * 12-month total, today's count and a cohort rate from looking alike.
   */
  protected const GOAL_GROUPS = [
    'full_year' => [
      'title' => 'A full year of data',
      'note' => 'The last 12 months (or since logging began, for newer measures), or this year projected. Reads as "if the year ended today", so it compares directly with the annual goal.',
      'bases' => ['rolling_12', 'projected', 'last_year', 'since', ''],
    ],
    'now' => [
      'title' => 'Where we are today',
      'note' => 'Today\'s figure against where we want to be by December.',
      'bases' => ['point', 'rolling_90'],
    ],
    'so_far' => [
      'title' => 'This year so far',
      'note' => 'A share of this year\'s results to date, or the latest quarter. Fair to compare with the goal at any point in the year.',
      'bases' => ['ytd', 'quarter'],
    ],
    'slow' => [
      'title' => 'Slow measures',
      'note' => 'Retention by join group (people who joined 1–2 years ago) and survey scores. They move over months, not weeks.',
      'bases' => ['cohort', 'survey', 'multi_year'],
    ],
  ];

  /**
   * Plain names for the period a KPI value covers (KpiDataService
   * period_basis), shown next to each goal so a 12-month figure is not read
   * as last week's.
   */
  protected const PERIOD_LABELS = [
    'rolling_12' => 'last 12 months',
    'rolling_90' => 'last 90 days',
    'ytd' => 'year to date',
    'projected' => 'projected full year',
    'last_year' => 'last full year',
    'quarter' => 'last quarter',
    'point' => 'now',
    'cohort' => 'join cohorts',
    'survey' => 'survey',
    'multi_year' => 'multi-year',
    'since' => 'since logging began',
  ];

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
    protected ?KpiDataService $kpiData = NULL,
    protected ?ListeningDataService $listeningData = NULL,
    protected ?ClientInterface $httpClient = NULL,
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
    $briefing = $this->build();
    $this->recordKpis($briefing);
    $this->send($briefing, $this->recipientRoles());
  }

  /**
   * The recipient list from state.
   *
   * @return string[]
   *   Valid email addresses.
   */
  public function recipients(): array {
    return array_keys($this->recipientRoles());
  }

  /**
   * The recipient list from state, with each person's role.
   *
   * @return array
   *   [email => role key, or NULL for everyone's sections in the usual order].
   */
  public function recipientRoles(): array {
    return self::parseRecipients((string) $this->state->get(self::STATE_RECIPIENTS, ''), array_keys($this->definitions()));
  }

  /**
   * Parses "a@x.org=operations, b@x.org" into [email => role|null].
   *
   * An unknown role is dropped (the person still gets the email).
   */
  public static function parseRecipients(string $raw, array $roles): array {
    $out = [];
    foreach (array_filter(array_map('trim', preg_split('/[,\s]+/', $raw) ?: [])) as $entry) {
      [$mail, $role] = array_pad(explode('=', $entry, 2), 2, NULL);
      if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        continue;
      }
      $role = $role === NULL ? NULL : strtolower(trim($role));
      $out[$mail] = in_array($role, $roles, TRUE) ? $role : NULL;
    }
    return $out;
  }

  /**
   * Splits discussion items into the few for Tuesday and the rest by role.
   *
   * @param array $items
   *   [['text', 'role', 'rank'], ...] in the order they were found.
   * @param int $top
   *   How many go to Tuesday.
   *
   * @return array
   *   [tuesday items, [role => [items for a 1-on-1]]]. Ties keep their order.
   */
  public static function rankDiscuss(array $items, int $top): array {
    $order = array_keys($items);
    usort($order, static fn ($a, $b) => [$items[$a]['rank'], $a] <=> [$items[$b]['rank'], $b]);
    $sorted = array_map(static fn ($k) => $items[$k], $order);
    $rest = [];
    foreach (array_slice($sorted, $top) as $item) {
      $rest[$item['role']][] = $item;
    }
    return [array_slice($sorted, 0, $top), $rest];
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
        'goals' => $this->goals($def['goals'] ?? [], $weekEnd),
        'lists' => [],
        'followup' => [],
      ];
    }

    $discuss = [];
    $severity = [KpiStatus::GOOD => 0, KpiStatus::WARNING => 1, KpiStatus::POOR => 2];
    foreach ($roles as $roleKey => $role) {
      foreach ($role['goals'] as $goal) {
        if ($goal['was_status'] !== NULL && $goal['status'] !== NULL && $goal['status'] !== $goal['was_status']) {
          $worse = $severity[$goal['status']] > ($severity[$goal['was_status']] ?? 0);
          $discuss[] = $this->item($roleKey, $worse ? self::RANK_STUCK : self::RANK_NOTABLE, sprintf('%s is now %s against its %s goal (was %s): %s vs goal %s.', $goal['title'], strtolower(KpiStatus::LABELS[$goal['status']]), $goal['goal_year'], strtolower(KpiStatus::LABELS[$goal['was_status']]), $goal['value_text'], $goal['goal_text']));
        }
      }
    }
    $this->addLists($roles, $discuss, $weekEnd);
    foreach ($roles as $roleKey => $role) {
      foreach ($role['lines'] as $line) {
        if ($line['flag'] !== NULL) {
          $discuss[] = $this->item($roleKey, $line['urgent'] ? self::RANK_URGENT : self::RANK_NOTABLE, $line['discuss']);
        }
      }
    }

    $focus = $this->focus($weekEnd);
    $overdue = array_filter($focus['actions'] ?? [], static fn ($a) => $a['overdue']);
    if ($overdue) {
      $discuss[] = $this->item('director', self::RANK_STUCK, sprintf('%d board/committee %s past due: %s.', count($overdue), count($overdue) === 1 ? 'action' : 'actions', implode('; ', array_map(static fn ($a) => sprintf('%s (%s, due %s)', $a['text'], $a['owner'], date('M j', strtotime($a['due']))), $overdue))));
    }
    foreach ($focus['transitions'] as $t) {
      if (($t['to'] ?? '') === 'broken') {
        $discuss[] = $this->item('director', self::RANK_URGENT, sprintf('Process now broken: %s (was %s).', $t['name'] ?? '', $t['from'] ?? ''));
      }
    }

    [$tuesday, $followup] = self::rankDiscuss($discuss, self::DISCUSS_TOP);
    foreach ($followup as $roleKey => $items) {
      $roles[$roleKey]['followup'] = $items;
    }

    return [
      'week_start' => $weekEnd->modify('-7 days'),
      'week_end' => $weekEnd,
      'roles' => $roles,
      'discuss' => $tuesday,
      'changes' => $this->websiteChanges($weekEnd),
      'focus' => $focus,
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
        'goals' => [
          'overview' => ['kpi_total_active_members'],
          'outreach' => ['kpi_total_new_member_signups', 'kpi_tours', 'kpi_tours_to_member_conversion'],
          'finance' => ['kpi_member_revenue_quarterly', 'kpi_reserve_funds_months', 'kpi_earned_income_sustaining_core'],
          'development' => ['kpi_recurring_donors_count', 'kpi_annual_corporate_sponsorships', 'kpi_grant_pipeline_count', 'kpi_donor_retention_rate'],
        ],
        'metrics' => [
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
        'goals' => [
          'retention' => ['kpi_first_year_member_retention', 'kpi_member_post_12_month_retention', 'kpi_retention_poc', 'kpi_active_participation', 'kpi_new_member_first_badge_28_days', 'kpi_member_nps', 'kpi_membership_diversity_bipoc'],
          'outreach' => ['kpi_guest_waiver_to_member_conversion', 'kpi_event_participant_to_member_conversion'],
        ],
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
        'goals' => [
          'education' => ['kpi_workshop_attendees', 'kpi_workshop_capacity_utilization', 'kpi_program_capacity_utilization', 'kpi_education_nps', 'kpi_workshop_participants_bipoc', 'kpi_active_instructors_bipoc', 'kpi_net_income_education'],
          'outreach' => ['kpi_total_first_time_workshop_participants'],
        ],
        'metrics' => [
          'classes_held' => ['title' => 'Classes held', 'type' => 'window', 'requires' => 'civicrm_event', 'sql' => "SELECT COUNT(*) FROM civicrm_event WHERE is_active = 1 AND is_template = 0 AND event_type_id IN ($classTypes) AND start_date >= :sd AND start_date < :ed"],
          'class_seats' => ['title' => 'Class seats filled', 'type' => 'window', 'requires' => 'civicrm_event', 'sql' => "SELECT COUNT(*) FROM civicrm_participant p JOIN civicrm_event e ON e.id = p.event_id WHERE $counted AND e.is_active = 1 AND e.is_template = 0 AND e.event_type_id IN ($classTypes) AND e.start_date >= :sd AND e.start_date < :ed"],
          'registrations' => ['title' => 'Event registrations taken', 'type' => 'window', 'requires' => 'civicrm_participant', 'sql' => "SELECT COUNT(*) FROM civicrm_participant p WHERE $counted AND p.register_date >= :sd AND p.register_date < :ed"],
          'booked' => ['title' => 'Class fees booked for the next 30 days', 'type' => 'window', 'format' => 'money', 'requires' => 'civicrm_participant', 'no_flag' => TRUE, 'sql' => "SELECT IFNULL(SUM(p.fee_amount), 0) FROM civicrm_participant p JOIN civicrm_event e ON e.id = p.event_id WHERE $counted AND e.is_active = 1 AND e.is_template = 0 AND p.register_date < :ed AND e.start_date >= :ed AND e.start_date < :ed30"],
          'event_income' => ['title' => 'Event fee income', 'type' => 'window', 'format' => 'money', 'requires' => 'civicrm_contribution', 'sql' => "SELECT IFNULL(SUM(total_amount), 0) FROM civicrm_contribution WHERE is_test = 0 AND contribution_status_id = 1 AND financial_type_id = 4 AND receive_date >= :sd AND receive_date < :ed"],
          'quizzes' => ['title' => 'Badge quizzes completed', 'type' => 'window', 'requires' => 'quiz_result', 'sql' => "SELECT COUNT(*) FROM quiz_result WHERE is_invalid = 0 AND time_end >= :s AND time_end < :e"],
          'badges' => ['title' => 'Badges earned', 'type' => 'window', 'requires' => 'node__field_badge_status', 'sql' => "SELECT COUNT(*) FROM node_field_data n JOIN node__field_badge_status s ON s.entity_id = n.nid WHERE n.type = 'badge_request' AND s.field_badge_status_value = 'active' AND n.created >= :s AND n.created < :e"],
          'appointments' => ['title' => 'Facilitator appointments booked', 'type' => 'window', 'sql' => "SELECT COUNT(*) FROM node_field_data WHERE type = 'appointment' AND created >= :s AND created < :e"],
        ],
      ],
      'facility' => [
        'title' => 'Facility & safety',
        'link' => '/makerspace-dashboard/infrastructure',
        'goals' => [
          'infrastructure' => ['kpi_equipment_uptime_rate', 'kpi_equipment_investment'],
          'entrepreneurship' => ['kpi_incubator_workspace_occupancy'],
        ],
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
   * Priority and process health: the What's next board and the registry.
   *
   * Pinned and urgent board items come straight from the feedback ledger
   * (Drupal owns them). The board's ranking lives only in the registry's
   * board.js, with live votes, so the email links to it rather than
   * re-ranking here. Process state changes come from the registry's
   * registry.json; when it cannot be fetched that part is left out.
   *
   * @return array
   *   ['board' => [['title', 'why', 'url']], 'transitions' => [['name',
   *   'from', 'to', 'date']], 'broken' => [name, ...], 'counts' => [...]].
   */
  protected function focus(\DateTimeImmutable $weekEnd): array {
    $out = ['board' => [], 'transitions' => [], 'broken' => [], 'counts' => []];

    $schema = $this->database->schema();
    if ($schema->tableExists('node__field_fb_pinned') && $schema->tableExists('node__field_fb_urgent')) {
      $rows = $this->database->query("SELECT n.nid, n.title, IFNULL(p.field_fb_pinned_value, 0) pinned, IFNULL(u.field_fb_urgent_value, 0) urgent, r.field_fb_pin_reason_value reason FROM {node_field_data} n LEFT JOIN {node__field_fb_pinned} p ON p.entity_id = n.nid LEFT JOIN {node__field_fb_urgent} u ON u.entity_id = n.nid LEFT JOIN {node__field_fb_pin_reason} r ON r.entity_id = n.nid LEFT JOIN {node__field_fb_status} st ON st.entity_id = n.nid WHERE n.type = 'feedback_ledger' AND n.status = 1 AND (p.field_fb_pinned_value = 1 OR u.field_fb_urgent_value = 1) AND IFNULL(st.field_fb_status_value, '') NOT IN ('shipped', 'wont_do', 'superseded', 'not_actioned') ORDER BY pinned DESC, urgent DESC, n.created ASC")->fetchAll();
      foreach ($rows as $r) {
        $out['board'][] = [
          'title' => (string) $r->title,
          'why' => trim(($r->pinned ? 'Pinned' : '') . ($r->pinned && $r->urgent ? ', ' : '') . ($r->urgent ? 'urgent' : '') . ($r->reason ? ': ' . $r->reason : '')),
          'url' => '/node/' . $r->nid,
        ];
      }
    }

    $out += $this->governance($weekEnd);

    $feed = $this->registryFeed();
    if ($feed) {
      $start = $weekEnd->modify('-7 days')->format('Y-m-d');
      $end = $weekEnd->format('Y-m-d');
      foreach ((array) ($feed['transitions'] ?? []) as $t) {
        $date = (string) ($t['date'] ?? '');
        if ($date >= $start && $date < $end) {
          $out['transitions'][] = $t;
        }
      }
      foreach ((array) ($feed['processes'] ?? []) as $p) {
        if (($p['state'] ?? '') === 'broken') {
          $out['broken'][] = (string) $p['name'];
        }
      }
      $out['counts'] = (array) ($feed['counts'] ?? []);
    }
    return $out;
  }

  /**
   * Board and committee work from Governance Dance.
   *
   * Open actions that are overdue or due within two weeks of the briefing,
   * and meetings in the coming week. Empty when no token is configured or
   * the call fails: the briefing never waits on, or breaks for, another app.
   *
   * @return array
   *   ['actions' => [['text', 'owner', 'committee', 'due', 'overdue']],
   *   'meetings' => [['title', 'when', 'url']], 'undated' => int].
   */
  protected function governance(\DateTimeImmutable $weekEnd): array {
    $out = ['actions' => [], 'meetings' => [], 'undated' => 0];
    $actions = $this->governanceCall('list_actions', []);
    if (is_array($actions)) {
      $soon = $weekEnd->modify('+14 days')->format('Y-m-d');
      $today = $weekEnd->format('Y-m-d');
      foreach ($actions as $a) {
        $due = (string) ($a['dueDate'] ?? '');
        if ($due === '') {
          $out['undated']++;
          continue;
        }
        if ($due < $soon) {
          $out['actions'][] = [
            'text' => (string) ($a['text'] ?? ''),
            'owner' => (string) ($a['owner'] ?? ''),
            'committee' => (string) ($a['committeeId'] ?? ''),
            'due' => $due,
            'overdue' => $due < $today,
          ];
        }
      }
      usort($out['actions'], static fn ($x, $y) => strcmp($x['due'], $y['due']));
    }
    $meetings = $this->governanceCall('list_meetings', ['when' => 'upcoming', 'limit' => 15]);
    if (is_array($meetings)) {
      $until = $weekEnd->modify('+7 days')->getTimestamp();
      foreach ($meetings as $m) {
        $at = strtotime((string) ($m['scheduledAt'] ?? ''));
        if ($at && $at >= $weekEnd->getTimestamp() && $at < $until) {
          $out['meetings'][] = [
            'title' => (string) ($m['title'] ?? ''),
            'when' => (new \DateTimeImmutable('@' . $at))->setTimezone($this->timezone())->format('D M j, g:ia'),
            'url' => (string) ($m['url'] ?? ''),
          ];
        }
      }
    }
    return $out;
  }

  /**
   * Calls one read tool on Governance Dance; NULL on any failure.
   */
  protected function governanceCall(string $tool, array $arguments): ?array {
    $settings = $this->configFactory->get('makerspace_dashboard.settings');
    $token = trim((string) $settings->get('briefing_governance_token'));
    if (!$this->httpClient || $token === '' || !in_array($tool, ['list_actions', 'list_meetings'], TRUE)) {
      return NULL;
    }
    try {
      $response = $this->httpClient->request('POST', (string) ($settings->get('briefing_governance_url') ?: self::GOVERNANCE_URL), [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept' => 'application/json, text/event-stream',
        ],
        'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]],
        'timeout' => 20,
        'http_errors' => FALSE,
      ]);
      $body = json_decode((string) $response->getBody(), TRUE);
      $text = $body['result']['content'][0]['text'] ?? NULL;
      if ($response->getStatusCode() !== 200 || !is_string($text) || !empty($body['result']['isError'])) {
        $this->logger->notice('Staff briefing: Governance Dance @tool failed (HTTP @code).', ['@tool' => $tool, '@code' => $response->getStatusCode()]);
        return NULL;
      }
      $data = json_decode($text, TRUE);
      return is_array($data) ? $data : NULL;
    }
    catch (\Throwable $e) {
      $this->logger->notice('Staff briefing: Governance Dance @tool failed: @msg', ['@tool' => $tool, '@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Fetches registry.json, or NULL when unreachable or malformed.
   */
  protected function registryFeed(): ?array {
    if (!$this->httpClient) {
      return NULL;
    }
    $url = (string) ($this->configFactory->get('makerspace_dashboard.settings')->get('briefing_registry_url') ?: self::REGISTRY_URL);
    try {
      $response = $this->httpClient->request('GET', $url, ['timeout' => 10, 'http_errors' => FALSE]);
      if ($response->getStatusCode() !== 200) {
        return NULL;
      }
      $data = json_decode((string) $response->getBody(), TRUE);
      return is_array($data) && isset($data['processes']) ? $data : NULL;
    }
    catch (\Throwable $e) {
      $this->logger->notice('Staff briefing could not read the process registry: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * What's New entries dated in the week: what shipped on the website.
   *
   * Every deploy writes these for members (/whats-new), so the staff email
   * repeats the same plain-language lines rather than a second summary.
   *
   * @return array
   *   [['title', 'kind' (Added|Changed|Fixed|Removed), 'url'], ...].
   */
  protected function websiteChanges(\DateTimeImmutable $weekEnd): array {
    if (!$this->database->schema()->tableExists('node__field_cl_date')) {
      return [];
    }
    $rows = $this->database->query("SELECT n.nid, n.title, c.field_cl_category_value kind, u.field_cl_url_uri uri FROM {node_field_data} n JOIN {node__field_cl_date} d ON d.entity_id = n.nid LEFT JOIN {node__field_cl_category} c ON c.entity_id = n.nid LEFT JOIN {node__field_cl_url} u ON u.entity_id = n.nid WHERE n.type = 'changelog_entry' AND n.status = 1 AND d.field_cl_date_value >= :s AND d.field_cl_date_value < :e ORDER BY FIELD(c.field_cl_category_value, 'added', 'changed', 'fixed', 'removed'), n.nid", [
      ':s' => $weekEnd->modify('-7 days')->format('Y-m-d'),
      ':e' => $weekEnd->format('Y-m-d'),
    ])->fetchAll();
    $out = [];
    foreach ($rows as $r) {
      $uri = (string) $r->uri;
      $out[] = [
        'title' => (string) $r->title,
        'kind' => ucfirst((string) ($r->kind ?: 'changed')),
        'url' => str_starts_with($uri, 'internal:') ? substr($uri, 9) : ($uri !== '' ? $uri : '/whats-new'),
      ];
    }
    return $out;
  }

  /**
   * A role's KPIs against their goals, as /kpi shows them.
   *
   * @param array $map
   *   [dashboard section id => [kpi id, ...]].
   * @param \DateTimeImmutable $weekEnd
   *   The briefing's week end; last week's recorded values are shown beside.
   *
   * @return array
   *   [kpi id => ['title', 'value_text', 'goal_text', 'goal_year', 'period',
   *   'status' (KpiStatus constant|null), 'was_text', 'was_status']].
   */
  protected function goals(array $map, \DateTimeImmutable $weekEnd): array {
    if (!$this->kpiData || !$map) {
      return [];
    }
    $history = $this->state->get(self::STATE_KPI_HISTORY, []);
    $previous = is_array($history) ? ($history[$weekEnd->modify('-7 days')->format('Y-m-d')] ?? []) : [];

    $out = [];
    foreach ($map as $section => $ids) {
      try {
        $data = $this->freshSection($section);
      }
      catch (\Throwable $e) {
        $this->logger->error('Staff briefing could not read @section KPIs: @msg', ['@section' => $section, '@msg' => $e->getMessage()]);
        continue;
      }
      foreach ($ids as $id) {
        $kpi = $data[$id] ?? NULL;
        if (!$kpi || !is_numeric($kpi['current'] ?? NULL)) {
          continue;
        }
        $format = $kpi['display_format'] ?? NULL;
        $comparable = KpiFreshness::canCompare($kpi);
        $status = $comparable ? KpiStatus::status($kpi['current'], $kpi['goal_current_year'] ?? NULL, $format, (string) ($kpi['goal_direction'] ?? 'higher'), isset($kpi['period_fraction']) ? (float) $kpi['period_fraction'] : 1.0) : NULL;
        $was = $previous[$id] ?? NULL;
        $out[$id] = [
          'title' => (string) ($kpi['label'] ?? $id),
          'value' => (float) $kpi['current'],
          'value_text' => KpiStatus::format($kpi['current'], $format) ?? '–',
          'goal_text' => KpiStatus::format($kpi['goal_current_year'] ?? NULL, $format) ?? '–',
          'goal_year' => (string) ($kpi['goal_current_year_label'] ?? date('Y')),
          'period' => self::PERIOD_LABELS[$kpi['period_basis'] ?? ''] ?? '',
          'group' => self::goalGroup((string) ($kpi['period_basis'] ?? '')),
          'status' => $status,
          'was_text' => is_array($was) ? (KpiStatus::format($was['value'] ?? NULL, $format) ?? '–') : '–',
          'was_status' => is_array($was) ? ($was['status'] ?? NULL) : NULL,
        ];
      }
    }
    return $out;
  }

  /**
   * The GOAL_GROUPS key for a KPI period basis.
   */
  public static function goalGroup(string $basis): string {
    foreach (self::GOAL_GROUPS as $key => $group) {
      if (in_array($basis, $group['bases'], TRUE)) {
        return $key;
      }
    }
    return 'full_year';
  }

  /**
   * A section's KPIs, recomputed first if the stored copy has expired.
   *
   * KpiFreshness refuses to compare an expired value with its goal; the
   * hourly warm queue may not have run before the Monday send.
   */
  protected function freshSection(string $section): array {
    $data = $this->kpiData->getKpiData($section);
    foreach ($data as $kpi) {
      if (($kpi['refresh_status'] ?? '') !== 'current') {
        $this->kpiData->warmSection($section);
        return $this->kpiData->getKpiData($section);
      }
    }
    return $data;
  }

  /**
   * Remembers this week's KPI values for next week's comparison.
   */
  protected function recordKpis(array $briefing): void {
    $values = [];
    foreach ($briefing['roles'] as $role) {
      foreach ($role['goals'] as $id => $goal) {
        $values[$id] = ['value' => $goal['value'], 'status' => $goal['status']];
      }
    }
    $history = $this->state->get(self::STATE_KPI_HISTORY, []);
    $history = is_array($history) ? $history : [];
    $history[$briefing['week_end']->format('Y-m-d')] = $values;
    krsort($history);
    $this->state->set(self::STATE_KPI_HISTORY, array_slice($history, 0, 12, TRUE));
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
      foreach ([':s' => $start->getTimestamp(), ':e' => $end->getTimestamp(), ':sd' => $start->format('Y-m-d H:i:s'), ':ed' => $end->format('Y-m-d H:i:s'), ':sday' => $start->format('Y-m-d'), ':eday' => $end->format('Y-m-d'), ':ed30' => $end->modify('+30 days')->format('Y-m-d H:i:s')] as $ph => $v) {
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
    // 'no_flag': the history is biased, so a range check would mislead. Past
    // weeks of booked fees only count registrations still active today
    // (later cancellations vanish), which understates every past week.
    if ($now !== NULL && count($history) >= self::COMPARE_WEEKS && empty($metric['no_flag'])) {
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
      'arrow_text' => self::arrow($stock ? $now : (($value !== NULL && $last !== NULL) ? $value - $last : NULL), $money),
      // The nine weekly values (levels, also for stocks), oldest first.
      'spark' => array_reverse(array_slice($series, 0, self::COMPARE_WEEKS + 1)),
      // A median of counts reads as a count: "107", not "106.5".
      'avg_text' => $stock ? self::signed(round($usual ?? 0.0), $money) : self::format($usual === NULL ? NULL : round($usual), $money),
      'stock' => $stock,
      'flag' => $flag,
      'discuss' => $discuss,
      'urgent' => !empty($metric['always_discuss']),
    ];
  }

  /**
   * One discussion item.
   */
  protected function item(string $role, int $rank, string $text): array {
    return ['text' => $text, 'role' => $role, 'rank' => $rank];
  }

  /**
   * A change as an arrow: "▲ 4", "▼ 2", "same".
   *
   * Arrows are neutral (no green/red): up is good for joins and bad for
   * failed payments, and color is kept for "outside the normal range".
   */
  public static function arrow(?float $change, bool $money = FALSE): string {
    if ($change === NULL) {
      return '';
    }
    if (abs($change) < 0.05) {
      return 'same';
    }
    return ($change > 0 ? '▲ ' : '▼ ') . self::format(abs($change), $money);
  }

  /**
   * Nine weeks as a tiny bar chart built from table cells.
   *
   * Mail clients strip SVG and scripts, so each bar is a div with a height
   * inside a bottom-aligned cell. Bars start at zero (a flat line means flat
   * numbers); history is light gray, the latest week dark, or the brand color
   * when the line is flagged.
   */
  public static function sparkHtml(array $values, bool $flagged, string $brand, bool $right = TRUE): string {
    $values = array_map(static fn ($v) => $v === NULL ? NULL : max(0.0, (float) $v), $values);
    $max = max(array_filter($values, static fn ($v) => $v !== NULL) ?: [0.0]);
    $height = 18;
    $last = count($values) - 1;
    $cells = '';
    foreach (array_values($values) as $i => $v) {
      $h = ($v === NULL || $max <= 0) ? 0 : max(1, (int) round($height * $v / $max));
      $color = $i === $last ? ($flagged ? $brand : '#555555') : '#c4c4c4';
      $bar = $h > 0
        ? '<div style="width:5px;height:' . $h . 'px;background:' . $color . ';border-radius:1px 1px 0 0;font-size:0;line-height:0">&nbsp;</div>'
        : '<div style="width:5px;height:1px;background:#e2e2e2;font-size:0;line-height:0">&nbsp;</div>';
      $cells .= '<td style="vertical-align:bottom;padding:0 2px 0 0;height:' . $height . 'px">' . $bar . '</td>';
    }
    return '<table cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse' . ($right ? ';margin-left:auto' : '') . '"><tr>' . $cells . '</tr></table>';
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
        $discuss[] = $this->item('director', self::RANK_URGENT, 'Website early-warning alarms tripped: ' . count($items) . '.');
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

    // Operations: storage units in use.
    if ($schema->tableExists('storage_unit__field_storage_status')) {
      $counts = $this->database->query("SELECT field_storage_status_value, COUNT(*) FROM {storage_unit__field_storage_status} WHERE deleted = 0 GROUP BY field_storage_status_value")->fetchAllKeyed();
      $total = array_sum(array_map('intval', $counts));
      if ($total > 0) {
        $roles['operations']['lists'][] = [
          'title' => sprintf('Storage: %d of %d units occupied, %d vacant', (int) ($counts['occupied'] ?? 0), $total, (int) ($counts['vacant'] ?? 0)),
          'items' => [],
          'empty' => '',
        ];
      }
    }

    // Operations: feedback channels that have gone quiet. A channel going
    // silent (a broken form, a dead link) writes no error; this is how the
    // Listening dashboard catches it.
    if ($this->listeningData) {
      try {
        // Only channels that went quiet in the last ~6 months are listed:
        // long-dead ones (a 2022 evaluation form) would repeat every week.
        $quiet = [];
        $longSilent = 0;
        foreach ($this->listeningData->getSources() as $source) {
          $state = (string) ($source['liveness']['value'] ?? '');
          if (!in_array($state, ['dormant', 'dead'], TRUE)) {
            continue;
          }
          $days = (int) ($source['days_silent'] ?? 0);
          if ($days > 180) {
            $longSilent++;
            continue;
          }
          $quiet[] = ['text' => sprintf('%s — nothing in %d days', $source['label'], $days), 'warn' => $state === 'dead'];
        }
        $roles['operations']['lists'][] = [
          'title' => sprintf('Feedback channels that went quiet recently: %d', count($quiet)),
          'items' => $quiet,
          'more' => $longSilent ? sprintf('%d more have been silent over six months (Listening dashboard).', $longSilent) : NULL,
          'empty' => 'Every active feedback channel is receiving submissions.',
        ];
      }
      catch (\Throwable $e) {
        $this->logger->error('Staff briefing could not read feedback channels: @msg', ['@msg' => $e->getMessage()]);
      }
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
      $rows = $this->database->query("SELECT e.id, e.title, e.start_date, e.max_participants cap, (SELECT COUNT(*) FROM civicrm_participant p WHERE p.event_id = e.id AND p.is_test = 0 AND p.status_id IN (SELECT id FROM civicrm_participant_status_type WHERE is_counted = 1)) n, (SELECT COUNT(*) FROM civicrm_participant p JOIN civicrm_participant_status_type st ON st.id = p.status_id WHERE p.event_id = e.id AND p.is_test = 0 AND st.name = 'On waitlist') w FROM civicrm_event e WHERE e.is_active = 1 AND e.is_template = 0 AND e.event_type_id IN (" . implode(',', self::CLASS_EVENT_TYPES) . ") AND e.start_date >= :s AND e.start_date < :e ORDER BY e.start_date", [
        ':s' => $weekEnd->format('Y-m-d H:i:s'),
        ':e' => $weekEnd->modify('+14 days')->format('Y-m-d H:i:s'),
      ])->fetchAll();
      $items = [];
      $low = [];
      $waiting = [];
      foreach ($rows as $r) {
        $cap = (int) $r->cap;
        $isLow = $cap > 0 && ((int) $r->n / $cap) < self::LOW_FILL;
        $wait = (int) $r->w;
        $items[] = ['text' => sprintf('%s — %s: %d%s%s', date('D M j', strtotime($r->start_date)), $r->title, (int) $r->n, $cap ? " of $cap" : ' registered', $wait ? ", $wait on the waitlist" : ''), 'warn' => $isLow];
        if ($isLow) {
          $low[] = sprintf('%s (%s, %d of %d)', $r->title, date('M j', strtotime($r->start_date)), (int) $r->n, $cap);
        }
        if ($wait) {
          $waiting[] = sprintf('%s (%s): %d waiting', $r->title, date('M j', strtotime($r->start_date)), $wait);
        }
      }
      if ($waiting) {
        $discuss[] = $this->item('education', self::RANK_NOTABLE, 'Waitlists — demand for another session: ' . implode('; ', $waiting) . '.');
      }
      $roles['education']['lists'][] = [
        'title' => sprintf('Classes in the next two weeks: %d, %d under half full', count($rows), count($low)),
        'items' => $items,
        'empty' => 'No classes scheduled.',
      ];
      if ($low) {
        $discuss[] = $this->item('education', self::RANK_NOTABLE, 'Classes under half full in the next two weeks: ' . implode('; ', $low) . '.');
      }
    }

    // Facility: tools not in normal service, and whether anyone is on it.
    // A "Reported Concern" does not take a tool down (JR 2026-10-28); one
    // that sits unresolved is the problem. "Unresolved" uses asset_status's
    // own stale thresholds (Reported Concern 14 days, Degraded 30, …), the
    // same ones that nudge #broken, so the email and Slack agree.
    if ($schema->tableExists('node__field_item_status')) {
      $thresholds = (array) ($this->configFactory->get('asset_status.settings')->get('stale_days') ?: ['Reported Concern' => 14]);
      $rows = $this->database->query("SELECT n.nid, n.title, n.changed, t.name status, (SELECT MAX(l.created) FROM {asset_log_entry} l WHERE l.asset = n.nid) logged FROM {node_field_data} n JOIN {node__field_item_status} s ON s.entity_id = n.nid AND s.deleted = 0 JOIN {taxonomy_term_field_data} t ON t.tid = s.field_item_status_target_id WHERE n.type = 'item' AND n.status = 1 AND t.name NOT IN (:ok[])", [':ok[]' => self::TOOL_STATUS_OK])->fetchAll();
      $tools = [];
      foreach ($rows as $r) {
        // Any log entry (a note, a confirmation) counts as someone on it.
        $since = (int) ($r->logged ?: $r->changed);
        $days = (int) floor(($now - $since) / 86400);
        $limit = (int) ($thresholds[$r->status] ?? 0);
        $tools[] = [
          'days' => $days,
          'text' => $r->title,
          'status' => $r->status,
          'new' => $since >= $now - 7 * 86400,
          'stale' => $limit > 0 && $days >= $limit,
        ];
      }
      usort($tools, static fn ($a, $b) => $b['days'] <=> $a['days']);
      $items = array_map(static fn ($t) => [
        'text' => sprintf('%s — %s, %s', $t['text'], $t['status'], $t['new'] ? 'new this week' : sprintf('no update in %d days', $t['days'])),
        'warn' => $t['stale'],
      ], $tools);
      $roles['facility']['lists'][] = [
        'title' => sprintf('Tools not in normal service: %d', count($tools)),
        'items' => $items,
        'empty' => 'Every tool is operational.',
      ];
      $stale = array_filter($tools, static fn ($t) => $t['stale']);
      if ($stale) {
        $discuss[] = $this->item('facility', self::RANK_STUCK, sprintf('%d tool %s with no update past the follow-up window: %s.', count($stale), count($stale) === 1 ? 'issue' : 'issues', implode('; ', array_map(static fn ($t) => sprintf('%s (%s, %d days)', $t['text'], strtolower($t['status']), $t['days']), $stale))));
      }
    }
  }

  /**
   * Renders the email body (inline styles: mail clients drop <style>).
   *
   * @param array $briefing
   *   From build().
   * @param string|null $forRole
   *   The reader's role: their section comes first, the others follow under
   *   "The rest of the team". NULL keeps the usual order.
   */
  public function renderHtml(array $briefing, ?string $forRole = NULL): string {
    $base = rtrim((string) ($this->configFactory->get('makerspace_dashboard.settings')->get('briefing_base_url') ?: 'https://www.makehaven.org'), '/');
    $e = static fn ($s) => Html::escape((string) $s);
    $brand = '#8b1919';
    $ink = '#2b2522';
    $muted = '#6b625d';
    $line = '#ece6e1';
    $range = $briefing['week_start']->format('M j') . ' – ' . $briefing['week_end']->modify('-1 day')->format('M j');
    $test = (bool) $this->state->get(self::STATE_TEST, TRUE);
    $card = 'background:#ffffff;border-radius:10px;padding:18px 20px;margin:0 0 14px;border:1px solid ' . $line;
    $h2 = 'font-size:17px;margin:0 0 10px;color:' . $ink;
    $small = 'font-size:12px;color:' . $muted;
    $roleTitles = array_map(static fn ($r) => $r['title'], $briefing['roles']);

    $h = '<div style="background:#f6f2ee;padding:16px 8px;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:' . $ink . ';line-height:1.5">';
    $h .= '<div style="max-width:640px;margin:0 auto">';

    // Header.
    $h .= '<div style="background:' . $brand . ';color:#ffffff;border-radius:10px;padding:18px 20px;margin:0 0 14px">'
      . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85">MakeHaven · Monday briefing' . ($test ? ' · test' : '') . '</div>'
      . '<div style="font-size:24px;font-weight:bold;margin:4px 0 2px">Last week, ' . $e($range) . '</div>'
      . '<div style="font-size:14px;opacity:.9">A quick look before Tuesday\'s staff meeting.</div></div>';

    $mailto = static fn ($subject, $body = '') => 'mailto:' . self::FEEDBACK_TO . '?subject=' . rawurlencode(self::FEEDBACK_SUBJECT . ($subject !== '' ? ': ' . $subject : '')) . ($body !== '' ? '&body=' . rawurlencode($body) : '');
    if ($test) {
      $h .= '<div style="background:#fff6d9;border:1px solid #e9c46a;border-radius:10px;padding:14px 18px;margin:0 0 14px;font-size:14px">'
        . '<b>This is a test.</b> JR is trying out a Monday email that sums up the week before our staff meeting. '
        . 'Nothing here is new work for you, and it isn\'t a review of anyone. '
        . 'Skim your own section, then <a href="' . $e($mailto('', "What helped:\n\nWhat was confusing:\n\nWhat to drop:\n\nWhat's missing:\n")) . '" style="color:' . $brand . ';font-weight:bold">tell JR</a> what helped, what was confusing, and what to drop. You can also just reply to this email.</div>';
    }

    // For Tuesday.
    $h .= '<div style="' . $card . '"><h2 style="' . $h2 . '">For Tuesday</h2>';
    if ($briefing['discuss']) {
      $h .= '<table cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;width:100%">';
      foreach (array_values($briefing['discuss']) as $i => $item) {
        $h .= '<tr><td style="vertical-align:top;padding:4px 12px 10px 0;width:28px">'
          . '<div style="width:26px;height:26px;border-radius:13px;background:' . $brand . ';color:#ffffff;font-weight:bold;text-align:center;line-height:26px;font-size:14px">' . ($i + 1) . '</div></td>'
          . '<td style="vertical-align:top;padding:4px 0 12px">' . self::headline($item['text'], $e)
          . (isset($roleTitles[$item['role']]) ? ' <span style="' . $small . ';white-space:nowrap">· ' . $e($roleTitles[$item['role']]) . '</span>' : '') . '</td></tr>';
      }
      $h .= '</table>';
      $more = array_sum(array_map(static fn ($r) => count($r['followup']), $briefing['roles']));
      if ($more) {
        $h .= '<p style="margin:2px 0 0;' . $small . '">' . $e(sprintf('%d more %s in the sections below, for 1-on-1s.', $more, $more === 1 ? 'item is' : 'items are')) . '</p>';
      }
    }
    else {
      $h .= '<p style="margin:0">Nothing unusual last week.</p>';
    }
    $h .= '</div>';

    // Sections: the reader's own first.
    $order = array_keys($briefing['roles']);
    if ($forRole !== NULL && isset($briefing['roles'][$forRole])) {
      $order = array_merge([$forRole], array_diff($order, [$forRole]));
    }
    $sections = [];
    foreach ($order as $key) {
      // Other people's lists are shortened; the reader's own are kept.
      $cap = ($forRole === NULL || $key === $forRole) ? 10 : 3;
      $sections[$key] = $this->renderRole($briefing['roles'][$key], $key === $forRole, $base, $e, compact('brand', 'ink', 'muted', 'line', 'card', 'h2', 'small'), $cap);
    }
    $mine = ($forRole !== NULL && isset($sections[$forRole])) ? array_shift($sections) : '';
    $h .= $mine;

    $h .= $this->renderNews($briefing, $base, $e, compact('brand', 'ink', 'muted', 'line', 'card', 'h2', 'small'));

    if ($sections) {
      if ($mine !== '') {
        $h .= '<div style="margin:22px 4px 10px;font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:' . $muted . '">The rest of the team</div>';
      }
      $h .= implode('', $sections);
    }

    // Footer: how to read it, and an easy way to answer.
    $feedback = static fn ($label, $subject) => '<a href="' . $e($mailto($subject)) . '" style="display:inline-block;margin:0 6px 6px 0;padding:7px 12px;border-radius:16px;border:1px solid ' . $brand . ';color:' . $brand . ';text-decoration:none;font-size:13px">' . $label . '</a>';
    $h .= '<div style="' . $card . '"><div style="font-weight:bold;margin:0 0 8px">Was this useful?</div>'
      . $feedback('Useful', 'useful') . $feedback('Too long', 'too long') . $feedback('Confusing', 'confusing') . $feedback('Add something', 'please add')
      . '<p style="margin:8px 0 0;' . $small . '">"Typical" is a normal week over the last two months. A number in red is higher or lower than any of those weeks. The little bars under each name show the last nine weeks.</p></div>';
    $h .= '<p style="margin:6px 4px 0;' . $small . '">Sent by the website on Monday mornings. Weeks run Monday to Sunday.</p>';
    $h .= '</div></div>';
    return $h;
  }

  /**
   * "Headline: details" as a bold headline over smaller details.
   */
  protected static function headline(string $text, callable $e): string {
    $parts = explode(': ', $text, 2);
    if (count($parts) < 2 || mb_strlen($parts[0]) > 90) {
      return $e($text);
    }
    return '<b>' . $e($parts[0]) . '</b><div style="font-size:13px;color:#6b625d;margin-top:2px">' . $e($parts[1]) . '</div>';
  }

  /**
   * One role's card: numbers, goal summary, lists, and 1-on-1 items.
   */
  protected function renderRole(array $role, bool $mine, string $base, callable $e, array $s, int $listCap = 10): string {
    $h = '<div style="' . $s['card'] . ($mine ? ';border-top:4px solid ' . $s['brand'] : '') . '">';
    $h .= ($mine ? '<div style="' . $s['small'] . ';text-transform:uppercase;letter-spacing:.06em">Your area</div>' : '')
      . '<h2 style="' . $s['h2'] . '">' . $e($role['title']) . '</h2>';

    $groups = [
      'Right now' => ['cols' => ['Now', 'Last week', 'Typical'], 'lines' => array_filter($role['lines'], static fn ($l) => $l['stock'])],
      'Last week' => ['cols' => ['Count', 'vs. before', 'Typical'], 'lines' => array_filter($role['lines'], static fn ($l) => !$l['stock'])],
    ];
    foreach ($groups as $label => $group) {
      if (!$group['lines']) {
        continue;
      }
      $h .= '<table cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;width:100%;margin:4px 0 10px">';
      $h .= '<tr style="' . $s['small'] . '"><td style="padding:2px 6px 4px 0;font-weight:bold;text-transform:uppercase;letter-spacing:.05em">' . $e($label) . '</td>';
      foreach ($group['cols'] as $col) {
        $h .= '<td style="padding:2px 0 4px 8px;text-align:right;width:58px">' . $e($col) . '</td>';
      }
      $h .= '</tr>';
      foreach ($group['lines'] as $l) {
        $flag = (bool) $l['flag'];
        $h .= '<tr style="border-top:1px solid ' . $s['line'] . '"><td style="padding:8px 0">' . $e($l['title'])
          . ($flag ? '<div style="font-size:12px;color:' . $s['brand'] . '">' . $e(ucfirst($l['flag'])) . '</div>' : '')
          . '<div style="margin-top:4px">' . self::sparkHtml($l['spark'], $flag, $s['brand'], FALSE) . '</div></td>'
          . '<td style="padding:8px 0 8px 8px;text-align:right;font-weight:bold;font-size:16px;' . ($flag ? 'color:' . $s['brand'] : '') . '">' . $e($l['value_text']) . '</td>'
          . '<td style="padding:8px 0 8px 8px;text-align:right;font-size:13px;color:' . $s['muted'] . ';white-space:nowrap">' . $e($l['arrow_text']) . '</td>'
          . '<td style="padding:8px 0 8px 8px;text-align:right;font-size:13px;color:' . $s['muted'] . '">' . $e($l['avg_text']) . '</td></tr>';
      }
      $h .= '</table>';
    }

    // Goals: one line. The full table belongs to the monthly roundup.
    if ($role['goals']) {
      $counts = array_count_values(array_filter(array_map(static fn ($g) => $g['status'], $role['goals'])));
      $chip = static fn ($n, $text, $bg, $fg) => $n ? '<span style="display:inline-block;margin:0 4px 4px 0;padding:2px 9px;border-radius:12px;background:' . $bg . ';color:' . $fg . ';font-size:13px;white-space:nowrap">' . $n . ' ' . $text . '</span>' : '';
      $year = reset($role['goals'])['goal_year'];
      $h .= '<div style="margin:2px 0 8px;font-size:13px"><span style="color:' . $s['muted'] . '">' . $e($year) . ' goals: </span>'
        . $chip($counts[KpiStatus::GOOD] ?? 0, 'on track', '#e5f3e8', '#1e6b31')
        . $chip($counts[KpiStatus::WARNING] ?? 0, 'close', '#fbf0d5', '#7a5300')
        . $chip($counts[KpiStatus::POOR] ?? 0, 'behind', '#f7e1e1', $s['brand'])
        . ' <a href="' . $e($base . '/kpi') . '" style="color:' . $s['brand'] . ';white-space:nowrap">see goals</a></div>';
    }

    foreach ($role['lists'] as $list) {
      $h .= '<div style="margin:12px 0 4px;font-weight:bold">' . $e($list['title']) . '</div>';
      if ($list['items']) {
        $h .= '<ul style="margin:0;padding-left:18px">';
        foreach (array_slice($list['items'], 0, $listCap) as $item) {
          $text = is_array($item) ? $item['text'] : $item;
          $warn = is_array($item) && !empty($item['warn']);
          $h .= '<li style="margin:0 0 3px' . ($warn ? ';color:' . $s['brand'] : '') . '">' . $e($text) . '</li>';
        }
        $h .= '</ul>';
        $hidden = count($list['items']) - $listCap;
        if ($hidden > 0 || !empty($list['more'])) {
          $h .= '<div style="margin:2px 0 0;' . $s['small'] . '">' . $e($hidden > 0 ? "+$hidden more on the dashboard." : $list['more']) . '</div>';
        }
      }
      elseif ($list['empty'] !== '') {
        $h .= '<div style="color:' . $s['muted'] . '">' . $e($list['empty']) . '</div>';
      }
    }

    if ($role['followup']) {
      $h .= '<div style="margin:14px 0 4px;background:#f6f2ee;border-radius:8px;padding:10px 14px"><div style="font-weight:bold;margin:0 0 4px">For a 1-on-1</div><ul style="margin:0;padding-left:18px">';
      foreach ($role['followup'] as $item) {
        $h .= '<li style="margin:0 0 3px">' . $e($item['text']) . '</li>';
      }
      $h .= '</ul></div>';
    }

    $h .= '<div style="margin:10px 0 0;font-size:13px"><a href="' . $e($base . $role['link']) . '" style="color:' . $s['brand'] . '">Open the ' . $e($role['title']) . ' dashboard →</a></div>';
    return $h . '</div>';
  }

  /**
   * Website changes, board and committee work, and process changes.
   */
  protected function renderNews(array $briefing, string $base, callable $e, array $s): string {
    $h = '';
    if (!empty($briefing['changes'])) {
      $h .= '<div style="' . $s['card'] . '"><h2 style="' . $s['h2'] . '">New on the website</h2><ul style="margin:0;padding-left:18px">';
      foreach (array_slice($briefing['changes'], 0, 8) as $c) {
        $url = str_starts_with($c['url'], 'http') ? $c['url'] : $base . $c['url'];
        $h .= '<li style="margin:0 0 4px"><a href="' . $e($url) . '" style="color:' . $s['ink'] . '">' . $e($c['title']) . '</a> <span style="' . $s['small'] . '">' . $e(strtolower($c['kind'])) . '</span></li>';
      }
      $h .= '</ul>';
      $more = count($briefing['changes']) - 8;
      $h .= '<div style="margin:6px 0 0;font-size:13px"><a href="' . $e($base . '/whats-new') . '" style="color:' . $s['brand'] . '">' . ($more > 0 ? $e("$more more on What's New →") : "Everything on What's New →") . '</a></div></div>';
    }

    $focus = $briefing['focus'] ?? [];
    if (empty($focus['board']) && empty($focus['transitions']) && empty($focus['broken']) && empty($focus['actions']) && empty($focus['meetings'])) {
      return $h;
    }
    $sub = 'margin:12px 0 4px;font-weight:bold';
    $h .= '<div style="' . $s['card'] . '"><h2 style="' . $s['h2'] . '">Board, priorities and processes</h2>';
    if (!empty($focus['meetings'])) {
      $h .= '<div style="' . $sub . ';margin-top:0">Meetings this week</div><ul style="margin:0;padding-left:18px">';
      foreach ($focus['meetings'] as $m) {
        $title = $m['url'] !== '' ? '<a href="' . $e($m['url']) . '" style="color:' . $s['ink'] . '">' . $e($m['title']) . '</a>' : $e($m['title']);
        $h .= '<li style="margin:0 0 3px">' . $title . ' <span style="' . $s['small'] . '">' . $e($m['when']) . '</span></li>';
      }
      $h .= '</ul>';
    }
    if (!empty($focus['actions'])) {
      $h .= '<div style="' . $sub . '">Board and committee actions due</div><ul style="margin:0;padding-left:18px">';
      foreach ($focus['actions'] as $a) {
        $h .= '<li style="margin:0 0 3px' . ($a['overdue'] ? ';color:' . $s['brand'] : '') . '">' . $e($a['text']) . ' <span style="' . $s['small'] . '">' . $e(trim($a['owner'] . ($a['committee'] !== '' ? ', ' . $a['committee'] : '') . ', ' . ($a['overdue'] ? 'was due ' : 'due ') . date('M j', strtotime($a['due'])))) . '</span></li>';
      }
      $h .= '</ul>';
    }
    if (!empty($focus['board'])) {
      $h .= '<div style="' . $sub . '">Pinned and urgent on the What\'s next board</div><ul style="margin:0;padding-left:18px">';
      foreach ($focus['board'] as $b) {
        $h .= '<li style="margin:0 0 3px"><a href="' . $e($base . $b['url']) . '" style="color:' . $s['ink'] . '">' . $e($b['title']) . '</a> <span style="' . $s['small'] . '">' . $e($b['why']) . '</span></li>';
      }
      $h .= '</ul>';
    }
    if (!empty($focus['transitions'])) {
      $h .= '<div style="' . $sub . '">Processes that changed</div><ul style="margin:0;padding-left:18px">';
      foreach ($focus['transitions'] as $t) {
        $warn = ($t['to'] ?? '') === 'broken';
        $h .= '<li style="margin:0 0 3px' . ($warn ? ';color:' . $s['brand'] : '') . '">' . $e($t['name'] ?? '') . ': ' . $e($t['from'] ?? '') . ' → <b>' . $e($t['to'] ?? '') . '</b></li>';
      }
      $h .= '</ul>';
    }
    if (!empty($focus['broken'])) {
      $h .= '<div style="margin:10px 0 0;color:' . $s['brand'] . '"><b>Broken right now:</b> ' . $e(implode('; ', $focus['broken'])) . '</div>';
    }
    $h .= '<div style="margin:10px 0 0;font-size:13px"><a href="https://process.makehaven.org/" style="color:' . $s['brand'] . '">All processes on process.makehaven.org →</a></div></div>';
    return $h;
  }

  /**
   * Sends the briefing to each recipient, their own section first.
   *
   * @param array $briefing
   *   From build().
   * @param array $recipients
   *   [email => role|null], or a plain list of emails.
   *
   * @return int
   *   How many messages the mail system accepted.
   */
  public function send(array $briefing, array $recipients): int {
    $range = $briefing['week_start']->format('M j') . '–' . $briefing['week_end']->modify('-1 day')->format('M j');
    $test = (bool) $this->state->get(self::STATE_TEST, TRUE);
    $subject = ($test ? '[Test] ' : '') . 'Monday briefing: last week, ' . $range;
    $sent = 0;
    foreach ($recipients as $key => $value) {
      [$to, $role] = is_int($key) ? [$value, NULL] : [$key, $value];
      $params = ['subject' => $subject, 'html' => $this->renderHtml($briefing, $role), 'reply_to' => self::FEEDBACK_TO];
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
