<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Overview;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\KpiDataService;
use Drupal\makerspace_dashboard\Support\RangeSelectionTrait;

/**
 * Builds the reserve funds coverage chart.
 */
class OverviewReserveFundsChartBuilder extends ChartBuilderBase {

  use RangeSelectionTrait;

  protected const SECTION_ID = 'overview';
  protected const CHART_ID = 'reserve_funds_months';
  protected const WEIGHT = 40;
  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['3m', '1y', '2y', 'all'];

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected KpiDataService $kpiDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->kpiDataService->getReserveFundsMonthlySeries();
    $items = $series['items'] ?? [];
    if (!$items) {
      return NULL;
    }

    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $rangeEnd = new \DateTimeImmutable('first day of this month');
    $bounds = $this->calculateRangeBounds($activeRange, $rangeEnd);
    $startDate = $bounds['start'];
    $endDate = $bounds['end'];

    $filteredItems = array_filter($items, static function (array $item) use ($startDate, $endDate) {
      $date = $item['date'] ?? NULL;
      if (!$date instanceof \DateTimeImmutable) {
        return FALSE;
      }
      if ($startDate && $date < $startDate) {
        return FALSE;
      }
      if ($date >= $endDate) {
        return FALSE;
      }
      return TRUE;
    });

    if (!$filteredItems) {
      return NULL;
    }

    $labels = [];
    $values = [];
    foreach ($filteredItems as $item) {
      $labels[] = (string) ($item['label'] ?? '');
      $values[] = round((float) ($item['months'] ?? 0), 2);
    }

    if (!$labels || !array_filter($values)) {
      return NULL;
    }

    $datasets = [[
      'label' => (string) $this->t('Months of coverage'),
      'data' => $values,
      'borderColor' => '#15803d',
      'backgroundColor' => 'rgba(21, 128, 61, 0.15)',
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
        'elements' => [
          'line' => ['tension' => 0.25],
        ],
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
                'format' => 'decimal',
                'decimals' => 1,
                'suffix' => (string) $this->t('months'),
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
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Months of operating expense'),
            ],
            'ticks' => [
              'display' => TRUE,
              'padding' => 6,
              'color' => '#475569',
              'callback' => $this->chartCallback('value_format', [
                'format' => 'decimal',
                'decimals' => 1,
                'suffix' => (string) $this->t('mo'),
                'showLabel' => FALSE,
              ]),
            ],
            'grid' => [
              'color' => 'rgba(148, 163, 184, 0.25)',
            ],
            'min' => 0,
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Source: Balance-Sheet tab ("Cash and Cash Equivalents") and Income-Statement tab ("Total Expense").'),
      (string) $this->t('Processing: Converts the latest cash balance into months of runway using the trailing four-quarter average monthly expense.'),
    ];
    if (!empty($series['last_updated'])) {
      $notes[] = (string) $this->t('Last sheet update: @date', ['@date' => $series['last_updated']]);
    }

    return $this->newDefinition(
      (string) $this->t('Reserve Funds Coverage'),
      (string) $this->t('Tracks how many months of operating expense current cash reserves can sustain, showing trends over time.'),
      $visualization,
      $notes,
      [
        'active' => $activeRange,
        'options' => $this->getRangePresets(self::RANGE_OPTIONS),
      ],
    );
  }

}
