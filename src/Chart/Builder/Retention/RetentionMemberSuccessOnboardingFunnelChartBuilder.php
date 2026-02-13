<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MemberSuccessDataService;

/**
 * Builds onboarding funnel chart from member success snapshots.
 */
class RetentionMemberSuccessOnboardingFunnelChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'member_success_onboarding_funnel';
  protected const WEIGHT = 35;

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
    $funnel = $this->memberSuccessData->getLatestOnboardingFunnel(90);
    if (empty($funnel)) {
      return NULL;
    }

    $joined = (int) ($funnel['joined_recent'] ?? 0);
    $orientation = (int) ($funnel['orientation_complete'] ?? 0);
    $badgeActive = (int) ($funnel['badge_active'] ?? 0);
    $serialPresent = (int) ($funnel['serial_present'] ?? 0);

    $labels = [
      (string) $this->t('Joined (last @days days)', ['@days' => (int) ($funnel['cohort_days'] ?? 90)]),
      (string) $this->t('Orientation Complete'),
      (string) $this->t('Door Badge Active'),
      (string) $this->t('Serial Number Present'),
    ];

    $values = [$joined, $orientation, $badgeActive, $serialPresent];
    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => $values,
          'backgroundColor' => ['#2563eb', '#16a34a', '#f59e0b', '#7c3aed'],
          'borderWidth' => 0,
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', ['format' => 'integer', 'showLabel' => FALSE]),
            ],
          ],
        ],
        'scales' => [
          'x' => [
            'ticks' => ['maxRotation' => 0, 'minRotation' => 0],
          ],
          'y' => [
            'beginAtZero' => TRUE,
            'ticks' => [
              'precision' => 0,
              'callback' => $this->chartCallback('value_format', ['format' => 'integer', 'showLabel' => FALSE]),
            ],
          ],
        ],
      ],
    ];

    $snapshotDate = $funnel['snapshot_date'] ?? NULL;
    $snapshotText = $snapshotDate instanceof \DateTimeImmutable ? $snapshotDate->format('Y-m-d') : (string) $this->t('unknown');

    return $this->newDefinition(
      (string) $this->t('Onboarding Completion Funnel'),
      (string) $this->t('Recent join cohort progress through orientation, badge activation, and serial setup.'),
      $visualization,
      [
        (string) $this->t('Source: latest daily row set in `ms_member_success_snapshot`.'),
        (string) $this->t('Cohort: members with join_date in the last @days days at snapshot @date.', [
          '@days' => (int) ($funnel['cohort_days'] ?? 90),
          '@date' => $snapshotText,
        ]),
        (string) $this->t('Current cohort size: @count', ['@count' => $joined]),
      ],
    );
  }

}
