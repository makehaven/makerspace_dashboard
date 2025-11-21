<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Operations;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\ActivityDataService;

/**
 * Stacked monthly chart of CiviCRM activities by type.
 */
class OperationsActivityTypeChartBuilder extends OperationsChartBuilderBase {

  protected const CHART_ID = 'activity_types';
  protected const WEIGHT = 40;

  public function __construct(
    protected ActivityDataService $activityDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->activityDataService->getMonthlyActivityTypeCounts(12, 6);
    if (empty($series['labels']) || empty($series['datasets'])) {
      return NULL;
    }

    $palette = $this->defaultColorPalette();
    $datasets = [];
    foreach ($series['datasets'] as $index => $dataset) {
      $datasets[] = [
        'label' => (string) $this->t($dataset['label']),
        'data' => $dataset['data'],
        'backgroundColor' => $palette[$index % count($palette)],
        'stack' => 'activity_types',
      ];
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $series['labels'],
        'datasets' => $datasets,
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'x' => [
            'stacked' => TRUE,
          ],
          'y' => [
            'stacked' => TRUE,
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Activities logged'),
            ],
          ],
        ],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'suffix' => ' ' . (string) $this->t('activities'),
              ]),
            ],
          ],
        ],
      ],
    ];

    $notes = [];
    if (!empty($series['range'])) {
      $start = $series['range']['start'] ?? NULL;
      $end = $series['range']['end'] ?? NULL;
      if ($start instanceof \DateTimeInterface && $end instanceof \DateTimeInterface) {
        $notes[] = (string) $this->t('Range: @start – @end', [
          '@start' => $start->format('M Y'),
          '@end' => $end->format('M Y'),
        ]);
      }
    }
    $notes[] = (string) $this->t('Source: civicrm_activity (status = completed, not deleted).');
    $notes[] = (string) $this->t('Processing: Shows the top activity types over the past 12 months; additional types are grouped into "Other activity types".');

    return $this->newDefinition(
      (string) $this->t('Activities by Type'),
      (string) $this->t('Stacked totals for the most common activity types (e.g., tours, orientations) over the past year.'),
      $visualization,
      $notes,
    );
  }

}
