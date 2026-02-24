<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\makerspace_dashboard\Service\MembershipLocationDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for providing member location data to the frontend.
 */
class LocationApiController extends ControllerBase {

  /**
   * The membership location data service.
   *
   * @var \Drupal\makerspace_dashboard\Service\MembershipLocationDataService
   */
  protected MembershipLocationDataService $membershipLocationDataService;

  /**
   * Constructs a new LocationApiController object.
   *
   * @param \Drupal\makerspace_dashboard\Service\MembershipLocationDataService $membership_location_data_service
   *   The membership location data service.
   */
  public function __construct(MembershipLocationDataService $membership_location_data_service) {
    $this->membershipLocationDataService = $membership_location_data_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makerspace_dashboard.membership_location_data')
    );
  }

  /**
   * Returns member location data as a JSON response.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the member location data.
   */
  public function getLocations(): JsonResponse {
    $locations = $this->membershipLocationDataService->getMemberLocations();
    $response = new CacheableJsonResponse($locations);
    $cacheability = (new CacheableMetadata())
      ->setCacheMaxAge(900)
      ->addCacheTags(['makerspace_dashboard:api:locations'])
      ->addCacheContexts([]);
    $response->addCacheableDependency($cacheability);
    $response->setPublic();
    $response->setMaxAge(900);
    $response->setSharedMaxAge(900);
    return $response;
  }

}
