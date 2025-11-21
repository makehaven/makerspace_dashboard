<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

/**
 * Shared helpers for membership type snapshot charts.
 */
abstract class RetentionMembershipTypeSnapshotChartBuilderBase extends RetentionSnapshotChartBuilderBase {

  /**
   * Default number of periods shown in the charts.
   */
  protected const PERIOD_LIMIT = 18;

  /**
   * Loads the latest membership type snapshot series.
   */
  protected function getMembershipTypeSnapshots(): array {
    $limit = static::PERIOD_LIMIT > 0 ? static::PERIOD_LIMIT : NULL;
    return $this->snapshotData->getMembershipTypeSeries('month', FALSE, NULL, $limit);
  }

  /**
   * Builds formatted x-axis labels for the supplied series.
   */
  protected function buildPeriodLabels(array $series): array {
    $labels = [];
    foreach ($series as $row) {
      $periodDate = $row['period_date'] ?? NULL;
      if ($periodDate instanceof \DateTimeInterface) {
        $labels[] = $this->formatDate($periodDate, 'M Y');
      }
      else {
        $labels[] = (string) ($row['period_key'] ?? '');
      }
    }
    return $labels;
  }

  /**
   * Determines the membership type order sorted by total contribution.
   */
  protected function determineTypeOrder(array $series): array {
    $totals = [];
    foreach ($series as $row) {
      foreach (($row['types'] ?? []) as $label => $count) {
        $totals[$label] = ($totals[$label] ?? 0) + (int) $count;
      }
    }
    if (!$totals) {
      return [];
    }
    arsort($totals);
    $ordered = array_keys(array_filter($totals, static fn($total) => $total > 0));
    if (!$ordered) {
      $ordered = array_keys($totals);
    }
    return $ordered;
  }

  /**
   * Builds stacked datasets for each membership type label.
   *
   * @param array $series
   *   Snapshot series ordered chronologically.
   * @param array $typeLabels
   *   Ordered list of membership type labels.
   * @param callable $valueCallback
   *   Callback that transforms ($count, $row) into the stored value.
   * @param bool $includeRawCounts
   *   When TRUE, includes makerspaceCounts metadata on each dataset.
   * @param callable|null $datasetMutator
   *   Optional callback to customize the final dataset array. Receives the
   *   dataset array, type label, and zero-based index.
   *
   * @return array
   *   List of Chart.js dataset definitions.
   */
  protected function buildTypeDatasets(array $series, array $typeLabels, callable $valueCallback, bool $includeRawCounts = FALSE, ?callable $datasetMutator = NULL): array {
    $palette = $this->defaultColorPalette();
    $colorCount = count($palette);
    $datasets = [];

    foreach ($typeLabels as $index => $label) {
      $rawCounts = [];
      $values = [];
      foreach ($series as $row) {
        $count = (int) ($row['types'][$label] ?? 0);
        $rawCounts[] = $count;
        $values[] = $valueCallback($count, $row);
      }
      if (!array_sum($rawCounts)) {
        continue;
      }
      $dataset = [
        'label' => $label,
        'data' => $values,
        'backgroundColor' => $palette[$index % $colorCount],
        'stack' => 'membership_types',
        'borderColor' => '#ffffff',
        'borderWidth' => 1,
        'maxBarThickness' => 36,
      ];
      if ($includeRawCounts) {
        $dataset['makerspaceCounts'] = $rawCounts;
      }
      if ($datasetMutator) {
        $dataset = $datasetMutator($dataset, $label, $index);
      }
      $datasets[] = $dataset;
    }

    return $datasets;
  }

}
