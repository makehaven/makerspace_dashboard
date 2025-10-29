<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

/**
 * Provides aggregated demographic metrics for the dashboard.
 */
class DemographicsDataService {

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend for results.
   */
  protected CacheBackendInterface $cache;

  /**
   * Cache lifetime in seconds.
   */
  protected int $ttl;

  /**
   * Role machine names that indicate active membership.
   */
  protected array $memberRoles;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, array $member_roles = NULL, int $ttl = 1800) {
    $this->database = $database;
    $this->cache = $cache;
    $this->ttl = $ttl;
    $this->memberRoles = $member_roles ?: ['current_member', 'member'];
  }

  /**
   * Builds a town/locality distribution for active members.
   */
  public function getLocalityDistribution(int $minimum = 5, int $limit = 8): array {
    $cid = sprintf('makerspace_dashboard:demographics:locality:%d:%d', $minimum, $limit);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('profile', 'p');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');
    $query->addField('addr', 'field_member_address_locality', 'locality_raw');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->leftJoin('profile__field_member_address', 'addr', 'addr.entity_id = p.profile_id AND addr.deleted = 0 AND addr.delta = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');

    $query->groupBy('addr.field_member_address_locality');
    $query->orderBy('member_count', 'DESC');

    $results = $query->execute();

    $aggregated = [];
    foreach ($results as $record) {
      $raw = trim((string) $record->locality_raw);
      $key = $raw === '' ? '__unknown' : mb_strtolower($raw);
      $label = $raw === '' ? 'Unknown / not provided' : $this->formatLabel($raw, '');
      if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
          'label' => $label,
          'count' => 0,
        ];
      }
      $aggregated[$key]['count'] += (int) $record->member_count;
    }

    $rows = array_values($aggregated);
    usort($rows, function (array $a, array $b) {
      return $b['count'] <=> $a['count'];
    });

    $output = [];
    $other = 0;
    foreach ($rows as $row) {
      if ($row['label'] === 'Unknown / not provided') {
        if ($row['count'] < $minimum) {
          $other += $row['count'];
        }
        else {
          $output[] = $row;
        }
        continue;
      }

      if ($row['count'] < $minimum || count($output) >= $limit) {
        $other += $row['count'];
      }
      else {
        $output[] = $row;
      }
    }

    if ($other > 0) {
      $output[] = [
        'label' => 'Other (< ' . $minimum . ')',
        'count' => $other,
      ];
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $output, $expire, ['profile_list', 'user_list']);

    return $output;
  }

  /**
   * Builds a gender distribution for active members.
   */
  public function getGenderDistribution(int $minimum = 5): array {
    $cid = sprintf('makerspace_dashboard:demographics:gender:%d', $minimum);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('profile', 'p');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');
    $query->addField('gender', 'field_member_gender_value', 'gender_raw');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->leftJoin('profile__field_member_gender', 'gender', 'gender.entity_id = p.profile_id AND gender.deleted = 0 AND gender.delta = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');

    $query->groupBy('gender.field_member_gender_value');
    $query->orderBy('member_count', 'DESC');

    $results = $query->execute();

    $aggregated = [];
    foreach ($results as $record) {
      $raw = trim((string) $record->gender_raw);
      $key = $raw === '' ? '__unknown' : mb_strtolower($raw);
      $label = $raw === '' ? 'Not provided' : $this->formatLabel($raw, '');
      if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
          'label' => $label,
          'count' => 0,
        ];
      }
      $aggregated[$key]['count'] += (int) $record->member_count;
    }

    $rows = array_values($aggregated);
    usort($rows, function (array $a, array $b) {
      return $b['count'] <=> $a['count'];
    });

    $output = [];
    $other = 0;
    foreach ($rows as $row) {
      if ($row['count'] < $minimum) {
        $other += $row['count'];
        continue;
      }
      $output[] = $row;
    }

    if ($other > 0) {
      $output[] = [
        'label' => 'Other (< ' . $minimum . ')',
        'count' => $other,
      ];
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $output, $expire, ['profile_list', 'user_list']);

    return $output;
  }

  /**
   * Builds an interest distribution for active members.
   */
  public function getInterestDistribution(int $minimum = 5, int $limit = 10): array {
    $cid = sprintf('makerspace_dashboard:demographics:interest:%d:%d', $minimum, $limit);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    // Some sites may not expose member interests. Return empty results gracefully.
    if (!$this->database->schema()->tableExists('profile__field_member_interest')) {
      return [];
    }

    $query = $this->database->select('profile', 'p');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');
    $query->addField('interest', 'field_member_interest_value', 'interest_raw');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->leftJoin('profile__field_member_interest', 'interest', 'interest.entity_id = p.profile_id AND interest.deleted = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');

    $query->groupBy('interest.field_member_interest_value');
    $query->orderBy('member_count', 'DESC');
    $query->range(0, $limit);

    $results = $query->execute();

    $aggregated = [];
    foreach ($results as $record) {
      $raw = trim((string) $record->interest_raw);
      $key = $raw === '' ? '__unknown' : mb_strtolower($raw);
      $label = $raw === '' ? 'Not provided' : $this->formatLabel($raw, '');
      if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
          'label' => $label,
          'count' => 0,
        ];
      }
      $aggregated[$key]['count'] += (int) $record->member_count;
    }

    $rows = array_values($aggregated);
    usort($rows, function (array $a, array $b) {
      return $b['count'] <=> $a['count'];
    });

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $rows, $expire, ['profile_list', 'user_list']);

    return $rows;
  }

  /**
   * Builds an interest distribution for new members within a date range.
   */
  public function getRecentInterestDistribution(\DateTimeImmutable $start, \DateTimeImmutable $end, int $minimum = 2, int $limit = 10): array {
    $rangeStart = $start->setTime(0, 0, 0)->getTimestamp();
    $rangeEnd = $end->setTime(23, 59, 59)->getTimestamp();
    $cid = sprintf('makerspace_dashboard:demographics:interest_recent:%d:%d:%d:%d', $rangeStart, $rangeEnd, $minimum, $limit);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('profile__field_member_interest')) {
      return [];
    }

    $query = $this->database->select('profile', 'p');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');
    $query->addField('interest', 'field_member_interest_value', 'interest_raw');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->condition('p.created', $rangeStart, '>=');
    $query->condition('p.created', $rangeEnd, '<=');

    $query->leftJoin('profile__field_member_interest', 'interest', 'interest.entity_id = p.profile_id AND interest.deleted = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);

    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');

    $query->groupBy('interest.field_member_interest_value');
    $query->orderBy('member_count', 'DESC');
    $query->range(0, $limit * 2);

    $results = $query->execute();

    $aggregated = [];
    foreach ($results as $record) {
      $raw = trim((string) $record->interest_raw);
      $key = $raw === '' ? '__unknown' : mb_strtolower($raw);
      $label = $raw === '' ? 'Not provided' : $this->formatLabel($raw, '');
      if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
          'label' => $label,
          'count' => 0,
        ];
      }
      $aggregated[$key]['count'] += (int) $record->member_count;
    }

    $rows = array_values($aggregated);
    usort($rows, function (array $a, array $b) {
      return $b['count'] <=> $a['count'];
    });

    $output = [];
    $other = 0;
    foreach ($rows as $row) {
      if ($row['count'] < $minimum) {
        $other += $row['count'];
        continue;
      }
      $output[] = $row;
      if (count($output) >= $limit) {
        break;
      }
    }
    if ($other > 0) {
      $output[] = [
        'label' => 'Other (< ' . $minimum . ')',
        'count' => $other,
      ];
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $output, $expire, ['profile_list', 'user_list']);

    return $output;
  }

  /**
   * Builds a discovery source distribution for active members.
   */
  public function getDiscoveryDistribution(int $minimum = 5): array {
    $cid = sprintf('makerspace_dashboard:demographics:discovery:%d', $minimum);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('profile__field_member_discovery')) {
      return [];
    }

    $query = $this->database->select('profile', 'p');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');
    $query->addField('discovery', 'field_member_discovery_value', 'discovery_raw');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->leftJoin('profile__field_member_discovery', 'discovery', 'discovery.entity_id = p.profile_id AND discovery.deleted = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');

    $query->groupBy('discovery.field_member_discovery_value');
    $query->orderBy('member_count', 'DESC');

    $results = $query->execute();

    $aggregated = [];
    foreach ($results as $record) {
      $raw = trim((string) $record->discovery_raw);
      $key = $raw === '' ? '__unknown' : mb_strtolower($raw);
      $label = $raw === '' ? 'Not captured' : $this->formatLabel($raw, '');
      if (!isset($aggregated[$key])) {
        $aggregated[$key] = [
          'label' => $label,
          'count' => 0,
        ];
      }
      $aggregated[$key]['count'] += (int) $record->member_count;
    }

    $rows = array_values($aggregated);
    usort($rows, function (array $a, array $b) {
      return $b['count'] <=> $a['count'];
    });

    $output = [];
    $other = 0;
    foreach ($rows as $row) {
      if ($row['count'] < $minimum) {
        $other += $row['count'];
        continue;
      }
      $output[] = $row;
    }

    if ($other > 0) {
      $output[] = [
        'label' => 'Other (< ' . $minimum . ')',
        'count' => $other,
      ];
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $output, $expire, ['profile_list', 'user_list']);

    return $output;
  }

  /**
   * Builds an age distribution for active members.
   */
  public function getAgeDistribution(int $minimumAge = 0, int $maximumAge = 100): array {
    $cid = sprintf('makerspace_dashboard:demographics:ages:%d:%d', $minimumAge, $maximumAge);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('profile__field_member_birthday')) {
      return [];
    }

    $query = $this->database->select('profile', 'p');
    $query->addField('birthday', 'field_member_birthday_value', 'birthday_raw');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->leftJoin('profile__field_member_birthday', 'birthday', 'birthday.entity_id = p.profile_id AND birthday.deleted = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');
    $query->addField('p', 'uid', 'uid');
    $query->distinct();

    $results = $query->execute();

    $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
    $distribution = [];
    $seen = [];
    foreach ($results as $record) {
      $uid = (int) $record->uid;
      if (isset($seen[$uid])) {
        continue;
      }
      $seen[$uid] = TRUE;
      $raw = trim((string) $record->birthday_raw);
      if ($raw === '') {
        continue;
      }
      try {
        $birthDate = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
      }
      catch (\Exception $e) {
        continue;
      }
      $age = (int) $birthDate->diff($now)->y;
      if ($age < $minimumAge || $age > $maximumAge) {
        continue;
      }
      $distribution[$age] = ($distribution[$age] ?? 0) + 1;
    }

    ksort($distribution);
    $output = [];
    foreach ($distribution as $age => $count) {
      $output[] = [
        'label' => (string) $age,
        'count' => $count,
      ];
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $output, $expire, ['profile_list', 'user_list']);

    return $output;
  }

  /**
   * Normalizes stored strings into human-readable labels.
   */
  protected function formatLabel(string $raw, string $fallback): string {
    $prepared = trim($raw);
    if ($prepared === '') {
      return $fallback;
    }

    $prepared = str_replace(['_', '-'], ' ', $prepared);
    $prepared = mb_convert_case($prepared, MB_CASE_TITLE, 'UTF-8');

    $map = [
      'Decline' => 'Prefer Not To Say',
    ];

    return $map[$prepared] ?? $prepared;
  }

}
