<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\makerspace_dashboard\Service\MemberDemographicLocationDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * API controller for demographic location maps.
 */
class DemographicLocationApiController extends ControllerBase {

  /**
   * Demographic data service.
   */
  protected MemberDemographicLocationDataService $demographicLocationData;

  /**
   * Constructs the controller.
   */
  public function __construct(MemberDemographicLocationDataService $demographic_location_data) {
    $this->demographicLocationData = $demographic_location_data;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makerspace_dashboard.member_demographic_location_data')
    );
  }

  /**
   * Returns filtered location data.
   */
  public function getLocations(Request $request): CacheableJsonResponse {
    $type = (string) $request->query->get('type', '');
    $value = (string) $request->query->get('value', '');

    $cacheability = (new CacheableMetadata())
      ->setCacheMaxAge(900)
      ->addCacheTags(['makerspace_dashboard:api:demographic_locations'])
      ->addCacheContexts(['url.query_args:type', 'url.query_args:value']);

    if ($type === '' || $value === '') {
      $response = new CacheableJsonResponse([
        'locations' => [],
        'mappable_count' => 0,
        'total_count' => 0,
        'filters' => [
          'type' => $type,
          'value' => $value,
          'label' => $value,
        ],
      ]);
      $response->addCacheableDependency($cacheability);
      $response->setPublic();
      $response->setMaxAge(900);
      $response->setSharedMaxAge(900);
      return $response;
    }

    $payload = $this->demographicLocationData->getLocations($type, $value);
    $response = new CacheableJsonResponse($payload);
    $response->addCacheableDependency($cacheability);
    $response->setPublic();
    $response->setMaxAge(900);
    $response->setSharedMaxAge(900);
    return $response;
  }

}
