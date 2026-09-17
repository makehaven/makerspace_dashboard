<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\EngagementDataService;
use Drupal\makerspace_dashboard\Service\KpiDataService;

/**
 * Shows early member engagement signals like badges earned and trainings.
 */
class EducationSection extends DashboardSectionBase {

  protected EngagementDataService $dataService;

  protected TimeInterface $time;

  protected KpiDataService $kpiDataService;

  /**
   * Constructs the section.
   */
  public function __construct(EngagementDataService $data_service, TimeInterface $time, KpiDataService $kpi_data_service, ChartBuilderManager $chart_builder_manager) {
    parent::__construct(NULL, $chart_builder_manager);
    $this->dataService = $data_service;
    $this->time = $time;
    $this->kpiDataService = $kpi_data_service;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'education';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Education');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;

    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('education'));
    $build['kpi_table']['#weight'] = $weight++;

    // Cross-links: the dashboard answers "how are we doing"; these answer
    // "what do I do about it" and "where did the money numbers come from".
    $build['quick_links'] = $this->buildEducationQuickLinks();
    $build['quick_links']['#weight'] = $weight++;

    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()));

    // Resolve the active time range for the charts.
    $activeRange = $this->resolveSelectedRange($filters, 'engagement', '3m', ['3m', '6m', '1y']);
    $range = $this->calculateRangeBounds($activeRange, $now);
    $snapshot = $this->dataService->getEngagementSnapshot($range['start'], $range['end']);
    $activationDays = $this->dataService->getActivationWindowDays();

    // Allow engagement builders to reuse the computed snapshot.
    $filters['engagement_snapshot'] = $snapshot;
    $filters['engagement_activation_days'] = $activationDays;
    $filters['engagement_cohort_range'] = $range;

    foreach ($this->buildTieredChartContainers($filters) as $tier => $container) {
      $container['#weight'] = $weight++;
      $build['tier_' . $tier] = $container;
    }

    $funnel = $snapshot['funnel'] ?? ['totals' => []];
    $velocity = $snapshot['velocity'] ?? [
      'cohort_percent' => 0,
      'median' => 0,
    ];

    $joined = (int) ($funnel['totals']['joined'] ?? 0);
    $firstBadge = (int) ($funnel['totals']['first_badge'] ?? 0);
    $toolEnabled = (int) ($funnel['totals']['tool_enabled'] ?? 0);

    if ($joined === 0) {
      $build['engagement_empty'] = [
        '#markup' => $this->t('No new members joined within the configured cohort window. Adjust the engagement settings or check recent member activity.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
        '#weight' => $weight++,
      ];
    }
    else {
      $summaryItems = array_filter([
        $this->t('Cohort size: @count members', ['@count' => $joined]),
        $joined ? $this->t('@percent% reach their first badge within @days days', [
          '@percent' => $velocity['cohort_percent'] ?? 0,
          '@days' => $activationDays,
        ]) : NULL,
        $firstBadge ? $this->t('Median days to first badge: @median', ['@median' => $velocity['median'] ?? 0]) : NULL,
        $toolEnabled ? $this->t('@count members earn a tool-enabled badge', ['@count' => $toolEnabled]) : NULL,
      ]);

      if ($summaryItems) {
        $build['summary'] = [
          '#theme' => 'item_list',
          '#items' => $summaryItems,
          '#attributes' => ['class' => ['makerspace-dashboard-summary']],
          '#weight' => $weight++,
        ];
      }
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => ['user_list', 'config:makerspace_dashboard.settings', 'civicrm_participant_list'],
    ];

    return $build;
  }


  /**
   * Builds the row of cross-links shown under the education KPI table.
   *
   * The dashboard reports how the programme is doing. These point at the pages
   * that let someone act on it, and at the finance review the money KPIs are
   * reconciled against.
   */
  protected function buildEducationQuickLinks(): array {
    $links = [
      ['/admin/education', $this->t('Education console'), $this->t('Proposals, closeouts, evaluations to read')],
      ['/admin/workshops', $this->t('Workshops by course'), $this->t('Runs, seats, revenue and the gap to a full room')],
      ['/admin/program', $this->t('Programs and cohorts'), $this->t('Institutional cohorts and their runs')],
      ['/admin/meetups', $this->t('Meetups'), $this->t('Free events, for comparison against ticketed classes')],
    ];

    $items = [];
    foreach ($links as [$path, $title, $description]) {
      $items[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['education-quick-link']],
        'link' => [
          '#type' => 'link',
          '#title' => $title,
          '#url' => Url::fromUserInput($path),
        ],
        'description' => [
          '#markup' => '<span class="education-quick-link__description">' . $description . '</span>',
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['education-quick-links']],
      'heading' => [
        '#markup' => '<h3>' . $this->t('Act on this') . '</h3>',
      ],
      'items' => $items,
    ];
  }

}
