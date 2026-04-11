<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MemberSuccessDataService;

/**
 * 18-month at-risk member share trend from member success snapshots.
 *
 * Plots the percentage of active members flagged as at-risk each month so
 * staff can spot rising churn signals before they materialise in hard numbers.
 */
class RetentionMembersAtRiskChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'members_at_risk_timeline';
  protected const WEIGHT = 7;

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

    $series = $this->memberSuccessData->getMonthlyRiskShareSeries(18, 20);
    if (empty($series)) {
      return NULL;
    }

    $labels = [];
    $ratios = [];
    $atRiskCounts = [];
    $totalCounts = [];

    foreach ($series as $row) {
      if (empty($row['snapshot_date']) || !$row['snapshot_date'] instanceof \DateTimeImmutable) {
        continue;
      }
      $labels[] = $row['snapshot_date']->format('M Y');
      $ratios[] = $row['ratio'] !== NULL ? round((float) $row['ratio'] * 100, 1) : NULL;
      $atRiskCounts[] = (int) $row['at_risk'];
      $totalCounts[] = (int) $row['total'];
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
            'label' => (string) $this->t('At-risk members (%)'),
            'data' => $ratios,
            'borderColor' => '#dc2626',
            'backgroundColor' => 'rgba(220,38,38,0.12)',
            'fill' => TRUE,
            'tension' => 0.25,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
            'borderWidth' => 2,
            'spanGaps' => TRUE,
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
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
          'x' => [
            'ticks' => [
              'autoSkip' => TRUE,
              'maxRotation' => 0,
              'minRotation' => 0,
              'maxTicksLimit' => 12,
            ],
          ],
          'y' => [
            'min' => 0,
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('% at risk'),
            ],
          ],
        ],
      ],
    ];

    $firstDate = reset($series)['snapshot_date'] ?? NULL;
    $lastDate = end($series)['snapshot_date'] ?? NULL;
    $notes = [
      (string) $this->t('Source: ms_member_success_snapshot daily snapshots, latest date per month.'),
      (string) $this->t('Definition: At-risk = risk_score ≥ 20. Threshold can be adjusted in service parameters.'),
    ];
    if ($firstDate instanceof \DateTimeImmutable && $lastDate instanceof \DateTimeImmutable) {
      $notes[] = (string) $this->t('Coverage: @start – @end (@count months).', [
        '@start' => $firstDate->format('M Y'),
        '@end' => $lastDate->format('M Y'),
        '@count' => count($labels),
      ]);
    }

    return $this->newDefinition(
      (string) $this->t('Members at Risk'),
      (string) $this->t('Share of active members flagged as at-risk each month — an early-warning signal for churn.'),
      $visualization,
      $notes,
    );
  }

}
