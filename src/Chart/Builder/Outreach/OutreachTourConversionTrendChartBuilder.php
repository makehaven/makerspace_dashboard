<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FunnelDataService;

/**
 * Charts the monthly trend of tours and 90-day membership conversions.
 */
class OutreachTourConversionTrendChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'outreach';
  protected const CHART_ID = 'tour_conversion_trend';
  protected const WEIGHT = 19;

  /**
   * Days after a tour within which a join counts as a conversion.
   */
  protected const CONVERSION_WINDOW_DAYS = 90;

  public function __construct(
    protected FunnelDataService $funnelDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->funnelDataService->getTourMonthlyConversionSeries(12, self::CONVERSION_WINDOW_DAYS);
    $labels = array_map('strval', $series['labels'] ?? []);
    $tours = array_map('intval', $series['tours'] ?? []);
    $rates = array_map('floatval', $series['rates'] ?? []);

    if (!$labels || !array_filter($tours)) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'type' => 'bar',
            'label' => (string) $this->t('Tour contacts'),
            'data' => $tours,
            'backgroundColor' => 'rgba(59,130,246,0.6)',
            'yAxisID' => 'yTours',
          ],
          [
            'type' => 'line',
            'label' => (string) $this->t('Joined within @days days (%)', ['@days' => self::CONVERSION_WINDOW_DAYS]),
            'data' => $rates,
            'borderColor' => '#16a34a',
            'backgroundColor' => 'rgba(22,163,74,0.2)',
            'fill' => FALSE,
            'tension' => 0.25,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
            'yAxisID' => 'yRate',
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'yTours' => [
            'position' => 'left',
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Tour contacts'),
            ],
          ],
          'yRate' => [
            'position' => 'right',
            'min' => 0,
            'grid' => ['drawOnChartArea' => FALSE],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Conversion rate (%)'),
            ],
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'mode' => 'index',
            'intersect' => FALSE,
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'perAxis' => [
                  'yRate' => [
                    'format' => 'percent',
                    'decimals' => 1,
                  ],
                ],
              ]),
            ],
          ],
        ],
      ],
    ];

    $notes = $this->buildRangeNotes($series['range'] ?? NULL);
    $notes[] = (string) $this->t('Source: Tour event participants (counted statuses) unioned with Tour activity targets in CiviCRM, plus Drupal member join dates.');
    $notes[] = (string) $this->t('Processing: Each contact counts once per month using their earliest tour date; conversions are joins within @days days of that tour. Contacts who were already members are excluded.', ['@days' => self::CONVERSION_WINDOW_DAYS]);
    $notes[] = (string) $this->t('Definitions: Recent months may understate conversions until the full @days-day window has elapsed.', ['@days' => self::CONVERSION_WINDOW_DAYS]);

    return $this->newDefinition(
      (string) $this->t('Tour Conversion Trend'),
      (string) $this->t('Month-by-month view of how many contacts toured and what share became members within @days days.', ['@days' => self::CONVERSION_WINDOW_DAYS]),
      $visualization,
      $notes,
    );
  }

  /**
   * Formats range metadata for chart notes.
   */
  protected function buildRangeNotes(?array $range): array {
    if (empty($range['start']) || empty($range['end'])) {
      return [];
    }
    if ($range['start'] instanceof \DateTimeInterface && $range['end'] instanceof \DateTimeInterface) {
      return [
        (string) $this->t('Window: @start – @end', [
          '@start' => $range['start']->format('M Y'),
          '@end' => $range['end']->format('M Y'),
        ]),
      ];
    }
    return [];
  }

}
