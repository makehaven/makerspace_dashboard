<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Infrastructure;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\UtilizationWindowService;

/**
 * Stacked chart showing first entry time by weekday.
 */
class InfrastructureFirstEntryChartBuilder extends InfrastructureUtilizationChartBuilderBase {

  protected const SECTION_ID = 'infrastructure';
  protected const CHART_ID = 'weekday_first_entry';
  protected const WEIGHT = 70;

  public function __construct(UtilizationWindowService $windowService, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($windowService, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $metrics = $this->getMetrics();
    $buckets = $metrics['first_entry_buckets'] ?? [];
    $timeOfDayLabels = $metrics['time_of_day_labels'] ?? [];
    if (!$buckets || !$timeOfDayLabels) {
      return NULL;
    }

    $weekdayOrder = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $labels = array_map(fn($label) => (string) $this->t($label), $weekdayOrder);
    $datasets = [];
    $colors = ['#0ea5e9', '#22c55e', '#f97316', '#a855f7', '#3b82f6', '#ef4444'];
    $index = 0;
    foreach (array_keys($timeOfDayLabels) as $bucketId) {
      $series = [];
      foreach (range(0, 6) as $weekday) {
        $series[] = (int) ($buckets[$weekday][$bucketId] ?? 0);
      }
      $datasets[] = [
        'label' => $timeOfDayLabels[$bucketId],
        'data' => $series,
        'backgroundColor' => $colors[$index % count($colors)],
        'stack' => 'timeofday',
      ];
      $index++;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'scales' => [
          'x' => ['stacked' => TRUE],
          'y' => [
            'stacked' => TRUE,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Members (first entry)'),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('First Entry Time by Weekday (Stacked)'),
      (string) $this->t('Shows when members first badge in each day, grouped by weekday and time-of-day bucket.'),
      $visualization,
      [
        (string) $this->t('Source: Access-control requests within the rolling window grouped by member, day, and time of day.'),
        (string) $this->t('Processing: Members are counted once per weekday/time bucket (early morning, morning, midday, afternoon, evening, night).'),
        (string) $this->t('Definitions: Night spans 22:00-04:59 and rolls across midnight.'),
      ],
    );
  }

}
