<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MemberSuccessDataService;

/**
 * Builds a lifecycle stage trend chart from member success snapshots.
 */
class RetentionMemberSuccessLifecycleChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'member_success_lifecycle';
  protected const WEIGHT = 34;

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected MemberSuccessDataService $memberSuccessData,
    ?TranslationInterface $stringTranslation = NULL
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->memberSuccessData->getLifecycleStageSeries(12);
    if (empty($series)) {
      return NULL;
    }

    $labels = [];
    $onboarding = [];
    $engagement = [];
    $retention = [];
    $recovery = [];

    foreach ($series as $row) {
      if (empty($row['snapshot_date']) || !$row['snapshot_date'] instanceof \DateTimeImmutable) {
        continue;
      }
      $labels[] = $row['snapshot_date']->format('M Y');
      $stages = $row['stages'] ?? [];
      $onboarding[] = (int) ($stages['onboarding'] ?? 0);
      $engagement[] = (int) ($stages['engagement'] ?? 0);
      $retention[] = (int) ($stages['retention'] ?? 0);
      $recovery[] = (int) ($stages['recovery'] ?? 0);
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
            'label' => (string) $this->t('Onboarding'),
            'data' => $onboarding,
            'borderColor' => '#2563eb',
            'backgroundColor' => 'rgba(37,99,235,0.15)',
            'tension' => 0.25,
            'pointRadius' => 2,
            'fill' => FALSE,
            'borderWidth' => 2,
          ],
          [
            'label' => (string) $this->t('Engagement'),
            'data' => $engagement,
            'borderColor' => '#16a34a',
            'backgroundColor' => 'rgba(22,163,74,0.15)',
            'tension' => 0.25,
            'pointRadius' => 2,
            'fill' => FALSE,
            'borderWidth' => 2,
          ],
          [
            'label' => (string) $this->t('Retention'),
            'data' => $retention,
            'borderColor' => '#7c3aed',
            'backgroundColor' => 'rgba(124,58,237,0.15)',
            'tension' => 0.25,
            'pointRadius' => 2,
            'fill' => FALSE,
            'borderWidth' => 2,
          ],
          [
            'label' => (string) $this->t('Recovery'),
            'data' => $recovery,
            'borderColor' => '#dc2626',
            'backgroundColor' => 'rgba(220,38,38,0.15)',
            'tension' => 0.25,
            'pointRadius' => 2,
            'fill' => FALSE,
            'borderWidth' => 2,
          ],
        ],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['position' => 'top'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', ['format' => 'integer']),
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'ticks' => [
              'precision' => 0,
              'callback' => $this->chartCallback('value_format', ['format' => 'integer', 'showLabel' => FALSE]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Member Success Lifecycle Stage Mix'),
      (string) $this->t('Month-end counts by lifecycle stage (onboarding, engagement, retention, recovery).'),
      $visualization,
      [
        (string) $this->t('Source: `ms_member_success_snapshot` daily snapshots grouped by stage.'),
        (string) $this->t('Processing: uses the latest snapshot date available in each month.'),
      ],
    );
  }

}
