<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\makerspace_dashboard\Service\DashboardSectionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides JSON access to Makerspace dashboard chart data.
 */
class DashboardDataController extends ControllerBase {

  /**
   * Dashboard section manager service.
   */
  protected DashboardSectionManager $sectionManager;

  /**
   * Constructs a new controller.
   */
  public function __construct(DashboardSectionManager $sectionManager) {
    $this->sectionManager = $sectionManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('makerspace_dashboard.section_manager')
    );
  }

  /**
   * Returns raw chart data for a given section/chart combo.
   */
  public function chart(Request $request, string $section, string $chart): JsonResponse {
    $filters = [];
    $range = $request->query->get('range');
    if (is_string($range) && $range !== '') {
      $filters['ranges'][$chart] = $range;
      $filters['range'] = $range;
    }

    $definition = $this->sectionManager->getChartDefinition($section, $chart, $filters);
    if (!$definition) {
      return new JsonResponse(['error' => 'Chart not found.'], 404);
    }

    $response = new CacheableJsonResponse($definition);
    $response->setMaxAge(0);
    $response->headers->set('Cache-Control', 'private, no-cache, no-store, must-revalidate');
    return $response;
  }

}
