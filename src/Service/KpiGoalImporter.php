<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use RuntimeException;

/**
 * Handles importing KPI goal snapshots from CSV files.
 */
class KpiGoalImporter {

  /**
   * Configuration factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * File system service.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs the importer.
   */
  public function __construct(ConfigFactoryInterface $configFactory, FileSystemInterface $fileSystem, LoggerChannelFactoryInterface $loggerFactory) {
    $this->configFactory = $configFactory;
    $this->fileSystem = $fileSystem;
    $this->logger = $loggerFactory->get('makerspace_dashboard');
  }

  /**
   * Imports KPI goals from a CSV snapshot.
   *
   * @param string $filePath
   *   Path or stream wrapper URI to the CSV file.
   * @param bool $dryRun
   *   When TRUE the configuration is not persisted.
   *
   * @return array
   *   Summary data containing 'changes', 'path', and 'dry_run' keys.
   *
   * @throws \RuntimeException
   *   When the file cannot be read or the CSV is malformed.
   */
  public function import(string $filePath, bool $dryRun = FALSE): array {
    $path = $this->resolvePath($filePath);
    if (!$path || !is_readable($path)) {
      throw new RuntimeException(sprintf('Unable to read KPI goal snapshot file at "%s".', $filePath));
    }

    $handle = fopen($path, 'r');
    if (!$handle) {
      throw new RuntimeException(sprintf('Failed to open "%s" for reading.', $path));
    }

    $header = fgetcsv($handle);
    if (!$header) {
      fclose($handle);
      throw new RuntimeException('KPI goal snapshot appears to be empty.');
    }

    $header = array_map(static fn($value) => strtolower(trim((string) $value)), $header);
    $required = ['section', 'kpi_id', 'label', 'base_2025', 'goal_2030'];
    foreach ($required as $column) {
      if (!in_array($column, $header, TRUE)) {
        fclose($handle);
        throw new RuntimeException(sprintf('Missing required header column "%s".', $column));
      }
    }

    $config = $this->configFactory->getEditable('makerspace_dashboard.kpis');
    $current = $config->getRawData() ?? [];
    $updated = $current;
    $changes = [];

    while (($row = fgetcsv($handle)) !== FALSE) {
      if ($row === [NULL]) {
        continue;
      }
      $row = array_map(static fn($value) => is_string($value) ? trim($value) : $value, $row);
      if (empty(array_filter($row, static fn($value) => $value !== NULL && $value !== ''))) {
        continue;
      }
      $row = array_pad($row, count($header), NULL);
      $data = array_combine($header, $row);
      if ($data === FALSE) {
        $this->logger->warning('Skipping malformed KPI goal row: @row', ['@row' => implode(',', $row)]);
        continue;
      }

      $section = (string) ($data['section'] ?? '');
      $kpiId = (string) ($data['kpi_id'] ?? '');
      if ($section === '' || $kpiId === '') {
        $this->logger->warning('Skipping row with missing section or KPI ID.');
        continue;
      }

      $label = $data['label'] !== '' ? $data['label'] : $kpiId;
      $base = $this->normalizeMetricValue($data['base_2025'] ?? NULL);
      $goal = $this->normalizeMetricValue($data['goal_2030'] ?? NULL);

      $description = $data['description'] ?? NULL;

      if (!isset($updated[$section][$kpiId])) {
        $updated[$section][$kpiId] = [];
      }

      $updated[$section][$kpiId]['label'] = $label;
      if ($base !== NULL) {
        $updated[$section][$kpiId]['base_2025'] = $base;
      }
      if ($goal !== NULL) {
        $updated[$section][$kpiId]['goal_2030'] = $goal;
      }
      if ($description !== NULL && $description !== '') {
        $updated[$section][$kpiId]['description'] = $description;
      }

      $changes[] = [
        'section' => $section,
        'kpi' => $kpiId,
        'label' => $label,
        'base_2025' => $base,
        'goal_2030' => $goal,
      ];
    }

    fclose($handle);

    if (empty($changes)) {
      throw new RuntimeException('No KPI rows were processed. Confirm the CSV contains data.');
    }

    if (!$dryRun) {
      $config->setData($updated);
      $config->save();
      $this->logger->notice('Imported KPI goals snapshot from @file.', ['@file' => $path]);
    }

    return [
      'changes' => $changes,
      'path' => $path,
      'dry_run' => $dryRun,
    ];
  }

  /**
   * Normalizes numeric values for storage.
   *
   * @param mixed $value
   *   Raw CSV value.
   *
   * @return int|float|string|null
   *   Parsed numeric/string value or NULL when not provided.
   */
  protected function normalizeMetricValue($value) {
    if ($value === NULL) {
      return NULL;
    }
    if (is_string($value)) {
      $value = trim($value);
    }
    if ($value === '' || $value === 'n/a') {
      return NULL;
    }
    if (is_numeric($value)) {
      $float = (float) $value;
      return abs($float - round($float)) < 0.0001 ? (int) round($float) : $float;
    }
    return $value;
  }

  /**
   * Resolves a filesystem path, handling stream wrappers.
   */
  protected function resolvePath(string $path): ?string {
    $realpath = $this->fileSystem->realpath($path);
    if ($realpath) {
      return $realpath;
    }
    if (is_file($path)) {
      return $path;
    }
    return NULL;
  }

}
