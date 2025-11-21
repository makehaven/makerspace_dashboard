<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Entrepreneurship;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Visualizes new members selecting entrepreneurship goals.
 */
class EntrepreneurshipGoalTrendChartBuilder extends EntrepreneurshipChartBuilderBase {

  protected const CHART_ID = 'entrepreneur_goal_trend';
  protected const WEIGHT = 20;
  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['3m', '1y', '2y'];

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $range = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $bounds = $this->calculateRangeBounds($range, $this->now());
    $trend = $this->entrepreneurshipData->getEntrepreneurGoalTrend($bounds['start'], $bounds['end']);
    $labels = $trend['labels'] ?? [];
    $series = $trend['series'] ?? [];
    if (empty($labels) || empty($series)) {
      return NULL;
    }

    $goalSeries = [
      'inventor' => [
        'label' => $this->t('Develop a prototype/product'),
        'color' => '#6366f1',
      ],
      'seller' => [
        'label' => $this->t('Produce products/art to sell'),
        'color' => '#0ea5e9',
      ],
      'entrepreneur' => [
        'label' => $this->t('Business entrepreneurship'),
        'color' => '#ea580c',
      ],
    ];

    $datasets = [];
    $stackTotals = array_fill(0, count($labels), 0);
    foreach ($goalSeries as $key => $meta) {
      $rawCounts = array_map('intval', $series[$key] ?? array_fill(0, count($labels), 0));
      $stacked = [];
      foreach ($rawCounts as $index => $value) {
        $stackTotals[$index] += $value;
        $stacked[] = $stackTotals[$index];
      }

      $datasets[] = [
        'label' => (string) $meta['label'],
        'data' => $stacked,
        'makerspaceCounts' => $rawCounts,
        'borderColor' => $meta['color'],
        'backgroundColor' => $this->applyAlpha($meta['color'], 0.25),
        'fill' => count($datasets) === 0 ? 'origin' : '-1',
        'tension' => 0.35,
        'pointRadius' => 0,
        'stack' => 'entrepreneur_goals',
      ];
    }

    if (!$datasets) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => array_map([$this, 'formatQuarterLabel'], $labels),
        'datasets' => $datasets,
      ],
      'options' => [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['position' => 'top'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('dataset_members_count', []),
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'ticks' => ['precision' => 0],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('New Members with Entrepreneurship Goals'),
      (string) $this->t('Counts new members per month who selected entrepreneurship-related goals on their profile.'),
      $visualization,
      [
        (string) $this->t('Source: profile join dates combined with the multi-select member goal field.'),
        (string) $this->t('Processing: Members who chose the entrepreneurship goal are counted separately from other goal selections.'),
      ],
      $this->buildRangeMetadata($range, self::RANGE_OPTIONS),
    );
  }

  /**
   * Formats YYYY-MM-01 strings into readable month labels.
   */
  protected function formatQuarterLabel(string $quarterKey): string {
    if (!str_contains($quarterKey, '-Q')) {
      return $quarterKey;
    }
    [$year, $quarter] = explode('-Q', $quarterKey, 2);
    return $this->t('Q@quarter @year', ['@quarter' => $quarter, '@year' => $year]);
  }

  /**
   * Applies an alpha channel to a color.
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
