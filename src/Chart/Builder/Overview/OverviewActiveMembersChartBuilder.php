<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Overview;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;
use Drupal\makerspace_dashboard\Support\RangeSelectionTrait;

/**
 * Builds the active members monthly snapshot chart.
 */
class OverviewActiveMembersChartBuilder extends ChartBuilderBase {

  use RangeSelectionTrait;

  protected const SECTION_ID = 'overview';
  protected const CHART_ID = 'snapshot_monthly';
  protected const WEIGHT = 20;
  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['3m', '1y', '2y', 'all'];

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected SnapshotDataService $snapshotDataService,
    protected DateFormatterInterface $dateFormatter,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->snapshotDataService->getMembershipCountSeries('month');
    if (!$series) {
      return NULL;
    }

    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $rangeEnd = new \DateTimeImmutable('first day of this month');
    $bounds = $this->calculateRangeBounds($activeRange, $rangeEnd);
    $startDate = $bounds['start'];
    $endDate = $bounds['end'];

    $filtered = array_filter($series, static function (array $row) use ($startDate, $endDate) {
      $periodDate = $row['period_date'] ?? NULL;
      if (!$periodDate instanceof \DateTimeImmutable) {
        return FALSE;
      }
      if ($startDate && $periodDate < $startDate) {
        return FALSE;
      }
      if ($periodDate >= $endDate) {
        return FALSE;
      }
      return TRUE;
    });

    $recent = array_values($filtered);
    if (!$recent) {
      return NULL;
    }

    $labels = [];
    $values = [];
    foreach ($recent as $row) {
      $labels[] = $this->dateFormatter->format($row['period_date']->getTimestamp(), 'custom', 'M Y');
      $values[] = (int) $row['members_active'];
    }

    if (!array_filter($values)) {
      return NULL;
    }

    $datasets = [[
      'label' => (string) $this->t('Active members'),
      'data' => $values,
      'borderColor' => '#2563eb',
      'backgroundColor' => 'rgba(37, 99, 235, 0.15)',
      'pointRadius' => 3,
      'pointBackgroundColor' => '#2563eb',
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
                'format' => 'integer',
                'suffix' => (string) $this->t('members'),
              ]),
            ],
          ],
        ],
        'hover' => [
          'mode' => 'index',
          'intersect' => FALSE,
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Active Members (Monthly Snapshot)'),
      (string) $this->t('Collapses snapshots to one point per month using the latest capture inside each month.'),
      $visualization,
      [
        (string) $this->t('Source: makerspace_snapshot membership_totals snapshots.'),
        (string) $this->t('Processing: Groups snapshots by calendar month and keeps the most recent capture in each month.'),
      ],
      [
        'active' => $activeRange,
        'options' => $this->getRangePresets(self::RANGE_OPTIONS),
      ],
    );
  }

}
