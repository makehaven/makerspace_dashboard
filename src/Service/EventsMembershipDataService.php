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
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend.
   */
  protected CacheBackendInterface $cache;

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
      $join_date = new DrupalDateTime($result->field_member_join_date_value);
      $diff = $join_date->getTimestamp() - $event_date->getTimestamp();
      $days = round($diff / (60 * 60 * 24));

      if ($days >= 0 && $days <= 30) {
        $joins_30_days++;
      }
      if ($days >= 0 && $days <= 60) {
        $joins_60_days++;
      }
      if ($days >= 0 && $days <= 90) {
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
    $query->fields('e', ['start_date']);
    $query->fields('pmjd', ['field_member_join_date_value']);
    $query->addExpression("TIMESTAMPDIFF(DAY, e.start_date, pmjd.field_member_join_date_value)", 'days_to_join');
    $query->condition('e.start_date', [$start_date->format('Y-m-d H:i:s'), $end_date->format('Y-m-d H:i:s')], 'BETWEEN');
    $query->condition('p.status_id', 1); // Attended
    $query->where("pmjd.field_member_join_date_value IS NOT NULL");

    $results = $query->execute()->fetchAll();

    $monthly_averages = [];
    foreach ($results as $result) {
        $event_month = date('Y-m', strtotime($result->start_date));
        if (!isset($monthly_averages[$event_month])) {
            $monthly_averages[$event_month] = ['total_days' => 0, 'count' => 0];
        }
        $monthly_averages[$event_month]['total_days'] += $result->days_to_join;
        $monthly_averages[$event_month]['count']++;
    }

    $data = [];
    foreach ($monthly_averages as $month => $values) {
        $data[] = $values['count'] > 0 ? $values['total_days'] / $values['count'] : 0;
    }

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
