<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\RetentionFlowDataService;

/**
 * Builds the recruitment by membership type chart.
 */
class RetentionRecruitmentByTypeChartBuilder extends RetentionFlowChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'type_incoming';
  protected const WEIGHT = 60;

  public function __construct(RetentionFlowDataService $flowDataService, DateFormatterInterface $dateFormatter, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($flowDataService, $dateFormatter, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $window = $this->getWindowData();
    $periodKeys = $window['period_keys'] ?? [];
    $incomingByType = $window['incoming_by_type'] ?? [];
    if (!$periodKeys || empty($incomingByType)) {
      return NULL;
    }

    $labels = $this->formatMonthLabels($periodKeys);
    $datasets = [];
    $colors = ['#2563eb', '#16a34a', '#f97316', '#a855f7', '#06b6d4', '#f43f5e', '#94a3b8'];
    $index = 0;
    foreach ($incomingByType as $type => $dataByPeriod) {
      $series = [];
      foreach ($periodKeys as $period) {
        $series[] = (int) ($dataByPeriod[$period] ?? 0);
      }
      $color = $colors[$index % count($colors)];
      $datasets[] = [
        'label' => $type ?: (string) $this->t('Unclassified'),
        'data' => $series,
        'backgroundColor' => $this->applyAlpha($color, 0.25),
        'borderColor' => $color,
        'type' => 'line',
        'fill' => 'origin',
        'tension' => 0.35,
        'pointRadius' => 0,
        'pointHoverRadius' => 4,
        'pointHitRadius' => 8,
        'borderWidth' => 2,
        'stack' => 'membership_types',
      ];
      $index++;
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
        'elements' => [
          'line' => ['fill' => TRUE],
          'point' => ['radius' => 0],
        ],
        'plugins' => [
          'legend' => ['position' => 'top'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'showLabel' => TRUE,
              ]),
            ],
          ],
        ],
        'scales' => [
          'x' => [
            'ticks' => [
              'autoSkip' => TRUE,
              'maxRotation' => 0,
              'minRotation' => 0,
              'maxTicksLimit' => 18,
            ],
          ],
          'y' => [
            'stacked' => TRUE,
            'beginAtZero' => TRUE,
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'integer',
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Recruitment by Membership Type'),
      (string) $this->t('Breakdown by membership type across the selected periods.'),
      $visualization,
      [
        (string) $this->t('Source: Same join-date dataset as the recruitment totals, segmented by membership type taxonomy terms.'),
        (string) $this->t('Processing: Counts distinct members per type per month based on the taxonomy term active at join time.'),
        (string) $this->t('Definitions: Type names come from taxonomy terms; unknown or missing terms appear as "Unclassified".'),
      ],
    );
  }

  /**
   * Applies an alpha channel to a hex color when supported.
   */
  protected function applyAlpha(string $hexColor, float $alpha): string {
    $hex = ltrim($hexColor, '#');
    if (strlen($hex) !== 6) {
      return $hexColor;
    }
    $alpha = max(0, min(1, $alpha));
    $alphaChannel = strtoupper(str_pad(dechex((int) round($alpha * 255)), 2, '0', STR_PAD_LEFT));
    return '#' . $hex . $alphaChannel;
  }

}
