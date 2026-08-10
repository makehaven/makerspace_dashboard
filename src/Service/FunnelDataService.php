<?php

namespace Drupal\makerspace_dashboard\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

/**
 * Aggregates cross-system metrics for outreach funnel charts.
 */
class FunnelDataService {

  /**
   * Default trailing window in months for funnel calculations.
   */
  protected const WINDOW_MONTHS = 12;

  /**
   * Minimum percentage width to display for funnel bars.
   */
  protected const MIN_WIDTH_PERCENT = 8;

  protected DateTimeZone $timezone;

  protected ?int $activityTargetRecordTypeId = NULL;

  /**
   * Cached event_type option group id (0 means "looked up, not found").
   */
  protected ?int $eventTypeOptionGroupId = NULL;

  /**
   * Constructs the service.
   */
  public function __construct(
    protected ContactDataService $contactDataService,
    protected Connection $database,
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
  ) {
    $this->timezone = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
  }

  /**
   * Provides the standard lead-generation funnel (mailing list -> workshops -> joins).
   */
  public function getLeadFunnelData(): array {
    $window = $this->buildWindow(self::WINDOW_MONTHS);
    $cacheId = sprintf('makerspace_dashboard:funnel:lead:%s', $window['cache_key']);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $workshopContacts = $this->getEventContactMap('workshop', $window['start'], $window['end']);

    $mailingList = $this->contactDataService->getEmailReadyContactsBetween($window['start'], $window['end']);

    $data = [
      'range' => $window,
      'mailing_list' => $mailingList,
      'workshop_participants' => count($workshopContacts),
      'member_joins' => $this->countMembersJoinedBetween($window['start'], $window['end']),
    ];

    $this->cache->set($cacheId, $data, $this->time->getRequestTime() + 3600, [
      'civicrm_contact_list',
      'civicrm_participant_list',
      'profile_list',
    ]);

    return $data;
  }

  /**
   * Provides stats for the tour-to-join conversion funnel.
   */
  public function getTourFunnelData(): array {
    $window = $this->buildWindow(self::WINDOW_MONTHS);
    $cacheId = sprintf('makerspace_dashboard:funnel:tours:%s', $window['cache_key']);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    // 1. Get contact maps from both events and activities.
    $eventMap = $this->getEventContactMap('tour', $window['start'], $window['end']);
    $activityMap = $this->getActivityContactMap('tour', $window['start'], $window['end']);

    // 2. Merge maps, taking the earliest date for each contact.
    $contactMap = $eventMap;
    foreach ($activityMap as $contactId => $touchDate) {
      if (!isset($contactMap[$contactId]) || $touchDate < $contactMap[$contactId]) {
        $contactMap[$contactId] = $touchDate;
      }
    }

    $summary = $this->summarizeContactConversions($contactMap);

    $data = [
      'range' => $window,
      'participants' => $summary['eligible_contacts'],
      'participants_total' => $summary['total_contacts'],
      'participants_already_members' => $summary['already_members'],
      'conversions' => $summary['conversions'],
      'conversion_rate' => $summary['conversion_rate'],
    ];

    $this->cache->set($cacheId, $data, $this->time->getRequestTime() + 3600, [
      'civicrm_participant_list',
      'civicrm_activity_list',
      'profile_list',
    ]);

    return $data;
  }

  /**
   * Provides stats for all event participants converting to membership joins.
   */
  public function getEventParticipantFunnelData(): array {
    $window = $this->buildWindow(self::WINDOW_MONTHS);
    $cacheId = sprintf('makerspace_dashboard:funnel:events:%s', $window['cache_key']);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    // Empty label match intentionally includes all event types.
    $contactMap = $this->getEventContactMap('', $window['start'], $window['end']);
    $summary = $this->summarizeContactConversions($contactMap);

    $data = [
      'range' => $window,
      'participants' => $summary['eligible_contacts'],
      'participants_total' => $summary['total_contacts'],
      'participants_already_members' => $summary['already_members'],
      'conversions' => $summary['conversions'],
      'conversion_rate' => $summary['conversion_rate'],
    ];

    $this->cache->set($cacheId, $data, $this->time->getRequestTime() + 3600, [
      'civicrm_participant_list',
      'profile_list',
    ]);

    return $data;
  }

  /**
   * Provides stats for recorded visits (activities) through to membership joins.
   */
  public function getVisitFunnelData(): array {
    $data = $this->getActivityFunnelData('visit', self::WINDOW_MONTHS);
    return [
      'range' => $data['range'],
      'visits' => $data['activities'],
      'conversions' => $data['conversions'],
    ];
  }

  /**
   * Provides activity-type conversion stats to membership joins.
   */
  public function getActivityFunnelData(string $activityTypeLabelMatch, int $months = self::WINDOW_MONTHS): array {
    $labelMatch = trim($activityTypeLabelMatch);
    if ($labelMatch === '') {
      return [
        'range' => $this->buildWindow(max(1, $months)),
        'activities' => 0,
        'conversions' => 0,
        'conversion_rate' => NULL,
        'label_match' => $labelMatch,
      ];
    }

    $window = $this->buildWindow(max(1, $months));
    $cacheId = sprintf('makerspace_dashboard:funnel:activity:%s:%s', md5(strtolower($labelMatch)), $window['cache_key']);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $contactMap = $this->getActivityContactMap($labelMatch, $window['start'], $window['end']);
    $summary = $this->summarizeContactConversions($contactMap);
    $activities = $summary['eligible_contacts'];
    $converted = $summary['conversions'];

    $data = [
      'range' => $window,
      'activities' => $activities,
      'activities_total' => $summary['total_contacts'],
      'activities_already_members' => $summary['already_members'],
      'conversions' => $converted,
      'conversion_rate' => $activities > 0 ? ($converted / $activities) : NULL,
      'label_match' => $labelMatch,
    ];

    $this->cache->set($cacheId, $data, $this->time->getRequestTime() + 3600, [
      'civicrm_activity_list',
      'profile_list',
    ]);

    return $data;
  }

