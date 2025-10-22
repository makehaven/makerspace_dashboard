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

}
