<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
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
   * Chart builder manager service.
   */
  protected ChartBuilderManager $builderManager;

  /**
   * Constructs a new controller.
   */
  public function __construct(DashboardSectionManager $sectionManager, ChartBuilderManager $builderManager) {
    $this->sectionManager = $sectionManager;
    $this->builderManager = $builderManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('makerspace_dashboard.section_manager'),
      $container->get('makerspace_dashboard.chart_builder_manager')
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

    $definition = $this->buildDefinitionFromBuilder($section, $chart, $filters);
    if (!$definition) {
      $chartRenderable = $this->sectionManager->buildSectionChart($section, $chart, $filters);
      $definition = $chartRenderable['#makerspace_chart'] ?? $this->sectionManager->getChartDefinition($section, $chart, $filters);
    }
    if (!$definition) {
      return new JsonResponse(['error' => 'Chart not found.'], 404);
    }

    $response = new CacheableJsonResponse($definition);
    $response->setMaxAge(0);
    $response->headers->set('Cache-Control', 'private, no-cache, no-store, must-revalidate');

    return $response;
  }

  /**
   * Builds chart metadata directly from a chart builder.
   */
  protected function buildDefinitionFromBuilder(string $sectionId, string $chartId, array $filters = []): ?array {
    $builder = $this->builderManager->getBuilder($sectionId, $chartId);
    @file_put_contents('/tmp/makerspace_chart_api.log', sprintf("[%s] lookup builder=%s section=%s chart=%s filters=%s\n", date('c'), $builder ? 'yes' : 'no', $sectionId, $chartId, var_export($filters, TRUE)), FILE_APPEND);
    if (!$builder) {
      return NULL;
    }
    $definition = $builder->build($filters);
    if (!$definition) {
      return NULL;
    }

    $metadata = $definition->toMetadata();
    $metadata['notes'] = $definition->getNotes();
    $metadata['downloadUrl'] = Url::fromRoute('makerspace_dashboard.download_chart_csv', [
      'sid' => $definition->getSectionId(),
      'chart_id' => $definition->getChartId(),
    ])->toString();

    return $metadata;
  }

}
