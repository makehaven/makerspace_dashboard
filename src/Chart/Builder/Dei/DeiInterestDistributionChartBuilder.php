<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Dei;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Builds the member interests bar chart.
 */
class DeiInterestDistributionChartBuilder extends DeiChartBuilderBase {

  protected const CHART_ID = 'interest_distribution';
  protected const WEIGHT = 30;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $rows = $this->demographicsDataService->getInterestDistribution();
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
          'backgroundColor' => '#0ea5e9',
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Member Interests'),
      (string) $this->t('Top member interests, based on profile selections.'),
      $visualization,
      [
        (string) $this->t('Source: Active "main" member profiles joined to field_member_interest for users with active membership roles (defaults: @roles).', ['@roles' => 'current_member, member']),
        (string) $this->t('Processing: Aggregates distinct members per interest, returns the top ten values, and respects the configured minimum count. Unknowns display as "Not provided".'),
        (string) $this->t('Definitions: Only published accounts with a default profile and status = 1 are considered.'),
      ],
    );
  }

}
