<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Overview;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;
use Drupal\makerspace_dashboard\Support\RangeSelectionTrait;

/**
 * Builds the Overview MRR Trend chart.
 */
class OverviewMrrTrendChartBuilder extends ChartBuilderBase {

  use RangeSelectionTrait;

  protected const SECTION_ID = 'overview';
  protected const CHART_ID = 'mrr_trend';
  protected const WEIGHT = 10;
  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['3m', '1y', '2y', 'all'];

  /**
   * Constructs the builder.
   */
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
    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $rangeEnd = new \DateTimeImmutable('first day of this month');
    $bounds = $this->calculateRangeBounds($activeRange, $rangeEnd);
    $startDate = $bounds['start'] ?? $rangeEnd->modify('-20 years');
    $endDate = $bounds['end']->modify('-1 day');

    if ($startDate > $endDate) {
      return NULL;
    }

    $trend = $this->financialDataService->getMrrTrend($startDate, $endDate);
    $values = array_map('floatval', $trend['data'] ?? []);
    if (empty(array_filter($values))) {
      return NULL;
    }

    $labels = array_map('strval', $trend['labels'] ?? []);
    $datasets = [[
      'label' => (string) $this->t('MRR ($)'),
      'data' => $values,
      'borderColor' => '#1d4ed8',
      'backgroundColor' => 'rgba(37, 99, 235, 0.15)',
      'pointRadius' => 3,
      'pointBackgroundColor' => '#1d4ed8',
      'fill' => FALSE,
    ]];

    if ($trendDataset = $this->buildTrendDataset($values, (string) $this->t('Trend'))) {
      $datasets[] = $trendDataset;
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
        'interaction' => [
          'mode' => 'index',
          'intersect' => FALSE,
        ],
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'mode' => 'index',
            'intersect' => FALSE,
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
              ]),
            ],
          ],
        ],
        'hover' => [
          'mode' => 'index',
          'intersect' => FALSE,
        ],
        'scales' => [
          'y' => [
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
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Monthly Recurring Revenue Trend'),
      (string) $this->t('Aggregate by billing source to highlight sustainability of recruitment/retention efforts.'),
      $visualization,
      [
        (string) $this->t('Source: Member join dates paired with membership type taxonomy terms.'),
        (string) $this->t('Processing: Includes joins within the selected time window and multiplies counts by assumed monthly values.'),
      ],
      [
        'active' => $activeRange,
        'options' => $this->getRangePresets(self::RANGE_OPTIONS),
      ],
    );
  }

}
