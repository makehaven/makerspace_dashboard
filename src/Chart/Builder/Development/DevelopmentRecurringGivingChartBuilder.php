<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Development;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\DevelopmentDataService;

/**
 * Visualizes recurring vs one-time giving trends.
 */
class DevelopmentRecurringGivingChartBuilder extends DevelopmentChartBuilderBase {

  protected const CHART_ID = 'recurring_vs_onetime';
  protected const WEIGHT = 20;

  public function __construct(
    protected DevelopmentDataService $developmentDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->developmentDataService->getRecurringVsOnetimeSeries(12);
    if (empty($series['labels'])) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $series['labels'],
        'datasets' => [
          [
            'type' => 'bar',
            'label' => (string) $this->t('Recurring $'),
            'data' => $series['recurring']['amounts'],
            'backgroundColor' => 'rgba(34,197,94,0.6)',
            'stack' => 'amount',
            'yAxisID' => 'yAmount',
          ],
          [
            'type' => 'bar',
            'label' => (string) $this->t('One-time $'),
            'data' => $series['one_time']['amounts'],
            'backgroundColor' => 'rgba(248,113,113,0.65)',
            'stack' => 'amount',
            'yAxisID' => 'yAmount',
          ],
          [
            'type' => 'line',
            'label' => (string) $this->t('Recurring donors'),
            'data' => $series['recurring']['donors'],
            'borderColor' => '#047857',
            'backgroundColor' => 'rgba(4,120,87,0.15)',
            'fill' => FALSE,
            'tension' => 0.25,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
            'yAxisID' => 'yDonors',
          ],
          [
            'type' => 'line',
            'label' => (string) $this->t('One-time donors'),
            'data' => $series['one_time']['donors'],
            'borderColor' => '#ea580c',
            'backgroundColor' => 'rgba(234,88,12,0.15)',
            'fill' => FALSE,
            'tension' => 0.25,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
            'yAxisID' => 'yDonors',
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'yAmount' => [
            'position' => 'left',
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Raised ($)'),
            ],
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
          ],
          'yDonors' => [
            'position' => 'right',
            'grid' => ['drawOnChartArea' => FALSE],
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Unique donors'),
            ],
          ],
        ],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
                'perAxis' => [
                  'yDonors' => [
                    'format' => 'integer',
                    'suffix' => ' ' . (string) $this->t('donors'),
                  ],
                ],
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Recurring vs one-time giving'),
      (string) $this->t('Highlights how recurring giving compares to one-time gifts over the past 12 months.'),
      $visualization,
      [
        (string) $this->t('Source: CiviCRM contributions marked Complete; recurring is determined by a linked recurring contribution ID.'),
        (string) $this->t('Processing: Bars show dollars raised per month while lines track the donor counts for each stream.'),
      ],
    );
  }

}