  /**
   * Builds a rolling window definition.
   */
  protected function buildWindow(int $months): array {
    $months = max(1, $months);
    $now = $this->now();
    $end = $now->modify('last day of this month')->setTime(23, 59, 59);
    $start = $now
      ->modify('first day of this month')
      ->setTime(0, 0, 0)
      ->sub(new DateInterval(sprintf('P%dM', $months - 1)));

    return [
      'start' => $start,
      'end' => $end,
      'months' => $months,
      'cache_key' => sprintf('%s:%s', $start->format('Ymd'), $end->format('Ymd')),
    ];
  }

  /**
   * Loads a contact => earliest event date map for the given label match.
   */
  protected function getEventContactMap(string $labelMatch, DateTimeImmutable $start, DateTimeImmutable $end): array {
    $cacheId = sprintf('makerspace_dashboard:funnel:event_map:v2:%s:%s:%s', strtolower($labelMatch), $start->format('Ymd'), $end->format('Ymd'));
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $query = $this->database->select('civicrm_participant', 'p');
    $query->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    $query->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $query->fields('p', ['contact_id']);
    $query->addExpression('MIN(e.start_date)', 'first_event_date');
    $this->applyRealParticipantConditions($query);
    $query->condition('e.start_date', [
      $start->format('Y-m-d H:i:s'),
      $end->format('Y-m-d H:i:s'),
    ], 'BETWEEN');
    $this->applyEventTypeLabelMatch($query, $labelMatch);
    $query->groupBy('p.contact_id');

    $map = [];
    foreach ($query->execute() as $record) {
      $contactId = (int) ($record->contact_id ?? 0);
      if ($contactId <= 0) {
        continue;
      }
      $eventDate = $this->normalizeDate($record->first_event_date ?? NULL);
      if (!$eventDate) {
        continue;
      }
      $map[$contactId] = $eventDate;
    }

    $this->cache->set($cacheId, $map, $this->time->getRequestTime() + 3600, ['civicrm_participant_list']);
    return $map;
  }

  /**
   * Restricts a participant query to real, counted registrations.
   *
   * CiviCRM keeps test registrations and template events in the same tables as
   * live ones. Counting them inflates the denominator of every event funnel,
   * which reads as a conversion-rate drop rather than as bad data.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   A query with `civicrm_participant` aliased as `p`, `civicrm_event` as
   *   `e`, and `civicrm_participant_status_type` as `pst`.
   */
  protected function applyRealParticipantConditions($query): void {
    $query->condition('pst.is_counted', 1);
    $query->condition('p.contact_id', 0, '>');
    // COALESCE rather than `= 0`: legacy rows can carry NULL in these flags,
    // and a plain equality check would silently drop real registrations.
    $query->where('COALESCE(p.is_test, 0) = 0');
    $query->where('COALESCE(e.is_template, 0) = 0');
  }

  /**
   * Joins the event-type option value and applies an optional label filter.
   *
   * `civicrm_option_value.value` is only unique within its option group, so
   * joining on the raw event_type_id without scoping the group can match a
   * label belonging to an unrelated group (activity types, participant roles).
   * An empty $labelMatch still joins, so callers can select the label.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   A query with `civicrm_event` aliased as `e`.
   * @param string $labelMatch
   *   Case-insensitive substring the event type label must contain. An empty
   *   string matches every event type.
   */
  protected function applyEventTypeLabelMatch($query, string $labelMatch): void {
    $groupId = $this->getEventTypeOptionGroupId();
    if ($groupId) {
      $query->leftJoin('civicrm_option_value', 'ov', 'ov.value = e.event_type_id AND ov.option_group_id = :event_type_group', [
        ':event_type_group' => $groupId,
      ]);
    }
    else {
      $query->leftJoin('civicrm_option_value', 'ov', 'ov.value = e.event_type_id');
      $query->innerJoin('civicrm_option_group', 'og', 'og.id = ov.option_group_id');
      $query->condition('og.name', 'event_type');
    }

    $trimmed = trim($labelMatch);
    if ($trimmed === '') {
      return;
    }
    $query->where('LOWER(COALESCE(ov.label, \'\')) LIKE :event_label', [
      ':event_label' => '%' . $this->database->escapeLike(strtolower($trimmed)) . '%',
    ]);
  }

  /**
   * Resolves and caches the option group id holding event types.
   */
  protected function getEventTypeOptionGroupId(): ?int {
    if ($this->eventTypeOptionGroupId !== NULL) {
      return $this->eventTypeOptionGroupId ?: NULL;
    }
    $query = $this->database->select('civicrm_option_group', 'og');
    $query->fields('og', ['id']);
    $query->condition('og.name', 'event_type');
    $groupId = $query->execute()->fetchField();
    $this->eventTypeOptionGroupId = $groupId ? (int) $groupId : 0;
    return $this->eventTypeOptionGroupId ?: NULL;
  }

