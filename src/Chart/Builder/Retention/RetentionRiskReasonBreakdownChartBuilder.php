<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MemberSuccessDataService;

/**
 * Horizontal bar chart showing risk reason distribution across at-risk members.
 *
 * Answers "why are members at risk?" by tallying the reasons the risk scorer
 * flagged across all currently at-risk members.  Lets staff prioritise the
 * highest-volume problem (e.g. if inactive_60 dwarfs payment_failed, the
 * intervention focus should shift toward re-engagement rather than billing).
 */
class RetentionRiskReasonBreakdownChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'risk_reason_breakdown';
  protected const WEIGHT = 17;
  protected const TIER = 'supplemental';

  /**
   * Human-readable labels for each risk reason flag.
   */
  private const REASON_LABELS = [
    'payment_failed'               => 'Payment failed',
    'pause_ending'                 => 'Pause near 90-day limit',
    'door_badge_pending'           => 'Door badge not active',
    'missing_serial'               => 'Missing member serial',
    'no_badge_1'                   => 'No badge in first 28 days',
    'no_badge_4'                   => 'Fewer than 4 badges at 180 days',
    'inactive_30'                  => 'No visit in 30+ days',
    'inactive_60'                  => 'No visit in 60+ days',
    'inactive_90'                  => 'No visit in 90+ days',
    'inactive_120'                 => 'No visit in 120+ days',
    'inactive_180'                 => 'No visit in 180+ days',
    'orientation_scheduled_upcoming' => 'Orientation upcoming (suppressed)',
  ];

  /**
   * Colour per risk reason category.
   */
  private const REASON_COLORS = [
    'payment_failed'               => '#dc2626',
    'pause_ending'                 => '#f97316',
    'door_badge_pending'           => '#eab308',
    'missing_serial'               => '#a16207',
    'no_badge_1'                   => '#0284c7',
    'no_badge_4'                   => '#2563eb',
    'inactive_30'                  => '#7c3aed',
    'inactive_60'                  => '#6d28d9',
    'inactive_90'                  => '#5b21b6',
    'inactive_120'                 => '#4c1d95',
    'inactive_180'                 => '#3b0764',
    'orientation_scheduled_upcoming' => '#94a3b8',
  ];

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

    $data = $this->memberSuccessData->getCurrentRiskReasonBreakdown(20);
    if (empty($data['reasons'])) {
      return NULL;
    }

    $totalAtRisk = (int) ($data['total_at_risk'] ?? 0);
    $reasons = $data['reasons'];

    $labels = [];
    $counts = [];
    $colors = [];

    foreach ($reasons as $key => $count) {
      $labels[] = self::REASON_LABELS[$key] ?? ucwords(str_replace('_', ' ', $key));
      $counts[] = (int) $count;
      $colors[] = self::REASON_COLORS[$key] ?? '#64748b';
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members affected'),
          'data' => $counts,
          'backgroundColor' => $colors,
          'borderColor' => $colors,
          'borderWidth' => 1,
          'borderRadius' => 3,
        ]],
      ],
      'options' => [
        'indexAxis' => 'y',
        'responsive' => TRUE,
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'suffix' => (string) $this->t('members'),
              ]),
            ],
          ],
        ],
        'scales' => [
          'x' => [
            'ticks' => [
              'precision' => 0,
              'callback' => $this->chartCallback('value_format', [
                'format' => 'integer',
                'showLabel' => FALSE,
              ]),
            ],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Members with this risk flag'),
            ],
          ],
          'y' => [
            'ticks' => ['font' => ['size' => 12]],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Snapshot: latest member state (is_latest = 1, risk_score ≥ 20).'),
      (string) $this->t('A single member can carry multiple risk flags; totals may exceed @total.', [
        '@total' => number_format($totalAtRisk),
      ]),
      (string) $this->t('Total members at risk: @count.', ['@count' => number_format($totalAtRisk)]),
    ];

    return $this->newDefinition(
      (string) $this->t('Risk Reason Breakdown'),
      (string) $this->t('Why are members currently flagged as at-risk? Sorted by prevalence.'),
      $visualization,
      $notes,
    );
  }

}
