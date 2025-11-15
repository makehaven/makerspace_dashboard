<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Infrastructure;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\UtilizationWindowService;

/**
 * Monthly member entries aggregation.
 */
class InfrastructureMonthlyEntriesChartBuilder extends InfrastructureUtilizationChartBuilderBase {

  protected const SECTION_ID = 'infrastructure';
  protected const CHART_ID = 'daily_unique_entries';
  protected const WEIGHT = 20;

  public function __construct(UtilizationWindowService $windowService, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($windowService, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $metrics = $this->getMetrics();
    $labels = $metrics['monthly_labels'] ?? [];
    $data = $metrics['monthly_data'] ?? [];
    if (!$labels || !array_filter($data)) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Unique entries'),
          'data' => array_map('intval', $data),
          'backgroundColor' => '#2563eb',
        ]],
      ],
      'options' => [
        'scales' => [
          'x' => [
            'ticks' => [
              'autoSkip' => FALSE,
              'maxRotation' => 0,
              'minRotation' => 0,
              'padding' => 8,
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Monthly Member Entries (Unique Visits)'),
      (string) $this->t('Sums the number of unique members badging in per day into monthly totals.'),
      $visualization,
      [
        (string) $this->t('Source: Access-control request logs joined to the requesting user.'),
        (string) $this->t('Processing: Counts distinct members per day within the configured window, then aggregates into monthly totals (latest 12 months).'),
        (string) $this->t('Definitions: Only users with active membership roles are included.'),
      ],
    );
  }

}
