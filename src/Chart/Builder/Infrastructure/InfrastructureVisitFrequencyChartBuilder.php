<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Infrastructure;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\UtilizationWindowService;

/**
 * Visit frequency distribution chart.
 */
class InfrastructureVisitFrequencyChartBuilder extends InfrastructureUtilizationChartBuilderBase {

  protected const SECTION_ID = 'infrastructure';
  protected const CHART_ID = 'frequency_buckets';
  protected const WEIGHT = 40;

  public function __construct(UtilizationWindowService $windowService, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($windowService, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $metrics = $this->getMetrics();
    $buckets = $metrics['frequency_buckets'] ?? [];
    if (!$buckets) {
      return NULL;
    }

    $bucketLabels = [
      'no_visits' => $this->t('No visits'),
      'one_per_month' => $this->t('1 visit / month'),
      'two_to_three' => $this->t('2-3 visits / month'),
      'weekly' => $this->t('Weekly (4-6)'),
      'twice_weekly' => $this->t('2-3 per week'),
      'daily_plus' => $this->t('Daily or more'),
    ];
    $labels = [];
    $values = [];
    foreach ($bucketLabels as $key => $label) {
      $labels[] = (string) $label;
      $values[] = (int) ($buckets[$key] ?? 0);
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Share of members'),
          'data' => $values,
          'backgroundColor' => '#0ea5e9',
        ]],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Visit Frequency Distribution'),
      (string) $this->t('Distribution of member visit frequency based on distinct check-in days over the last @days days.', ['@days' => $metrics['summary']['days'] ?? 0]),
      $visualization,
      [
        (string) $this->t('Source: Distinct visit days per member calculated from access-control logs over the rolling window.'),
        (string) $this->t('Processing: Normalizes visits to a 30-day window before bucketing (none, 1, 2-3, 4-6, 7-12, 13+ per month equivalent).'),
        (string) $this->t('Definitions: Each active member appears in exactly one bucket; members with no visits during the window are counted explicitly.'),
      ],
    );
  }

}
