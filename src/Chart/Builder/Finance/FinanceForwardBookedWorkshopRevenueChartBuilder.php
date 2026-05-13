<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Forward-booked workshop revenue: registrations × fee, by horizon and event type.
 */
class FinanceForwardBookedWorkshopRevenueChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'forward_booked_workshop_revenue';
  protected const WEIGHT = 50;

  public function __construct(
    protected FinancialDataService $financialDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $trend = $this->financialDataService->getForwardBookedWorkshopRevenue([30, 60, 90]);
    if (empty($trend['labels']) || empty($trend['matrix'])) {
      return NULL;
    }
    if ($trend['total'] <= 0) {
      return NULL;
    }

    $palette = $this->defaultColorPalette();
    $datasets = [];
    $index = 0;
    foreach ($trend['matrix'] as $type => $values) {
      $color = $palette[$index % count($palette)];
      $datasets[] = [
        'label' => (string) $this->t($type),
        'data' => $values,
        'backgroundColor' => $color,
        'borderColor' => $color,
        'stack' => 'forward_revenue',
      ];
      $index++;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $trend['labels'],
        'datasets' => $datasets,
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'x' => ['stacked' => TRUE],
          'y' => [
            'stacked' => TRUE,
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
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Forward-booked workshop revenue (next 90d)'),
      (string) $this->t('Sum of registration fees for upcoming events in the next 30 / 31–60 / 61–90 days, broken out by event type. A leading indicator of education income before it lands in the books.'),
      $visualization,
      [
        (string) $this->t('Source: civicrm_participant joined to civicrm_event; only counts registrations whose status type is_counted = 1 (registered/attended).'),
        (string) $this->t('Processing: Buckets by days-until-start_date. Total forward-booked: $@total.', [
          '@total' => number_format($trend['total'], 0),
        ]),
        (string) $this->t('Caveat: Free events with $0 fee_amount are excluded. Cancellations after registration drop out automatically (status changes flip is_counted = 0).'),
      ],
    );
  }

}
