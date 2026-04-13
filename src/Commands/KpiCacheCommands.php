<?php

declare(strict_types=1);

namespace Drupal\makerspace_dashboard\Commands;

use Drupal\makerspace_dashboard\Service\KpiDataService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for managing the KPI persistent store.
 */
class KpiCacheCommands extends DrushCommands {

  protected KpiDataService $kpiData;

  public function __construct(KpiDataService $kpi_data) {
    parent::__construct();
    $this->kpiData = $kpi_data;
  }

  /**
   * Warms KPI section payloads in the persistent store.
   *
   * @command makerspace-dashboard:kpi-warm
   * @aliases msd:kpi-warm
   *
   * @param string $section
   *   Optional section ID to warm. Defaults to all sections.
   *
   * @usage drush msd:kpi-warm
   *   Recompute and store payloads for every dashboard section.
   * @usage drush msd:kpi-warm finance
   *   Recompute and store only the finance section.
   */
  public function warm(string $section = ''): void {
    $start = microtime(TRUE);
    if ($section !== '') {
      $this->kpiData->warmSection($section);
      $this->logger()->success(dt('Warmed section @s in @t seconds.', [
        '@s' => $section,
        '@t' => round(microtime(TRUE) - $start, 2),
      ]));
      return;
    }
    $this->kpiData->warmSectionCache();
    $this->logger()->success(dt('Warmed all sections in @t seconds.', [
      '@t' => round(microtime(TRUE) - $start, 2),
    ]));
  }

  /**
   * Clears all stored KPI section payloads.
   *
   * Next page load recomputes the visited section synchronously; cron
   * re-warms the rest via the queue worker.
   *
   * @command makerspace-dashboard:kpi-clear
   * @aliases msd:kpi-clear
   *
   * @usage drush msd:kpi-clear
   */
  public function clear(): void {
    $this->kpiData->clearStoredKpis();
    $this->logger()->success(dt('Cleared KPI payload store.'));
  }

}
