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
  protected const WEIGHT = 10;
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
    $readings = $this->snapshotDataService->getActiveMemberReadings();
    if (!$readings) {
      return NULL;
    }

    // Offer only the ranges that show something different: the short ones,
    // plus the first one that already reaches back to the earliest reading.
    // Readings start in January 2026, so "2 years" and "All" would otherwise
    // draw exactly the same chart as "1 year".
    $rangeEnd = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0);
    $earliest = $readings[0]['captured'];
    $rangeOptions = [];
    foreach (self::RANGE_OPTIONS as $option) {
      $rangeOptions[] = $option;
      $optionStart = $this->calculateRangeBounds($option, $rangeEnd)['start'];
      if ($optionStart === NULL || $optionStart <= $earliest) {
        break;
      }
    }
    $default = in_array(self::RANGE_DEFAULT, $rangeOptions, TRUE) ? self::RANGE_DEFAULT : end($rangeOptions);
    $requested = $filters['ranges'][$this->getChartId()] ?? ($filters['range'] ?? NULL);
    if (is_string($requested) && in_array($requested, self::RANGE_OPTIONS, TRUE) && !in_array($requested, $rangeOptions, TRUE)) {
      // A longer range than the data covers shows all of it.
      $requested = end($rangeOptions);
      $filters['ranges'][$this->getChartId()] = $requested;
    }
    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), $default, $rangeOptions);
    $startDate = $this->calculateRangeBounds($activeRange, $rangeEnd)['start'];

    // No upper bound: the newest reading (taken on the 1st) is complete.
    $recent = array_values(array_filter($readings, static fn(array $row) => !$startDate || $row['captured'] >= $startDate));
    if (!$recent) {
      return NULL;
    }

    $labels = [];
    $values = [];
    foreach ($recent as $row) {
      $labels[] = $this->dateFormatter->format($row['captured']->getTimestamp(), 'custom', 'M j, Y');
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
      (string) $this->t('Active Members'),
      (string) $this->t('Members with an active (not paused) membership, counted on the date shown, usually the 1st of the month.'),
      $visualization,
      [
        (string) $this->t('Source: monthly membership snapshots (makerspace_snapshot membership_totals): enabled accounts with the Member role and no payment or manual pause, counted when the snapshot runs.'),
        (string) $this->t('Processing: Each point is placed on the day the count was taken; the latest count in a calendar month is kept. Hand-run backfills are left out because they copied the roster of the day they ran into past months.'),
        (string) $this->t('Coverage: Counts start January 2026 (taken January 22). Earlier months were never recorded, so longer ranges are not offered.'),
      ],
      [
        'active' => $activeRange,
        'options' => $this->getRangePresets($rangeOptions),
      ],
    );
  }

}
