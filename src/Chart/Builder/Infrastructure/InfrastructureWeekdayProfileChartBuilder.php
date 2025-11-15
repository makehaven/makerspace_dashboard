<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Infrastructure;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\UtilizationWindowService;

/**
 * Average unique members by day of week chart.
 */
class InfrastructureWeekdayProfileChartBuilder extends InfrastructureUtilizationChartBuilderBase {

  protected const SECTION_ID = 'infrastructure';
  protected const CHART_ID = 'weekday_profile';
  protected const WEIGHT = 50;

  public function __construct(UtilizationWindowService $windowService, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($windowService, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $metrics = $this->getMetrics();
    $labels = $metrics['weekday_labels'] ?? [];
    $averages = $metrics['weekday_averages'] ?? [];
    if (!$labels || !$averages) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => array_map('strval', $labels),
        'datasets' => [[
          'label' => (string) $this->t('Average members'),
          'data' => array_map('floatval', $averages),
          'backgroundColor' => '#4f46e5',
        ]],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Average Members by Day of Week'),
      (string) $this->t('Average unique members badging in on each weekday over the last @days days.', ['@days' => $metrics['summary']['days'] ?? 0]),
      $visualization,
      [
        (string) $this->t('Source: Same daily unique member dataset used for the rolling average chart.'),
        (string) $this->t('Processing: Totals unique members per weekday over the configured window and divides by the number of occurrences.'),
        (string) $this->t('Definitions: Values represent average unique members per weekday; blank weekdays indicate no data within the window.'),
      ],
    );
  }

}
