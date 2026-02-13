<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\AppointmentInsightsService;
use Drupal\makerspace_dashboard\Support\RangeSelectionTrait;

/**
 * Shows month-over-month appointment volume.
 */
class RetentionAppointmentVolumeChartBuilder extends ChartBuilderBase {

  use RangeSelectionTrait;

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'appointment_volume_monthly';
  protected const WEIGHT = 64;
  protected const TIER = 'supplemental';
  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['3m', '1y', '2y'];

  protected AppointmentInsightsService $appointmentInsights;

  /**
   * Constructs the builder.
   */
  public function __construct(AppointmentInsightsService $appointmentInsights, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->appointmentInsights = $appointmentInsights;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $bounds = $this->calculateRangeBounds($activeRange, new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())));
    if (!$bounds['start']) {
      $bounds['start'] = $bounds['end']->modify('-1 year');
    }

    $series = $this->appointmentInsights->getMonthlyAppointmentVolumeSeries($bounds['start'], $bounds['end']);
    $labels = $series['labels'] ?? [];
    $counts = $series['counts'] ?? [];
    if (empty($labels) || empty($counts)) {
      return NULL;
    }

    $trend = $this->buildTrendDataset($counts, (string) $this->t('Linear trend'), '#9ca3af');
    $datasets = [
      [
        'label' => (string) $this->t('Appointments'),
        'data' => array_values(array_map('intval', $counts)),
        'borderColor' => '#0284c7',
        'backgroundColor' => 'rgba(2,132,199,0.20)',
        'fill' => TRUE,
        'tension' => 0.25,
        'pointRadius' => 2,
        'borderWidth' => 2,
      ],
    ];
    if ($trend) {
      $datasets[] = $trend;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'y' => [
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Appointments'),
            ],
          ],
        ],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', ['format' => 'integer']),
            ],
          ],
        ],
      ],
    ];

    $totals = $series['totals'] ?? [];
    $notes = [
      (string) $this->t('Window: @start – @end', [
        '@start' => $bounds['start']->format('M j, Y'),
        '@end' => $bounds['end']->format('M j, Y'),
      ]),
      (string) $this->t('Source: appointment nodes, excluding records with canceled status.'),
      (string) $this->t('Processing: counts all qualifying appointments by month based on appointment date.'),
    ];
    if (!empty($totals['appointments'])) {
      $notes[] = (string) $this->t('Total appointments in window: @total (avg @avg per month).', [
        '@total' => number_format((int) $totals['appointments']),
        '@avg' => number_format((float) ($totals['monthly_average'] ?? 0), 1),
      ]);
    }

    return $this->newDefinition(
      (string) $this->t('Monthly Appointment Volume'),
      (string) $this->t('Trend in total member appointments over time.'),
      $visualization,
      $notes,
      [
        'active' => $activeRange,
        'options' => $this->getRangePresets(self::RANGE_OPTIONS),
      ],
    );
  }

}

