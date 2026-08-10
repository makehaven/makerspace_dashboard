<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Aggregates every channel MakeHaven listens on into one view.
 *
 * The organisation hears from people through twenty-five different mechanisms
 * spanning four storage classes: webforms, a node type, fields hung off nodes
 * and profiles, and a bespoke table written by the tool chatbot. Nothing
 * previously watched them as a set, which is how several went quiet without
 * anyone noticing.
 *
 * It is called listening rather than feedback deliberately. "Feedback" reads
 * as surveys and comment forms, and while the narrower word was in use the
 * inventory quietly omitted mess reports, broken-equipment reports and — most
 * damagingly — the membership cancellation reasons synced back from Chargebee,
 * which turn out to be the best-covered channel in the whole organisation. The
 * word we used was deciding what got counted.
 *
 * Every figure this service returns is wrapped by ::claim() and carries an
 * explicit evidence tier — the Observe / Substantiate / Think / Assume lens.
 * The tier is not decoration. A count pulled from a system of record and a
 * threshold-based judgement about whether a channel is "dead" are different
 * kinds of statement, and rendering them identically is precisely what makes a
 * dashboard (or an AI-written summary) read as uniformly authoritative when it
 * is not. Anything above OBSERVED must declare what it rests on and what would
 * falsify it, or ::claim() downgrades it to unsupported and the section renders
 * it as a defect rather than a finding.
 */
class ListeningDataService {

  use StringTranslationTrait;

  /**
   * Counted directly from a system of record. No interpretation applied.
   */
  public const EVIDENCE_OBSERVED = 'observed';

  /**
   * An observation corroborated by a second, independent system.
   */
  public const EVIDENCE_SUBSTANTIATED = 'substantiated';

  /**
   * Our reasoning over observations. True only if the reasoning holds.
   */
  public const EVIDENCE_INFERRED = 'inferred';

  /**
   * Acted on without evidence. Registered so it can be argued with.
   */
  public const EVIDENCE_ASSUMED = 'assumed';

  /**
   * A claim that failed the evidence guard — shown as a defect, not a finding.
   */
  public const EVIDENCE_UNSUPPORTED = 'unsupported';

  /**
   * Human labels for each tier, in escalating order of uncertainty.
   */
  public const EVIDENCE_LABELS = [
    self::EVIDENCE_OBSERVED => 'Observed',
    self::EVIDENCE_SUBSTANTIATED => 'Substantiated',
    self::EVIDENCE_INFERRED => 'Think',
    self::EVIDENCE_ASSUMED => 'Assume',
    self::EVIDENCE_UNSUPPORTED => 'Unsupported',
  ];

  /**
   * What each tier means, shown in the dashboard legend.
   */
  public const EVIDENCE_DEFINITIONS = [
    self::EVIDENCE_OBSERVED => 'Counted from a system of record. If this is wrong, the query is wrong.',
    self::EVIDENCE_SUBSTANTIATED => 'An observation independently corroborated by a second system that would not move for the same reason.',
    self::EVIDENCE_INFERRED => 'Our reasoning over the observations above. States its basis and what would falsify it.',
    self::EVIDENCE_ASSUMED => 'Acted on without evidence. Listed so it can be challenged rather than absorbed.',
    self::EVIDENCE_UNSUPPORTED => 'A claim that did not declare its basis. Treat as a defect in the analysis, not as a finding.',
  ];

  /**
   * Cache lifetime for the aggregate queries, in seconds.
   */
  protected const CACHE_TTL = 900;

  /**
   * Lifetime submissions below which a channel cannot support a generalisation.
   */
  protected const THIN_LIFETIME_THRESHOLD = 10;

  /**
   * A channel younger than this is judged on response rate, not lifetime total.
   */
  protected const YOUNG_CHANNEL_DAYS = 180;

