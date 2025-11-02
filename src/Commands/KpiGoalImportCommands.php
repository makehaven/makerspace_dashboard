<?php

namespace Drupal\makerspace_dashboard\Commands;

use Drupal\makerspace_dashboard\Service\KpiGoalImporter;
use Drush\Commands\DrushCommands;
use RuntimeException;

/**
 * Drush commands for managing Makerspace Dashboard KPI goals.
 */
class KpiGoalImportCommands extends DrushCommands {

  /**
   * KPI goal importer.
   */
  protected KpiGoalImporter $importer;

  /**
   * Constructs the command handler.
   */
  public function __construct(KpiGoalImporter $importer) {
    parent::__construct();
    $this->importer = $importer;
  }

  /**
   * Imports KPI goals from a CSV snapshot.
   *
   * Expected headers:
   *   section,kpi_id,label,base_2025,goal_2030[,description]
   *
   * @command makerspace-dashboard:import-kpi-goals
   * @aliases msd:import-kpi-goals
   *
   * @param string $file
   *   Path to the CSV snapshot file to ingest.
   * @option dry-run
   *   When set, no configuration will be persisted.
   *
   * @usage drush makerspace-dashboard:import-kpi-goals /tmp/kpi-goals.csv
   * @usage drush makerspace-dashboard:import-kpi-goals /tmp/kpi-goals.csv --dry-run
   */
  public function import(string $file, array $options = ['dry-run' => FALSE]): void {
    $dryRun = !empty($options['dry-run']);

    try {
      $summary = $this->importer->import($file, $dryRun);
    }
    catch (RuntimeException $exception) {
      throw $exception;
    }

    $count = count($summary['changes']);
    $path = $summary['path'];

    if ($dryRun) {
      $this->io()->warning(sprintf('Dry-run only: %d KPI rows parsed from %s.', $count, $path));
      foreach ($summary['changes'] as $change) {
        $baseText = $change['base_2025'] !== NULL ? $change['base_2025'] : '(keep existing)';
        $goalText = $change['goal_2030'] !== NULL ? $change['goal_2030'] : '(keep existing)';
        $this->io()->text(sprintf(' - [%s] %s (%s) base: %s goal: %s', $change['section'], $change['label'], $change['kpi'], $baseText, $goalText));
      }
      return;
    }

    $this->io()->success(sprintf('Imported %d KPI goal rows from %s.', $count, $path));
  }

}
