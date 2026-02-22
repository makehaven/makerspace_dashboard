<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Service to query CiviCRM data for event and membership insights.
 */
class EventsMembershipDataService {

  /**
   * Number of events-per-member buckets returned for pre-join charts.
   */
  private const PRE_JOIN_BUCKET_KEYS = [0, 1, 2, 3, 4];

  /**
   * Maximum number of event types to expose before grouping into "Other".
   */
  private const PRE_JOIN_EVENT_TYPE_LIMIT = 5;

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Cached ethnicity field metadata [table, column].
   */
  protected ?array $ethnicityFieldMetadata = NULL;

  /**
   * Cached gender option group id.
   */
  protected ?int $genderOptionGroupId = NULL;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache) {
    $this->database = $database;
    $this->cache = $cache;
  }

  /**
   * Fetches event-to-membership conversion data.
   *
   * @param \DateTimeImmutable $start_date
   *   The start date for the query range.
   * @param \DateTimeImmutable $end_date
   *   The end date for the query range.
   *
   * @return array
   *   An array of conversion data.
   */
  public function getEventToMembershipConversion(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array {
    $cid = 'makerspace_dashboard:event_to_membership_conversion:' . $start_date->getTimestamp() . ':' . $end_date->getTimestamp();
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('civicrm_participant', 'p');
    $query->join('civicrm_event', 'e', 'p.event_id = e.id');
    $query->join('civicrm_uf_match', 'ufm', 'p.contact_id = ufm.contact_id');
    $query->join('users_field_data', 'u', 'ufm.uf_id = u.uid');
    $query->join('profile__field_member_join_date', 'pmjd', 'u.uid = pmjd.entity_id');
    $query->fields('e', ['start_date']);
    $query->fields('pmjd', ['field_member_join_date_value']);
    $query->condition('e.start_date', [$start_date->format('Y-m-d H:i:s'), $end_date->format('Y-m-d H:i:s')], 'BETWEEN');
    $query->condition('p.status_id', 1); // Attended
    $results = $query->execute()->fetchAll();

    $event_attendees = count($results);
    $joins_30_days = 0;
    $joins_60_days = 0;
    $joins_90_days = 0;

    foreach ($results as $result) {
      $event_date = new DrupalDateTime($result->start_date);
      $joinValue = $result->field_member_join_date_value ?? NULL;
      if (!$joinValue) {
        continue;
      }
      $join_date = new DrupalDateTime($joinValue);
      $diff = $join_date->getTimestamp() - $event_date->getTimestamp();
      $days = round($diff / (60 * 60 * 24));
      if ($days < 0) {
        continue;
      }
      if ($days <= 30) {
        $joins_30_days++;
      }
      elseif ($days <= 60) {
        $joins_60_days++;
      }
      elseif ($days <= 90) {
        $joins_90_days++;
      }
    }

    $data = [
      'event_attendees' => $event_attendees,
      'joins_30_days' => $joins_30_days,
      'joins_60_days' => $joins_60_days,
      'joins_90_days' => $joins_90_days,
    ];

    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, ['civicrm_participant_list', 'user_list', 'profile_list']);

    return $data;
  }

  /**
   * Gets average time from event to membership.
   */
  public function getAverageTimeToJoin(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array {
    $cid = 'makerspace_dashboard:avg_time_to_join:' . $start_date->getTimestamp() . ':' . $end_date->getTimestamp();
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('civicrm_participant', 'p');
    $query->join('civicrm_event', 'e', 'p.event_id = e.id');
    $query->join('civicrm_uf_match', 'ufm', 'p.contact_id = ufm.contact_id');
    $query->join('users_field_data', 'u', 'ufm.uf_id = u.uid');
    $query->join('profile__field_member_join_date', 'pmjd', 'u.uid = pmjd.entity_id');
    $query->fields('ufm', ['contact_id']);
    $query->fields('e', ['start_date']);
    $query->fields('pmjd', ['field_member_join_date_value']);
    $query->condition('e.start_date', [$start_date->format('Y-m-d H:i:s'), $end_date->format('Y-m-d H:i:s')], 'BETWEEN');
    $query->condition('p.status_id', 1); // Attended
    $query->isNotNull('pmjd.field_member_join_date_value');

    $rows = $query->execute()->fetchAll();
    if (!$rows) {
      return [
        'labels' => [],
        'values' => [],
      ];
    }

    $firstTouches = [];
    foreach ($rows as $row) {
      $contactId = (int) ($row->contact_id ?? 0);
      if ($contactId <= 0) {
        continue;
      }
      $eventTs = strtotime($row->start_date);
      $joinTs = strtotime($row->field_member_join_date_value ?? '');
      if (!$eventTs || !$joinTs || $joinTs < $eventTs) {
        continue;
      }
      if (!isset($firstTouches[$contactId]) || $eventTs < $firstTouches[$contactId]['event_ts']) {
        $firstTouches[$contactId] = [
          'event_ts' => $eventTs,
          'join_ts' => $joinTs,
        ];
      }
    }

    if (!$firstTouches) {
      return [
        'labels' => [],
        'values' => [],
      ];
    }

    $buckets = [];
    foreach ($firstTouches as $record) {
      $eventMonth = date('Y-m', $record['event_ts']);
      $diffDays = round(($record['join_ts'] - $record['event_ts']) / 86400);
      if (!isset($buckets[$eventMonth])) {
        $buckets[$eventMonth] = ['total_days' => 0, 'count' => 0];
      }
      $buckets[$eventMonth]['total_days'] += $diffDays;
      $buckets[$eventMonth]['count']++;
    }

    ksort($buckets);
    $labels = [];
    $values = [];
    foreach ($buckets as $month => $info) {
      $labels[] = $month;
      $values[] = $info['count'] > 0 ? round($info['total_days'] / $info['count'], 2) : 0;
    }

    $data = [
      'labels' => $labels,
      'values' => $values,
    ];

    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, ['civicrm_participant_list', 'user_list', 'profile_list']);

    return $data;
  }

  /**
   * Returns monthly registration counts grouped by event type.
   */
  public function getMonthlyRegistrationsByType(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array {
    $cid = 'makerspace_dashboard:registrations_by_type:' . $start_date->getTimestamp() . ':' . $end_date->getTimestamp();
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $eventTypeGroupId = $this->getEventTypeGroupId();

    $query = $this->database->select('civicrm_participant', 'p');
    $query->fields('p', []);
    $query->addExpression("DATE_FORMAT(e.start_date, '%Y-%m-01')", 'month_key');
    $query->addExpression("COALESCE(ov.label, 'Unknown')", 'event_type');
    $query->addExpression('COUNT(DISTINCT p.id)', 'registrations');
    $query->innerJoin('civicrm_event', 'e', 'p.event_id = e.id');
    if ($eventTypeGroupId) {
      $query->leftJoin('civicrm_option_value', 'ov', 'ov.value = e.event_type_id AND ov.option_group_id = :event_type_group', [':event_type_group' => $eventTypeGroupId]);
    }
    else {
      $query->leftJoin('civicrm_option_value', 'ov', 'ov.value = e.event_type_id');
      $query->leftJoin('civicrm_option_group', 'og', 'og.id = ov.option_group_id');
      $query->condition('og.name', 'event_type');
    }
    $query->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $query->condition('pst.is_counted', 1);
    $query->condition('e.start_date', [$start_date->format('Y-m-d H:i:s'), $end_date->format('Y-m-d H:i:s')], 'BETWEEN');
    $query->groupBy('month_key');
    $query->groupBy('event_type');
    $query->orderBy('month_key', 'ASC');
    $query->orderBy('event_type', 'ASC');

    $results = $query->execute();

    $months = $this->buildMonthRange($start_date, $end_date);
    $types = [];
    foreach ($months as $monthKey => $label) {
      $types[$monthKey] = [];
    }

    $typeNames = [];
    foreach ($results as $record) {
      $monthKey = $record->month_key;
      $type = $record->event_type;
      $registrations = (int) $record->registrations;
      $typeNames[$type] = TRUE;
      if (!isset($types[$monthKey][$type])) {
        $types[$monthKey][$type] = 0;
      }
      $types[$monthKey][$type] += $registrations;
    }

    $typeList = array_keys($typeNames);
    sort($typeList);

    $dataset = [];
    foreach ($typeList as $eventType) {
      $dataset[$eventType] = [];
      foreach (array_keys($months) as $monthKey) {
        $dataset[$eventType][] = $types[$monthKey][$eventType] ?? 0;
      }
    }

    $data = [
      'months' => array_values($months),
      'types' => $dataset,
    ];

    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, ['civicrm_participant_list']);

    return $data;
  }

  /**
   * Returns monthly workshop attendance counts for the specified window.
   *
   * @param \DateTimeImmutable $start_date
   *   Start of the reporting window.
   * @param \DateTimeImmutable $end_date
   *   End of the reporting window.
   * @param string $eventTypeLabel
   *   Event type label to filter by. Defaults to "Ticketed Workshop".
   *
   * @return array
   *   Structured series data with keys:
   *   - items: Ordered list of month info arrays containing:
   *     - month_key: Canonical Y-m-01 string.
   *     - label: Human-readable month label.
   *     - date: \DateTimeImmutable instance.
   *     - count: Integer registration count.
   *   - labels: Month labels mapped from the items.
   *   - counts: Numeric counts aligned with the labels order.
   */
  public function getMonthlyWorkshopAttendanceSeries(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date, string $eventTypeLabel = 'Ticketed Workshop'): array {
    $months = $this->buildMonthRange($start_date, $end_date);
    if (empty($months)) {
      return [
        'items' => [],
        'labels' => [],
        'counts' => [],
      ];
    }

    $cacheId = sprintf(
      'makerspace_dashboard:workshop_attendance:%s:%s:%s',
      $start_date->format('Ymd'),
      $end_date->format('Ymd'),
      md5($eventTypeLabel)
    );
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $registrations = $this->getMonthlyRegistrationsByType($start_date, $end_date);
    $series = $registrations['types'][$eventTypeLabel] ?? [];

    $items = [];
    $labels = [];
    $counts = [];
    $index = 0;
    $now = new \DateTimeImmutable('first day of this month');
    foreach ($months as $monthKey => $label) {
      $monthDate = new \DateTimeImmutable($monthKey);
      if ($monthDate >= $now) {
        break;
      }
      $count = isset($series[$index]) ? (int) $series[$index] : 0;
      $items[] = [
        'month_key' => $monthKey,
        'label' => $label,
        'date' => $monthDate,
        'count' => $count,
      ];
      $labels[] = $label;
      $counts[] = $count;
      $index++;
    }

    $result = [
      'items' => $items,
      'labels' => $labels,
      'counts' => $counts,
    ];
    $this->cache->set($cacheId, $result, $this->buildTtl(), ['civicrm_participant_list']);

    return $result;
  }

  /**
   * Returns monthly counts of first-time workshop participants.
   *
   * @param \DateTimeImmutable $start_date
   *   Reporting window start date.
   * @param \DateTimeImmutable $end_date
   *   Reporting window end date.
   * @param string $eventTypeLabel
   *   Workshop label filter (default matches any workshop).
   *
   * @return array
   *   Array identical to getMonthlyWorkshopAttendanceSeries() output.
   */
  public function getMonthlyFirstTimeWorkshopParticipantsSeries(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date, string $eventTypeLabel = 'Workshop'): array {
    $months = $this->buildMonthRange($start_date, $end_date);
    if (empty($months)) {
      return [
        'items' => [],
        'labels' => [],
        'counts' => [],
      ];
    }

    $normalizedFilter = strtolower(trim($eventTypeLabel));
    if ($normalizedFilter === '') {
      $normalizedFilter = 'workshop';
    }

    $cacheId = sprintf(
      'makerspace_dashboard:first_time_workshop:%s:%s:%s',
      $start_date->format('Ymd'),
      $end_date->format('Ymd'),
      md5($normalizedFilter)
    );
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $eventTypeGroupId = $this->getEventTypeGroupId();

    $firstEventSubquery = $this->database->select('civicrm_participant', 'fp');
    $firstEventSubquery->innerJoin('civicrm_event', 'fe', 'fe.id = fp.event_id');
    $firstEventSubquery->innerJoin('civicrm_participant_status_type', 'fpst', 'fpst.id = fp.status_id');
    $firstEventSubquery->addField('fp', 'contact_id');
    $firstEventSubquery->addExpression('MIN(fe.start_date)', 'first_event_date');
    $firstEventSubquery->condition('fpst.is_counted', 1);
    $firstEventSubquery->groupBy('fp.contact_id');

    $query = $this->database->select($firstEventSubquery, 'first');
    $query->addExpression("DATE_FORMAT(first.first_event_date, '%Y-%m-01')", 'month_key');
    $query->addExpression('COUNT(DISTINCT first.contact_id)', 'first_time_count');
    $query->innerJoin('civicrm_participant', 'p', 'p.contact_id = first.contact_id');
    $query->innerJoin('civicrm_event', 'e', 'e.id = p.event_id AND e.start_date = first.first_event_date');
    $query->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $query->condition('pst.is_counted', 1);
    $query->condition('first.first_event_date', [
      $start_date->format('Y-m-d H:i:s'),
      $end_date->format('Y-m-d H:i:s'),
    ], 'BETWEEN');

    if ($eventTypeGroupId) {
      $query->innerJoin('civicrm_option_value', 'ov', 'ov.value = e.event_type_id AND ov.option_group_id = :event_type_group', [
        ':event_type_group' => $eventTypeGroupId,
      ]);
    }
    else {
      $query->innerJoin('civicrm_option_value', 'ov', 'ov.value = e.event_type_id');
      $query->innerJoin('civicrm_option_group', 'og', 'og.id = ov.option_group_id');
      $query->condition('og.name', 'event_type');
    }
    $query->addExpression("COALESCE(ov.label, 'Unknown')", 'event_type_label');
    $query->where('LOWER(ov.label) LIKE :event_type_label', [
      ':event_type_label' => '%' . $normalizedFilter . '%',
    ]);

    $query->groupBy('month_key');
    $query->groupBy('event_type_label');
    $query->orderBy('month_key', 'ASC');

    $results = $query->execute();

    $countsByMonth = array_fill_keys(array_keys($months), 0);
    foreach ($results as $record) {
      $monthKey = $record->month_key;
      if (!isset($countsByMonth[$monthKey])) {
        continue;
      }
      $countsByMonth[$monthKey] += (int) $record->first_time_count;
    }

    $items = [];
    $labels = [];
    $counts = [];
    $now = new \DateTimeImmutable('first day of this month');
    foreach ($months as $monthKey => $label) {
      $monthDate = new \DateTimeImmutable($monthKey);
      if ($monthDate >= $now) {
        break;
      }
      $count = (int) ($countsByMonth[$monthKey] ?? 0);
      $items[] = [
        'month_key' => $monthKey,
        'label' => $label,
        'date' => $monthDate,
        'count' => $count,
      ];
      $labels[] = $label;
      $counts[] = $count;
    }

    $result = [
      'items' => $items,
      'labels' => $labels,
      'counts' => $counts,
    ];
    $this->cache->set($cacheId, $result, $this->buildTtl(), ['civicrm_participant_list']);

    return $result;
  }

  /**
   * Builds aggregated demographics for active instructors in the range.
   *
   * @param \DateTimeImmutable $start
   *   Range start (inclusive).
   * @param \DateTimeImmutable $end
   *   Range end (inclusive).
   * @param array $eventTypeLabels
   *   Event type labels that qualify (case-insensitive).
   *
   * @return array
   *   Array with keys: instructors (detail rows), gender_counts, ethnicity_counts, range.
   */
  public function getActiveInstructorDemographics(\DateTimeImmutable $start, \DateTimeImmutable $end, array $eventTypeLabels = ['Ticketed Workshop', 'Program']): array {
    $startDate = $start->setTime(0, 0, 0);
    $endDate = $end->setTime(23, 59, 59);
    if ($endDate < $startDate) {
      [$startDate, $endDate] = [$endDate, $startDate];
    }

    $labels = array_values(array_unique(array_filter(array_map(static function ($label) {
      return strtolower(trim((string) $label));
    }, $eventTypeLabels))));
    sort($labels);
    $cacheId = sprintf(
      'makerspace_dashboard:instructors:demographics:%s:%s:%s',
      $startDate->format('Ymd'),
      $endDate->format('Ymd'),
      md5(implode('|', $labels))
    );
    if ($cache = $this->cache->get($cacheId)) {
      return $cache->data;
    }

    $metadata = $this->getEthnicityFieldMetadata();
    $ethnicityTable = $metadata['table'] ?? NULL;
    $ethnicityField = $metadata['column'] ?? NULL;
    $genderGroupId = $this->getGenderOptionGroupId();

    $query = $this->database->select('civicrm_event', 'e');
    $query->innerJoin('civicrm_event__field_civi_event_instructor', 'instr', 'instr.entity_id = e.id AND instr.deleted = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = instr.field_civi_event_instructor_target_id');
    $query->innerJoin('user__roles', 'role', "role.entity_id = u.uid AND role.roles_target_id = 'instructor'");
    $query->innerJoin('civicrm_uf_match', 'ufm', 'ufm.uf_id = u.uid');
    $query->innerJoin('civicrm_contact', 'c', 'c.id = ufm.contact_id');
    $query->leftJoin('civicrm_option_value', 'event_type', 'event_type.value = e.event_type_id');
    $query->leftJoin('civicrm_option_group', 'event_type_group', 'event_type_group.id = event_type.option_group_id');
    $query->condition('event_type_group.name', 'event_type');

    if ($labels) {
      $query->where('LOWER(COALESCE(event_type.label, \'\')) IN (:types[])', [':types[]' => $labels]);
    }

    $query->condition('instr.field_civi_event_instructor_target_id', 0, '>');
    $query->condition('u.status', 1);
    $query->condition('c.is_deleted', 0);
    $query->condition('e.start_date', [
      $startDate->format('Y-m-d H:i:s'),
      $endDate->format('Y-m-d H:i:s'),
    ], 'BETWEEN');

    if ($genderGroupId) {
      $query->leftJoin('civicrm_option_value', 'gender', 'gender.value = c.gender_id AND gender.option_group_id = :gender_group', [':gender_group' => $genderGroupId]);
      $query->addExpression('MAX(COALESCE(gender.label, \'\'))', 'gender_label');
    }
    else {
      $query->addExpression("''", 'gender_label');
    }

    if ($ethnicityTable && $ethnicityField) {
      $query->leftJoin($ethnicityTable, 'eth', 'eth.entity_id = c.id');
      $query->addExpression("MAX(COALESCE(eth.$ethnicityField, ''))", 'ethnicity_raw');
    }
    else {
      $query->addExpression("''", 'ethnicity_raw');
    }

    $query->addField('u', 'uid', 'uid');
    $query->addField('u', 'name', 'username');
    $query->addField('c', 'display_name', 'contact_name');
    $query->addExpression('COUNT(DISTINCT e.id)', 'event_count');
    $query->addExpression('MAX(e.start_date)', 'last_event_date');
    $query->groupBy('u.uid');
    $query->groupBy('u.name');
    $query->groupBy('c.display_name');

    $results = $query->execute();

    $instructors = [];
    $genderCounts = [];
    $ethnicityCounts = [];
    $ignoredEthnicity = $this->getIgnoredEthnicityValues();

    foreach ($results as $record) {
      $uid = (int) ($record->uid ?? 0);
      if ($uid <= 0) {
        continue;
      }
      $name = trim((string) ($record->contact_name ?? '')) ?: trim((string) ($record->username ?? ''));
      if ($name === '') {
        $name = sprintf('User #%d', $uid);
      }
      $gender = trim((string) ($record->gender_label ?? ''));
      if ($gender === '') {
        $gender = 'Unspecified';
      }
      $genderCounts[$gender] = ($genderCounts[$gender] ?? 0) + 1;

      $rawEthnicity = $record->ethnicity_raw ?? '';
      $ethnicities = $this->normalizeEthnicityValues($rawEthnicity, $ignoredEthnicity);
      if (!$ethnicities) {
        $ethnicities = ['Unspecified'];
      }
      foreach (array_unique($ethnicities) as $value) {
        $ethnicityCounts[$value] = ($ethnicityCounts[$value] ?? 0) + 1;
      }

      $lastEvent = NULL;
      if (!empty($record->last_event_date)) {
        try {
          $lastEvent = (new \DateTimeImmutable($record->last_event_date))->format('Y-m-d');
        }
        catch (\Exception $exception) {
          $lastEvent = NULL;
        }
      }

      $instructors[] = [
        'uid' => $uid,
        'name' => $name,
        'gender' => $gender,
        'ethnicity' => $ethnicities,
        'event_count' => (int) ($record->event_count ?? 0),
        'last_event' => $lastEvent,
      ];
    }

    usort($instructors, static function (array $a, array $b): int {
      $eventComparison = ($b['event_count'] ?? 0) <=> ($a['event_count'] ?? 0);
      if ($eventComparison !== 0) {
        return $eventComparison;
      }
      return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    });

    ksort($genderCounts);
    ksort($ethnicityCounts);

    $data = [
      'range' => [
        'start' => $startDate,
        'end' => $endDate,
      ],
      'instructors' => $instructors,
      'total' => count($instructors),
      'gender_counts' => $genderCounts,
      'ethnicity_counts' => $ethnicityCounts,
    ];

    $this->cache->set($cacheId, $data, $this->buildTtl(), [
      'civicrm_event_list',
      'user_list',
      'civicrm_contact_list',
    ]);

    return $data;
  }

  /**
   * Returns average paid amount per registration grouped by event type and month.
   */
  public function getAverageRevenuePerRegistration(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array {
    $cid = 'makerspace_dashboard:avg_revenue_by_type:' . $start_date->getTimestamp() . ':' . $end_date->getTimestamp();
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $eventTypeGroupId = $this->getEventTypeGroupId();

    $query = $this->database->select('civicrm_participant', 'p');
    $query->addExpression("DATE_FORMAT(e.start_date, '%Y-%m-01')", 'month_key');
    $query->addExpression("COALESCE(ov.label, 'Unknown')", 'event_type');
    $query->addExpression('SUM(c.total_amount)', 'total_amount');
    $query->addExpression('COUNT(DISTINCT p.id)', 'registration_count');
    $query->innerJoin('civicrm_event', 'e', 'p.event_id = e.id');
    if ($eventTypeGroupId) {
      $query->leftJoin('civicrm_option_value', 'ov', 'ov.value = e.event_type_id AND ov.option_group_id = :event_type_group', [':event_type_group' => $eventTypeGroupId]);
    }
    else {
      $query->leftJoin('civicrm_option_value', 'ov', 'ov.value = e.event_type_id');
      $query->leftJoin('civicrm_option_group', 'og', 'og.id = ov.option_group_id');
      $query->condition('og.name', 'event_type');
    }
    $query->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $query->condition('pst.is_counted', 1);
    $query->leftJoin('civicrm_participant_payment', 'pp', 'pp.participant_id = p.id');
    $query->leftJoin('civicrm_contribution', 'c', 'c.id = pp.contribution_id');
    $query->condition('e.start_date', [$start_date->format('Y-m-d H:i:s'), $end_date->format('Y-m-d H:i:s')], 'BETWEEN');
    $query->groupBy('month_key');
    $query->groupBy('event_type');
    $query->orderBy('month_key', 'ASC');
    $query->orderBy('event_type', 'ASC');

    $results = $query->execute();

    $months = $this->buildMonthRange($start_date, $end_date);
    $typeNames = [];
    $averages = [];
    foreach ($results as $record) {
      $monthKey = $record->month_key;
      $type = $record->event_type;
      $total = (float) $record->total_amount;
      $count = max(1, (int) $record->registration_count);
      $typeNames[$type] = TRUE;
      if (!isset($averages[$type])) {
        $averages[$type] = [];
      }
      $averages[$type][$monthKey] = $total > 0 ? round($total / $count, 2) : 0;
    }

    $typeList = array_keys($typeNames);
    sort($typeList);

    $dataset = [];
    foreach ($typeList as $eventType) {
      $series = [];
      foreach (array_keys($months) as $monthKey) {
        $series[] = $averages[$eventType][$monthKey] ?? 0;
      }
      $dataset[$eventType] = $series;
    }

    $data = [
      'months' => array_values($months),
      'types' => $dataset,
    ];

    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, ['civicrm_participant_list']);

    return $data;
  }

  /**
   * Helper to produce a placeholder chart dataset for future capacity metrics.
   */
  public function getSampleCapacitySeries(): array {
    return [
      'months' => ['Jan', 'Feb', 'Mar', 'Apr'],
      'data' => [65, 72, 68, 75],
      'note' => 'Sample data – replace with actual workshop capacity utilization.',
    ];
  }

  /**
   * Builds top-level area of interest stats for events in the range.
   */
  public function getEventInterestBreakdown(?\DateTimeImmutable $start_date, \DateTimeImmutable $end_date, int $limit = 10): array {
    $cacheKeyStart = $start_date ? $start_date->getTimestamp() : 'all';
    $cid = sprintf('makerspace_dashboard:event_interest:%s:%d:%d', $cacheKeyStart, $end_date->getTimestamp(), $limit);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $schema = $this->database->schema();
    if (!$schema->tableExists('civicrm_event__field_civi_event_area_interest')) {
      return [];
    }

    $termHierarchy = $this->loadInterestTermHierarchy();
    if (!$termHierarchy) {
      return [];
    }

    $eventInterestQuery = $this->database->select('civicrm_event__field_civi_event_area_interest', 'interest');
    $eventInterestQuery->addField('interest', 'entity_id', 'event_id');
    $eventInterestQuery->addField('interest', 'field_civi_event_area_interest_target_id', 'term_id');
    $eventInterestQuery->innerJoin('civicrm_event', 'e', 'e.id = interest.entity_id');
    $eventInterestQuery->fields('e', ['event_type_id']);
    if ($start_date) {
      $eventInterestQuery->condition('e.start_date', [
        $start_date->format('Y-m-d H:i:s'),
        $end_date->format('Y-m-d H:i:s'),
      ], 'BETWEEN');
    }
    else {
      $eventInterestQuery->condition('e.start_date', $end_date->format('Y-m-d H:i:s'), '<=');
    }

    $eventInterestMap = [];
    foreach ($eventInterestQuery->execute() as $record) {
      $eventId = (int) $record->event_id;
      $termId = (int) $record->term_id;
      if (!isset($termHierarchy[$termId])) {
        continue;
      }
      $topTermId = $this->resolveTopInterestId($termHierarchy, $termId);
      if ($topTermId === NULL) {
        continue;
      }
      $eventInterestMap[$eventId][$topTermId] = TRUE;
    }

    if (!$eventInterestMap) {
      $this->cache->set($cid, [], $this->buildTtl(), ['civicrm_event_list']);
      return [];
    }

    $eventMetrics = $this->loadEventRegistrationMetrics(array_keys($eventInterestMap), $start_date, $end_date);

    $interestStats = [];
    foreach ($eventInterestMap as $eventId => $topTermSet) {
      $metrics = $eventMetrics[$eventId] ?? [
        'registrations' => 0,
        'total_amount' => 0.0,
        'paid_count' => 0,
      ];
      foreach (array_keys($topTermSet) as $topTid) {
        $interestStats[$topTid]['events'][$eventId] = TRUE;
        $interestStats[$topTid]['registrations'] = ($interestStats[$topTid]['registrations'] ?? 0) + $metrics['registrations'];
        $interestStats[$topTid]['total_amount'] = ($interestStats[$topTid]['total_amount'] ?? 0.0) + $metrics['total_amount'];
        $interestStats[$topTid]['paid_count'] = ($interestStats[$topTid]['paid_count'] ?? 0) + $metrics['paid_count'];
      }
    }

    $items = [];
    foreach ($interestStats as $tid => $data) {
      $events = isset($data['events']) ? count($data['events']) : 0;
      $registrations = (int) ($data['registrations'] ?? 0);
      $totalAmount = (float) ($data['total_amount'] ?? 0.0);
      $avgTicket = $registrations > 0 ? round($totalAmount / $registrations, 2) : 0.0;
      $items[] = [
        'tid' => $tid,
        'interest' => $termHierarchy[$tid]['name'] ?? (string) $tid,
        'events' => $events,
        'registrations' => $registrations,
        'avg_ticket' => $avgTicket,
        'total_amount' => round($totalAmount, 2),
      ];
    }

    usort($items, function (array $a, array $b) {
      return $b['events'] <=> $a['events'] ?: $b['registrations'] <=> $a['registrations'];
    });

    if ($limit > 0 && count($items) > $limit) {
      $items = array_slice($items, 0, $limit);
    }

    $result = [
      'items' => $items,
      'total_events' => array_sum(array_map(fn(array $row) => $row['events'], $items)),
      'total_registrations' => array_sum(array_map(fn(array $row) => $row['registrations'], $items)),
    ];

    $this->cache->set($cid, $result, $this->buildTtl(), ['civicrm_event_list', 'civicrm_participant_list']);

    return $result;
  }

  /**
   * Returns event counts per skill level, split by workshop vs other types.
   */
  public function getSkillLevelBreakdown(?\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array {
    $cacheKeyStart = $start_date ? $start_date->getTimestamp() : 'all';
    $cid = sprintf('makerspace_dashboard:event_skill_levels:%s:%d', $cacheKeyStart, $end_date->getTimestamp());
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $schema = $this->database->schema();
    if (!$schema->tableExists('civicrm_event__field_event_skill_level')) {
      return [];
    }

    $eventTypeLabels = $this->getOptionValueLabels('event_type');
    $skillLevelLabels = $this->getSkillLevelLabels();

    $query = $this->database->select('civicrm_event__field_event_skill_level', 'skill');
    $query->addField('skill', 'entity_id', 'event_id');
    $query->addField('skill', 'field_event_skill_level_value', 'skill_value');
    $query->innerJoin('civicrm_event', 'e', 'e.id = skill.entity_id');
    $query->addField('e', 'event_type_id');
    if ($start_date) {
      $query->condition('e.start_date', [
        $start_date->format('Y-m-d H:i:s'),
        $end_date->format('Y-m-d H:i:s'),
      ], 'BETWEEN');
    }
    else {
      $query->condition('e.start_date', $end_date->format('Y-m-d H:i:s'), '<=');
    }

    $counts = [
      'workshop' => [],
      'other' => [],
    ];

    foreach ($skillLevelLabels as $key => $label) {
      $counts['workshop'][$key] = 0;
      $counts['other'][$key] = 0;
    }

    foreach ($query->execute() as $record) {
      $skillKey = $record->skill_value ?: 'unknown';
      if (!isset($skillLevelLabels[$skillKey])) {
        $skillLevelLabels[$skillKey] = ucfirst(str_replace('_', ' ', $skillKey));
        $counts['workshop'][$skillKey] = 0;
        $counts['other'][$skillKey] = 0;
      }
      $eventTypeId = (int) $record->event_type_id;
      $eventTypeLabel = $eventTypeLabels[$eventTypeId] ?? '';
      $bucket = (stripos($eventTypeLabel, 'workshop') !== FALSE) ? 'workshop' : 'other';
      $counts[$bucket][$skillKey]++;
    }

    $result = [
      'levels' => [],
      'workshop' => [],
      'other' => [],
    ];

    foreach ($skillLevelLabels as $key => $label) {
      $result['levels'][] = $label;
      $result['workshop'][] = $counts['workshop'][$key] ?? 0;
      $result['other'][] = $counts['other'][$key] ?? 0;
    }

    $this->cache->set($cid, $result, $this->buildTtl(), ['civicrm_event_list']);

    return $result;
  }

  /**
   * Returns participant demographics grouped by workshop/non-workshop.
   */
  public function getParticipantDemographics(?\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array {
    $cacheKeyStart = $start_date ? $start_date->getTimestamp() : 'all';
    $cid = sprintf('makerspace_dashboard:event_demographics:%s:%d', $cacheKeyStart, $end_date->getTimestamp());
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $schema = $this->database->schema();
    if (!$schema->tableExists('civicrm_participant')) {
      return [];
    }

    $eventTypeLabels = $this->getOptionValueLabels('event_type');
    $genderLabels = $this->getOptionValueLabels('gender');
    $ethnicityLabels = $this->getOptionValueLabels('ethnicity');

    $demoTable = $schema->tableExists('civicrm_value_demographics_15') ? 'civicrm_value_demographics_15' : NULL;

    $query = $this->database->select('civicrm_participant', 'p');
    $query->addField('p', 'event_id');
    $query->addField('p', 'id', 'participant_id');
    $query->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    $query->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $query->condition('pst.is_counted', 1);
    if ($start_date) {
      $query->condition('e.start_date', [
        $start_date->format('Y-m-d H:i:s'),
        $end_date->format('Y-m-d H:i:s'),
      ], 'BETWEEN');
    }
    else {
      $query->condition('e.start_date', $end_date->format('Y-m-d H:i:s'), '<=');
    }
    $query->innerJoin('civicrm_contact', 'c', 'c.id = p.contact_id');
    $query->addField('c', 'gender_id');
    $query->addField('c', 'birth_date');
    $query->addField('e', 'event_type_id');

    if ($demoTable) {
      $query->leftJoin($demoTable, 'demo', 'demo.entity_id = c.id');
      if ($schema->fieldExists($demoTable, 'ethnicity_46')) {
        $query->addField('demo', 'ethnicity_46');
      }
      else {
        $query->addExpression('NULL', 'ethnicity_46');
      }
    }
    else {
      $query->addExpression('NULL', 'ethnicity_46');
    }

    $data = [
      'gender' => [
        'labels' => [],
        'workshop' => [],
        'other' => [],
      ],
      'ethnicity' => [
        'labels' => [],
        'workshop' => [],
        'other' => [],
      ],
      'age' => [
        'labels' => [],
        'workshop' => [],
        'other' => [],
      ],
    ];

    $genderBuckets = [];
    $ethnicityBuckets = [];
    $ageBuckets = $this->getAgeBucketDefinitions();
    $ageCounts = [
      'workshop' => array_fill(0, count($ageBuckets), 0),
      'other' => array_fill(0, count($ageBuckets), 0),
    ];

    foreach ($query->execute() as $record) {
      $eventTypeId = (int) $record->event_type_id;
      $eventTypeLabel = $eventTypeLabels[$eventTypeId] ?? '';
      $bucket = (stripos($eventTypeLabel, 'workshop') !== FALSE) ? 'workshop' : 'other';

      $genderKey = (int) $record->gender_id;
      $genderLabel = $genderLabels[$genderKey] ?? ($genderKey ? (string) $genderKey : 'Unspecified');
      $genderBuckets[$genderLabel][$bucket] = ($genderBuckets[$genderLabel][$bucket] ?? 0) + 1;

      $birthDate = $record->birth_date;
      $ageIndex = $this->resolveAgeBucketIndex($ageBuckets, $birthDate, $start_date);
      if ($ageIndex !== NULL) {
        $ageCounts[$bucket][$ageIndex]++;
      }

      $ethnicityRaw = isset($record->ethnicity_46) ? (string) $record->ethnicity_46 : '';
      foreach ($this->explodeMultiValue($ethnicityRaw) as $ethnicityValue) {
        if ($ethnicityValue === '') {
          $label = 'Unspecified';
        }
        elseif (ctype_digit($ethnicityValue) && isset($ethnicityLabels[(int) $ethnicityValue])) {
          $label = $ethnicityLabels[(int) $ethnicityValue];
        }
        else {
          $label = $ethnicityValue;
        }
        $ethnicityBuckets[$label][$bucket] = ($ethnicityBuckets[$label][$bucket] ?? 0) + 1;
      }
    }

    ksort($genderBuckets);
    foreach ($genderBuckets as $label => $bucketCounts) {
      $data['gender']['labels'][] = $label;
      $data['gender']['workshop'][] = $bucketCounts['workshop'] ?? 0;
      $data['gender']['other'][] = $bucketCounts['other'] ?? 0;
    }

    arsort($ethnicityBuckets);
    $ethnicityTop = array_slice($ethnicityBuckets, 0, 10, TRUE);
    $otherWorkshop = 0;
    $otherOther = 0;
    foreach ($ethnicityBuckets as $label => $bucketCounts) {
      if (!isset($ethnicityTop[$label])) {
        $otherWorkshop += $bucketCounts['workshop'] ?? 0;
        $otherOther += $bucketCounts['other'] ?? 0;
      }
    }
    foreach ($ethnicityTop as $label => $bucketCounts) {
      $data['ethnicity']['labels'][] = $label;
      $data['ethnicity']['workshop'][] = $bucketCounts['workshop'] ?? 0;
      $data['ethnicity']['other'][] = $bucketCounts['other'] ?? 0;
    }
    if ($otherWorkshop > 0 || $otherOther > 0) {
      $data['ethnicity']['labels'][] = 'Other';
      $data['ethnicity']['workshop'][] = $otherWorkshop;
      $data['ethnicity']['other'][] = $otherOther;
    }

    foreach ($ageBuckets as $bucket) {
      $data['age']['labels'][] = $bucket['label'];
    }
    $data['age']['workshop'] = $ageCounts['workshop'];
    $data['age']['other'] = $ageCounts['other'];

    $this->cache->set($cid, $data, $this->buildTtl(), ['civicrm_participant_list', 'civicrm_contact_list']);

    return $data;
  }

  /**
   * Builds the distribution of events attended before members joined.
   */
  public function getPreJoinEventAttendance(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array {
    $cid = sprintf('makerspace_dashboard:pre_join_events:%d:%d', $start_date->getTimestamp(), $end_date->getTimestamp());
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $schema = $this->database->schema();
    $requiredTables = [
      'profile__field_member_join_date',
      'users_field_data',
      'civicrm_uf_match',
      'civicrm_participant',
      'civicrm_event',
      'civicrm_participant_status_type',
      'civicrm_contact',
    ];
    foreach ($requiredTables as $table) {
      if (!$schema->tableExists($table)) {
        return [];
      }
    }

    $demoTable = $schema->tableExists('civicrm_value_demographics_15') ? 'civicrm_value_demographics_15' : NULL;
    $eventTypeLabels = $this->getOptionValueLabels('event_type');
    $genderLabels = $this->getOptionValueLabels('gender');
    $ethnicityLabels = $this->getOptionValueLabels('ethnicity');

    $startTimestamp = $start_date->setTime(0, 0, 0)->getTimestamp();
    $endTimestamp = $end_date->setTime(23, 59, 59)->getTimestamp();

    $memberQuery = $this->database->select('profile', 'p');
    $memberQuery->fields('p', ['profile_id', 'uid', 'created']);
    $memberQuery->condition('p.type', 'main');
    $memberQuery->condition('p.status', 1);
    $memberQuery->condition('p.is_default', 1);
    $memberQuery->condition('p.created', [$startTimestamp, $endTimestamp], 'BETWEEN');
    $memberQuery->innerJoin('profile__field_member_join_date', 'pmjd', 'pmjd.entity_id = p.profile_id AND pmjd.deleted = 0');
    $memberQuery->addField('pmjd', 'field_member_join_date_value', 'join_value');
    $memberQuery->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $memberQuery->addField('u', 'uid', 'user_id');
    $memberQuery->leftJoin('civicrm_uf_match', 'ufm', 'ufm.uf_id = u.uid');
    $memberQuery->addField('ufm', 'contact_id');
    $memberQuery->leftJoin('civicrm_contact', 'c', 'c.id = ufm.contact_id');
    $memberQuery->addField('c', 'gender_id');
    if ($demoTable) {
      $memberQuery->leftJoin($demoTable, 'demo', 'demo.entity_id = c.id');
      $memberQuery->addField('demo', 'ethnicity_46');
    }
    else {
      $memberQuery->addExpression('NULL', 'ethnicity_46');
    }

    $members = [];
    $contactMap = [];
    foreach ($memberQuery->execute() as $record) {
      $joinTimestamp = $this->buildJoinTimestamp($record->join_value);
      if ($joinTimestamp === NULL && !empty($record->created)) {
        $joinTimestamp = ((int) $record->created) > 0 ? ((int) $record->created) + 86399 : NULL;
      }
      if ($joinTimestamp === NULL) {
        continue;
      }
      $contactId = $record->contact_id ? (int) $record->contact_id : NULL;
      $key = $contactId ? 'c:' . $contactId : 'u:' . (int) $record->user_id;
      if (isset($members[$key])) {
        continue;
      }
      $members[$key] = [
        'contact_id' => $contactId,
        'join_timestamp' => $joinTimestamp,
        'total_events' => 0,
        'type_counts' => [],
        'gender_id' => $record->gender_id !== NULL ? (int) $record->gender_id : NULL,
        'ethnicity_raw' => $record->ethnicity_46 ?? '',
        'profile_created' => (int) $record->created,
        'had_tour' => FALSE,
      ];
      if ($contactId) {
        $contactMap[$contactId] = $key;
      }
    }

    if (empty($members)) {
      return [];
    }

    if (!empty($contactMap)) {
      $participantQuery = $this->database->select('civicrm_participant', 'p');
      $participantQuery->fields('p', ['contact_id']);
      $participantQuery->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
      $participantQuery->condition('pst.is_counted', 1);
      $participantQuery->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
      $participantQuery->addField('e', 'event_type_id');
      $participantQuery->addField('e', 'start_date', 'event_date');
      $participantQuery->condition('p.contact_id', array_keys($contactMap), 'IN');
      $participantQuery->orderBy('e.start_date', 'ASC');

      foreach ($participantQuery->execute() as $row) {
        $contactId = (int) $row->contact_id;
        if (empty($contactMap[$contactId])) {
          continue;
        }
        $memberKey = $contactMap[$contactId];
        $member = &$members[$memberKey];
        $joinTimestamp = $member['join_timestamp'];
        $eventTimestamp = $row->event_date ? strtotime($row->event_date) : NULL;
        if (!$eventTimestamp || !$joinTimestamp || $eventTimestamp > $joinTimestamp) {
          continue;
        }
        $member['total_events']++;
        $typeLabel = $this->resolveEventTypeLabel((int) $row->event_type_id, $eventTypeLabels);
        $member['type_counts'][$typeLabel] = ($member['type_counts'][$typeLabel] ?? 0) + 1;
        if ($this->isTourEventLabel($typeLabel)) {
          $member['had_tour'] = TRUE;
        }
      }
    }

    $memberCount = count($members);
    $overallCounts = $this->bucketizePreJoinCounts(array_map(static function (array $member): int {
      return (int) ($member['total_events'] ?? 0);
    }, $members));

    $tourSummary = ['with_tour' => 0, 'without_tour' => 0];
    foreach ($members as $member) {
      if (!empty($member['had_tour'])) {
        $tourSummary['with_tour']++;
      }
      else {
        $tourSummary['without_tour']++;
      }
    }

    $typeTotals = [];
    foreach ($members as $member) {
      foreach ($member['type_counts'] as $label => $count) {
        if ($this->isTourEventLabel($label)) {
          continue;
        }
        $typeTotals[$label] = ($typeTotals[$label] ?? 0) + (int) $count;
      }
    }
    arsort($typeTotals);
    $topTypeLabels = array_slice(array_keys($typeTotals), 0, self::PRE_JOIN_EVENT_TYPE_LIMIT);
    $otherTypeLabels = array_diff(array_keys($typeTotals), $topTypeLabels);

    $eventTypeSeries = [];
    $eventTypeSeries[] = [
      'id' => 'all',
      'label' => 'all',
      'counts' => $overallCounts,
      'members' => $memberCount,
    ];
    foreach ($topTypeLabels as $label) {
      $values = [];
      foreach ($members as $member) {
        $values[] = (int) ($member['type_counts'][$label] ?? 0);
      }
      $eventTypeSeries[] = [
        'id' => 'type',
        'label' => $label,
        'counts' => $this->bucketizePreJoinCounts($values),
        'members' => $memberCount,
      ];
    }
    if (!empty($otherTypeLabels)) {
      $values = [];
      $otherLookup = array_flip($otherTypeLabels);
      foreach ($members as $member) {
        $count = 0;
      foreach ($member['type_counts'] as $label => $value) {
        if (isset($otherLookup[$label])) {
          $count += (int) $value;
        }
      }
      $values[] = $count;
      }
      if (array_sum($values) > 0) {
        $eventTypeSeries[] = [
          'id' => 'other',
          'label' => 'other',
          'counts' => $this->bucketizePreJoinCounts($values),
          'members' => $memberCount,
        ];
      }
    }

    $genderGroups = [];
    $raceGroups = [];
    foreach ($members as $member) {
      $bucketIndex = $this->resolvePreJoinBucketIndex((int) $member['total_events']);

      $genderLabel = $this->resolveGenderLabel($member['gender_id'] ?? NULL, $genderLabels);
      if (!isset($genderGroups[$genderLabel])) {
        $genderGroups[$genderLabel] = $this->initializePreJoinBuckets();
      }
      $genderGroups[$genderLabel][$bucketIndex]++;

      $raceLabel = $this->resolveRaceGroupLabel($member['ethnicity_raw'] ?? '', $ethnicityLabels);
      if (!isset($raceGroups[$raceLabel])) {
        $raceGroups[$raceLabel] = $this->initializePreJoinBuckets();
      }
      $raceGroups[$raceLabel][$bucketIndex]++;
    }

    $result = [
      'bucket_keys' => self::PRE_JOIN_BUCKET_KEYS,
      'member_total' => $memberCount,
      'event_types' => $eventTypeSeries,
      'tour_summary' => $tourSummary,
      'demographics' => [
        'gender' => array_map(function (array $row): array {
          $row['dimension'] = 'gender';
          return $row;
        }, $this->normalizePreJoinGroups($genderGroups)),
        'race' => array_map(function (array $row): array {
          $row['dimension'] = 'race';
          return $row;
        }, $this->normalizePreJoinGroups($raceGroups)),
      ],
      'window' => [
        'start' => $start_date->format('Y-m-d'),
        'end' => $end_date->format('Y-m-d'),
      ],
    ];

    $this->cache->set($cid, $result, $this->buildTtl(), [
      'profile_list',
      'user_list',
      'civicrm_participant_list',
    ]);

    return $result;
  }

  /**
   * Builds an array of Y-m keyed months within range.
   */
  protected function buildMonthRange(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $startMonth = $start->modify('first day of this month');
    $endMonth = $end->modify('first day of next month');
    $period = new \DatePeriod($startMonth, new \DateInterval('P1M'), $endMonth);
    $months = [];
    foreach ($period as $month) {
      $months[$month->format('Y-m-01')] = $month->format('M Y');
    }
    return $months;
  }

  /**
   * Converts a Y-m-d join value into a timestamp (end of day).
   */
  protected function buildJoinTimestamp(?string $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $timestamp = strtotime($value . ' 23:59:59');
    return $timestamp ?: NULL;
  }

  /**
   * Initializes a zeroed bucket array for the pre-join charts.
   */
  protected function initializePreJoinBuckets(): array {
    return array_fill(0, count(self::PRE_JOIN_BUCKET_KEYS), 0);
  }

  /**
   * Resolves which bucket index a count should increment.
   */
  protected function resolvePreJoinBucketIndex(int $count): int {
    if ($count <= 0) {
      return 0;
    }
    if ($count === 1) {
      return 1;
    }
    if ($count === 2) {
      return 2;
    }
    if ($count === 3) {
      return 3;
    }
    return 4;
  }

  /**
   * Converts an array of event counts into per-bucket totals.
   */
  protected function bucketizePreJoinCounts(array $values): array {
    $buckets = $this->initializePreJoinBuckets();
    foreach ($values as $value) {
      $index = $this->resolvePreJoinBucketIndex((int) $value);
      $buckets[$index]++;
    }
    return $buckets;
  }

  /**
   * Detects whether an event type label represents a tour.
   */
  protected function isTourEventLabel(string $label): bool {
    return stripos($label, 'tour') !== FALSE;
  }

  /**
   * Normalizes grouped bucket counts into a sorted list.
   */
  protected function normalizePreJoinGroups(array $groups): array {
    if (!$groups) {
      return [];
    }
    $normalized = [];
    foreach ($groups as $label => $counts) {
      $normalized[] = [
        'label' => $label,
        'counts' => array_values($counts),
        'members' => array_sum($counts),
      ];
    }
    usort($normalized, static function (array $a, array $b): int {
      return ($b['members'] ?? 0) <=> ($a['members'] ?? 0);
    });
    return $normalized;
  }

  /**
   * Resolves an event type label or fallback for missing IDs.
   */
  protected function resolveEventTypeLabel(int $typeId, array $eventTypeLabels): string {
    if ($typeId && isset($eventTypeLabels[$typeId])) {
      return $eventTypeLabels[$typeId];
    }
    return 'Unspecified';
  }

  /**
   * Resolves a gender label or default placeholder.
   */
  protected function resolveGenderLabel(?int $genderId, array $genderLabels): string {
    if ($genderId !== NULL && isset($genderLabels[$genderId])) {
      return $genderLabels[$genderId];
    }
    return 'Unspecified';
  }

  /**
   * Resolves a simplified race grouping (White, BIPOC/Multiracial, Unspecified).
   */
  protected function resolveRaceGroupLabel(?string $rawValues, array $ethnicityLabels): string {
    if ($rawValues === NULL || $rawValues === '') {
      return 'Unspecified';
    }
    $values = $this->explodeMultiValue($rawValues);
    $hasWhite = FALSE;
    $hasNonWhite = FALSE;
    foreach ($values as $value) {
      if ($value === '') {
        continue;
      }
      $label = '';
      if (ctype_digit($value) && isset($ethnicityLabels[(int) $value])) {
        $label = strtolower($ethnicityLabels[(int) $value]);
      }
      else {
        $label = strtolower((string) $value);
      }
      if ($label === '') {
        continue;
      }
      if (str_contains($label, 'white')) {
        $hasWhite = TRUE;
      }
      else {
        $hasNonWhite = TRUE;
      }
      if ($hasWhite && $hasNonWhite) {
        break;
      }
    }
    if ($hasNonWhite) {
      return 'BIPOC / Multiracial';
    }
    if ($hasWhite) {
      return 'White';
    }
    return 'Unspecified';
  }

  /**
   * Resolves the option group ID for event types.
   */
  protected function getEventTypeGroupId(): ?int {
    static $cache;
    if ($cache !== NULL) {
      return $cache;
    }
    $query = $this->database->select('civicrm_option_group', 'og');
    $query->fields('og', ['id']);
    $query->condition('og.name', 'event_type');
    $groupId = $query->execute()->fetchField();
    $cache = $groupId ? (int) $groupId : NULL;
    return $cache;
  }

  /**
   * Returns the TTL used for the cached aggregates in this service.
   */
  protected function buildTtl(): int {
    return CacheBackendInterface::CACHE_PERMANENT;
  }

  /**
   * Loads option value labels keyed by value for the specified group.
   */
  protected function getOptionValueLabels(string $groupName): array {
    static $cache = [];
    if (isset($cache[$groupName])) {
      return $cache[$groupName];
    }

    $query = $this->database->select('civicrm_option_group', 'og');
    $query->fields('ov', ['value', 'label']);
    $query->innerJoin('civicrm_option_value', 'ov', 'ov.option_group_id = og.id');
    $query->condition('og.name', $groupName);
    $query->condition('ov.is_active', 1);
    $query->orderBy('ov.weight', 'ASC');

    $labels = [];
    foreach ($query->execute() as $record) {
      $labels[(int) $record->value] = $record->label;
    }

    $cache[$groupName] = $labels;
    return $labels;
  }

  /**
   * Loads taxonomy term hierarchy for the event area of interest vocabulary.
   */
  protected function loadInterestTermHierarchy(): array {
    $query = $this->database->select('taxonomy_term_field_data', 't');
    $query->fields('t', ['tid', 'name']);
    $query->leftJoin('taxonomy_term__parent', 'tp', 'tp.entity_id = t.tid');
    $query->addExpression('COALESCE(MAX(tp.parent_target_id), 0)', 'parent_id');
    $query->condition('t.vid', 'area_of_interest');
    $query->groupBy('t.tid');
    $query->groupBy('t.name');

    $map = [];
    foreach ($query->execute() as $record) {
      $map[(int) $record->tid] = [
        'name' => $record->name,
        'parent' => (int) $record->parent_id,
      ];
    }
    return $map;
  }

  /**
   * Resolves the top-most parent in the interest hierarchy.
   */
  protected function resolveTopInterestId(array $hierarchy, int $tid): ?int {
    $visited = [];
    $current = $tid;
    while ($current && isset($hierarchy[$current])) {
      if (isset($visited[$current])) {
        return $current;
      }
      $visited[$current] = TRUE;
      $parent = $hierarchy[$current]['parent'] ?? 0;
      if (!$parent) {
        return $current;
      }
      if (!isset($hierarchy[$parent])) {
        return $current;
      }
      $current = $parent;
    }
    return isset($hierarchy[$tid]) ? $tid : NULL;
  }

  /**
   * Loads registration count and revenue totals per event.
   */
  protected function loadEventRegistrationMetrics(array $eventIds, ?\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array {
    if (empty($eventIds)) {
      return [];
    }

    $query = $this->database->select('civicrm_participant', 'p');
    $query->addField('p', 'event_id');
    $query->addExpression('COUNT(DISTINCT p.id)', 'registration_count');
    $query->addExpression('SUM(COALESCE(c.total_amount, 0))', 'total_amount');
    $query->addExpression('COUNT(DISTINCT c.id)', 'paid_count');
    $query->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $query->condition('pst.is_counted', 1);
    $query->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    if ($start_date) {
      $query->condition('e.start_date', [
        $start_date->format('Y-m-d H:i:s'),
        $end_date->format('Y-m-d H:i:s'),
      ], 'BETWEEN');
    }
    else {
      $query->condition('e.start_date', $end_date->format('Y-m-d H:i:s'), '<=');
    }
    $query->condition('p.event_id', $eventIds, 'IN');
    $query->leftJoin('civicrm_participant_payment', 'pp', 'pp.participant_id = p.id');
    $query->leftJoin('civicrm_contribution', 'c', 'c.id = pp.contribution_id');
    $query->groupBy('p.event_id');

    $metrics = [];
    foreach ($query->execute() as $record) {
      $eventId = (int) $record->event_id;
      $metrics[$eventId] = [
        'registrations' => (int) $record->registration_count,
        'total_amount' => (float) $record->total_amount,
        'paid_count' => (int) $record->paid_count,
      ];
    }

    return $metrics;
  }

  /**
   * Returns known skill level labels keyed by value.
   */
  protected function getSkillLevelLabels(): array {
    return [
      'introductory' => 'Introductory - No experience required',
      'intermediate' => 'Intermediate - Basics familiarity required',
      'advanced' => 'Advanced -  Strong Competency required',
    ];
  }

  /**
   * Provides age bucket definitions.
   */
  protected function getAgeBucketDefinitions(): array {
    return [
      ['min' => NULL, 'max' => 17, 'label' => 'Under 18'],
      ['min' => 18, 'max' => 24, 'label' => '18-24'],
      ['min' => 25, 'max' => 34, 'label' => '25-34'],
      ['min' => 35, 'max' => 44, 'label' => '35-44'],
      ['min' => 45, 'max' => 54, 'label' => '45-54'],
      ['min' => 55, 'max' => 64, 'label' => '55-64'],
      ['min' => 65, 'max' => NULL, 'label' => '65+'],
    ];
  }

  /**
   * Determines the bucket index for a birth date value.
   */
  protected function resolveAgeBucketIndex(array $buckets, $birthDate, \DateTimeImmutable $referenceDate): ?int {
    if (empty($birthDate)) {
      return NULL;
    }
    try {
      $birth = new \DateTimeImmutable($birthDate);
    }
    catch (\Exception $e) {
      return NULL;
    }
    $age = (int) $referenceDate->diff($birth)->y;
    foreach ($buckets as $index => $bucket) {
      $min = $bucket['min'];
      $max = $bucket['max'];
      if (($min === NULL || $age >= $min) && ($max === NULL || $age <= $max)) {
        return $index;
      }
    }
    return NULL;
  }

  /**
   * Splits a stored multi-value string into individual values.
   */
  protected function explodeMultiValue(?string $value): array {
    if ($value === NULL || $value === '') {
      return [''];
    }
    if (str_contains($value, chr(0))) {
      $value = str_replace(chr(0), ',', $value);
    }
    $parts = preg_split('/[,|;]/', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($parts)) {
      return [$value];
    }
    return array_map('trim', $parts);
  }

  /**
   * Returns codes ignored when reporting ethnicity.
   */
  protected function getIgnoredEthnicityValues(): array {
    return [
      '',
      'decline',
      'prefer_not_to_say',
      'prefer_not_to_disclose',
      'not_specified',
    ];
  }

  /**
   * Determines the ethnicity field metadata for CiviCRM contacts.
   */
  protected function getEthnicityFieldMetadata(): array {
    if ($this->ethnicityFieldMetadata !== NULL) {
      return $this->ethnicityFieldMetadata;
    }
    $schema = $this->database->schema();
    $metadata = [];
    if ($schema->tableExists('civicrm_value_demographics_15')) {
      foreach (['custom_46', 'ethnicity_46'] as $candidate) {
        if ($schema->fieldExists('civicrm_value_demographics_15', $candidate)) {
          $metadata = [
            'table' => 'civicrm_value_demographics_15',
            'column' => $candidate,
          ];
          break;
        }
      }
    }
    $this->ethnicityFieldMetadata = $metadata;
    return $this->ethnicityFieldMetadata;
  }

  /**
   * Resolves the gender option group ID.
   */
  protected function getGenderOptionGroupId(): ?int {
    if ($this->genderOptionGroupId !== NULL) {
      return $this->genderOptionGroupId;
    }
    $query = $this->database->select('civicrm_option_group', 'og');
    $query->addField('og', 'id');
    $query->condition('og.name', 'gender');
    $value = $query->execute()->fetchField();
    $this->genderOptionGroupId = $value ? (int) $value : NULL;
    return $this->genderOptionGroupId;
  }

  /**
   * Converts raw custom field values into normalized ethnicity labels.
   */
  protected function normalizeEthnicityValues(?string $rawValue, array $ignoredValues = []): array {
    $entries = $this->explodeMultiValue($rawValue);
    $values = [];
    foreach ($entries as $entry) {
      $normalized = strtolower(trim($entry));
      if ($normalized === '' || in_array($normalized, $ignoredValues, TRUE)) {
        continue;
      }
      $values[] = $this->mapEthnicityCodeToLabel($normalized);
    }
    return $values;
  }

  /**
   * Maps stored ethnicity codes to readable labels.
   */
  protected function mapEthnicityCodeToLabel(string $code): string {
    $map = [
      'asian' => 'Asian',
      'black' => 'Black / African American',
      'middleeast' => 'Middle Eastern / North African',
      'mena' => 'Middle Eastern / North African',
      'hispanic' => 'Hispanic / Latino',
      'native' => 'American Indian / Alaska Native',
      'aian' => 'American Indian / Alaska Native',
      'islander' => 'Native Hawaiian / Pacific Islander',
      'nhpi' => 'Native Hawaiian / Pacific Islander',
      'white' => 'White',
      'multi' => 'Multiracial',
      'other' => 'Other',
      'prefer_not_to_say' => 'Prefer not to say',
      'prefer_not_to_disclose' => 'Prefer not to disclose',
      'decline' => 'Prefer not to say',
      'not_specified' => 'Unspecified',
    ];
    return $map[$code] ?? ucwords(str_replace('_', ' ', $code));
  }

  /**
   * Gets the total unique participants in entrepreneurial events for a year.
   */
  /**
   * Gets unique entrepreneurship event participants in the trailing 12 months.
   */
  public function getEntrepreneurshipEventParticipantsTrailing(): int {
    $end = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $start = (new \DateTimeImmutable('-12 months'))->format('Y-m-d H:i:s');

    $query = $this->database->select('civicrm_participant', 'p');
    $query->innerJoin('civicrm_event', 'e', 'p.event_id = e.id');
    $query->innerJoin('civicrm_event__field_civi_event_area_interest', 'ai', 'ai.entity_id = e.id');
    $query->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $query->addExpression('COUNT(DISTINCT p.contact_id)', 'unique_participants');
    $query->condition('ai.field_civi_event_area_interest_target_id', [3249, 3335], 'IN');
    $query->condition('e.start_date', [$start, $end], 'BETWEEN');
    $query->condition('pst.is_counted', 1);
    $query->condition('p.contact_id', 0, '>');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Returns quarterly entrepreneurship event participant counts (oldest-first).
   *
   * Runs the same query as getEntrepreneurshipEventParticipantsTrailing() but
   * over discrete quarterly windows to produce a sparkline trend.
   */
  public function getEntrepreneurshipEventParticipantsTrend(int $quarters = 8): array {
    $now   = new \DateTimeImmutable('now');
    $month = (int) $now->format('n');
    $year  = (int) $now->format('Y');

    $prevQ = (int) ceil($month / 3) - 1;
    if ($prevQ <= 0) {
      $prevQ = 4;
      $year--;
    }

    $trend = [];
    for ($i = $quarters - 1; $i >= 0; $i--) {
      $targetQ    = $prevQ - $i;
      $targetYear = $year;
      while ($targetQ <= 0) {
        $targetQ += 4;
        $targetYear--;
      }

      $startMonth = ($targetQ - 1) * 3 + 1;
      $endMonth   = $targetQ * 3;
      $lastDay    = (int)(new \DateTimeImmutable(sprintf('%d-%02d-01', $targetYear, $endMonth)))->format('t');
      $start = sprintf('%d-%02d-01 00:00:00', $targetYear, $startMonth);
      $end   = sprintf('%d-%02d-%02d 23:59:59', $targetYear, $endMonth, $lastDay);

      $query = $this->database->select('civicrm_participant', 'p');
      $query->innerJoin('civicrm_event', 'e', 'p.event_id = e.id');
      $query->innerJoin('civicrm_event__field_civi_event_area_interest', 'ai', 'ai.entity_id = e.id');
      $query->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
      $query->addExpression('COUNT(DISTINCT p.contact_id)', 'unique_participants');
      $query->condition('ai.field_civi_event_area_interest_target_id', [3249, 3335], 'IN');
      $query->condition('e.start_date', [$start, $end], 'BETWEEN');
      $query->condition('pst.is_counted', 1);
      $query->condition('p.contact_id', 0, '>');

      $trend[] = (float) $query->execute()->fetchField();
    }

    return $trend;
  }

  public function getEntrepreneurshipEventParticipants(int $year): int {
    $start = $year . '-01-01 00:00:00';
    $end = $year . '-12-31 23:59:59';

    // Areas of interest: 3249 (Entrepreneurship, Startups & Business), 3335 (Prototyping & Invention)
    $query = $this->database->select('civicrm_participant', 'p');
    $query->innerJoin('civicrm_event', 'e', 'p.event_id = e.id');
    $query->innerJoin('civicrm_event__field_civi_event_area_interest', 'ai', 'ai.entity_id = e.id');
    $query->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    
    $query->addExpression('COUNT(DISTINCT p.contact_id)', 'unique_participants');
    
    $query->condition('ai.field_civi_event_area_interest_target_id', [3249, 3335], 'IN');
    $query->condition('e.start_date', [$start, $end], 'BETWEEN');
    $query->condition('pst.is_counted', 1);
    $query->condition('p.contact_id', 0, '>');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Gets the total number of workshop attendees for the year.
   *
   * @return int
   *   The total number of workshop attendees.
   */
  public function getAnnualWorkshopAttendees(): int {
    // @todo: Implement logic to get this from the CiviCRM "Event Registration
    // Report" where the event type is "Ticketed Workshop". This will be called
    // by the 'annual' snapshot in the makerspace_snapshot module.
    return 1350;
  }

  /**
   * Gets the total number of first-time workshop participants for the year.
   *
   * @return int
   *   The total number of first-time workshop participants.
   */
  public function getAnnualFirstTimeWorkshopParticipants(): int {
    // @todo: Implement logic to get a unique count of participants who attended
    // their first-ever event in that year. This will be called by the 'annual'
    // snapshot.
    return 450;
  }

  /**
   * Gets the Net Promoter Score (NPS) for the education program for the year.
   *
   * @return int
   *   The education NPS.
   */
  public function getAnnualEducationNps(): int {
    // @todo: Implement logic to query CiviCRM evaluations for the year and
    // calculate the NPS. The formula is: ((Promoters - Detractors) / Total
    // Responses) * 100. Promoters: 5, Passives: 4, Detractors: 1-3. This will
    // be called by the 'annual' snapshot.
    return 65;
  }

  /**
   * Gets the percentage of workshop participants who are BIPOC for the year.
   *
   * @return float
   *   The percentage of BIPOC workshop participants.
   */
  public function getAnnualParticipantDemographics(): float {
    // @todo: Implement logic to get this from the CiviCRM report on
    // demographics selected at registration (all not white). This will be
    // called by the 'annual' snapshot.
    return 0.20;
  }

  /**
   * Gets the percentage of active instructors who are BIPOC for the year.
   *
   * @return float
   *   The percentage of BIPOC active instructors.
   */
  public function getAnnualInstructorDemographics(): float {
    // @todo: Implement logic to get this count from CiviCRM for the past 12
    // months. This will be called by the 'annual' snapshot.
    return 0.15;
  }

}
