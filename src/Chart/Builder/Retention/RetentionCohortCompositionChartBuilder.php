<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Cohort composition stacked chart.
 */
class RetentionCohortCompositionChartBuilder extends RetentionCohortChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'annual_cohorts';
  protected const WEIGHT = 5;

  public function __construct(MembershipMetricsService $membershipMetrics, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($membershipMetrics, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $cohorts = $this->getCohorts();
    if (!$cohorts) {
      return NULL;
    }

    $labels = [];
    $active = [];
    $inactive = [];
    foreach ($cohorts as $row) {
      $labels[] = (string) $row['year'];
      $active[] = (int) $row['active'];
      $inactive[] = max(0, (int) $row['joined'] - (int) $row['active']);
    }

    if (!array_sum($active) && !array_sum($inactive)) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Still active'),
            'data' => $active,
            'backgroundColor' => '#ef4444',
            'stack' => 'cohort',
          ],
          [
            'label' => (string) $this->t('No longer active'),
            'data' => $inactive,
            'backgroundColor' => '#94a3b8',
            'stack' => 'cohort',
          ],
        ],
      ],
      'options' => [
        'plugins' => [
          'tooltip' => [
            'callbacks' => [
              'afterBody' => $this->chartCallback('tooltip_after_body_cohort'),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Cohort Composition by Join Year'),
      (string) $this->t('Active vs inactive members for each join year cohort.'),
      $visualization,
      [
        (string) $this->t('Source: Members with join dates in profile__field_member_join_date grouped by calendar year.'),
        (string) $this->t('Processing: Counts total members per cohort and marks a member as active when they hold an active membership role today.'),
        (string) $this->t('Definitions: "Still active" reflects active role assignment today; "No longer active" covers members without those roles.'),
      ],
    );
  }

}