  /**
   * Every feedback channel the organisation operates.
   *
   * Adding a new feedback form means adding one entry here; the counting,
   * liveness classification and coverage maths are generic over the registry.
   *
   * cadence drives the staleness thresholds, because "no submissions in six
   * months" is a failure for the website drawer and completely normal for an
   * annual survey. Flagging the two the same way would be the sort of false
   * equivalence this whole section exists to prevent.
   */
  protected const SOURCES = [
    'website_feedback' => [
      'label' => 'Website feedback drawer',
      'family' => 'website',
      'mode' => 'passive',
      'cadence' => 'continuous',
      'storage' => ['type' => 'webform', 'id' => 'website_feedback'],
    ],
    'member_page_feedback' => [
      'label' => 'Member page feedback',
      'family' => 'website',
      'mode' => 'passive',
      'cadence' => 'continuous',
      'storage' => ['type' => 'webform', 'id' => 'member_page_feedback'],
    ],
    'chatbot' => [
      'label' => 'Tool chatbot "bad answer" reports',
      'family' => 'ai',
      'mode' => 'passive',
      'cadence' => 'continuous',
      'storage' => [
        'type' => 'table',
        'table' => 'makerspace_ai_chat_feedback',
        'created' => 'created',
        'uid' => 'uid',
      ],
    ],
    'wishes' => [
      'label' => 'Wishes (member requests for tools and materials)',
      'family' => 'community',
      'mode' => 'passive',
      'cadence' => 'continuous',
      'storage' => ['type' => 'node', 'bundle' => 'wish'],
    ],
    'appointment_feedback' => [
      'label' => 'Facilitator appointment feedback',
      'family' => 'program',
      'mode' => 'solicited',
      'cadence' => 'per_event',
      'storage' => [
        'type' => 'node_field',
        'table' => 'node__field_appointment_feedback',
        'column' => 'field_appointment_feedback_value',
      ],
      'denominator' => [
        'type' => 'node',
        'bundle' => 'appointment',
        'label' => 'appointments held',
      ],
    ],
    'event_feedback' => [
      'label' => 'Event feedback',
      'family' => 'program',
      'mode' => 'solicited',
      'cadence' => 'per_event',
      'storage' => ['type' => 'webform', 'id' => 'webform_1181'],
    ],
    'instructor_feedback' => [
      'label' => 'Post-class feedback',
      'family' => 'program',
      'mode' => 'solicited',
      'cadence' => 'per_event',
      'storage' => ['type' => 'webform', 'id' => 'instructor_feedback'],
    ],
    'safety_accident' => [
      'label' => 'Safety concern and accident reports',
      'family' => 'safety',
      'mode' => 'passive',
      'cadence' => 'continuous',
      'storage' => ['type' => 'webform', 'id' => 'webform_5199'],
    ],
    'accessibility' => [
      'label' => 'Accessibility issue reports',
      'family' => 'access',
      'mode' => 'passive',
      'cadence' => 'continuous',
      'storage' => ['type' => 'webform', 'id' => 'webform_15999'],
    ],
    'member_survey_2026' => [
      'label' => 'Member survey (2026)',
      'family' => 'survey',
      'mode' => 'solicited',
      'cadence' => 'annual',
      'storage' => ['type' => 'webform', 'id' => '2026_member_survey'],
    ],
    'member_survey_2025' => [
      'label' => 'Member survey (2025)',
      'family' => 'survey',
      'mode' => 'solicited',
      'cadence' => 'retired',
      'storage' => ['type' => 'webform', 'id' => '2025_member_survey'],
    ],
    'meetup_evaluation' => [
      'label' => 'Meetup evaluation',
      'family' => 'program',
      'mode' => 'solicited',
      'cadence' => 'per_event',
      'storage' => ['type' => 'webform', 'id' => 'evaluation'],
    ],
    // Both GEMS and Foundations have two exit surveys apiece: one that was
    // built and never wired up, and an older one that collected a handful of
    // responses years ago. Registering all four is the point — duplicated,
    // abandoned forms are exactly the sprawl this inventory exists to surface,
    // and collapsing them would hide it.
    'gems_exit' => [
      'label' => 'GEMS program exit survey (never wired up)',
      'family' => 'program',
      'mode' => 'solicited',
      'cadence' => 'per_event',
      'storage' => ['type' => 'webform', 'id' => 'gems_program_exit_survey'],
    ],
    'gems_participant_evaluation' => [
      'label' => 'GEMS post-program participant evaluation',
      'family' => 'program',
      'mode' => 'solicited',
      'cadence' => 'per_event',
      'storage' => ['type' => 'webform', 'id' => 'webform_14335'],
    ],
    'foundations_exit' => [
      'label' => 'Foundations of Fabrication exit survey (never wired up)',
      'family' => 'program',
      'mode' => 'solicited',
      'cadence' => 'per_event',
      'storage' => ['type' => 'webform', 'id' => 'foundations_of_fabrication_exit'],
    ],
    'foundations_course_evaluation' => [
      'label' => 'Foundations course evaluation',
      'family' => 'program',
      'mode' => 'solicited',
      'cadence' => 'per_event',
      'storage' => ['type' => 'webform', 'id' => 'webform_9283'],
    ],
    'pathway_post' => [
      'label' => 'Pathway to Trades post-program survey',
      'family' => 'program',
      'mode' => 'solicited',
      'cadence' => 'per_event',
      'storage' => ['type' => 'webform', 'id' => 'post_program_survey_pathway_to_t'],
    ],
    // Exit reasons, captured in Chargebee's cancellation flow and synced back
    // by chargebee_status_sync into a structured field plus a free-text note.
    //
    // This is the best-covered listening channel the organisation has — 457 of
    // the 461 memberships that ended in the last year carry a reason — and two
    // earlier passes of this review declared we had no exit channel at all.
    // The cause of that error is worth stating plainly, because it is the same
    // failure the review exists to catch: the registry could only see
    // webforms, nodes and bespoke tables, so a channel stored in an entity
    // field was invisible, and its absence from the frame was read as absence
    // from the organisation. Hence the 'entity_field' storage type.
    'membership_exit_reason' => [
      'label' => 'Membership end reason (Chargebee cancellation flow)',
      'family' => 'exit',
      'mode' => 'solicited',
      'cadence' => 'continuous',
      'storage' => [
        'type' => 'entity_field',
        'table' => 'profile__field_member_end_reason',
        'column' => 'field_member_end_reason_value',
        'date_table' => 'profile__field_member_end_date',
        'date_column' => 'field_member_end_date_value',
        'uid_table' => 'profile',
        'uid_column' => 'uid',
      ],
      'denominator' => [
        'type' => 'entity_field_rows',
        'table' => 'profile__field_member_end_date',
        'column' => 'field_member_end_date_value',
        'label' => 'memberships ended',
      ],
    ],
    'membership_exit_notes' => [
      'label' => 'Membership end reason — free-text notes',
      'family' => 'exit',
      'mode' => 'solicited',
      'cadence' => 'continuous',
      'storage' => [
        'type' => 'entity_field',
        'table' => 'profile__field_member_end_reason_notes',
        'column' => 'field_member_end_reason_notes_value',
        'date_table' => 'profile__field_member_end_date',
        'date_column' => 'field_member_end_date_value',
        'uid_table' => 'profile',
        'uid_column' => 'uid',
      ],
      'denominator' => [
        'type' => 'entity_field_rows',
        'table' => 'profile__field_member_end_date',
        'column' => 'field_member_end_date_value',
        'label' => 'memberships ended',
      ],
    ],
    // Facility and equipment reports. These are listening channels every bit
    // as much as a survey is — a member telling us the shop is a mess or a
    // machine is broken is feedback about how the place is running, and it
    // arrives unprompted from people who would never fill in a survey.
    'report_a_mess' => [
      'label' => 'Report a mess',
      'family' => 'facility',
      'mode' => 'passive',
      'cadence' => 'continuous',
      'storage' => ['type' => 'webform', 'id' => 'webform_28014'],
    ],
    'broken_equipment' => [
      'label' => 'Broken or malfunctioning equipment reports',
      'family' => 'equipment',
      'mode' => 'passive',
      'cadence' => 'continuous',
      'storage' => ['type' => 'webform', 'id' => 'webform_9252'],
    ],
    'maintenance_request' => [
      'label' => 'Maintenance request',
      'family' => 'facility',
      'mode' => 'passive',
      'cadence' => 'continuous',
      'storage' => ['type' => 'webform', 'id' => 'webform_22162'],
    ],
    'agreement_violation' => [
      'label' => 'Membership agreement violation report',
      'family' => 'conduct',
      'mode' => 'passive',
      'cadence' => 'continuous',
      'storage' => ['type' => 'webform', 'id' => 'webform_27582'],
    ],
    'quiz_edit_suggestion' => [
      'label' => 'Quiz edit suggestion',
      'family' => 'content',
      'mode' => 'passive',
      'cadence' => 'continuous',
      'storage' => ['type' => 'webform', 'id' => 'webform_25062'],
    ],
    // The single highest-information channel in this registry, and it went
    // dark in February 2025: 387 submissions from 194 people telling us why
    // they were leaving. Self-serve cancellation almost certainly routed
    // around it. Nothing replaced it, so we now lose every departing member's
    // reason.
    'membership_pause_cancel' => [
      'label' => 'Pause or cancel membership (exit reasons)',
      'family' => 'exit',
      'mode' => 'passive',
      'cadence' => 'continuous',
      'storage' => ['type' => 'webform', 'id' => 'webform_7578'],
    ],
    // Superseded by instructor_feedback but still actively emailed: 18 CiviCRM
    // reminders point instructors here one hour after the class *starts*.
    // Left in the registry rather than marked retired precisely because it is
    // still sending — a duplicate competing with the loop that works.
    'post_workshop_instructor_eval' => [
      'label' => 'Post-workshop instructor evaluation (superseded duplicate)',
      'family' => 'program',
      'mode' => 'solicited',
      'cadence' => 'per_event',
      'storage' => ['type' => 'webform', 'id' => 'post_workshop_instructor_evaluat'],
    ],
    // The mentor program ran as an experiment and was retired after it. These
    // two forms are therefore closed questions, not neglected ones, and are
    // marked retired so they stop reading as a coverage failure.
    'mentor_feedback' => [
      'label' => 'Mentor feedback form (program retired)',
      'family' => 'program',
      'mode' => 'solicited',
      'cadence' => 'retired',
      'storage' => ['type' => 'webform', 'id' => 'webform_17972'],
    ],
    'mentee_feedback' => [
      'label' => 'Feedback about your mentor (program retired)',
      'family' => 'program',
      'mode' => 'solicited',
      'cadence' => 'retired',
      'storage' => ['type' => 'webform', 'id' => 'webform_17973'],
    ],
  ];

