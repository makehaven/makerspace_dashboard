<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RendererInterface;
use Drupal\makerspace_dashboard\Service\DashboardSectionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * AJAX controller for refreshing dashboard charts by time range.
 */
class DashboardChartController extends ControllerBase {

  /**
   * Dashboard section manager.
   */
  protected DashboardSectionManager $sectionManager;

  /**
   * Renderer service.
   */
  protected RendererInterface $renderer;

  /**
   * Constructs the controller.
   */
  public function __construct(DashboardSectionManager $section_manager, RendererInterface $renderer) {
    $this->sectionManager = $section_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('makerspace_dashboard.section_manager'),
      $container->get('renderer')
    );
  }

  /**
   * Returns refreshed markup for a dashboard chart.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Current request object.
   * @param string $section
   *   Section machine name.
   * @param string $chart
   *   Chart identifier within the section.
   * @param string $range
   *   Selected time range key.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with markup and attachment metadata.
   */
  public function chart(Request $request, string $section, string $chart, string $range): JsonResponse {
    $filters = [
      'ranges' => [
        $chart => $range,
      ],
    ];

    $chartRenderArray = $this->sectionManager->buildSectionChart($section, $chart, $filters);
    if (!$chartRenderArray) {
      return new JsonResponse(['error' => 'Chart not found.'], 404);
    }

    // Capture attachments before rendering.
    $metadata = BubbleableMetadata::createFromRenderArray($chartRenderArray);
    $markup = $this->renderer->renderRoot($chartRenderArray);
    $attachments = $metadata->getAttachments();

    $response = [
      'markup' => $markup,
      'drupalSettings' => $attachments['drupalSettings'] ?? [],
      'libraries' => $attachments['library'] ?? [],
    ];

    return new JsonResponse($response);
  }

}
