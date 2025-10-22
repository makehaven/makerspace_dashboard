<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Shows early member engagement signals like badges earned and trainings.
 */
class EngagementSection extends DashboardSectionBase {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'engagement';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('New Member Engagement');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Track how quickly new members earn key badges and complete onboarding milestones within their first 90 days.'),
    ];

    $build['badge_funnel'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Badge activation funnel (sample)'),
      '#description' => $this->t('Replace with badge status changes pulled from profile revisions to show cohort progress.'),
    ];

    $build['badge_funnel']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Members'),
      '#data' => [130, 92, 71, 38],
    ];

    $build['badge_funnel']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => [
        $this->t('Joined'),
        $this->t('Attended orientation'),
        $this->t('Earned first badge'),
        $this->t('Reached active tool status'),
      ],
    ];

    $build['engagement_velocity'] = [
      '#type' => 'chart',
      '#chart_type' => 'line',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Days to first badge (sample distribution)'),
      '#description' => $this->t('Plot histogram buckets of days-to-first-badge to see how onboarding adjustments impact adoption.'),
    ];

    $build['engagement_velocity']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Members'),
      '#data' => [5, 22, 47, 31, 12, 4],
    ];

    $build['engagement_velocity']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => [
        $this->t('0-3 days'),
        $this->t('4-7 days'),
        $this->t('8-14 days'),
        $this->t('15-30 days'),
        $this->t('31-60 days'),
        $this->t('60+ days'),
      ],
    ];

    return $build;
  }

}