  /**
   * Loads a contact => earliest activity date map for the given label match.
   */
  protected function getActivityContactMap(string $labelMatch, DateTimeImmutable $start, DateTimeImmutable $end): array {
    $cacheId = sprintf('makerspace_dashboard:funnel:activity_map:%s:%s:%s', strtolower($labelMatch), $start->format('Ymd'), $end->format('Ymd'));
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $pattern = '%' . $this->database->escapeLike(strtolower($labelMatch)) . '%';
    $targetRecordTypeId = $this->getActivityTargetRecordTypeId();

    $query = $this->database->select('civicrm_activity', 'a');
    $query->innerJoin('civicrm_activity_contact', 'ac', 'ac.activity_id = a.id');
    $query->leftJoin('civicrm_option_value', 'ov', 'ov.value = a.activity_type_id');
    $query->fields('ac', ['contact_id']);
    $query->addExpression('MIN(a.activity_date_time)', 'first_activity_date');
    $query->condition('ac.record_type_id', $targetRecordTypeId);
    $query->condition('ac.contact_id', 0, '>');
    $query->condition('a.activity_date_time', [
      $start->format('Y-m-d H:i:s'),
      $end->format('Y-m-d H:i:s'),
    ], 'BETWEEN');
    $query->condition('a.is_test', 0);
    $query->condition('a.is_deleted', 0);
    $query->where('LOWER(COALESCE(ov.label, \'\')) LIKE :activity_label', [
      ':activity_label' => $pattern,
    ]);
    $query->groupBy('ac.contact_id');

    $map = [];
    foreach ($query->execute() as $record) {
      $contactId = (int) ($record->contact_id ?? 0);
      if ($contactId <= 0) {
        continue;
      }
      $activityDate = $this->normalizeDate($record->first_activity_date ?? NULL);
      if (!$activityDate) {
        continue;
      }
      $map[$contactId] = $activityDate;
    }

    $this->cache->set($cacheId, $map, $this->time->getRequestTime() + 3600, ['civicrm_activity_list']);
    return $map;
  }

  /**
   * Counts distinct members who joined within the provided range.
   */
  protected function countMembersJoinedBetween(DateTimeImmutable $start, DateTimeImmutable $end): int {
    $cacheId = sprintf('makerspace_dashboard:funnel:joins:%s:%s', $start->format('Ymd'), $end->format('Ymd'));
    if ($cache = $this->cache->get($cacheId)) {
      return (int) $cache->data;
    }

    $query = $this->database->select('profile', 'p');
    $query->innerJoin('profile__field_member_join_date', 'join_date', 'join_date.entity_id = p.profile_id AND join_date.deleted = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'join_count');
    $query->condition('p.type', 'main');
    $query->condition('p.is_default', 1);
    $query->condition('p.status', 1);
    $query->condition('u.status', 1);
    $query->condition('join_date.field_member_join_date_value', [
      $start->format('Y-m-d'),
      $end->format('Y-m-d'),
    ], 'BETWEEN');

    $count = (int) $query->execute()->fetchField();
    $this->cache->set($cacheId, $count, $this->time->getRequestTime() + 3600, ['profile_list', 'user_list']);
    return $count;
  }

  /**
   * Summarizes conversion eligibility and outcomes for touchpoint contacts.
   *
   * @param array $contactDates
   *   Map of contact_id => DateTimeImmutable representing the first touch date.
   */
  protected function summarizeContactConversions(array $contactDates): array {
    $build = static fn(int $total, int $eligible, int $alreadyMembers, int $conversions): array => [
      'total_contacts' => $total,
      'eligible_contacts' => $eligible,
      'already_members' => $alreadyMembers,
      'conversions' => $conversions,
      'conversion_rate' => $eligible > 0 ? ($conversions / $eligible) : NULL,
    ];

    if (empty($contactDates)) {
      return $build(0, 0, 0, 0);
    }

    $contactToUid = $this->loadContactUserMap(array_keys($contactDates));
    $joinDates = !empty($contactToUid) ? $this->loadJoinDates(array_values($contactToUid)) : [];

    $eligible = 0;
    $alreadyMembers = 0;
    $converted = 0;
    foreach ($contactDates as $contactId => $touchDate) {
      $uid = $contactToUid[$contactId] ?? NULL;
      if (!$uid) {
        $eligible++;
        continue;
      }
      if (!isset($joinDates[$uid])) {
        $eligible++;
        continue;
      }

      $joinDate = $joinDates[$uid];
      if ($joinDate < $touchDate) {
        $alreadyMembers++;
        continue;
      }

      $eligible++;
      $converted++;
    }

    return $build(count($contactDates), $eligible, $alreadyMembers, $converted);
  }

  /**
   * Loads a map of contact_id => Drupal user ID.
   */
  protected function loadContactUserMap(array $contactIds): array {
    if (empty($contactIds)) {
      return [];
    }
    $query = $this->database->select('civicrm_uf_match', 'ufm');
    $query->fields('ufm', ['contact_id', 'uf_id']);
    $query->condition('ufm.contact_id', $contactIds, 'IN');

    $map = [];
    foreach ($query->execute() as $record) {
      $contactId = (int) ($record->contact_id ?? 0);
      $uid = (int) ($record->uf_id ?? 0);
      if ($contactId <= 0 || $uid <= 0) {
        continue;
      }
      $map[$contactId] = $uid;
    }
    return $map;
  }

  /**
   * Loads inferred join dates indexed by user ID.
   *
   * Join date source: earliest `profile.created` timestamp for the user's
   * default main profile. This replaces the legacy member join date field.
   */
  protected function loadJoinDates(array $uids): array {
    if (empty($uids)) {
      return [];
    }
    $query = $this->database->select('profile', 'p');
    $query->fields('p', ['uid']);
    $query->addExpression('MIN(p.created)', 'join_value');
    $query->condition('p.uid', $uids, 'IN');
    $query->condition('p.type', 'main');
    $query->condition('p.is_default', 1);
    $query->groupBy('p.uid');

    $map = [];
    foreach ($query->execute() as $record) {
      $uid = (int) ($record->uid ?? 0);
      if ($uid <= 0) {
        continue;
      }
      $joinDate = $this->normalizeDate($record->join_value ?? NULL);
      if (!$joinDate) {
        continue;
      }
      $map[$uid] = $joinDate;
    }
    return $map;
  }

  /**
   * Resolves and caches the record_type_id used for activity targets.
   */
  protected function getActivityTargetRecordTypeId(): int {
    if ($this->activityTargetRecordTypeId !== NULL) {
      return $this->activityTargetRecordTypeId;
    }
    $query = $this->database->select('civicrm_option_group', 'og');
    $query->innerJoin('civicrm_option_value', 'ov', 'ov.option_group_id = og.id');
    $query->addField('ov', 'value');
    $query->condition('og.name', 'activity_contacts');
    $query->where('LOWER(ov.label) LIKE :pattern', [':pattern' => '%target%']);
    $value = $query->execute()->fetchField();
    $this->activityTargetRecordTypeId = $value ? (int) $value : 3;
    return $this->activityTargetRecordTypeId;
  }

  /**
   * Converts a raw date value into a DateTimeImmutable.
   */
  protected function normalizeDate($value): ?DateTimeImmutable {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
      try {
        return (new DateTimeImmutable('@' . (int) $value))->setTimezone($this->timezone);
      }
      catch (\Throwable $e) {
        return NULL;
      }
    }
    $timestamp = strtotime((string) $value);
    if ($timestamp === FALSE) {
      return NULL;
    }
    return (new DateTimeImmutable("@$timestamp"))->setTimezone($this->timezone);
  }

