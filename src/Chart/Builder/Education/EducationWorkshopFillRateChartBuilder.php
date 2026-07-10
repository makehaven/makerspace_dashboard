<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Charts the share of workshop seats filled each month.
 */
class EducationWorkshopFillRateChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'workshop_fill_rate';
  protected const WEIGHT = 30;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $monthStart = $this->now()->modify('first day of this month')->setTime(0, 0, 0);
    $start = $monthStart->modify('-12 months');
    $end = $monthStart->modify('-1 second');

    $series = $this->eventsMembershipDataService->getMonthlyWorkshopFillRateSeries($start, $end);
    $labels = array_map('strval', $series['labels'] ?? []);
    $fillRates = array_map('floatval', $series['fill_rates'] ?? []);
    $seatsFilled = array_map('intval', $series['seats_filled'] ?? []);
    $capacity = array_map('intval', $series['capacity'] ?? []);

    if (!$labels || !array_filter($capacity)) {
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
            'label' => (string) $this->t('Fill rate (%)'),
            'data' => $fillRates,
            'backgroundColor' => 'rgba(37,99,235,0.6)',
            'yAxisID' => 'yRate',
          ],
          [
            'type' => 'line',
            'label' => (string) $this->t('Seats filled'),
            'data' => $seatsFilled,
            'borderColor' => '#16a34a',
            'backgroundColor' => 'rgba(22,163,74,0.2)',
            'fill' => FALSE,
            'tension' => 0.25,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
            'yAxisID' => 'ySeats',
          ],
          [
            'type' => 'line',
            'label' => (string) $this->t('Total capacity'),
            'data' => $capacity,
            'borderColor' => '#9ca3af',
            'backgroundColor' => '#9ca3af',
            'borderDash' => [6, 4],
            'fill' => FALSE,
            'tension' => 0.25,
            'pointRadius' => 2,
            'pointHoverRadius' => 4,
            'yAxisID' => 'ySeats',
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'yRate' => [
            'position' => 'left',
            'min' => 0,
            'max' => 100,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Seats filled (%)'),
            ],
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
          ],
          'ySeats' => [
            'position' => 'right',
            'grid' => ['drawOnChartArea' => FALSE],
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Seats'),
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

    return $this->newDefinition(
      (string) $this->t('Workshop Fill Rate'),
      (string) $this->t('Percentage of available ticketed workshop seats filled each month over the trailing 12 full months.'),
      $visualization,
      [
        (string) $this->t('Source: CiviCRM events with event type "Ticketed Workshop", an explicit capacity (max participants > 0), and counted participant statuses.'),
        (string) $this->t('Processing: Fill rate = counted registrations ÷ total capacity across eligible events starting in the month; deactivated events are excluded.'),
        (string) $this->t('Definitions: Months without capacity-tracked workshops render as zero.'),
      ],
    );
  }

}
