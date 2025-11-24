<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Infrastructure;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\UtilizationDataService;
use Drupal\makerspace_dashboard\Service\UtilizationWindowService;

/**
 * Shows inactivity duration before members cancel.
 */
class InfrastructureCancellationInactivityChartBuilder extends InfrastructureUtilizationChartBuilderBase {

  protected const CHART_ID = 'cancellation_inactivity';
  protected const WEIGHT = 50;

  protected UtilizationDataService $utilizationDataService;

  protected int $lookbackMonths = 12;

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

    $data = $this->utilizationDataService->getCancellationInactivityBuckets($asOf->getTimestamp(), $this->lookbackMonths);
    $sampleSize = (int) ($data['sample_size'] ?? 0);
    if ($sampleSize === 0) {
      return NULL;
    }

    $bucketLabels = [
      '0_30' => $this->t('0–30 days'),
      '31_60' => $this->t('31–60 days'),
      '61_90' => $this->t('61–90 days'),
      '91_180' => $this->t('91–180 days'),
      '181_plus' => $this->t('181+ days'),
      'never' => $this->t('No recorded visit'),
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

    $startLabel = !empty($data['start_date']) ? date('M j, Y', strtotime($data['start_date'])) : '';
    $endLabel = !empty($data['end_date']) ? date('M j, Y', strtotime($data['end_date'])) : '';
    $median = $data['median_days'];
    $average = $data['average_days'];
    $withVisit = (int) ($data['with_visit_sample_size'] ?? 0);
    $neverCount = (int) ($data['buckets']['never'] ?? 0);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Cancelled members'),
          'data' => $values,
          'backgroundColor' => '#f97316',
        ]],
      ],
    ];

    $description = $this->t('Days between a member’s last recorded visit and cancellation for @count members who ended between @start and @end. Median inactivity: @median days (mean: @average).', [
      '@count' => $sampleSize,
      '@start' => $startLabel,
      '@end' => $endLabel,
      '@median' => $median !== NULL ? round($median, 1) : $this->t('n/a'),
      '@average' => $average !== NULL ? round($average, 1) : $this->t('n/a'),
    ]);

    $notes = [
      (string) $this->t('Source: Membership profiles with an end date in the last @months months joined to access-control logs to find the final badge before cancellation.', ['@months' => $this->lookbackMonths]),
      (string) $this->t('Processing: Calculates day gaps between the last visit (if any) and the membership end date, then buckets them into month-length ranges.'),
      (string) $this->t('@with of @count cancellations had at least one recorded visit before ending; @never had no visit on record, indicating onboarding issues.', [
        '@with' => $withVisit,
        '@count' => $sampleSize,
        '@never' => $neverCount,
      ]),
    ];

    return $this->newDefinition(
      (string) $this->t('Inactivity Before Cancellation'),
      $description,
      $visualization,
      $notes,
    );
  }

}

