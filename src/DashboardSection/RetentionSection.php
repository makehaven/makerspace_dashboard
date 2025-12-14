<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Support\LocationMapTrait;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;
use Drupal\makerspace_dashboard\Service\KpiDataService;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;
use Drupal\makerspace_dashboard\Service\RetentionFlowDataService;

/**
 * Tracks recruitment and retention cohorts.
 */
class RetentionSection extends DashboardSectionBase {

  use LocationMapTrait;

  /**
   * Membership metrics service.
   */
  protected MembershipMetricsService $membershipMetrics;

  /**
   * Date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Time service.
   */
  protected TimeInterface $time;

  /**
   * Legacy snapshot data service reference (kept for BC).
   */
  protected ?SnapshotDataService $snapshotData = NULL;

  /**
   * KPI data service.
   */
  protected KpiDataService $kpiDataService;

  /**
   * Flow data service.
   */
  protected RetentionFlowDataService $flowDataService;

  /**
   * Constructs the retention section.
   */
  public function __construct(MembershipMetricsService $membership_metrics, DateFormatterInterface $date_formatter, TimeInterface $time, ?SnapshotDataService $snapshot_data, KpiDataService $kpi_data_service, RetentionFlowDataService $flow_data_service, ChartBuilderManager $chart_builder_manager) {
    parent::__construct(NULL, $chart_builder_manager);
    $this->membershipMetrics = $membership_metrics;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
    $this->snapshotData = $snapshot_data;
    $this->kpiDataService = $kpi_data_service;
    $this->flowDataService = $flow_data_service;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'retention';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Retention');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;


    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('retention'));
    $build['kpi_table']['#weight'] = $weight++;

    foreach ($this->buildTieredChartContainers($filters) as $tier => $container) {
      $container['#weight'] = $weight++;
      $build['tier_' . $tier] = $container;
    }

    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
      ->setTime(0, 0);

    $flowWindow = $this->flowDataService->getFlowWindow();
    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
      ->setTime(0, 0);
    $totals = $flowWindow['flow']['totals'] ?? [];
    if ($totals) {
      $recent = array_slice($totals, -6);
      $increase = 0;
      $decrease = 0;
      $netChange = 0;
      foreach ($recent as $row) {
        $increase += $row['incoming'];
        $decrease += $row['ending'];
        $netChange += $row['incoming'] - $row['ending'];
      }
      $build['summary_recent'] = [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Last 6 months recruitment: @in', ['@in' => $increase]),
          $this->t('Last 6 months churn: @out', ['@out' => $decrease]),
          $this->t('Net change: @net', ['@net' => $netChange]),
        ],
        '#attributes' => ['class' => ['makerspace-dashboard-summary']],
        '#weight' => $weight++,
      ];
    }
    $build['location_map'] = [
      '#type' => 'details',
      '#title' => $this->t('Active members by home region'),
      '#open' => TRUE,
      'map' => $this->buildLocationMapRenderable([
        'initial_view' => 'heatmap',
        'fit_bounds' => FALSE,
        'zoom' => 10,
      ]),
    ];

    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => [
        'access_control_log_list',
        'user_list',
        'civicrm_contact_list',
        'civicrm_address_list',
        'config:makerspace_dashboard.settings',
      ],
    ];

    return $build;
  }
}
