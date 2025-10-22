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

}
