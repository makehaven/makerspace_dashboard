<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Infrastructure;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\UtilizationDataService;
use Drupal\makerspace_dashboard\Service\UtilizationWindowService;

/**
 * Charts how long it has been since active members last badged in.
 */
class InfrastructureVisitRecencyChartBuilder extends InfrastructureUtilizationChartBuilderBase {

  protected const CHART_ID = 'visit_recency';
  protected const WEIGHT = 45;

  protected UtilizationDataService $utilizationDataService;

  public function __construct(UtilizationWindowService $windowService, UtilizationDataService $utilizationDataService, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($windowService, $stringTranslation);
    $this->utilizationDataService = $utilizationDataService;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $metrics = $this->getMetrics();
    $asOf = $metrics['end_of_day'] ?? NULL;
    if (!$asOf instanceof \DateTimeInterface) {
      return NULL;
    }
    $asOfTimestamp = $asOf->getTimestamp();
    $recency = $this->utilizationDataService->getMonthsSinceLastVisitBuckets($asOfTimestamp);
    $activeMembers = (int) ($recency['active_members'] ?? 0);
    if ($activeMembers === 0) {
      return NULL;
    }

    $bucketLabels = [
      '0_1' => $this->t('Visited in the last month'),
      '1_2' => $this->t('1–2 months ago'),
      '2_3' => $this->t('2–3 months ago'),
      '3_6' => $this->t('3–6 months ago'),
      '6_12' => $this->t('6–12 months ago'),
      '12_plus' => $this->t('12+ months ago'),
      'never' => $this->t('No recorded visit'),
    ];

    $labels = [];
    $values = [];
    foreach ($bucketLabels as $bucketId => $label) {
      if (!isset($recency['buckets'][$bucketId])) {
        continue;
      }
      $labels[] = (string) $label;
      $values[] = (int) $recency['buckets'][$bucketId];
    }

    $asOfLabel = date('M j, Y', $asOfTimestamp);
    $lastMonthCount = (int) ($recency['buckets']['0_1'] ?? 0);
    $lastMonthShare = $activeMembers > 0 ? round(($lastMonthCount / $activeMembers) * 100, 1) : 0;
    $inactiveBuckets = ['3_6', '6_12', '12_plus', 'never'];
    $inactiveCount = 0;
    foreach ($inactiveBuckets as $bucketId) {
      $inactiveCount += (int) ($recency['buckets'][$bucketId] ?? 0);
    }
    $inactiveShare = $activeMembers > 0 ? round(($inactiveCount / $activeMembers) * 100, 1) : 0;

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => $values,
          'backgroundColor' => '#6366f1',
        ]],
      ],
    ];

    $description = $this->t('Distribution of @total active members by how many months it has been since their last recorded visit (as of @date). @share% have badged in within the past month.', [
      '@total' => $activeMembers,
      '@date' => $asOfLabel,
      '@share' => $lastMonthShare,
    ]);

    $notes = [
      (string) $this->t('Source: Access-control logs reduced to the most recent badge timestamp for each member with an active membership role as of @date.', ['@date' => $asOfLabel]),
      (string) $this->t('Processing: Converts days since last visit into month-based buckets (0–1, 1–2, 2–3, 3–6, 6–12, 12+). Members with no recorded visit are counted separately.'),
      (string) $this->t('@count members (~@percent%) have gone roughly 90+ days without visiting (buckets ≥3 months), indicating potential churn risk.', [
        '@count' => $inactiveCount,
        '@percent' => $inactiveShare,
      ]),
    ];

    return $this->newDefinition(
      (string) $this->t('Months Since Last Visit'),
      $description,
      $visualization,
      $notes,
    );
  }

}

