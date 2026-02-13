<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
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

    $builderDefinition = $this->buildDefinitionFromBuilder($section, $chart, $filters);
    $definition = $builderDefinition ? $this->buildMetadataFromDefinition($builderDefinition) : NULL;

    if (!$definition) {
      $chartRenderable = $this->sectionManager->buildSectionChart($section, $chart, $filters);
      $definition = $chartRenderable['#makerspace_chart'] ?? $this->sectionManager->getChartDefinition($section, $chart, $filters);
    }
    if (!$definition) {
      return new JsonResponse(['error' => 'Chart not found.'], 404);
    }

    $response = new CacheableJsonResponse($definition);
    $cacheability = (new CacheableMetadata())
      ->addCacheContexts(['user.permissions', 'url.query_args:range']);

    if ($builderDefinition) {
      $cache = $builderDefinition->getCacheMetadata();
      $maxAge = isset($cache['max-age']) ? (int) $cache['max-age'] : 900;
      $cacheability->setCacheMaxAge($maxAge);
      if (!empty($cache['tags']) && is_array($cache['tags'])) {
        $cacheability->addCacheTags($cache['tags']);
      }
      if (!empty($cache['contexts']) && is_array($cache['contexts'])) {
        $cacheability->addCacheContexts($cache['contexts']);
      }
      $response->setMaxAge($maxAge);
      $response->setPrivate();
    }
    else {
      $cacheability->setCacheMaxAge(0);
      $response->setMaxAge(0);
      $response->headers->set('Cache-Control', 'private, no-cache, no-store, must-revalidate');
    }

    $response->addCacheableDependency($cacheability);

    return $response;
  }

  /**
   * Builds chart metadata directly from a chart builder.
   */
  protected function buildDefinitionFromBuilder(string $sectionId, string $chartId, array $filters = []): ?ChartDefinition {
    $builder = $this->builderManager->getBuilder($sectionId, $chartId);
    if (!$builder) {
      return NULL;
    }
    $definition = $builder->build($filters);
    if (!$definition) {
      return NULL;
    }

    return $definition;
  }

  /**
   * Converts a chart definition to API metadata format.
   */
  protected function buildMetadataFromDefinition(ChartDefinition $definition): array {
    $metadata = $definition->toMetadata();
    $metadata['notes'] = $definition->getNotes();
    $metadata['downloadUrl'] = Url::fromRoute('makerspace_dashboard.download_chart_csv', [
      'sid' => $definition->getSectionId(),
      'chart_id' => $definition->getChartId(),
    ])->toString();

    return $metadata;
  }

}
