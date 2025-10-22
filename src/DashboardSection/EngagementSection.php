<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\EngagementDataService;

/**
 * Shows early member engagement signals like badges earned and trainings.
 */
class EngagementSection extends DashboardSectionBase {

  protected EngagementDataService $dataService;

  protected DateFormatterInterface $dateFormatter;

  protected TimeInterface $time;

  public function __construct(EngagementDataService $data_service, DateFormatterInterface $date_formatter, TimeInterface $time) {
    parent::__construct();
    $this->dataService = $data_service;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
  }

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
    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    $range = $this->dataService->getDefaultRange($now);
    $snapshot = $this->dataService->getEngagementSnapshot($range['start'], $range['end']);

    $activationDays = $this->dataService->getActivationWindowDays();
    $cohortStart = $this->dateFormatter->format($range['start']->getTimestamp(), 'custom', 'M j, Y');
    $cohortEnd = $this->dateFormatter->format($range['end']->getTimestamp(), 'custom', 'M j, Y');

    $build = [];
    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Tracking new members who joined between @start and @end. Activation window: @days days from join date.', [
        '@start' => $cohortStart,
        '@end' => $cohortEnd,
        '@days' => $activationDays,
      ]),
    ];

    $funnel = $snapshot['funnel'];
    if (empty($funnel['totals']['joined'])) {
      $build['empty'] = [
        '#markup' => $this->t('No new members joined within the configured cohort window. Adjust the engagement settings or check recent member activity.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
      ];
      return $build;
    }
    $labels = array_map(fn($label) => $this->t($label), $funnel['labels']);

    $build['badge_funnel'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Badge activation funnel'),
      '#description' => $this->t('Progression of new members through orientation, first badge, and tool-enabled badges.'),
    ];
    $build['badge_funnel']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Members'),
      '#data' => array_map('intval', $funnel['counts']),
    ];
    $build['badge_funnel']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => $labels,
    ];
    $build['badge_funnel_info'] = $this->buildChartInfo([
      $this->t('Source: Badge request nodes completed within the activation window for members who joined during the cohort range.'),
      $this->t('Processing: Orientation completion is keyed off configured orientation badge term IDs; first/tool-enabled badges use the earliest qualifying badge within the activation window (default 90 days).'),
      $this->t('Definitions: Members without any qualifying badge remain at the "Joined" stage; tool-enabled requires the taxonomy flag field_badge_access_control.'),
    ]);

    $velocity = $snapshot['velocity'];
    $velocityLabels = array_map(fn($label) => $this->t($label), $velocity['labels']);

    $build['engagement_velocity'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Days to first badge'),
      '#description' => $this->t('Distribution of days elapsed from join date to first non-orientation badge.'),
    ];
    $build['engagement_velocity']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Members'),
      '#data' => array_map('intval', $velocity['counts']),
    ];
    $build['engagement_velocity']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => $velocityLabels,
    ];
    $build['engagement_velocity_info'] = $this->buildChartInfo([
      $this->t('Source: First non-orientation badge timestamps pulled from badge requests for the same cohort used in the funnel chart.'),
      $this->t('Processing: Calculates elapsed days between join date and first badge award, then buckets into ranges (0-3, 4-7, 8-14, 15-30, 31-60, 60+, no badge).'),
      $this->t('Definitions: Members without a qualifying badge fall into the "No badge yet" bucket; orientation-only completions do not count toward the distribution.'),
    ]);

    $joined = (int) $funnel['totals']['joined'];
    $firstBadge = (int) $funnel['totals']['first_badge'];
    $toolEnabled = (int) $funnel['totals']['tool_enabled'];

    $build['summary'] = [
      '#theme' => 'item_list',
      '#items' => array_filter([
        $this->t('Cohort size: @count members', ['@count' => $joined]),
        $joined ? $this->t('@percent% reach their first badge within @days days', [
          '@percent' => $velocity['cohort_percent'],
          '@days' => $activationDays,
        ]) : NULL,
        $firstBadge ? $this->t('Median days to first badge: @median', ['@median' => $velocity['median']]) : NULL,
        $toolEnabled ? $this->t('@count members earn a tool-enabled badge', ['@count' => $toolEnabled]) : NULL,
      ]),
      '#attributes' => ['class' => ['makerspace-dashboard-summary']],
    ];

    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => ['user_list', 'config:makerspace_dashboard.settings'],
    ];

    return $build;
  }

}
