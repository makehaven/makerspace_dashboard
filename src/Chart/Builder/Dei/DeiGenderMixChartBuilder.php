<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Dei;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Builds the gender identity pie chart.
 */
class DeiGenderMixChartBuilder extends DeiChartBuilderBase {

  protected const CHART_ID = 'gender_mix';
  protected const WEIGHT = 20;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $rows = $this->demographicsDataService->getGenderDistribution();
    if (empty($rows)) {
      return NULL;
    }

    $filteredLabels = [];
    $filteredCounts = [];
    $excluded = [];
    foreach ($rows as $row) {
      $label = $row['label'];
      $count = (int) $row['count'];
      $normalized = mb_strtolower($label);
      if (in_array($normalized, ['not provided', 'prefer not to say'], TRUE)) {
        $excluded[$label] = ($excluded[$label] ?? 0) + $count;
        continue;
      }
      $filteredLabels[] = $label;
      $filteredCounts[] = $count;
    }

    if (empty(array_filter($filteredCounts))) {
      $filteredLabels = array_map(fn(array $row) => $row['label'], $rows);
      $filteredCounts = array_map(fn(array $row) => (int) $row['count'], $rows);
      $excluded = [];
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'pie',
      'data' => [
        'labels' => array_map('strval', $filteredLabels),
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => $filteredCounts,
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['position' => 'top'],
          'datalabels' => [
            'color' => '#0f172a',
            'font' => ['weight' => 'bold'],
            'formatter' => $this->chartCallback('dataset_share_percent', [
              'decimals' => 1,
            ]),
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Source: Active "main" member profiles mapped to field_member_gender for members with active roles (defaults: @roles).', ['@roles' => 'current_member, member']),
      (string) $this->t('Processing: Distinct member counts per gender value with buckets under five members merged into "Other (< 5)".'),
      (string) $this->t('Definitions: Missing or blank values surface as "Not provided" and are excluded from the chart to highlight the proportional mix.'),
    ];

    if (!empty($excluded)) {
      $parts = [];
      foreach ($excluded as $label => $count) {
        $parts[] = $this->t('@label: @count', ['@label' => $label, '@count' => $count]);
      }
      $notes[] = (string) $this->t('Excluded from chart (shown below for reference): @list', ['@list' => implode(', ', $parts)]);
    }

    return $this->newDefinition(
      (string) $this->t('Gender Identity Mix'),
      (string) $this->t('Aggregated from primary member profile gender selections.'),
      $visualization,
      $notes,
    );
  }

}
