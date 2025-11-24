<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Shows tenure buckets grouped by membership type.
 */
class RetentionMembershipTypeTenureChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'membership_type_tenure';
  protected const WEIGHT = 96;

  protected MembershipMetricsService $membershipMetrics;

  public function __construct(MembershipMetricsService $membershipMetrics, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->membershipMetrics = $membershipMetrics;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $distribution = $this->membershipMetrics->getMembershipTypeTenureDistribution();
    if (empty($distribution)) {
      return NULL;
    }

    $bucketLabels = [
      '1yr' => (string) $this->t('1 year'),
      '2yr' => (string) $this->t('2 years'),
      '3yr' => (string) $this->t('3 years'),
      '4yr' => (string) $this->t('4 years'),
      '5_plus' => (string) $this->t('5+ years'),
    ];
    $colorPalette = ['#0ea5e9', '#6366f1', '#f97316', '#22c55e', '#a855f7'];

    $labels = [];
    $datasets = [];
    foreach ($bucketLabels as $bucketId => $label) {
      $datasets[$bucketId] = [
        'label' => $label,
        'backgroundColor' => $colorPalette[count($datasets) % count($colorPalette)],
        'data' => [],
      ];
    }

    foreach ($distribution as $typeRow) {
      $labels[] = $typeRow['membership_type_label'];
      foreach ($datasets as $bucketId => &$dataset) {
        $dataset['data'][] = (int) ($typeRow['buckets'][$bucketId] ?? 0);
      }
      unset($dataset);
    }

    if (!$labels) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => array_values($datasets),
      ],
      'options' => [
        'responsive' => TRUE,
        'plugins' => [
          'legend' => ['position' => 'top'],
        ],
        'scales' => [
          'x' => [
            'stacked' => TRUE,
          ],
          'y' => [
            'stacked' => TRUE,
            'beginAtZero' => TRUE,
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'integer',
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Tenure is calculated using the member join date and last recorded end date for “main” profiles.'),
      (string) $this->t('Only members with at least one active badge are included to focus on engaged membership.'),
      (string) $this->t('Stacked bars show how many members in each membership type stayed 1, 2, 3, 4, or 5+ years.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Membership Tenure by Type'),
      (string) $this->t('How long members stay, grouped by membership plan.'),
      $visualization,
      $notes,
    );
  }

}

