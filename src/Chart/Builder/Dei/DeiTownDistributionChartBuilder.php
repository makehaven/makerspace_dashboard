<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Dei;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Builds the members-by-town bar chart.
 */
class DeiTownDistributionChartBuilder extends DeiChartBuilderBase {

  protected const CHART_ID = 'town_distribution';
  protected const WEIGHT = 10;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $rows = $this->demographicsDataService->getLocalityDistribution();
    if (empty($rows)) {
      return NULL;
    }

    $labels = array_map(static fn(array $row) => (string) $row['label'], $rows);
    $counts = array_map(static fn(array $row) => (int) $row['count'], $rows);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => $counts,
          'backgroundColor' => '#2563eb',
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
        ],
        'scales' => [
          'x' => [
            'ticks' => ['autoSkip' => FALSE],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Members by Town'),
      (string) $this->t('Top hometowns for active members; smaller groups are aggregated into “Other”.'),
      $visualization,
      [
        (string) $this->t('Source: Active "main" member profiles joined to the address locality field for users holding active membership roles (defaults: @roles).', ['@roles' => 'current_member, member']),
        (string) $this->t('Processing: Counts distinct members per town and collapses values under the minimum threshold into "Other (< 5)".'),
        (string) $this->t('Definitions: Only published users with a default profile are included; blank addresses appear as "Unknown / not provided".'),
      ],
    );
  }

}
