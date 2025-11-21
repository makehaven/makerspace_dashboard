<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Development;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\DevelopmentDataService;

/**
 * Trends member vs non-member donor activity.
 */
class DevelopmentMemberDonorTrendChartBuilder extends DevelopmentChartBuilderBase {

  protected const CHART_ID = 'member_donor_trend';
  protected const WEIGHT = 10;

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
    $series = $this->developmentDataService->getMemberDonorTrend(12);
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
            'label' => (string) $this->t('Member donors'),
            'data' => $series['member']['donors'],
            'backgroundColor' => 'rgba(59,130,246,0.6)',
            'yAxisID' => 'yDonors',
            'stack' => 'donors',
          ],
          [
            'type' => 'bar',
            'label' => (string) $this->t('Non-member donors'),
            'data' => $series['non_member']['donors'],
            'backgroundColor' => 'rgba(148,163,184,0.7)',
            'yAxisID' => 'yDonors',
            'stack' => 'donors',
          ],
          [
            'type' => 'line',
            'label' => (string) $this->t('Member giving ($)'),
            'data' => $series['member']['amounts'],
            'borderColor' => '#16a34a',
            'backgroundColor' => 'rgba(22,163,74,0.2)',
            'fill' => FALSE,
            'tension' => 0.25,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
            'yAxisID' => 'yAmount',
          ],
          [
            'type' => 'line',
            'label' => (string) $this->t('Non-member giving ($)'),
            'data' => $series['non_member']['amounts'],
            'borderColor' => '#f97316',
            'backgroundColor' => 'rgba(249,115,22,0.2)',
            'fill' => FALSE,
            'tension' => 0.25,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
            'yAxisID' => 'yAmount',
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'yDonors' => [
            'position' => 'left',
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Unique donors'),
            ],
          ],
          'yAmount' => [
            'position' => 'right',
            'grid' => ['drawOnChartArea' => FALSE],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Total raised ($)'),
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
        ],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'perAxis' => [
                  'yAmount' => [
                    'format' => 'currency',
                    'currency' => 'USD',
                    'decimals' => 0,
                  ],
                ],
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Member vs non-member donors'),
      (string) $this->t('Twelve-month trend of how many donors are members versus the dollars they contribute.'),
      $visualization,
      [
        (string) $this->t('Source: CiviCRM contributions (completed, non-test). Members are determined by active membership records on the contribution date.'),
        (string) $this->t('Processing: Donor counts represent unique contacts per month; amount lines show total dollars from each segment.'),
      ],
    );
  }

}
