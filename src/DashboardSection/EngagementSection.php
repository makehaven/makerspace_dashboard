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
