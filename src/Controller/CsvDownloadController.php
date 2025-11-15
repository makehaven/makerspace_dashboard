<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\DashboardSectionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for downloading chart data as CSV.
 */
class CsvDownloadController extends ControllerBase {

  /**
   * The dashboard section manager.
   *
   * @var \Drupal\makerspace_dashboard\Service\DashboardSectionManager
   */
  protected DashboardSectionManager $dashboardSectionManager;

  /**
   * Chart builder manager.
   */
  protected ChartBuilderManager $chartBuilderManager;

  /**
   * Backwards compatibility alias for legacy property access.
   *
   * @var \Drupal\makerspace_dashboard\Service\DashboardSectionManager
   */
  protected DashboardSectionManager $sectionManager;

  /**
   * Constructs a new CsvDownloadController object.
   *
   * @param \Drupal\makerspace_dashboard\Service\DashboardSectionManager $dashboard_section_manager
   *   The dashboard section manager.
   */
  public function __construct(DashboardSectionManager $dashboard_section_manager, ChartBuilderManager $chart_builder_manager) {
    $this->dashboardSectionManager = $dashboard_section_manager;
    $this->sectionManager = $dashboard_section_manager;
    $this->chartBuilderManager = $chart_builder_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makerspace_dashboard.section_manager'),
      $container->get('makerspace_dashboard.chart_builder_manager')
    );
  }

  /**
   * Downloads the chart data as a CSV file.
   *
   * @param string $sid
   *   The ID of the dashboard section.
   * @param string $chart_id
   *   The ID of the chart.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   A streamed response containing the CSV file.
   */
  public function downloadCsv(string $sid, string $chart_id): StreamedResponse {
    $definition = $this->buildChartDefinition($sid, $chart_id);
    if (!$definition) {
      throw new NotFoundHttpException();
    }

    $visualization = $definition['visualization'] ?? [];
    if (($visualization['type'] ?? '') !== 'chart' || empty($visualization['data']['datasets'])) {
      throw new NotFoundHttpException();
    }

    $response = new StreamedResponse(function () use ($visualization) {
      $handle = fopen('php://output', 'w');
      $this->generateCsvFromVisualization($visualization, $handle);
      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', "attachment; filename=\"{$chart_id}.csv\"");

    return $response;
  }

  /**
   * Generates the CSV data from the chart visualization metadata.
   *
   * @param array $visualization
   *   Visualization payload from the chart definition.
   * @param resource $handle
   *   The file handle to write the CSV data to.
   */
  protected function generateCsvFromVisualization(array $visualization, $handle): void {
    $data = $visualization['data'] ?? [];
    $labels = $data['labels'] ?? [];
    $datasets = $data['datasets'] ?? [];
    if (!$datasets || !$labels) {
      return;
    }

    // Write the header row.
    $header = ['Label'];
    foreach ($datasets as $dataset) {
      $header[] = $dataset['label'] ?? 'Series';
    }
    fputcsv($handle, $header);

    // Write the data rows.
    foreach ($labels as $i => $label) {
      $row = [$label];
      foreach ($datasets as $dataset) {
        $values = $dataset['data'] ?? [];
        $row[] = $values[$i] ?? '';
      }
      fputcsv($handle, $row);
    }
  }

  /**
   * Builds chart metadata either from a builder or legacy render array.
   */
  protected function buildChartDefinition(string $sectionId, string $chartId): ?array {
    if ($builder = $this->chartBuilderManager->getBuilder($sectionId, $chartId)) {
      $definition = $builder->build();
      if ($definition) {
        return $definition->toMetadata();
      }
    }

    return $this->dashboardSectionManager->getChartDefinition($sectionId, $chartId);
  }

}
