<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;
use Drupal\makerspace_dashboard\Service\KpiDataService;

/**
 * Provides demographic breakdowns without exposing individual identities.
 */
class OutreachSection extends DashboardSectionBase {

  /**
   * Data service for demographic aggregates.
   */
  protected DemographicsDataService $dataService;

  /**
   * KPI data service.
   */
  protected KpiDataService $kpiDataService;

  /**
   * Constructs the section.
   */
  public function __construct(DemographicsDataService $data_service, KpiDataService $kpi_data_service) {
    parent::__construct();
    $this->dataService = $data_service;
    $this->kpiDataService = $kpi_data_service;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'outreach';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Outreach');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;


    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('outreach'));
    $build['kpi_table']['#weight'] = $weight++;

    $build['charts_section_heading'] = [
      '#markup' => '<h2>' . $this->t('Charts') . '</h2>',
      '#weight' => $weight++,
    ];

    $discoveryRows = $this->dataService->getDiscoveryDistribution();
    if (!empty($discoveryRows)) {
      $discoveryLabels = array_map(fn(array $row) => $row['label'], $discoveryRows);
      $discoveryCounts = array_map(fn(array $row) => $row['count'], $discoveryRows);

      $chart_id = 'discovery_sources';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
      ];
      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $discoveryCounts,
      ];
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $discoveryLabels),
      ];

      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('How Members Discovered Us'),
        $this->t('Self-reported discovery sources from member profiles.'),
        $chart,
        [
          $this->t('Source: field_member_discovery on active default member profiles with membership roles (defaults: current_member, member).'),
          $this->t('Processing: Aggregates responses and rolls options with fewer than five members into "Other".'),
          $this->t('Definitions: Missing responses surface as "Not captured"; encourage staff to populate this field for richer recruitment insights.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;
    }

    $recentWindowEnd = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
    $recentWindowStart = $recentWindowEnd->modify('-3 months');
    $recentInterestRows = $this->dataService->getRecentInterestDistribution($recentWindowStart, $recentWindowEnd);
    if (!empty($recentInterestRows)) {
      $interestLabels = array_map(fn(array $row) => $row['label'], $recentInterestRows);
      $interestCounts = array_map(fn(array $row) => $row['count'], $recentInterestRows);
      $startLabel = $recentWindowStart->format('M j, Y');
      $endLabel = $recentWindowEnd->format('M j, Y');

      $chart_id = 'recent_member_interests';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
      ];
      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $interestCounts,
      ];
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $interestLabels),
      ];

      $build[$chart_id] = $this->buildChartContainer(
        $chart_id,
        $this->t('New Member Interests (Last 3 Months)'),
        $this->t('Interest areas selected on member profiles created between @start and @end.', [
          '@start' => $startLabel,
          '@end' => $endLabel,
        ]),
        $chart,
        [
          $this->t('Source: Default "main" member profiles created in the last 3 months with interest selections (field_member_interest).'),
          $this->t('Processing: Filters to published users, active membership roles, and aggregates distinct members per interest.'),
          $this->t('Definitions: Bins with fewer than two members roll into "Other" to avoid displaying sensitive counts.'),
        ]
      );
      $build[$chart_id]['#weight'] = $weight++;
    }
    else {
      $build['recent_member_interests_empty'] = [
        '#markup' => $this->t('No interest data captured for new members in the last three months.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
        '#weight' => $weight++,
      ];
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list'],
      'contexts' => ['timezone'],
    ];

    return $build;
  }

}