  /**
   * Roles whose holders are running the place rather than experiencing it.
   *
   * Their feedback is valuable and often the most actionable, but it is not
   * member voice, and counting the two together is how a handful of diligent
   * staff can make the listening system look far broader than it is.
   */
  protected const STAFF_ROLES = [
    'administrator',
    'manager',
    'content_editor',
    'data',
    'event_management',
    'librarian',
    'services',
  ];

  /**
   * Roles held by members who also volunteer in an operational capacity.
   *
   * Reported separately because they see the space from both sides: closer to
   * the member experience than staff, further from it than a typical member.
   */
  protected const INSIDER_ROLES = [
    'facilitator',
    'instructor',
  ];

  /**
   * Days without a submission before a channel is called something other than
   * live, keyed by cadence.
   *
   * These thresholds are a judgement, not a measurement — which is why every
   * liveness verdict is returned at INFERRED and carries them as its basis.
   */
  protected const STALENESS_THRESHOLDS = [
    'continuous' => ['fading' => 30, 'dormant' => 90, 'dead' => 365],
    'per_event' => ['fading' => 60, 'dormant' => 180, 'dead' => 365],
    'annual' => ['fading' => 400, 'dormant' => 500, 'dead' => 730],
    'retired' => ['fading' => PHP_INT_MAX, 'dormant' => PHP_INT_MAX, 'dead' => PHP_INT_MAX],
  ];

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * Constructs the feedback data service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, TimeInterface $time, ?TranslationInterface $string_translation = NULL) {
    $this->database = $database;
    $this->cache = $cache;
    $this->time = $time;
    if ($string_translation) {
      $this->setStringTranslation($string_translation);
    }
  }

  /**
   * Wraps a value as an evidence-tagged claim.
   *
   * This is the guard that gives the Observe/Substantiate/Think/Assume lens
   * teeth. Anything above OBSERVED must say what observation it rests on and
   * what would prove it wrong; if it cannot, it comes back as UNSUPPORTED so
   * the reader sees an analytical defect instead of a confident-looking
   * sentence.
   *
   * @param string $tier
   *   One of the EVIDENCE_* constants.
   * @param string $label
   *   Short human label for the claim.
   * @param mixed $value
   *   The value being claimed.
   * @param array $meta
   *   Supporting keys: 'basis' (what it rests on), 'falsifier' (what would
   *   disprove it), 'detail' (free text), 'corroborator' (for SUBSTANTIATED).
   *
   * @return array
   *   A normalized claim array.
   */
  public function claim(string $tier, string $label, $value, array $meta = []): array {
    $basis = trim((string) ($meta['basis'] ?? ''));
    $falsifier = trim((string) ($meta['falsifier'] ?? ''));

    $needsSupport = in_array($tier, [
      self::EVIDENCE_SUBSTANTIATED,
      self::EVIDENCE_INFERRED,
      self::EVIDENCE_ASSUMED,
    ], TRUE);

    if ($needsSupport && ($basis === '' || $falsifier === '')) {
      $tier = self::EVIDENCE_UNSUPPORTED;
      $meta['detail'] = trim(($meta['detail'] ?? '') . ' This claim was published without a stated basis or falsifier.');
    }

    // A corroborated claim has to name the second system, otherwise it is just
    // an inference wearing a stronger badge.
    if ($tier === self::EVIDENCE_SUBSTANTIATED && trim((string) ($meta['corroborator'] ?? '')) === '') {
      $tier = self::EVIDENCE_INFERRED;
    }

    return [
      'tier' => $tier,
      'tier_label' => self::EVIDENCE_LABELS[$tier] ?? $tier,
      'label' => $label,
      'value' => $value,
      'basis' => $basis,
      'falsifier' => $falsifier,
      'corroborator' => $meta['corroborator'] ?? '',
      'detail' => trim((string) ($meta['detail'] ?? '')),
      'format' => $meta['format'] ?? 'raw',
    ];
  }

  /**
   * Returns every channel with its measured volume and a liveness verdict.
   *
   * @param int $windowDays
   *   Trailing window used for the "recent" columns.
   *
   * @return array
   *   Rows keyed by source id.
   */
  public function getSources(int $windowDays = 365): array {
    $cid = 'makerspace_dashboard:feedback:sources:' . $windowDays;
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }

    $now = $this->time->getRequestTime();
    $since = $now - ($windowDays * 86400);
    $rows = [];

    foreach (self::SOURCES as $key => $source) {
      $stats = $this->countSource($source['storage'], $since);
      if ($stats === NULL) {
        // The storage does not exist in this environment. Say so rather than
        // reporting a zero, which would read as "nobody submitted".
        $rows[$key] = [
          'key' => $key,
          'label' => $source['label'],
          'family' => $source['family'],
          'mode' => $source['mode'],
          'cadence' => $source['cadence'],
          'available' => FALSE,
          'total' => NULL,
          'recent' => NULL,
          'distinct_uids' => NULL,
          'last_created' => NULL,
          'days_silent' => NULL,
          'liveness' => $this->claim(
            self::EVIDENCE_OBSERVED,
            'Channel storage missing',
            'unavailable',
            ['detail' => 'The table backing this channel does not exist in this environment.']
          ),
        ];
        continue;
      }

      $daysSilent = $stats['last_created']
        ? (int) floor(($now - $stats['last_created']) / 86400)
        : NULL;

      $rows[$key] = [
        'key' => $key,
        'label' => $source['label'],
        'family' => $source['family'],
        'mode' => $source['mode'],
        'cadence' => $source['cadence'],
        'available' => TRUE,
        'total' => $stats['total'],
        'recent' => $stats['recent'],
        'distinct_uids' => $stats['distinct_uids'],
        'last_created' => $stats['last_created'],
        'days_silent' => $daysSilent,
        'liveness' => $this->classifyLiveness($source, $daysSilent, $stats['total']),
        'age_days' => $stats['first_created']
          ? (int) floor(($now - $stats['first_created']) / 86400)
          : NULL,
        'thin' => $this->classifyThinness(
          $source,
          $stats['total'],
          $stats['first_created'] ? (int) floor(($now - $stats['first_created']) / 86400) : NULL
        ),
        'denominator' => $this->resolveDenominator($source, $since, $stats['recent']),
      ];
    }

    $this->cache->set($cid, $rows, $now + self::CACHE_TTL, ['makerspace_dashboard:section:listening']);
    return $rows;
  }

  /**
   * Classifies how alive a channel is, as an explicitly inferred verdict.
   */
  protected function classifyLiveness(array $source, ?int $daysSilent, int $total): array {
    $cadence = $source['cadence'];
    $thresholds = self::STALENESS_THRESHOLDS[$cadence] ?? self::STALENESS_THRESHOLDS['continuous'];

    if ($cadence === 'retired') {
      return $this->claim(self::EVIDENCE_OBSERVED, 'Liveness', 'retired', [
        'detail' => 'Deliberately closed. Not counted as a failure.',
      ]);
    }

    if ($total === 0 || $daysSilent === NULL) {
      return $this->claim(self::EVIDENCE_OBSERVED, 'Liveness', 'never used', [
        'detail' => 'The channel exists and has never received a submission. This is a count, not a judgement.',
      ]);
    }

    if ($daysSilent <= $thresholds['fading']) {
      $state = 'live';
    }
    elseif ($daysSilent <= $thresholds['dormant']) {
      $state = 'fading';
    }
    elseif ($daysSilent <= $thresholds['dead']) {
      $state = 'dormant';
    }
    else {
      $state = 'dead';
    }

    return $this->claim(self::EVIDENCE_INFERRED, 'Liveness', $state, [
      'basis' => sprintf(
        'Last submission was %d days ago; the %s-cadence thresholds are %d/%d/%d days for fading/dormant/dead.',
        $daysSilent,
        $cadence,
        $thresholds['fading'],
        $thresholds['dormant'],
        $thresholds['dead']
      ),
      'falsifier' => 'A new submission on this channel, or evidence that the underlying activity (classes, appointments, events) stopped so the silence is expected.',
    ]);
  }

  /**
   * Flags channels that are technically alive but have never collected much.
   *
   * Liveness alone is a trap: post-class feedback and the accessibility form
   * both received a submission within the last fortnight, so a
   * time-since-last-submission test calls them healthy — while each holds five
   * responses across its entire life. Recency and volume are separate
   * questions, and a channel can pass one while failing the other badly enough
   * that any theme drawn from it is anecdote.
   */
  protected function classifyThinness(array $source, int $total, ?int $ageDays): ?array {
    if ($source['cadence'] === 'retired' || $total >= self::THIN_LIFETIME_THRESHOLD) {
      return NULL;
    }

    // Age is the difference between a channel nobody uses and one that has
    // barely started. Post-class feedback opened in June 2026 and had five
    // responses within two months; reading that as neglect — as an earlier
    // pass of this analysis did — inverts the conclusion about a channel that
    // is in fact converting well.
    if ($ageDays !== NULL && $ageDays < self::YOUNG_CHANNEL_DAYS) {
      return $this->claim(self::EVIDENCE_OBSERVED, 'Too new to judge', TRUE, [
        'format' => 'boolean',
        'detail' => sprintf(
          'First submission %d days ago. Low lifetime volume here is age, not neglect — judge it on its response rate, not its total.',
          $ageDays
        ),
      ]);
    }

    return $this->claim(self::EVIDENCE_INFERRED, 'Too thin to generalise from', TRUE, [
      'format' => 'boolean',
      'basis' => sprintf(
        '%d submissions across %s, below the threshold of %d at which we are willing to read a pattern.',
        $total,
        $ageDays !== NULL ? sprintf('the %d days since the channel opened', $ageDays) : 'the channel’s entire life',
        self::THIN_LIFETIME_THRESHOLD
      ),
      'falsifier' => 'Volume rising past the threshold, or a reason this channel is expected to be low-volume by design — a safety form should be rare, and rarity there is good news rather than a gap.',
    ]);
  }

  /**
   * Computes the response-rate denominator for channels that have one.
   *
   * Only appointment feedback currently has a denominator we can count without
   * guessing. Everywhere else this returns NULL rather than inventing one —
   * an unknown response rate is reported as unknown.
   */
  protected function resolveDenominator(array $source, int $since, ?int $recent): ?array {
    if (empty($source['denominator']) || $recent === NULL) {
      return NULL;
    }

    $definition = $source['denominator'];

    switch ($definition['type'] ?? '') {
      case 'node':
        $total = (int) $this->database->select('node_field_data', 'n')
          ->condition('n.type', $definition['bundle'])
          ->condition('n.created', $since, '>=')
          ->countQuery()
          ->execute()
          ->fetchField();
        break;

      case 'entity_field_rows':
        if (!$this->database->schema()->tableExists($definition['table'])) {
          return NULL;
        }
        $query = $this->database->select($definition['table'], 'd');
        $query->where(sprintf('UNIX_TIMESTAMP(d.%s) >= :since', $definition['column']), [':since' => $since]);
        $total = (int) $query->countQuery()->execute()->fetchField();
        break;

      default:
        return NULL;
    }

    if ($total === 0) {
      return NULL;
    }

    return [
      'label' => $definition['label'],
      'total' => $total,
      'rate' => $recent / $total,
    ];
  }

  /**
   * Counts one source, returning NULL when its storage is absent.
   */
  protected function countSource(array $storage, int $since): ?array {
    switch ($storage['type']) {
      case 'webform':
        if (!$this->database->schema()->tableExists('webform_submission')) {
          return NULL;
        }
        return $this->aggregate('webform_submission', 'created', 'uid', $since, [
          'webform_id' => $storage['id'],
        ]);

      case 'node':
        return $this->aggregate('node_field_data', 'created', 'uid', $since, [
          'type' => $storage['bundle'],
        ]);

      case 'table':
        if (!$this->database->schema()->tableExists($storage['table'])) {
          return NULL;
        }
        return $this->aggregate($storage['table'], $storage['created'], $storage['uid'], $since);

      case 'node_field':
        return $this->aggregateNodeField($storage, $since);

      case 'entity_field':
        return $this->aggregateEntityField($storage, $since);
    }

    return NULL;
  }

  /**
   * Counts a listening channel stored as a field on a non-node entity.
   *
   * Recency comes from a sibling date field rather than an entity timestamp,
   * because the thing being dated is the event the field describes (when the
   * membership ended), not when a row happened to be written. Those dates are
   * stored as 'Y-m-d' strings, hence the UNIX_TIMESTAMP conversion.
   */
  protected function aggregateEntityField(array $storage, int $since): ?array {
    if (!$this->database->schema()->tableExists($storage['table'])
      || !$this->database->schema()->tableExists($storage['date_table'])) {
      return NULL;
    }

    $dateExpr = sprintf('UNIX_TIMESTAMP(d.%s)', $storage['date_column']);

    $query = $this->database->select($storage['table'], 'f');
    $query->leftJoin($storage['date_table'], 'd', 'd.entity_id = f.entity_id');
    $query->isNotNull('f.' . $storage['column']);
    $query->condition('f.' . $storage['column'], '', '<>');
    $query->addExpression('COUNT(*)', 'total');
    $query->addExpression("SUM(CASE WHEN $dateExpr >= :since THEN 1 ELSE 0 END)", 'recent', [':since' => $since]);
    $query->addExpression("MAX($dateExpr)", 'last_created');
    $query->addExpression("MIN($dateExpr)", 'first_created');

    if (!empty($storage['uid_table'])) {
      $query->leftJoin($storage['uid_table'], 'o', 'o.profile_id = f.entity_id');
      $query->addExpression('COUNT(DISTINCT o.' . $storage['uid_column'] . ')', 'distinct_uids');
    }

    $row = $query->execute()->fetchAssoc() ?: [];

    return [
      'total' => (int) ($row['total'] ?? 0),
      'recent' => (int) ($row['recent'] ?? 0),
      'distinct_uids' => isset($row['distinct_uids']) ? (int) $row['distinct_uids'] : NULL,
      'last_created' => !empty($row['last_created']) ? (int) $row['last_created'] : NULL,
      'first_created' => !empty($row['first_created']) ? (int) $row['first_created'] : NULL,
    ];
  }

  /**
   * Runs the shared count/distinct/latest aggregate over a table.
   */
  protected function aggregate(string $table, string $createdColumn, ?string $uidColumn, int $since, array $conditions = []): array {
    $query = $this->database->select($table, 't');
    $query->addExpression('COUNT(*)', 'total');
    $query->addExpression("SUM(CASE WHEN t.$createdColumn >= :since THEN 1 ELSE 0 END)", 'recent', [':since' => $since]);
    $query->addExpression("MAX(t.$createdColumn)", 'last_created');
    $query->addExpression("MIN(t.$createdColumn)", 'first_created');
    if ($uidColumn) {
      $query->addExpression("COUNT(DISTINCT t.$uidColumn)", 'distinct_uids');
    }
    foreach ($conditions as $column => $value) {
      $query->condition('t.' . $column, $value);
    }
    $row = $query->execute()->fetchAssoc() ?: [];

    return [
      'total' => (int) ($row['total'] ?? 0),
      'recent' => (int) ($row['recent'] ?? 0),
      'distinct_uids' => isset($row['distinct_uids']) ? (int) $row['distinct_uids'] : NULL,
      'last_created' => !empty($row['last_created']) ? (int) $row['last_created'] : NULL,
      'first_created' => !empty($row['first_created']) ? (int) $row['first_created'] : NULL,
    ];
  }

  /**
   * Counts a feedback field hung off a node bundle.
   */
  protected function aggregateNodeField(array $storage, int $since): ?array {
    if (!$this->database->schema()->tableExists($storage['table'])) {
      return NULL;
    }

    $query = $this->database->select($storage['table'], 'f');
    $query->join('node_field_data', 'n', 'n.nid = f.entity_id');
    $query->isNotNull('f.' . $storage['column']);
    $query->condition('f.' . $storage['column'], '', '<>');
    $query->addExpression('COUNT(*)', 'total');
    $query->addExpression('SUM(CASE WHEN n.created >= :since THEN 1 ELSE 0 END)', 'recent', [':since' => $since]);
    $query->addExpression('MAX(n.created)', 'last_created');
    $query->addExpression('MIN(n.created)', 'first_created');
    $query->addExpression('COUNT(DISTINCT n.uid)', 'distinct_uids');
    $row = $query->execute()->fetchAssoc() ?: [];

    return [
      'total' => (int) ($row['total'] ?? 0),
      'recent' => (int) ($row['recent'] ?? 0),
      'distinct_uids' => isset($row['distinct_uids']) ? (int) $row['distinct_uids'] : NULL,
      'last_created' => !empty($row['last_created']) ? (int) $row['last_created'] : NULL,
      'first_created' => !empty($row['first_created']) ? (int) $row['first_created'] : NULL,
    ];
  }

  /**
   * Measures whether the listening system reaches anybody.
   *
   * This is the band that answers "are we assuming, or do we know?" — not what
   * members said, but how many of them said anything at all, how concentrated
   * those voices are, and how many channels are collecting nothing.
   *
   * @param int $windowDays
   *   Trailing window for reach and concentration.
   *
   * @return array
   *   A list of claims.
   */
  public function getCoverage(int $windowDays = 365): array {
    $sources = $this->getSources($windowDays);
    $now = $this->time->getRequestTime();
    $since = $now - ($windowDays * 86400);

    $activeMembers = $this->countActiveMembers();
    $reach = $this->countDistinctSubmitters($since);
    $concentration = $this->getConcentration($since);

    $claims = [];

    $claims['reach'] = $this->claim(
      self::EVIDENCE_OBSERVED,
      'Members who submitted any feedback',
      $activeMembers > 0 ? $reach / $activeMembers : NULL,
      [
        'format' => 'percent',
        'detail' => sprintf('%d distinct accounts submitted through a counted channel in the last %d days, against %d accounts holding the member role.', $reach, $windowDays, $activeMembers),
      ]
    );

    $claims['distinct_submitters'] = $this->claim(
      self::EVIDENCE_OBSERVED,
      'Distinct submitters',
      $reach,
      ['format' => 'integer', 'detail' => sprintf('Counted across all channels over %d days.', $windowDays)]
    );

    $voice = $this->getVoiceComposition($windowDays);
    if (!empty($voice['member_share'])) {
      $claims['member_share'] = $voice['member_share'];
      $claims['voice_verdict'] = $voice['verdict'];
    }

    $claims['concentration'] = $this->claim(
      self::EVIDENCE_OBSERVED,
      'Share of all feedback from the top 3 submitters',
      $concentration['top3_share'],
      [
        'format' => 'percent',
        'detail' => sprintf('%d of %d submissions came from 3 accounts.', $concentration['top3_count'], $concentration['total']),
      ]
    );

    // Channel liveness rolls up individual inferred verdicts, so the roll-up is
    // inferred too — it inherits the weakest link, not the strongest.
    $states = ['live' => 0, 'fading' => 0, 'dormant' => 0, 'dead' => 0, 'never used' => 0, 'retired' => 0];
    foreach ($sources as $row) {
      $state = (string) ($row['liveness']['value'] ?? '');
      if (isset($states[$state])) {
        $states[$state]++;
      }
    }
    $notCollecting = $states['dormant'] + $states['dead'] + $states['never used'];

    $claims['channels_live'] = $this->claim(
      self::EVIDENCE_OBSERVED,
      'Channels that exist',
      count($sources),
      ['format' => 'integer', 'detail' => 'Every feedback mechanism registered in the source registry.']
    );

    $claims['channels_not_collecting'] = $this->claim(
      self::EVIDENCE_INFERRED,
      'Channels collecting nothing',
      $notCollecting,
      [
        'format' => 'integer',
        'basis' => sprintf('%d dormant, %d dead and %d never-used channels by the cadence-adjusted staleness thresholds.', $states['dormant'], $states['dead'], $states['never used']),
        'falsifier' => 'New submissions on those channels, or confirmation that the programs they served have ended, which would make the silence correct rather than a gap.',
        'detail' => sprintf('Live: %d. Fading: %d. Retired on purpose: %d.', $states['live'], $states['fading'], $states['retired']),
      ]
    );

    $thin = 0;
    $thinLive = [];
    foreach ($sources as $row) {
      if (!empty($row['thin'])) {
        $thin++;
        if (in_array((string) ($row['liveness']['value'] ?? ''), ['live', 'fading'], TRUE)) {
          $thinLive[] = $row['label'];
        }
      }
    }

    $claims['channels_thin'] = $this->claim(
      self::EVIDENCE_INFERRED,
      'Channels too thin to generalise from',
      $thin,
      [
        'format' => 'integer',
        'basis' => sprintf('Channels with fewer than %d lifetime submissions.', self::THIN_LIFETIME_THRESHOLD),
        'falsifier' => 'Volume rising past the threshold, or these channels being low-volume by design.',
        'detail' => $thinLive
          ? sprintf('Of these, %d currently read as live on recency alone: %s. Recency is not volume.', count($thinLive), implode('; ', $thinLive))
          : 'None of these currently read as live, so recency and volume agree.',
      ]
    );

    // The interpretive claim the board conversation actually turns on. It is
    // stated at INFERRED on purpose: the numbers above are facts, the reading
    // of them is not.
    $claims['sourcing_verdict'] = $this->claim(
      self::EVIDENCE_INFERRED,
      'Feedback volume is not a sample of the membership',
      $reach < ($activeMembers * 0.1) || $concentration['top3_share'] > 0.5,
      [
        'format' => 'boolean',
        'basis' => sprintf('Reach is %s of members and the top 3 submitters account for %s of volume.', $this->formatPercent($activeMembers > 0 ? $reach / $activeMembers : NULL), $this->formatPercent($concentration['top3_share'])),
        'falsifier' => 'Reach above 10% of members with top-3 concentration below 50%, or evidence that the non-submitting majority is well represented through another route we are not counting here.',
        'detail' => 'Volume alone cannot tell us what the silent majority thinks. Any theme drawn only from these submissions is a hypothesis about the membership, not a measurement of it.',
      ]
    );

    return $claims;
  }

  /**
   * Counts accounts currently holding the member role.
   */
  protected function countActiveMembers(): int {
    if (!$this->database->schema()->tableExists('user__roles')) {
      return 0;
    }
    $query = $this->database->select('user__roles', 'r');
    $query->join('users_field_data', 'u', 'u.uid = r.entity_id');
    $query->condition('r.roles_target_id', 'member');
    $query->condition('u.status', 1);
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Returns submission counts keyed by uid, summed across every channel.
   *
   * uid 0 is dropped: anonymous submissions are real feedback but cannot be
   * attributed to a distinct person, so counting them would inflate reach.
   */
  protected function getSubmissionCountsByUid(int $since): array {
    $uids = [];
    foreach (self::SOURCES as $source) {
      foreach ($this->fetchSubmitterCounts($source['storage'], $since) as $uid => $count) {
        $uids[$uid] = ($uids[$uid] ?? 0) + $count;
      }
    }
    unset($uids[0]);
    return $uids;
  }

  /**
   * Counts distinct authenticated submitters across all counted channels.
   */
  protected function countDistinctSubmitters(int $since): int {
    return count($this->getSubmissionCountsByUid($since));
  }

  /**
   * Splits feedback volume by whether the submitter runs the place or uses it.
   *
   * This is the single most load-bearing number in the section. Raw volume
   * says the organisation hears a lot; this says how much of that is the
   * organisation talking to itself.
   */
  public function getVoiceComposition(int $windowDays = 365): array {
    $since = $this->time->getRequestTime() - ($windowDays * 86400);
    $counts = $this->getSubmissionCountsByUid($since);
    if (!$counts) {
      return [];
    }

    $roles = $this->fetchRolesByUid(array_keys($counts));
    $cohorts = ['staff' => 0, 'insider' => 0, 'member' => 0];
    $people = ['staff' => 0, 'insider' => 0, 'member' => 0];

    foreach ($counts as $uid => $count) {
      $held = $roles[$uid] ?? [];
      if (array_intersect($held, self::STAFF_ROLES)) {
        $cohort = 'staff';
      }
      elseif (array_intersect($held, self::INSIDER_ROLES)) {
        $cohort = 'insider';
      }
      else {
        $cohort = 'member';
      }
      $cohorts[$cohort] += $count;
      $people[$cohort]++;
    }

    $total = array_sum($cohorts);

    return [
      'counts' => $cohorts,
      'people' => $people,
      'total' => $total,
      'member_share' => $this->claim(
        self::EVIDENCE_OBSERVED,
        'Share of feedback volume from members who hold no staff or volunteer role',
        $total > 0 ? $cohorts['member'] / $total : NULL,
        [
          'format' => 'percent',
          'detail' => sprintf(
            '%d submissions from %d ordinary members; %d from %d staff; %d from %d facilitators or instructors.',
            $cohorts['member'], $people['member'],
            $cohorts['staff'], $people['staff'],
            $cohorts['insider'], $people['insider']
          ),
        ]
      ),
      'verdict' => $this->claim(
        self::EVIDENCE_INFERRED,
        'Feedback volume is substantially the organisation talking to itself',
        $total > 0 && (($cohorts['staff'] + $cohorts['insider']) / $total) > 0.4,
        [
          'format' => 'boolean',
          'basis' => sprintf(
            'Staff and operational volunteers account for %s of submissions over the last %d days.',
            $this->formatPercent($total > 0 ? ($cohorts['staff'] + $cohorts['insider']) / $total : NULL),
            $windowDays
          ),
          'falsifier' => 'Member-held-role-free submissions rising above 60% of volume, or evidence that staff submissions are relaying member reports rather than originating them — which the submission tables cannot distinguish.',
          'detail' => 'Staff feedback is not less valid, but it answers a different question. A theme sourced mostly from staff is a claim about what staff notice, which is not the same as what members experience.',
        ]
      ),
    ];
  }

  /**
   * Fetches the role ids held by each of the given accounts.
   */
  protected function fetchRolesByUid(array $uids): array {
    if (!$uids || !$this->database->schema()->tableExists('user__roles')) {
      return [];
    }

    $rows = $this->database->select('user__roles', 'r')
      ->fields('r', ['entity_id', 'roles_target_id'])
      ->condition('r.entity_id', $uids, 'IN')
      ->execute();

    $roles = [];
    foreach ($rows as $row) {
      $roles[(int) $row->entity_id][] = $row->roles_target_id;
    }
    return $roles;
  }

  /**
   * Measures how concentrated feedback volume is among a few submitters.
   */
  public function getConcentration(int $since): array {
    $uids = $this->getSubmissionCountsByUid($since);
    arsort($uids);
    $total = array_sum($uids);
    $top3 = array_sum(array_slice($uids, 0, 3, TRUE));

    return [
      'total' => $total,
      'top3_count' => $top3,
      'top3_share' => $total > 0 ? $top3 / $total : NULL,
      'submitters' => count($uids),
    ];
  }

  /**
   * Returns submission counts keyed by uid for one channel.
   */
  protected function fetchSubmitterCounts(array $storage, int $since): array {
    try {
      switch ($storage['type']) {
        case 'webform':
          if (!$this->database->schema()->tableExists('webform_submission')) {
            return [];
          }
          $query = $this->database->select('webform_submission', 't');
          $query->condition('t.webform_id', $storage['id']);
          $query->condition('t.created', $since, '>=');
          break;

        case 'node':
          $query = $this->database->select('node_field_data', 't');
          $query->condition('t.type', $storage['bundle']);
          $query->condition('t.created', $since, '>=');
          break;

        case 'table':
          if (!$this->database->schema()->tableExists($storage['table'])) {
            return [];
          }
          $query = $this->database->select($storage['table'], 't');
          $query->condition('t.' . $storage['created'], $since, '>=');
          $query->addField('t', $storage['uid'], 'uid');
          $query->addExpression('COUNT(*)', 'n');
          $query->groupBy('t.' . $storage['uid']);
          return $query->execute()->fetchAllKeyed();

        case 'node_field':
          if (!$this->database->schema()->tableExists($storage['table'])) {
            return [];
          }
          $query = $this->database->select($storage['table'], 'f');
          $query->join('node_field_data', 't', 't.nid = f.entity_id');
          $query->isNotNull('f.' . $storage['column']);
          $query->condition('f.' . $storage['column'], '', '<>');
          $query->condition('t.created', $since, '>=');
          break;

        case 'entity_field':
          if (empty($storage['uid_table']) || !$this->database->schema()->tableExists($storage['table'])) {
            return [];
          }
          $query = $this->database->select($storage['table'], 'f');
          $query->join($storage['uid_table'], 't', 't.profile_id = f.entity_id');
          $query->join($storage['date_table'], 'd', 'd.entity_id = f.entity_id');
          $query->isNotNull('f.' . $storage['column']);
          $query->condition('f.' . $storage['column'], '', '<>');
          $query->where(sprintf('UNIX_TIMESTAMP(d.%s) >= :since', $storage['date_column']), [':since' => $since]);
          break;

        default:
          return [];
      }

      $query->addField('t', 'uid', 'uid');
      $query->addExpression('COUNT(*)', 'n');
      $query->groupBy('t.uid');
      return $query->execute()->fetchAllKeyed();
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Returns monthly submission volume per channel family.
   *
   * @param int $months
   *   Number of trailing months to include.
   */
  public function getVolumeTrend(int $months = 24): array {
    $cid = 'makerspace_dashboard:feedback:trend:' . $months;
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }

    $now = $this->time->getRequestTime();
    $start = (new \DateTimeImmutable('@' . $now))
      ->modify('first day of this month')
      ->modify('-' . ($months - 1) . ' months')
      ->setTime(0, 0);
    $since = $start->getTimestamp();

    $periods = [];
    for ($i = 0; $i < $months; $i++) {
      $periods[] = $start->modify('+' . $i . ' months')->format('Y-m');
    }

    $families = [];
    foreach (self::SOURCES as $source) {
      $family = $source['family'];
      $families[$family] = $families[$family] ?? array_fill_keys($periods, 0);
      foreach ($this->fetchMonthlyCounts($source['storage'], $since) as $period => $count) {
        if (isset($families[$family][$period])) {
          $families[$family][$period] += $count;
        }
      }
    }

    $result = ['periods' => $periods, 'families' => $families];
    $this->cache->set($cid, $result, $now + self::CACHE_TTL, ['makerspace_dashboard:section:listening']);
    return $result;
  }

  /**
   * Returns submission counts keyed by YYYY-MM for one channel.
   */
  protected function fetchMonthlyCounts(array $storage, int $since): array {
    try {
      switch ($storage['type']) {
        case 'webform':
          if (!$this->database->schema()->tableExists('webform_submission')) {
            return [];
          }
          $query = $this->database->select('webform_submission', 't');
          $query->condition('t.webform_id', $storage['id']);
          $query->condition('t.created', $since, '>=');
          $column = 't.created';
          break;

        case 'node':
          $query = $this->database->select('node_field_data', 't');
          $query->condition('t.type', $storage['bundle']);
          $query->condition('t.created', $since, '>=');
          $column = 't.created';
          break;

        case 'table':
          if (!$this->database->schema()->tableExists($storage['table'])) {
            return [];
          }
          $query = $this->database->select($storage['table'], 't');
          $query->condition('t.' . $storage['created'], $since, '>=');
          $column = 't.' . $storage['created'];
          break;

        case 'node_field':
          if (!$this->database->schema()->tableExists($storage['table'])) {
            return [];
          }
          $query = $this->database->select($storage['table'], 'f');
          $query->join('node_field_data', 't', 't.nid = f.entity_id');
          $query->isNotNull('f.' . $storage['column']);
          $query->condition('f.' . $storage['column'], '', '<>');
          $query->condition('t.created', $since, '>=');
          $column = 't.created';
          break;

        case 'entity_field':
          if (!$this->database->schema()->tableExists($storage['table'])) {
            return [];
          }
          $query = $this->database->select($storage['table'], 'f');
          $query->join($storage['date_table'], 'd', 'd.entity_id = f.entity_id');
          $query->isNotNull('f.' . $storage['column']);
          $query->condition('f.' . $storage['column'], '', '<>');
          $query->where(sprintf('UNIX_TIMESTAMP(d.%s) >= :since', $storage['date_column']), [':since' => $since]);
          $column = sprintf('UNIX_TIMESTAMP(d.%s)', $storage['date_column']);
          break;

        default:
          return [];
      }

      $query->addExpression("DATE_FORMAT(FROM_UNIXTIME($column), '%Y-%m')", 'period');
      $query->addExpression('COUNT(*)', 'n');
      $query->groupBy('period');
      return $query->execute()->fetchAllKeyed();
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Returns how the public feedback ledger is moving.
   *
   * The ledger is the loop-closing half of the system: an item that is heard
   * but never answered is a channel that will stop being used. Throughput here
   * is the closest thing we have to a culture measurement.
   */
  public function getLedgerThroughput(): array {
    if (!$this->database->schema()->tableExists('node_field_data')) {
      return [];
    }

    $counts = [];
    try {
      $query = $this->database->select('node_field_data', 'n');
      $query->join('node__field_fb_status', 's', 's.entity_id = n.nid');
      $query->condition('n.type', 'feedback_ledger');
      $query->addField('s', 'field_fb_status_value', 'status');
      $query->addExpression('COUNT(*)', 'n');
      $query->groupBy('s.field_fb_status_value');
      $counts = $query->execute()->fetchAllKeyed();
    }
    catch (\Exception $e) {
      return [];
    }

    $total = array_sum($counts);
    $closed = ($counts['shipped'] ?? 0) + ($counts['wont_do'] ?? 0) + ($counts['forwarded'] ?? 0) + ($counts['superseded'] ?? 0);
    $open = $total - $closed;

    return [
      'by_status' => $counts,
      'total' => $this->claim(self::EVIDENCE_OBSERVED, 'Ledger items', $total, ['format' => 'integer']),
      'closed_rate' => $this->claim(
        self::EVIDENCE_OBSERVED,
        'Items answered (shipped, declined, forwarded or superseded)',
        $total > 0 ? $closed / $total : NULL,
        ['format' => 'percent', 'detail' => sprintf('%d closed, %d still open.', $closed, $open)]
      ),
      'loop_verdict' => $this->claim(
        self::EVIDENCE_SUBSTANTIATED,
        'Submitted feedback reliably receives an answer',
        $total > 0 && ($closed / $total) >= 0.5,
        [
          'format' => 'boolean',
          'corroborator' => 'The public /feedback-status ledger, which is curated by a human and visible to members, independent of the submission tables counted above.',
          'basis' => sprintf('%d of %d ledger items carry a terminal status.', $closed, $total),
          'falsifier' => 'A rising count of items stuck in received/reviewing, or ledger coverage falling behind raw submission volume.',
        ]
      ),
    ];
  }

  /**
   * Returns behavioural signals that move without anyone filing feedback.
   *
   * This is the counterweight to opinion. Every qualitative theme should be
   * paired with one of these — a number that would move if the theme is real.
   * A theme with no paired signal is an assumption, and is labelled as one.
   */
  public function getPairedSignals(): array {
    $now = $this->time->getRequestTime();
    $signals = [];

    // Onboarding: members stuck between joining and getting a door badge.
    if ($this->database->schema()->tableExists('ms_member_success_snapshot')) {
      $stages = $this->database->select('ms_member_success_snapshot', 's')
        ->condition('s.snapshot_type', 'daily')
        ->condition('s.is_latest', 1)
        ->fields('s', ['stage']);
      $stages->addExpression('COUNT(*)', 'n');
      $stages->groupBy('s.stage');
      $byStage = $stages->execute()->fetchAllKeyed();

      $signals['onboarding_backlog'] = $this->claim(
        self::EVIDENCE_OBSERVED,
        'Members in the onboarding stage',
        (int) ($byStage['onboarding'] ?? 0),
        [
          'format' => 'integer',
          'detail' => 'Pairs with any theme about the join flow being confusing. If the flow is genuinely hard, this backlog grows.',
        ]
      );
      $signals['recovery_queue'] = $this->claim(
        self::EVIDENCE_OBSERVED,
        'Members in the recovery stage',
        (int) ($byStage['recovery'] ?? 0),
        [
          'format' => 'integer',
          'detail' => 'Pairs with themes about billing, lapses and value. Rises before cancellations do.',
        ]
      );
    }

    // Safety: reports are rare by nature, so the useful reading is recency.
    $sources = $this->getSources(365);
    if (!empty($sources['safety_accident']['available'])) {
      $signals['safety_recency'] = $this->claim(
        self::EVIDENCE_OBSERVED,
        'Days since the last safety or accident report',
        $sources['safety_accident']['days_silent'],
        [
          'format' => 'integer',
          'detail' => 'Low volume here is ambiguous on its own: it means either a safe shop or an unused form. Read it against incident chatter in Slack before drawing a conclusion.',
        ]
      );
    }

    // Accessibility reporting is the clearest case of a channel whose silence
    // we must not read as an all-clear.
    if (!empty($sources['accessibility']['available'])) {
      $signals['accessibility_reach'] = $this->claim(
        self::EVIDENCE_ASSUMED,
        'Accessibility barriers are being reported when they occur',
        FALSE,
        [
          'basis' => sprintf('The accessibility form has %d submissions from %d accounts in total.', $sources['accessibility']['total'] ?? 0, $sources['accessibility']['distinct_uids'] ?? 0),
          'falsifier' => 'A proactive accessibility audit, or asking a sample of members directly, finding no unreported barriers.',
          'detail' => 'We have no evidence either way. Listed as an assumption because operating as though silence means no barriers is a decision, not a finding.',
        ]
      );
    }

    // Error load, which members mostly never report but always experience.
    if ($this->database->schema()->tableExists('watchdog')) {
      $week = $now - (7 * 86400);
      $errors = (int) $this->database->select('watchdog', 'w')
        ->condition('w.severity', 3, '<=')
        ->condition('w.timestamp', $week, '>=')
        ->countQuery()
        ->execute()
        ->fetchField();
      $signals['error_load'] = $this->claim(
        self::EVIDENCE_OBSERVED,
        'Site errors logged in the last 7 days (severity 3 and worse)',
        $errors,
        [
          'format' => 'integer',
          'detail' => 'Pairs with "the site is broken" themes. Members report a fraction of what this counts, so movement here often precedes feedback.',
        ]
      );
    }

    return $signals;
  }

  /**
   * Formats a ratio as a percentage string, or a dash when unknown.
   */
  public function formatPercent(?float $value): string {
    if ($value === NULL) {
      return '—';
    }
    return number_format($value * 100, 1) . '%';
  }

  /**
   * Exposes the evidence legend for rendering.
   */
  public function getEvidenceLegend(): array {
    $legend = [];
    foreach (self::EVIDENCE_LABELS as $tier => $label) {
      $legend[$tier] = [
        'label' => $label,
        'definition' => self::EVIDENCE_DEFINITIONS[$tier] ?? '',
      ];
    }
    return $legend;
  }

}