  /**
   * Returns quarterly tour-to-member conversion rates as a trend (oldest-first).
   *
   * Each point = (tour contacts who later joined) / (eligible tour contacts)
   * over the 12-month rolling window ending at that completed quarter.
   */
  public function getTourConversionRateTrend(int $quarters = 8): array {
    $cacheId = sprintf('makerspace_dashboard:funnel:tour_conversion_trend:%d', $quarters);
    if ($cached = $this->cache->get($cacheId)) {
      return $cached->data;
    }

    $now = $this->now();
    $trend = [];
    for ($i = $quarters - 1; $i >= 0; $i--) {
      $windowEnd = $this->completedQuarterEnd($now, $i);
      $windowStart = $this->windowStartFor($windowEnd);

      $eventMap = $this->getEventContactMap('tour', $windowStart, $windowEnd);
      $activityMap = $this->getActivityContactMap('tour', $windowStart, $windowEnd);

      $contactMap = $eventMap;
      foreach ($activityMap as $contactId => $touchDate) {
        if (!isset($contactMap[$contactId]) || $touchDate < $contactMap[$contactId]) {
          $contactMap[$contactId] = $touchDate;
        }
      }

      $summary = $this->summarizeContactConversions($contactMap);
      $eligible = $summary['eligible_contacts'];
      $trend[] = $eligible > 0 ? round($summary['conversions'] / $eligible, 4) : 0.0;
    }

    $this->cache->set($cacheId, $trend, $this->time->getRequestTime() + 3600, [
      'civicrm_participant_list',
      'civicrm_activity_list',
      'profile_list',
    ]);
    return $trend;
  }

  /**
   * Returns quarterly event-participant-to-member conversion rates (oldest-first).
   *
   * Each point = (event participants who later joined) / (eligible participants)
   * over the 12-month rolling window ending at that completed quarter.
   */
  public function getEventParticipantConversionRateTrend(int $quarters = 8): array {
    $cacheId = sprintf('makerspace_dashboard:funnel:event_conversion_trend:%d', $quarters);
    if ($cached = $this->cache->get($cacheId)) {
      return $cached->data;
    }

    $now = $this->now();
    $trend = [];
    for ($i = $quarters - 1; $i >= 0; $i--) {
      $windowEnd = $this->completedQuarterEnd($now, $i);
      $windowStart = $this->windowStartFor($windowEnd);

      // Empty label intentionally matches all event types.
      $contactMap = $this->getEventContactMap('', $windowStart, $windowEnd);
      $summary = $this->summarizeContactConversions($contactMap);
      $eligible = $summary['eligible_contacts'];
      $trend[] = $eligible > 0 ? round($summary['conversions'] / $eligible, 4) : 0.0;
    }

    $this->cache->set($cacheId, $trend, $this->time->getRequestTime() + 3600, [
      'civicrm_participant_list',
      'profile_list',
    ]);
    return $trend;
  }

