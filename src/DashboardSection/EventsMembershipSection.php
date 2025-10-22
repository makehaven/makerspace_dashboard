<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Connects civicrm event participation with membership conversions.
 */
class EventsMembershipSection extends DashboardSectionBase {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'events_membership';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Events ➜ Membership');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Correlate CiviCRM event engagement with subsequent membership starts to inform programming strategy.'),
    ];

    $build['conversion_funnel'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Event-to-membership conversion (sample)'),
      '#description' => $this->t('Aggregate attendees by cohort month and show how many activate a membership within 30/60/90 days.'),
    ];

    $build['conversion_funnel']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Members'),
      '#data' => [180, 74, 49, 21],
    ];

    $build['conversion_funnel']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => [
        $this->t('Event attendees'),
        $this->t('30-day joins'),
        $this->t('60-day joins'),
        $this->t('90-day joins'),
      ],
    ];

    $build['time_to_join'] = [
      '#type' => 'chart',
      '#chart_type' => 'line',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Average days from event to membership (sample)'),
      '#description' => $this->t('Visualize rolling averages for conversion velocity by program type.'),
    ];

    $build['time_to_join']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Days'),
      '#data' => [14, 19, 21, 16, 24, 18],
    ];

    $build['time_to_join']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => [
        $this->t('Jan'),
        $this->t('Feb'),
        $this->t('Mar'),
        $this->t('Apr'),
        $this->t('May'),
        $this->t('Jun'),
      ],
    ];

    return $build;
  }

}
