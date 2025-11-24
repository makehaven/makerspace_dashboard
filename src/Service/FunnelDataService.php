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

    $contactMap = $this->getEventContactMap('tour', $window['start'], $window['end']);
    $converted = $this->countContactConversions($contactMap);

    $data = [
      'range' => $window,
      'participants' => count($contactMap),
      'conversions' => $converted,
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
    $window = $this->buildWindow(self::WINDOW_MONTHS);
    $cacheId = sprintf('makerspace_dashboard:funnel:visits:%s', $window['cache_key']);
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $contactMap = $this->getActivityContactMap('visit', $window['start'], $window['end']);
    $converted = $this->countContactConversions($contactMap);

    $data = [
      'range' => $window,
      'visits' => count($contactMap),
      'conversions' => $converted,
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
   * Counts how many contacts in the supplied map eventually joined.
   *
   * @param array $contactDates
   *   Map of contact_id => DateTimeImmutable representing the first touch date.
   */
  protected function countContactConversions(array $contactDates): int {
    if (empty($contactDates)) {
      return 0;
    }

    $contactToUid = $this->loadContactUserMap(array_keys($contactDates));
    if (empty($contactToUid)) {
      return 0;
    }

    $joinDates = $this->loadJoinDates(array_values($contactToUid));
    if (empty($joinDates)) {
      return 0;
    }

    $converted = 0;
    foreach ($contactDates as $contactId => $touchDate) {
      $uid = $contactToUid[$contactId] ?? NULL;
      if (!$uid || !isset($joinDates[$uid])) {
        continue;
      }
      $joinDate = $joinDates[$uid];
      if ($touchDate > $joinDate) {
        continue;
      }
      $converted++;
    }
    return $converted;
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
   * Loads join dates indexed by user ID.
   */
  protected function loadJoinDates(array $uids): array {
    if (empty($uids)) {
      return [];
    }
    $query = $this->database->select('profile', 'p');
    $query->innerJoin('profile__field_member_join_date', 'join_date', 'join_date.entity_id = p.profile_id AND join_date.deleted = 0');
    $query->fields('p', ['uid']);
    $query->addField('join_date', 'field_member_join_date_value', 'join_value');
    $query->condition('p.uid', $uids, 'IN');
    $query->condition('p.type', 'main');
    $query->condition('p.is_default', 1);
    $query->condition('p.status', 1);

    $map = [];
    foreach ($query->execute() as $record) {
      $uid = (int) ($record->uid ?? 0);
      if ($uid <= 0) {
        continue;
      }
      $joinDate = $this->normalizeDate($record->join_value ?? NULL, 'Y-m-d');
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
   * Converts a raw date string into a DateTimeImmutable.
   */
  protected function normalizeDate(?string $value, string $format = 'Y-m-d H:i:s'): ?DateTimeImmutable {
    if (!$value) {
      return NULL;
    }
    $timestamp = strtotime($value);
    if ($timestamp === FALSE) {
      return NULL;
    }
    return (new DateTimeImmutable("@$timestamp"))->setTimezone($this->timezone);
  }

  /**
   * Helper retrieving the current timestamp.
   */
  protected function now(): DateTimeImmutable {
    return (new DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($this->timezone);
  }

}
