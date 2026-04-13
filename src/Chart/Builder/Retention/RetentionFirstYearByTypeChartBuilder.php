<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * First-year retention split by Standard vs Sliding Scale membership type.
 *
 * Terminal Program members are excluded from both the blended metric and this
 * breakdown — they are time-bounded memberships not expected to renew.  This
 * chart lets staff see whether the two primary paying tiers trend differently,
 * which would indicate that pricing or onboarding varies by segment.
 */
class RetentionFirstYearByTypeChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'first_year_by_type';
  protected const WEIGHT = 20;
  protected const TIER = 'supplemental';

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected MembershipMetricsService $membershipMetrics,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->membershipMetrics->getMonthlyFirstYearRetentionByType(36);
    if (empty($series)) {
      return NULL;
    }

    $labels = [];
    $standardData = [];
    $slidingData = [];
    $hasStandard = FALSE;
    $hasSliding = FALSE;

    foreach ($series as $row) {
      $labels[] = (new \DateTimeImmutable($row['period']))->format('M Y');

      $stdPct = $row['standard_percent'];
      $standardData[] = $stdPct !== NULL ? round($stdPct, 1) : NULL;
      if ($stdPct !== NULL) {
        $hasStandard = TRUE;
      }

      $sldPct = $row['sliding_percent'];
      $slidingData[] = $sldPct !== NULL ? round($sldPct, 1) : NULL;
      if ($sldPct !== NULL) {
        $hasSliding = TRUE;
      }
    }

    if (!$hasStandard && !$hasSliding) {
      return NULL;
    }

    $datasets = [];
    if ($hasStandard) {
      $datasets[] = [
        'label' => (string) $this->t('Standard'),
        'data' => $standardData,
        'borderColor' => '#1d4ed8',
        'backgroundColor' => 'rgba(29,78,216,0.10)',
        'fill' => FALSE,
        'tension' => 0.25,
        'pointRadius' => 3,
        'pointHoverRadius' => 6,
        'borderWidth' => 2,
        'spanGaps' => TRUE,
      ];
    }
    if ($hasSliding) {
      $datasets[] = [
        'label' => (string) $this->t('Sliding Scale'),
        'data' => $slidingData,
        'borderColor' => '#16a34a',
        'backgroundColor' => 'rgba(22,163,74,0.10)',
        'fill' => FALSE,
        'tension' => 0.25,
        'pointRadius' => 3,
        'pointHoverRadius' => 6,
        'borderWidth' => 2,
        'borderDash' => [5, 3],
        'spanGaps' => TRUE,
      ];
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
        'plugins' => [
          'legend' => ['position' => 'bottom'],
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
              'text' => (string) $this->t('Retained at 12 months (%)'),
            ],
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
      (string) $this->t('First-Year Retention by Membership Type'),
      (string) $this->t('Share of Standard and Sliding Scale members still active 12 months after joining. Terminal Program excluded.'),
      $visualization,
      [
        (string) $this->t('Source: profile records with field_membership_type matching Standard (tid 716) or Sliding Scale (tid 718).'),
        (string) $this->t('Terminal Program (tid 842) members are excluded — time-bounded memberships not expected to renew.'),
        (string) $this->t('A month appears only after its 12-month evaluation window has elapsed; the most recent 12 months are excluded.'),
        (string) $this->t('Unpreventable attrition (relocation, etc.) is excluded from both numerator and denominator.'),
        (string) $this->t('Null points indicate fewer than 1 members of that type joined in that month (too small to show).'),
      ],
    );
  }

}
