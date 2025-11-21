<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\ContactDataService;

/**
 * Shows how the contact list grows month over month.
 */
class OutreachContactGrowthChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'outreach';
  protected const CHART_ID = 'contact_growth';
  protected const WEIGHT = 15;

  public function __construct(
    protected ContactDataService $contactDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->contactDataService->getContactGrowthSeries(36);
    if (empty($series['labels'])) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $series['labels'],
        'datasets' => [
          [
            'label' => (string) $this->t('All contacts'),
            'data' => $series['totals'],
            'borderColor' => '#2563eb',
            'backgroundColor' => 'rgba(37,99,235,0.1)',
            'borderWidth' => 3,
            'fill' => TRUE,
            'tension' => 0.25,
          ],
          [
            'label' => (string) $this->t('Email-ready'),
            'data' => $series['email'],
            'borderColor' => '#16a34a',
            'backgroundColor' => 'rgba(22,163,74,0.15)',
            'borderWidth' => 3,
            'fill' => TRUE,
            'tension' => 0.25,
          ],
          [
            'label' => (string) $this->t('SMS-ready'),
            'data' => $series['sms'],
            'borderColor' => '#f97316',
            'backgroundColor' => 'rgba(249,115,22,0.15)',
            'borderWidth' => 3,
            'fill' => TRUE,
            'tension' => 0.25,
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'y' => [
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Unique contacts'),
            ],
          ],
        ],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
              ]),
            ],
          ],
        ],
      ],
    ];

    $current = $series['current'] ?? ['total' => 0, 'email' => 0, 'sms' => 0];
    $notes = [
      (string) $this->t('Current totals: @total contacts, @email emailable, @sms SMS-ready.', [
        '@total' => number_format($current['total'] ?? 0),
        '@email' => number_format($current['email'] ?? 0),
        '@sms' => number_format($current['sms'] ?? 0),
      ]),
      (string) $this->t('Source: civicrm_contact (primary email/phone status + opt-out flags). Chart shows cumulative totals per month.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Contact list growth'),
      (string) $this->t('Tracks how the overall contact list and opt-in channels have grown over the past 36 months.'),
      $visualization,
      $notes,
    );
  }

}
