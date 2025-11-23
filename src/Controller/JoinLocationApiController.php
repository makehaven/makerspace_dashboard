<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\makerspace_dashboard\Service\MemberJoinLocationDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * API controller for member join locations by quarter.
 */
class JoinLocationApiController extends ControllerBase {

  /**
   * Join location data service.
   */
  protected MemberJoinLocationDataService $joinLocationData;

  /**
   * Constructs the controller.
   */
  public function __construct(MemberJoinLocationDataService $join_location_data) {
    $this->joinLocationData = $join_location_data;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makerspace_dashboard.member_join_location_data')
    );
  }

  /**
   * Returns join-location data for the requested quarter.
   */
  public function getJoinLocations(Request $request): JsonResponse {
    $options = $this->joinLocationData->getAvailableQuarters();
    if (empty($options)) {
      return new JsonResponse([
        'locations' => [],
        'mappable_count' => 0,
        'total_count' => 0,
        'quarters' => [],
      ]);
    }

    $requestedYear = (int) $request->query->get('year', 0);
    $requestedQuarter = (int) $request->query->get('quarter', 0);

    if ($requestedYear === 0 || $requestedQuarter === 0) {
      $requestedYear = (int) $options[0]['year'];
      $requestedQuarter = (int) $options[0]['quarter'];
    }

    $data = $this->joinLocationData->getJoinLocationsForQuarter($requestedYear, $requestedQuarter);

    return new JsonResponse([
      'locations' => $data['locations'],
      'mappable_count' => $data['mappable_count'],
      'total_count' => $data['total_count'],
      'filters' => $data['filters'],
      'quarters' => $options,
    ]);
  }

}
