<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Histogram of members by total badges earned.
 */
class RetentionBadgeCountDistributionChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'badge_count_distribution';
  protected const WEIGHT = 95;

  protected MembershipMetricsService $membershipMetrics;

  public function __construct(MembershipMetricsService $membershipMetrics, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->membershipMetrics = $membershipMetrics;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $buckets = $this->membershipMetrics->getBadgeCountDistribution();
    if (empty($buckets)) {
      return NULL;
    }

    $labels = [];
    $counts = [];
    $hasMembers = FALSE;

    foreach ($buckets as $bucket) {
      $count = (int) ($bucket['member_count'] ?? 0);
      $labels[] = $this->formatBucketLabel($bucket, $count);
      $counts[] = $count;
      if ($count > 0) {
        $hasMembers = TRUE;
      }
    }

    if (!$hasMembers) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => $counts,
          'backgroundColor' => '#16a34a',
          'borderRadius' => 6,
        ]],
      ],
      'options' => [
        'scales' => [
          'y' => [
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
      (string) $this->t('Counts the total number of active or completed badge requests linked to each member profile.'),
      (string) $this->t('Buckets members into the same ranges used by the tenure correlation chart for easier comparison.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Members by Badges Earned'),
      (string) $this->t('How many badges members have earned, grouped into buckets.'),
      $visualization,
      $notes,
    );
  }

  /**
   * Formats a bucket label for display.
   */
  protected function formatBucketLabel(array $bucket, int $memberCount = 0): string {
    $min = (int) ($bucket['badge_min'] ?? 0);
    $max = $bucket['badge_max'];

    if ($min === 1 && $max === 1) {
      $label = (string) $this->t('1 badge');
    }
    elseif ($max === NULL) {
      $label = (string) $this->t('@min+ badges', ['@min' => $min]);
    }
    elseif ($min === $max) {
      $label = (string) $this->t('@count badges', ['@count' => $min]);
    }
    else {
      $label = (string) $this->t('@min-@max badges', ['@min' => $min, '@max' => (int) $max]);
    }

    if ($memberCount > 0) {
      $label = (string) $this->t('@label (@count)', [
        '@label' => $label,
        '@count' => $memberCount,
      ]);
    }
    return $label;
  }

}