  /**
   * Returns quarterly guest-waiver-to-member conversion rates (oldest-first).
   *
   * Each point = (guest waiver signers who later joined) / (eligible contacts)
   * over the 12-month rolling window ending at that completed quarter.
   */
  public function getGuestWaiverConversionRateTrend(int $quarters = 8): array {
    $cacheId = sprintf('makerspace_dashboard:funnel:guest_waiver_conversion_trend:%d', $quarters);
    if ($cached = $this->cache->get($cacheId)) {
      return $cached->data;
    }

    $now = $this->now();
    $trend = [];
    for ($i = $quarters - 1; $i >= 0; $i--) {
      $windowEnd = $this->completedQuarterEnd($now, $i);
      $windowStart = $this->windowStartFor($windowEnd);

      $contactMap = $this->getActivityContactMap('guest waiver', $windowStart, $windowEnd);
      $summary = $this->summarizeContactConversions($contactMap);
      $eligible = $summary['eligible_contacts'];
      $trend[] = $eligible > 0 ? round($summary['conversions'] / $eligible, 4) : 0.0;
    }

    $this->cache->set($cacheId, $trend, $this->time->getRequestTime() + 3600, [
      'civicrm_activity_list',
      'profile_list',
    ]);
    return $trend;
  }

  /**
   * Counts unique tour participants (events + activities) in a date range.
   */
  public function getTourParticipantCount(DateTimeImmutable $start, DateTimeImmutable $end): int {
    $eventMap = $this->getEventContactMap('tour', $start, $end);
    $activityMap = $this->getActivityContactMap('tour', $start, $end);

    $uids = array_unique(array_merge(array_keys($eventMap), array_keys($activityMap)));
    return count($uids);
  }

  /**
   * Returns a dense monthly series of unique tour contacts (oldest-first).
   */
  public function getTourMonthlyUniqueContactSeries(int $months = self::WINDOW_MONTHS): array {
    $window = $this->buildWindow($months);
    $cacheId = sprintf('makerspace_dashboard:funnel:tours:monthly:%s', $window['cache_key']);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $targetRecordTypeId = $this->getActivityTargetRecordTypeId();

    $query = $this->database->select('civicrm_activity', 'a');
    $query->innerJoin('civicrm_activity_contact', 'ac', 'ac.activity_id = a.id');
    $query->leftJoin('civicrm_option_value', 'ov', 'ov.value = a.activity_type_id');
    $query->addExpression("DATE_FORMAT(a.activity_date_time, '%Y-%m')", 'ym');
    $query->addExpression('COUNT(DISTINCT ac.contact_id)', 'unique_contacts');
    $query->condition('ac.record_type_id', $targetRecordTypeId);
    $query->condition('ac.contact_id', 0, '>');
    $query->condition('a.activity_date_time', [
      $window['start']->format('Y-m-d H:i:s'),
      $window['end']->format('Y-m-d H:i:s'),
    ], 'BETWEEN');
    $query->condition('a.is_test', 0);
    $query->condition('a.is_deleted', 0);
    $query->where("LOWER(COALESCE(ov.label, '')) LIKE :activity_label", [
      ':activity_label' => '%tour%',
    ]);
    $query->groupBy('ym');

    $byMonth = [];
    foreach ($query->execute() as $row) {
      $byMonth[(string) $row->ym] = (int) $row->unique_contacts;
    }

    $series = [];
    $cursor = $window['start'];
    for ($i = 0; $i < $window['months']; $i++) {
      $key = $cursor->format('Y-m');
      $series[] = $byMonth[$key] ?? 0;
      $cursor = $cursor->modify('+1 month');
    }

    $this->cache->set($cacheId, $series, $this->time->getRequestTime() + 3600, ['civicrm_activity_list']);
    return $series;
  }

