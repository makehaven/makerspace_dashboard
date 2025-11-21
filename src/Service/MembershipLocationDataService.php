<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

/**
 * Provides aggregated member location metrics for the dashboard.
 */
class MembershipLocationDataService {

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend for results.
   */
  protected CacheBackendInterface $cache;

  /**
   * Geocoding service.
   *
   * @var \Drupal\makerspace_dashboard\Service\GeocodingService
   */
  protected GeocodingService $geocoding;

  /**
   * Cache lifetime in seconds.
   */
  protected int $ttl;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, GeocodingService $geocoding, int $ttl = 1800) {
    $this->database = $database;
    $this->cache = $cache;
    $this->geocoding = $geocoding;
    $this->ttl = $ttl;
  }

  /**
   * Builds a list of member locations (city, state).
   *
   * @return array
   *   An array of location arrays, each with 'locality' and 'administrative_area' keys.
   */
  public function getMemberLocations(): array {
    $cid = 'makerspace_dashboard:membership:locations';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('civicrm_membership', 'm');
    $query->addField('addr', 'city', 'locality');
    $query->condition('m.is_test', 0);

    $query->innerJoin('civicrm_membership_status', 'ms', 'ms.id = m.status_id AND ms.is_current_member = 1');
    $query->innerJoin('civicrm_contact', 'c', 'c.id = m.contact_id');
    $query->condition('c.is_deleted', 0);

    $query->innerJoin('civicrm_address', 'addr', 'addr.contact_id = c.id AND addr.is_primary = 1');
    $query->leftJoin('civicrm_state_province', 'state', 'state.id = addr.state_province_id');
    $query->addExpression('COALESCE(state.abbreviation, state.name)', 'state_code');
    $query->condition('addr.city', '', '<>');
    $query->isNotNull('addr.city');
    $query->isNotNull('addr.state_province_id');

    $query->distinct();
    $results = $query->execute();

    $coordinates = [];
    $seen = [];
    foreach ($results as $record) {
      $city = trim((string) $record->locality);
      $state = trim((string) ($record->state_code ?? ''));
      if ($city === '' || $state === '') {
        continue;
      }
      $key = mb_strtolower($city . '|' . $state);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;

      $location_string = $city . ', ' . $state;
      $geocode_result = $this->geocoding->geocode($location_string);
      if ($geocode_result) {
        $coordinates[] = $geocode_result;
      }
    }

    if (!empty($coordinates)) {
      $expire = time() + $this->ttl;
      $this->cache->set($cid, $coordinates, $expire, [
        'civicrm_contact_list',
        'civicrm_membership_list',
        'civicrm_address_list',
      ]);
    }
    else {
      // Avoid caching empty payloads so the service can retry later.
      $this->cache->delete($cid);
    }

    return $coordinates;
  }

}
