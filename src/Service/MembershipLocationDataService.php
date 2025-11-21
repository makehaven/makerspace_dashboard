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
   * Role machine names that indicate active membership.
   */
  protected array $memberRoles;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, GeocodingService $geocoding, array $member_roles = NULL, int $ttl = 1800) {
    $this->database = $database;
    $this->cache = $cache;
    $this->geocoding = $geocoding;
    $this->ttl = $ttl;
    $this->memberRoles = $member_roles ?: ['current_member', 'member'];
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

    $query = $this->database->select('profile', 'p');
    $query->addField('addr', 'field_member_address_locality', 'locality');
    $query->addField('addr', 'field_member_address_administrative_area', 'administrative_area');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);

    $query->leftJoin('profile__field_member_address', 'addr', 'addr.entity_id = p.profile_id AND addr.deleted = 0 AND addr.delta = 0');
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = p.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');

    $results = $query->execute();

    $coordinates = [];
    foreach ($results as $record) {
      if (!empty($record->locality) && !empty($record->administrative_area)) {
        $location_string = $record->locality . ', ' . $record->administrative_area;
        $geocode_result = $this->geocoding->geocode($location_string);
        if ($geocode_result) {
            $coordinates[] = $geocode_result;
        }
      }
    }

    $expire = time() + $this->ttl;
    $this->cache->set($cid, $coordinates, $expire, ['profile_list', 'user_list']);

    return $coordinates;
  }

}
