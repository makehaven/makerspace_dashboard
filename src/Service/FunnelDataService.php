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
    $cacheId = sprintf('makerspace_dashboard:funnel:event_map:%s:%s:%s', strtolower($labelMatch), $start->format('Ymd'), $end->format('Ymd'));
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $pattern = '%' . $this->database->escapeLike(strtolower($labelMatch)) . '%';
    $query = $this->database->select('civicrm_participant', 'p');
    $query->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    $query->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $query->leftJoin('civicrm_option_value', 'ov', 'ov.value = e.event_type_id');
    $query->fields('p', ['contact_id']);
    $query->addExpression('MIN(e.start_date)', 'first_event_date');
    $query->condition('pst.is_counted', 1);
    $query->condition('p.contact_id', 0, '>');
    $query->condition('e.start_date', [
      $start->format('Y-m-d H:i:s'),
      $end->format('Y-m-d H:i:s'),
    ], 'BETWEEN');
    $query->where('LOWER(COALESCE(ov.label, \'\')) LIKE :event_label', [
      ':event_label' => $pattern,
    ]);
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
   * Counts unique tour participants (events + activities) in a date range.
   */
  public function getTourParticipantCount(DateTimeImmutable $start, DateTimeImmutable $end): int {
    $eventMap = $this->getEventContactMap('tour', $start, $end);
    $activityMap = $this->getActivityContactMap('tour', $start, $end);

    $uids = array_unique(array_merge(array_keys($eventMap), array_keys($activityMap)));
    return count($uids);
  }

  /**
   * Helper retrieving the current timestamp.
   */
  protected function now(): DateTimeImmutable {
    return (new DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($this->timezone);
  }

}