  /**
   * Returns a monthly tour-to-member conversion trend (oldest-first).
   *
   * For each of the trailing full months this counts the distinct contacts
   * with a tour touchpoint (event participation or activity) and how many of
   * those contacts joined within the conversion window of their earliest
   * tour date that month.
   *
   * @param int $months
   *   Number of trailing full months to include.
   * @param int $conversionWindowDays
   *   Days after the tour within which a join counts as a conversion.
   *
   * @return array
   *   Structured series data with keys:
   *   - range: Window metadata (start, end, months).
   *   - conversion_window_days: The conversion window applied.
   *   - labels: Month labels (oldest-first).
   *   - month_keys: Canonical Y-m-01 strings aligned with the labels.
   *   - tours: Eligible tour contacts per month (excludes existing members).
   *   - conversions: Contacts joining within the window, per month.
   *   - rates: Conversion percentages aligned with the labels.
   */
  public function getTourMonthlyConversionSeries(int $months = self::WINDOW_MONTHS, int $conversionWindowDays = 90): array {
    $months = max(1, $months);
    $end = $this->now()
      ->modify('first day of this month')
      ->setTime(0, 0, 0)
      ->modify('-1 second');
    $start = $end
      ->modify('first day of this month')
      ->setTime(0, 0, 0)
      ->sub(new DateInterval(sprintf('P%dM', $months - 1)));

    $cacheId = sprintf(
      'makerspace_dashboard:funnel:tours:monthly_conversion:%d:%s:%s',
      $conversionWindowDays,
      $start->format('Ymd'),
      $end->format('Ymd')
    );
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $labels = [];
    $monthKeys = [];
    $tours = [];
    $conversions = [];
    $rates = [];

    $cursor = $start;
    for ($i = 0; $i < $months; $i++) {
      $monthStart = $cursor;
      $monthEnd = $cursor->modify('last day of this month')->setTime(23, 59, 59);

      $contactMap = $this->getEventContactMap('tour', $monthStart, $monthEnd);
      foreach ($this->getActivityContactMap('tour', $monthStart, $monthEnd) as $contactId => $touchDate) {
        if (!isset($contactMap[$contactId]) || $touchDate < $contactMap[$contactId]) {
          $contactMap[$contactId] = $touchDate;
        }
      }

      $summary = $this->summarizeWindowedConversions($contactMap, $conversionWindowDays);

      $labels[] = $monthStart->format('M Y');
      $monthKeys[] = $monthStart->format('Y-m-01');
      $tours[] = $summary['eligible'];
      $conversions[] = $summary['conversions'];
      $rates[] = $summary['eligible'] > 0 ? round(($summary['conversions'] / $summary['eligible']) * 100, 1) : 0.0;

      $cursor = $cursor->modify('+1 month');
    }

    $data = [
      'range' => [
        'start' => $start,
        'end' => $end,
        'months' => $months,
      ],
      'conversion_window_days' => $conversionWindowDays,
      'labels' => $labels,
      'month_keys' => $monthKeys,
      'tours' => $tours,
      'conversions' => $conversions,
      'rates' => $rates,
    ];

    $this->cache->set($cacheId, $data, $this->time->getRequestTime() + 3600, [
      'civicrm_participant_list',
      'civicrm_activity_list',
      'profile_list',
    ]);
    return $data;
  }

  /**
   * Returns a monthly event-to-member conversion trend (oldest-first).
   *
   * The event equivalent of getTourMonthlyConversionSeries(): for each
   * trailing full month it counts the distinct contacts who attended any
   * counted event that month and how many of them joined within the
   * conversion window of their earliest event that month. Contacts who were
   * already members are excluded from the eligible pool.
   *
   * @param int $months
   *   Number of trailing full months to include.
   * @param int $conversionWindowDays
   *   Days after the event within which a join counts as a conversion.
   *
   * @return array
   *   Structured series data with keys: range, conversion_window_days,
   *   labels, month_keys, participants, conversions, rates.
   */
  public function getEventMonthlyConversionSeries(int $months = self::WINDOW_MONTHS, int $conversionWindowDays = 90): array {
    $months = max(1, $months);
    $end = $this->now()
      ->modify('first day of this month')
      ->setTime(0, 0, 0)
      ->modify('-1 second');
    $start = $end
      ->modify('first day of this month')
      ->setTime(0, 0, 0)
      ->sub(new DateInterval(sprintf('P%dM', $months - 1)));

    $cacheId = sprintf(
      'makerspace_dashboard:funnel:events:monthly_conversion:%d:%s:%s',
      $conversionWindowDays,
      $start->format('Ymd'),
      $end->format('Ymd')
    );
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $labels = [];
    $monthKeys = [];
    $participants = [];
    $conversions = [];
    $rates = [];

    $cursor = $start;
    for ($i = 0; $i < $months; $i++) {
      $monthStart = $cursor;
      $monthEnd = $cursor->modify('last day of this month')->setTime(23, 59, 59);

      // Empty label match intentionally includes every event type.
      $contactMap = $this->getEventContactMap('', $monthStart, $monthEnd);
      $summary = $this->summarizeWindowedConversions($contactMap, $conversionWindowDays);

      $labels[] = $monthStart->format('M Y');
      $monthKeys[] = $monthStart->format('Y-m-01');
      $participants[] = $summary['eligible'];
      $conversions[] = $summary['conversions'];
      $rates[] = $summary['eligible'] > 0 ? round(($summary['conversions'] / $summary['eligible']) * 100, 1) : 0.0;

      $cursor = $cursor->modify('+1 month');
    }

    $data = [
      'range' => [
        'start' => $start,
        'end' => $end,
        'months' => $months,
      ],
      'conversion_window_days' => $conversionWindowDays,
      'labels' => $labels,
      'month_keys' => $monthKeys,
      'participants' => $participants,
      'conversions' => $conversions,
      'rates' => $rates,
    ];

    $this->cache->set($cacheId, $data, $this->time->getRequestTime() + 3600, [
      'civicrm_participant_list',
      'profile_list',
    ]);
    return $data;
  }

