<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
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
   * Constructs a new CsvDownloadController object.
   *
   * @param \Drupal\makerspace_dashboard\Service\DashboardSectionManager $dashboard_section_manager
   *   The dashboard section manager.
   */
  public function __construct(DashboardSectionManager $dashboard_section_manager) {
    $this->dashboardSectionManager = $dashboard_section_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makerspace_dashboard.section_manager')
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
    $section = $this->sectionManager->getSection($sid);
    if (!$section) {
      throw new NotFoundHttpException();
    }

    $build = $section->build();
    $chart_container = $build[$chart_id] ?? NULL;
    $chart = $chart_container['chart'] ?? NULL;

    if (!$chart) {
      throw new NotFoundHttpException();
    }

    $response = new StreamedResponse(function () use ($chart) {
      $handle = fopen('php://output', 'w');
      $this->generateCsv($chart, $handle);
      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', "attachment; filename=\"{$chart_id}.csv\"");

    return $response;
  }

  /**
   * Generates the CSV data from the chart's render array.
   *
   * @param array $chart
   *   The chart's render array.
   * @param resource $handle
   *   The file handle to write the CSV data to.
   */
  protected function generateCsv(array $chart, $handle): void {
    $labels = $chart['xaxis']['#labels'] ?? [];
    $series = [];

    foreach ($chart as $key => $value) {
      if (strpos($key, 'series') === 0 && isset($value['#type']) && $value['#type'] === 'chart_data') {
        $series[] = $value;
      }
    }

    if (empty($series)) {
      return;
    }

    // Write the header row.
    $header = ['Label'];
    foreach ($series as $s) {
      $header[] = $s['#title'];
    }
    fputcsv($handle, $header);

    // Write the data rows.
    foreach ($labels as $i => $label) {
      $row = [$label];
      foreach ($series as $s) {
        $row[] = $s['#data'][$i] ?? '';
      }
      fputcsv($handle, $row);
    }
  }

}
