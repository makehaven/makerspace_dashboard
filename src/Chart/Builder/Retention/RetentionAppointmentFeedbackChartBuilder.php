<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\AppointmentInsightsService;
use Drupal\makerspace_dashboard\Support\RangeSelectionTrait;

/**
 * Visualizes appointment feedback completion and outcomes over time.
 */
class RetentionAppointmentFeedbackChartBuilder extends ChartBuilderBase {

  use RangeSelectionTrait;

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'appointment_feedback_outcomes';
  protected const WEIGHT = 66;
  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['3m', '1y', '2y'];

  protected AppointmentInsightsService $appointmentInsights;

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

    $series = $this->appointmentInsights->getFeedbackOutcomeSeries($bounds['start'], $bounds['end']);
    if (empty($series)) {
      return NULL;
    }

    $labels = $series['labels'] ?? [];
    if (empty($labels)) {
      return NULL;
    }

    $resultKeys = $this->appointmentInsights->getResultKeys();
    $resultLabels = $this->getResultLabels();
    $palette = $this->defaultColorPalette();
    $datasets = [];
    foreach ($resultKeys as $index => $resultKey) {
      $datasets[] = [
        'type' => 'bar',
        'label' => $resultLabels[$resultKey] ?? $resultKey,
        'data' => $series['results'][$resultKey] ?? array_fill(0, count($labels), 0),
        'backgroundColor' => $palette[$index % count($palette)],
        'stack' => 'result',
      ];
    }

    $datasets[] = [
      'type' => 'line',
      'label' => (string) $this->t('Feedback completion %'),
      'data' => $series['feedback_rates'] ?? array_fill(0, count($labels), 0),
      'borderColor' => '#0ea5e9',
      'backgroundColor' => 'rgba(14,165,233,0.15)',
      'fill' => FALSE,
      'tension' => 0.25,
      'pointRadius' => 3,
      'pointHoverRadius' => 5,
      'yAxisID' => 'yRate',
    ];

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'y' => [
            'stacked' => TRUE,
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Appointments'),
            ],
          ],
          'yRate' => [
            'position' => 'right',
            'grid' => ['drawOnChartArea' => FALSE],
            'min' => 0,
            'max' => 100,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Feedback completion %'),
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

    $rangeMetadata = [
      'active' => $activeRange,
      'options' => $this->getRangePresets(self::RANGE_OPTIONS),
    ];
    $notes = [
      (string) $this->t('Window: @start – @end', [
        '@start' => $bounds['start']->format('M j, Y'),
        '@end' => $bounds['end']->format('M j, Y'),
      ]),
      (string) $this->t('Source: appointment nodes (excluding canceled) with facilitator-entered results and optional feedback fields.'),
      (string) $this->t('Processing: Shows stacked appointment outcomes per month and overlays how many of those visits received written feedback.'),
    ];
    if (!empty($series['totals'])) {
      $notes[] = (string) $this->t('Overall feedback rate: @rate% (@feedback/@appointments appointments)', [
        '@rate' => $series['totals']['rate'],
        '@feedback' => number_format($series['totals']['feedback']),
        '@appointments' => number_format($series['totals']['appointments']),
      ]);
    }

    return $this->newDefinition(
      (string) $this->t('Appointment Outcomes & Feedback'),
      (string) $this->t('Track facilitator outcomes and how often members leave feedback on appointments.'),
      $visualization,
      $notes,
      $rangeMetadata,
    );
  }

  /**
   * Maps result machine names to human-readable labels.
   */
  protected function getResultLabels(): array {
    return [
      'met_successful' => (string) $this->t('Success'),
      'met_unsuccesful' => (string) $this->t('Problems'),
      'member_absent' => (string) $this->t('Member missed'),
      'volunteer_absent' => (string) $this->t('Volunteer missed'),
      '_none' => (string) $this->t('Unreported'),
    ];
  }

}
