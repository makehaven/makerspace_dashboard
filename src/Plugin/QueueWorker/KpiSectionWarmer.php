<?php

declare(strict_types=1);

namespace Drupal\makerspace_dashboard\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\makerspace_dashboard\Service\KpiDataService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Warms a single dashboard section's KPI payload.
 *
 * @QueueWorker(
 *   id = "makerspace_dashboard.kpi_section_warmer",
 *   title = @Translation("Makerspace dashboard KPI section warmer"),
 *   cron = {"time" = 45}
 * )
 */
class KpiSectionWarmer extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected KpiDataService $kpiData;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, KpiDataService $kpi_data) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->kpiData = $kpi_data;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('makerspace_dashboard.kpi_data')
    );
  }

  public function processItem($data): void {
    if (!is_array($data) || empty($data['section_id'])) {
      return;
    }
    $this->kpiData->warmSection((string) $data['section_id']);
  }

}