  /**
   * Summarizes the first-time event registrant cohort and what became of it.
   *
   * "First time" means the contact had never had a counted registration at any
   * MakeHaven event before this one — not merely their first registration
   * inside the reporting window. That distinction is the whole point of the
   * cohort: it isolates people meeting the organization for the first time
   * from regulars who keep coming back.
   *
   * @param int $months
   *   Number of trailing full months of first touches to include.
   * @param int $conversionWindowDays
   *   Days after the first event within which a join counts as a fast
   *   conversion.
   *
   * @return array
   *   Cohort data with keys: range, conversion_window_days, total_first_time,
   *   already_members, eligible, returned, converted_window, converted_ever,
   *   rate_window, rate_ever, return_rate, labels, month_keys, first_timers,
   *   conversions, rates.
   */
  public function getFirstTimeParticipantCohort(int $months = self::WINDOW_MONTHS, int $conversionWindowDays = 90): array {
    $months = max(1, $months);
    $end = $this->now()
      ->modify('first day of this month')
      ->setTime(0, 0, 0)
      ->modify('-1 second');
    $start = $end
      ->modify('first day of this month')
      ->setTime(0, 0, 0)
      ->sub(new DateInterval(sprintf('P%dM', $months - 1)));

    $cacheId = sprintf(
      'makerspace_dashboard:funnel:first_time_cohort:%d:%s:%s',
      $conversionWindowDays,
      $start->format('Ymd'),
      $end->format('Ymd')
    );
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $cohort = $this->getFirstTimeEventContactMap($start, $end);

    $windowSummary = $this->summarizeWindowedConversions($cohort, $conversionWindowDays);
    $everSummary = $this->summarizeContactConversions($cohort);

    // "Returned" is measured only across contacts still in the eligible pool,
    // so people who were already members before their first event cannot
    // inflate it.
    $eligibleContactIds = $this->filterOutExistingMembers($cohort);
    $returned = $this->countContactsWithRepeatParticipation($eligibleContactIds);

    $labels = [];
    $monthKeys = [];
    $firstTimers = [];
    $monthlyConversions = [];
    $monthlyRates = [];

    $cursor = $start;
    for ($i = 0; $i < $months; $i++) {
      $monthStart = $cursor;
      $monthEnd = $cursor->modify('last day of this month')->setTime(23, 59, 59);

      $monthCohort = [];
      foreach ($cohort as $contactId => $touchDate) {
        if ($touchDate >= $monthStart && $touchDate <= $monthEnd) {
          $monthCohort[$contactId] = $touchDate;
        }
      }
      $monthSummary = $this->summarizeWindowedConversions($monthCohort, $conversionWindowDays);

      $labels[] = $monthStart->format('M Y');
      $monthKeys[] = $monthStart->format('Y-m-01');
      $firstTimers[] = $monthSummary['eligible'];
      $monthlyConversions[] = $monthSummary['conversions'];
      $monthlyRates[] = $monthSummary['eligible'] > 0
        ? round(($monthSummary['conversions'] / $monthSummary['eligible']) * 100, 1)
        : 0.0;

      $cursor = $cursor->modify('+1 month');
    }

    $eligible = $windowSummary['eligible'];
    $data = [
      'range' => [
        'start' => $start,
        'end' => $end,
        'months' => $months,
      ],
      'conversion_window_days' => $conversionWindowDays,
      'total_first_time' => count($cohort),
      'already_members' => $windowSummary['already_members'],
      'eligible' => $eligible,
      'returned' => $returned,
      'converted_window' => $windowSummary['conversions'],
      'converted_ever' => $everSummary['conversions'],
      'rate_window' => $eligible > 0 ? ($windowSummary['conversions'] / $eligible) : NULL,
      'rate_ever' => $eligible > 0 ? ($everSummary['conversions'] / $eligible) : NULL,
      'return_rate' => $eligible > 0 ? ($returned / $eligible) : NULL,
      'labels' => $labels,
      'month_keys' => $monthKeys,
      'first_timers' => $firstTimers,
      'conversions' => $monthlyConversions,
      'rates' => $monthlyRates,
    ];

    $this->cache->set($cacheId, $data, $this->time->getRequestTime() + 3600, [
      'civicrm_participant_list',
      'profile_list',
    ]);
    return $data;
  }

  /**
   * Loads contacts whose first-ever counted registration falls in the window.
   *
   * @param \DateTimeImmutable $start
   *   Start of the window the first touch must fall inside.
   * @param \DateTimeImmutable $end
   *   End of the window the first touch must fall inside.
   *
   * @return array
   *   Map of contact_id => DateTimeImmutable first event date.
   */
  protected function getFirstTimeEventContactMap(DateTimeImmutable $start, DateTimeImmutable $end): array {
    $cacheId = sprintf(
      'makerspace_dashboard:funnel:first_time_map:%s:%s',
      $start->format('Ymd'),
      $end->format('Ymd')
    );
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    // MIN() runs over the contact's entire history, not the window, so a
    // contact whose earliest registration predates the window is excluded by
    // the HAVING clause rather than counted as a newcomer.
    $firstTouch = $this->database->select('civicrm_participant', 'p');
    $firstTouch->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    $firstTouch->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $firstTouch->fields('p', ['contact_id']);
    $firstTouch->addExpression('MIN(e.start_date)', 'first_event_date');
    $this->applyRealParticipantConditions($firstTouch);
    $firstTouch->groupBy('p.contact_id');
    $firstTouch->havingCondition('first_event_date', $start->format('Y-m-d H:i:s'), '>=');
    $firstTouch->havingCondition('first_event_date', $end->format('Y-m-d H:i:s'), '<=');

    $map = [];
    foreach ($firstTouch->execute() as $record) {
      $contactId = (int) ($record->contact_id ?? 0);
      if ($contactId <= 0) {
        continue;
      }
      $eventDate = $this->normalizeDate($record->first_event_date ?? NULL);
      if (!$eventDate) {
        continue;
      }
      $map[$contactId] = $eventDate;
    }

    $this->cache->set($cacheId, $map, $this->time->getRequestTime() + 3600, ['civicrm_participant_list']);
    return $map;
  }

