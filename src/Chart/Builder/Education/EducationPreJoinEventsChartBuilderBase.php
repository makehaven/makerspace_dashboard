<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

/**
 * Shared helpers for pre-join event charts.
 */
abstract class EducationPreJoinEventsChartBuilderBase extends EducationEventsChartBuilderBase {

  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['3m', '1y', '2y'];
  protected const FALLBACK_MONTHS = [24, 36];

  /**
   * Converts bucket keys into translated labels.
   */
  protected function buildBucketLabels(array $bucketKeys): array {
    $labels = [];
    foreach ($bucketKeys as $key) {
      switch (TRUE) {
        case $key <= 0:
          $labels[] = (string) $this->t('0 events');
          break;

        case $key === 1:
          $labels[] = (string) $this->t('1 event');
          break;

        case $key >= 4:
          $labels[] = (string) $this->t('4+ events');
          break;

        default:
          $labels[] = (string) $this->t('@count events', ['@count' => $key]);
      }
    }
    return $labels;
  }

  /**
   * Builds stacked datasets showing percentage share per bucket.
   */
  protected function buildStackedDatasets(array $series, array $bucketLabels, string $stackId = 'prejoin'): array {
    if (empty($series) || empty($bucketLabels)) {
      return [];
    }

    $palette = $this->defaultColorPalette();
    $datasetTemplates = [];
    foreach ($bucketLabels as $index => $label) {
      $datasetTemplates[$index] = [
        'label' => $label,
        'data' => [],
        'makerspaceCounts' => [],
        'backgroundColor' => $palette[$index % count($palette)],
        'stack' => $stackId,
      ];
    }

    foreach ($series as $row) {
      $counts = isset($row['counts']) && is_array($row['counts']) ? $row['counts'] : [];
      $total = array_sum($counts);
      foreach ($datasetTemplates as $index => &$dataset) {
        $count = (int) ($counts[$index] ?? 0);
        $value = $total > 0 ? round(($count / $total) * 100, 1) : 0;
        $dataset['data'][] = $value;
        $dataset['makerspaceCounts'][] = $count;
      }
      unset($dataset);
    }

    return array_values($datasetTemplates);
  }

  /**
   * Resolves the formatted labels for each series row.
   */
  protected function formatSeriesLabels(array $series): array {
    $labels = [];
    foreach ($series as $row) {
      $id = (string) ($row['id'] ?? '');
      $rawLabel = (string) ($row['label'] ?? '');
      $dimension = (string) ($row['dimension'] ?? '');
      if ($dimension === 'gender') {
        $labels[] = (string) $this->t('Gender: @label', ['@label' => $rawLabel ?: (string) $this->t('Unspecified')]);
        continue;
      }
      if ($dimension === 'race') {
        $labels[] = (string) $this->t('Race: @label', ['@label' => $rawLabel ?: (string) $this->t('Unspecified')]);
        continue;
      }
      if ($id === 'all') {
        $labels[] = (string) $this->t('All events');
      }
      elseif ($id === 'other') {
        $labels[] = (string) $this->t('Other event types');
      }
      else {
        $labels[] = $rawLabel ?: (string) $this->t('Unspecified');
      }
    }
    return $labels;
  }

  /**
   * Resolves the selected range, fetches data, and applies fallbacks.
   *
   * @param array $filters
   *   Chart filters.
   * @param callable $validator
   *   Callback that receives the dataset and should return TRUE when the data
   *   is usable (e.g., contains non-empty series).
   *
   * @return array|null
   *   Array with keys: data, window, range_key, range_metadata, months. NULL
   *   when no dataset could be produced.
   */
  protected function resolvePreJoinDataset(array $filters, callable $validator): ?array {
    $rangeOptions = static::RANGE_OPTIONS ?? self::RANGE_OPTIONS;
    $defaultRange = static::RANGE_DEFAULT ?? self::RANGE_DEFAULT;
    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), $defaultRange, $rangeOptions);
    $rangeMetadata = $this->buildRangeMetadata($activeRange, $rangeOptions);

    $primaryMonths = $this->rangeKeyToMonths($activeRange);
    $fallbackMonths = array_values(array_unique(array_filter(array_merge([$primaryMonths], static::FALLBACK_MONTHS ?? self::FALLBACK_MONTHS), static fn($value) => $value > 0)));

    foreach ($fallbackMonths as $months) {
      $window = $this->buildRollingWindow($months);
      $data = $this->eventsMembershipDataService->getPreJoinEventAttendance($window['start'], $window['end']);
      if ($validator($data)) {
        return [
          'data' => $data,
          'window' => $window,
          'range_key' => $activeRange,
          'range_metadata' => $rangeMetadata,
          'months' => $months,
          'selected_months' => $primaryMonths,
        ];
      }
    }

    return NULL;
  }

  /**
   * Maps a range preset key to an approximate month count.
   */
  protected function rangeKeyToMonths(string $rangeKey): int {
    return match ($rangeKey) {
      '3m' => 3,
      '1m' => 1,
      '2y' => 24,
      'all' => 36,
      default => 12,
    };
  }

}
