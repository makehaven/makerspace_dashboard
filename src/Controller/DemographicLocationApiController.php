<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\makerspace_dashboard\Service\MemberDemographicLocationDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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
  public function getLocations(Request $request): JsonResponse {
    $type = (string) $request->query->get('type', '');
    $value = (string) $request->query->get('value', '');
    if ($type === '' || $value === '') {
      return new JsonResponse([
        'locations' => [],
        'mappable_count' => 0,
        'total_count' => 0,
        'filters' => [
          'type' => $type,
          'value' => $value,
          'label' => $value,
        ],
      ]);
    }

    $payload = $this->demographicLocationData->getLocations($type, $value);
    return new JsonResponse($payload);
  }

}
