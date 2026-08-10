<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FunnelDataService;

/**
 * Charts the monthly trend of event participants and 90-day conversions.
 *
 * The event counterpart to the tour conversion trend. Events are the largest
 * top-of-funnel touchpoint, but until this chart existed the only thing the
 * dashboard said about them was a single rolling percentage in the KPI table,
 * which cannot show whether conversion is improving or decaying.
 */
class OutreachEventConversionTrendChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'outreach';
  protected const CHART_ID = 'event_conversion_trend';
  protected const WEIGHT = 6;

  /**
   * Days after an event within which a join counts as a conversion.
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
    $series = $this->funnelDataService->getEventMonthlyConversionSeries(12, self::CONVERSION_WINDOW_DAYS);
    $labels = array_map('strval', $series['labels'] ?? []);
    $participants = array_map('intval', $series['participants'] ?? []);
    $rates = array_map('floatval', $series['rates'] ?? []);

    if (!$labels || !array_filter($participants)) {
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
            'label' => (string) $this->t('Event participants (non-members)'),
            'data' => $participants,
            'backgroundColor' => 'rgba(147,51,234,0.55)',
            'yAxisID' => 'yParticipants',
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
          'yParticipants' => [
            'position' => 'left',
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Event participants'),
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
    $notes[] = (string) $this->t('Source: CiviCRM event participants in counted statuses (test registrations and template events excluded), plus Drupal member join dates.');
    $notes[] = (string) $this->t('Processing: Each contact counts once per month using their earliest event that month; conversions are joins within @days days of that event. Contacts who were already members are excluded from the bars.', ['@days' => self::CONVERSION_WINDOW_DAYS]);
    $notes[] = (string) $this->t('Definitions: All event types are included, tours among them. Recent months understate conversions until the full @days-day window has elapsed.', ['@days' => self::CONVERSION_WINDOW_DAYS]);

    return $this->newDefinition(
      (string) $this->t('Event Conversion Trend'),
      (string) $this->t('Month-by-month view of how many non-members attended an event and what share became members within @days days.', ['@days' => self::CONVERSION_WINDOW_DAYS]),
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
