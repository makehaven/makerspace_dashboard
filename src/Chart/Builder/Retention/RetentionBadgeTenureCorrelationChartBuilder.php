<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Shows membership tenure averages grouped by badge counts.
 */
class RetentionBadgeTenureCorrelationChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'badge_tenure_correlation';
  protected const WEIGHT = 94;

  protected MembershipMetricsService $membershipMetrics;

  public function __construct(MembershipMetricsService $membershipMetrics, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->membershipMetrics = $membershipMetrics;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $buckets = $this->membershipMetrics->getBadgeTenureCorrelation();
    if (empty($buckets)) {
      return NULL;
    }

    $labels = [];
    $averages = [];
    $counts = [];
    $hasData = FALSE;

    foreach ($buckets as $bucket) {
      $memberCount = (int) ($bucket['member_count'] ?? 0);
      $labels[] = $this->formatBucketLabel($bucket, $memberCount);
      $counts[] = $memberCount;

      $average = $bucket['average_years'] ?? NULL;
      if ($memberCount > 0 && $average !== NULL) {
        $hasData = TRUE;
        $averages[] = round((float) $average, 2);
      }
      else {
        $averages[] = NULL;
      }
    }

    if (!$hasData) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Average tenure (years)'),
          'data' => $averages,
          'backgroundColor' => '#0ea5e9',
          'borderRadius' => 6,
          'makerspaceCounts' => $counts,
        ]],
      ],
      'options' => [
        'scales' => [
          'y' => [
            'beginAtZero' => TRUE,
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'decimal',
                'decimals' => 1,
                'suffix' => (string) $this->t('years'),
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Badge counts include all active badge requests linked to a member profile (orientation + tool badges).'),
      (string) $this->t('Tenure is measured from join date to the most recent end date, or to today for currently active members.'),
      (string) $this->t('Hover a bar to see how many members fall into that badge bucket.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Badges vs. Membership Tenure'),
      (string) $this->t('Average membership length for members grouped by the total number of badges they have earned.'),
      $visualization,
      $notes,
    );
  }

  /**
   * Builds a display label for the badge bucket.
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
