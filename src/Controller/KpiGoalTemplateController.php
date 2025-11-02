<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\makerspace_dashboard\Service\KpiDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates a CSV template for KPI goal imports.
 */
class KpiGoalTemplateController extends ControllerBase {

  /**
   * KPI data service.
   */
  protected KpiDataService $kpiDataService;

  /**
   * Constructs the controller.
   */
  public function __construct(KpiDataService $kpiDataService) {
    $this->kpiDataService = $kpiDataService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('makerspace_dashboard.kpi_data')
    );
  }

  /**
   * Delivers the KPI goal CSV template.
   */
  public function download(): Response {
    $sections = $this->kpiDataService->getAllKpiDefinitions();

    $rows = [];
    $header = [
      'section',
      'kpi_id',
      'label',
      'base_2025',
      'goal_2030',
      'description',
    ];

    $rows[] = $header;

    foreach ($sections as $section_id => $kpis) {
      foreach ($kpis as $kpi_id => $definition) {
        $rows[] = [
          $section_id,
          $kpi_id,
          $definition['label'] ?? $kpi_id,
          $definition['base_2025'] ?? '',
          $definition['goal_2030'] ?? '',
          $definition['description'] ?? '',
        ];
      }
    }

    if (count($rows) === 1) {
      // Provide an empty sample row so the template remains useful even before
      // configuration is populated.
      $rows[] = ['overview', 'total_active_members', 'Total # Active Members', '1000', '1500', 'Example description'];
    }

    $handle = fopen('php://temp', 'w+');
    foreach ($rows as $row) {
      fputcsv($handle, $row);
    }
    rewind($handle);
    $data = stream_get_contents($handle);
    fclose($handle);

    $response = new Response($data);
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="makerspace-kpi-goals-template.csv"');

    return $response;
  }

}
