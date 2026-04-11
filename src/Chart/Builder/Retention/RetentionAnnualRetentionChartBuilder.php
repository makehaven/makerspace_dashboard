<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Annualized retention line chart.
 */
class RetentionAnnualRetentionChartBuilder extends RetentionCohortChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'annual_retention';
  protected const WEIGHT = 4;

  public function __construct(MembershipMetricsService $membershipMetrics, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($membershipMetrics, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $cohorts = $this->getCohorts();
    if (!$cohorts) {
      return NULL;
    }

    // Exclude the current year: cohorts haven't completed a full year yet, so
    // the geometric-mean formula degenerates to raw retention % (exponent = 1)
    // and reads artificially high for recently joined members.
    $currentYear = (int) date('Y');
    $labels = [];
    $annualized = [];
    foreach ($cohorts as $row) {
      if ((int) $row['year'] >= $currentYear) {
        continue;
      }
      $labels[] = (string) $row['year'];
      $annualized[] = round((float) $row['annualized_retention_percent'], 2);
    }
    if (!array_sum($annualized)) {
      return NULL;
    }

    // 3-year centered rolling average — single line, raw values in tooltip.
    // Showing two overlapping lines amplifies visual noise; one smooth line
    // is cleaner.  Proper centered window: use the neighbour on each side
    // where available, so edges get a 2-point average rather than being skipped.
    $count = count($annualized);
    $rolling = [];
    for ($i = 0; $i < $count; $i++) {
      $window = [];
      if ($i > 0) {
        $window[] = $annualized[$i - 1];
      }
      $window[] = $annualized[$i];
      if ($i < $count - 1) {
        $window[] = $annualized[$i + 1];
      }
      $rolling[] = round(array_sum($window) / count($window), 2);
    }

    // Build tooltip labels that show both the smoothed value and the raw cohort
    // value so users can still see the underlying data on hover.
    $tooltipRaw = array_map(static fn($v) => round($v, 1), $annualized);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('3-yr rolling avg'),
            'data' => $rolling,
            'borderColor' => '#0f172a',
            'backgroundColor' => 'rgba(15,23,42,0.08)',
            'fill' => TRUE,
            'tension' => 0.35,
            'borderWidth' => 2,
            'pointRadius' => 3,
            'pointHoverRadius' => 6,
          ],
          [
            'label' => (string) $this->t('Cohort rate'),
            'data' => $tooltipRaw,
            'borderColor' => 'transparent',
            'backgroundColor' => 'transparent',
            'pointRadius' => 0,
            'pointHoverRadius' => 0,
            'fill' => FALSE,
            'borderWidth' => 0,
          ],
        ],
      ],
      'options' => [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'percent',
                'decimals' => 1,
              ]),
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Annualized Retention Rate by Cohort'),
      (string) $this->t('Annualized retention rate estimating the average share of members retained each year since joining.'),
      $visualization,
      [
        (string) $this->t('Source: Same cohort dataset as the composition chart, using join dates from profile__field_member_join_date.'),
        (string) $this->t('Processing: Geometric mean of active/total per cohort, normalised for cohort age. Line shows 3-year centered rolling average; hover for per-cohort value.'),
        (string) $this->t('Definitions: Active roles default to current_member/member; cohorts without active members report 0% annualized retention.'),
      ],
    );
  }

}
