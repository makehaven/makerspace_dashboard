<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Infrastructure;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\UtilizationDataService;
use Drupal\makerspace_dashboard\Service\UtilizationWindowService;

/**
 * Breaks down inactive members (90+ days) by tenure segment.
 */
class InfrastructureInactiveTenureChartBuilder extends InfrastructureUtilizationChartBuilderBase {

  protected const CHART_ID = 'inactive_tenure';
  protected const WEIGHT = 55;

  protected UtilizationDataService $utilizationDataService;

  protected int $thresholdDays = 90;

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

    $data = $this->utilizationDataService->getInactiveMembersByTenure($asOf->getTimestamp(), $this->thresholdDays);
    $totalActive = (int) ($data['total_active'] ?? 0);
    if ($totalActive === 0) {
      return NULL;
    }

    $bucketLabels = [
      'under_three_months' => $this->t('Joined <3 months ago'),
      'three_to_twelve_months' => $this->t('Joined 3–12 months ago'),
      'one_to_three_years' => $this->t('Joined 1–3 years ago'),
      'three_plus_years' => $this->t('Joined 3+ years ago'),
      'unknown' => $this->t('Unknown join date'),
    ];

    $labels = [];
    $values = [];
    foreach ($bucketLabels as $bucketId => $label) {
      if (!isset($data['buckets'][$bucketId])) {
        continue;
      }
      $labels[] = (string) $label;
      $values[] = (int) $data['buckets'][$bucketId];
    }

    $totalInactive = (int) ($data['total_inactive'] ?? 0);
    $inactivePercent = $totalActive > 0 ? round(($totalInactive / $totalActive) * 100, 1) : 0;
    $asOfLabel = date('M j, Y', $asOf->getTimestamp());

    $daysLabel = $this->thresholdDays . '+';

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'options' => [
        'indexAxis' => 'y',
        'scales' => [
          'x' => [
            'title' => ['display' => TRUE, 'text' => (string) $this->t('Members inactive @days days', ['@days' => $daysLabel])],
          ],
        ],
      ],
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Inactive members'),
          'data' => $values,
          'backgroundColor' => '#0ea5e9',
        ]],
      ],
    ];

    $description = $this->t('@inactive of @active active members (@percent%) have not visited in @days days as of @date. This chart shows which tenure segments make up that at-risk group.', [
      '@inactive' => $totalInactive,
      '@active' => $totalActive,
      '@percent' => $inactivePercent,
      '@days' => $daysLabel,
      '@date' => $asOfLabel,
    ]);

    $notes = [
      (string) $this->t('Source: Active membership roster joined with access-control logs to find the most recent visit per member.'),
      (string) $this->t('Processing: Flags members with no visit in @days days (or never) and groups them by tenure calculated from the membership join date.', ['@days' => $daysLabel]),
      (string) $this->t('Use this view to decide if outreach should focus on onboarding newer members or re-engaging long-tenured members who have lapsed in attendance.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Inactive Members by Tenure'),
      $description,
      $visualization,
      $notes,
    );
  }

}
