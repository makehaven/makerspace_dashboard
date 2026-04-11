<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MemberSuccessDataService;

/**
 * 28-day badge activation rate trend from member success snapshots.
 *
 * For each month, shows what share of members in their 28–58 day window
 * had earned at least one badge — the primary activation milestone.  A
 * rising trend indicates the onboarding pipeline is working; a drop is
 * an early warning that new members are not engaging.
 */
class RetentionActivationCohortChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'activation_cohort_trend';
  protected const WEIGHT = 16;
  protected const TIER = 'supplemental';

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected MemberSuccessDataService $memberSuccessData,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    if (!$this->memberSuccessData->isAvailable()) {
      return NULL;
    }

    $series = $this->memberSuccessData->getMonthlyActivationSeries(18, 28, 30);
    if (empty($series)) {
      return NULL;
    }

    $labels = [];
    $ratios = [];
    $cohortTotals = [];
    $activatedTotals = [];

    foreach ($series as $row) {
      if (empty($row['snapshot_date']) || !$row['snapshot_date'] instanceof \DateTimeImmutable) {
        continue;
      }
      $labels[] = $row['snapshot_date']->format('M Y');
      $ratios[] = $row['ratio'] !== NULL ? round((float) $row['ratio'] * 100, 1) : NULL;
      $cohortTotals[] = (int) $row['cohort_total'];
      $activatedTotals[] = (int) $row['activated_total'];
    }

    if (!$labels) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Activation rate (%)'),
            'data' => $ratios,
            'borderColor' => '#16a34a',
            'backgroundColor' => 'rgba(22,163,74,0.12)',
            'fill' => TRUE,
            'tension' => 0.25,
            'pointRadius' => 4,
            'pointHoverRadius' => 6,
            'borderWidth' => 2,
            'spanGaps' => TRUE,
            'yAxisID' => 'y',
          ],
          [
            'label' => (string) $this->t('Cohort size'),
            'data' => $cohortTotals,
            'borderColor' => '#94a3b8',
            'backgroundColor' => 'rgba(148,163,184,0.10)',
            'fill' => FALSE,
            'tension' => 0.2,
            'pointRadius' => 2,
            'borderWidth' => 1,
            'borderDash' => [4, 3],
            'yAxisID' => 'y2',
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'mixed',
              ]),
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'type' => 'linear',
            'position' => 'left',
            'min' => 0,
            'max' => 100,
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('% activated'),
            ],
          ],
          'y2' => [
            'type' => 'linear',
            'position' => 'right',
            'ticks' => [
              'precision' => 0,
              'callback' => $this->chartCallback('value_format', [
                'format' => 'integer',
                'showLabel' => FALSE,
              ]),
            ],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Cohort size'),
            ],
            'grid' => ['drawOnChartArea' => FALSE],
          ],
          'x' => [
            'ticks' => [
              'autoSkip' => TRUE,
              'maxRotation' => 0,
              'maxTicksLimit' => 12,
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('28-Day Activation Rate'),
      (string) $this->t('Share of new members who earned at least one badge within 28 days of joining.'),
      $visualization,
      [
        (string) $this->t('Source: ms_member_success_snapshot daily snapshots, latest date per month.'),
        (string) $this->t('Cohort window: members whose join_date falls 28–58 days before the snapshot date (badge_count_total ≥ 1 = activated).'),
        (string) $this->t('Cohort size (dashed) shows how many members were in scope for each month — low counts make the rate more volatile.'),
        (string) $this->t('Activation = first badge earned, a proxy for meaningful first engagement with the space.'),
      ],
    );
  }

}