  /**
   * Returns the cohort contact ids that were not already members at first touch.
   *
   * @param array $contactDates
   *   Map of contact_id => DateTimeImmutable touch date.
   *
   * @return int[]
   *   Contact ids still eligible to convert.
   */
  protected function filterOutExistingMembers(array $contactDates): array {
    if (empty($contactDates)) {
      return [];
    }
    $contactToUid = $this->loadContactUserMap(array_keys($contactDates));
    $joinDates = !empty($contactToUid) ? $this->loadJoinDates(array_values($contactToUid)) : [];

    $eligible = [];
    foreach ($contactDates as $contactId => $touchDate) {
      $uid = $contactToUid[$contactId] ?? NULL;
      $joinDate = $uid !== NULL ? ($joinDates[$uid] ?? NULL) : NULL;
      if ($joinDate !== NULL && $joinDate < $touchDate) {
        continue;
      }
      $eligible[] = (int) $contactId;
    }
    return $eligible;
  }

  /**
   * Counts contacts with more than one counted event registration.
   *
   * @param int[] $contactIds
   *   Contact ids to inspect.
   *
   * @return int
   *   How many of them registered for at least two distinct events.
   */
  protected function countContactsWithRepeatParticipation(array $contactIds): int {
    if (empty($contactIds)) {
      return 0;
    }

    $inner = $this->database->select('civicrm_participant', 'p');
    $inner->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    $inner->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $inner->fields('p', ['contact_id']);
    $inner->addExpression('COUNT(DISTINCT p.event_id)', 'event_count');
    $this->applyRealParticipantConditions($inner);
    $inner->condition('p.contact_id', $contactIds, 'IN');
    $inner->groupBy('p.contact_id');
    $inner->havingCondition('event_count', 1, '>');

    $outer = $this->database->select($inner, 'repeat_contacts');
    $outer->addExpression('COUNT(*)', 'repeat_total');
    return (int) $outer->execute()->fetchField();
  }

  /**
   * Summarizes conversions that land within a bounded window of the touch.
   *
   * Mirrors summarizeContactConversions() but only counts joins occurring
   * within $conversionWindowDays of the contact's touch date. Contacts whose
   * join date precedes the touch are treated as existing members and
   * excluded from the eligible pool.
   *
   * @param array $contactDates
   *   Map of contact_id => DateTimeImmutable representing the touch date.
   * @param int $conversionWindowDays
   *   Days after the touch within which a join counts as a conversion.
   */
  protected function summarizeWindowedConversions(array $contactDates, int $conversionWindowDays): array {
    if (empty($contactDates)) {
      return [
        'eligible' => 0,
        'conversions' => 0,
        'already_members' => 0,
      ];
    }

    $contactToUid = $this->loadContactUserMap(array_keys($contactDates));
    $joinDates = !empty($contactToUid) ? $this->loadJoinDates(array_values($contactToUid)) : [];

    $eligible = 0;
    $alreadyMembers = 0;
    $converted = 0;
    foreach ($contactDates as $contactId => $touchDate) {
      $uid = $contactToUid[$contactId] ?? NULL;
      $joinDate = $uid !== NULL ? ($joinDates[$uid] ?? NULL) : NULL;
      if ($joinDate === NULL) {
        $eligible++;
        continue;
      }
      if ($joinDate < $touchDate) {
        $alreadyMembers++;
        continue;
      }
      $eligible++;
      $deadline = $touchDate->add(new DateInterval(sprintf('P%dD', $conversionWindowDays)));
      if ($joinDate <= $deadline) {
        $converted++;
      }
    }

    return [
      'eligible' => $eligible,
      'conversions' => $converted,
      'already_members' => $alreadyMembers,
    ];
  }

  /**
   * Returns the end of the completed quarter N quarters ago.
   */
  private function completedQuarterEnd(DateTimeImmutable $now, int $quartersAgo): DateTimeImmutable {
    $month = (int) $now->format('n');
    $year  = (int) $now->format('Y');

    $prevQ = (int) ceil($month / 3) - 1;
    if ($prevQ <= 0) {
      $prevQ = 4;
      $year--;
    }

    $targetQ = $prevQ - $quartersAgo;
    while ($targetQ <= 0) {
      $targetQ += 4;
      $year--;
    }

    $endMonth = $targetQ * 3;
    $lastDay  = (int) (new DateTimeImmutable(sprintf('%d-%02d-01', $year, $endMonth)))->format('t');
    return (new DateTimeImmutable(sprintf('%d-%02d-%02d 23:59:59', $year, $endMonth, $lastDay)))->setTimezone($this->timezone);
  }

  /**
   * Returns the start of a rolling window of $months ending at $windowEnd.
   */
  private function windowStartFor(DateTimeImmutable $windowEnd, int $months = self::WINDOW_MONTHS): DateTimeImmutable {
    return $windowEnd
      ->modify('first day of this month')
      ->setTime(0, 0, 0)
      ->sub(new DateInterval(sprintf('P%dM', $months - 1)));
  }

  /**
   * Helper retrieving the current timestamp.
   */
  protected function now(): DateTimeImmutable {
    return (new DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($this->timezone);
  }

}
