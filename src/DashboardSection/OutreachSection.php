<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;

/**
 * Provides demographic breakdowns without exposing individual identities.
 */
class OutreachSection extends DashboardSectionBase {

  /**
   * Data service for demographic aggregates.
   */
  protected DemographicsDataService $dataService;

  /**
   * Constructs the section.
   */
  public function __construct(DemographicsDataService $data_service) {
    parent::__construct();
    $this->dataService = $data_service;
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

    // This build method is organized into two main parts:
    // 1. Discovery Sources: A chart showing how members discovered the makerspace.
    // 2. New Member Interests: A chart showing the interests of new members.

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Summarizes member demographics sourced from profile fields today, with hooks to migrate to CiviCRM contact data later.'),
    ];

    $discoveryRows = $this->dataService->getDiscoveryDistribution();
    if (!empty($discoveryRows)) {
      $discoveryLabels = array_map(fn(array $row) => $row['label'], $discoveryRows);
      $discoveryCounts = array_map(fn(array $row) => $row['count'], $discoveryRows);

      $discovery_sources_chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
      ];
      $discovery_sources_chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $discoveryCounts,
      ];
      $discovery_sources_chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $discoveryLabels),
      ];
      $build['discovery_sources_metric'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['metric-container']],
        'heading' => [
          '#markup' => '<h2>' . $this->t('How Members Discovered Us') . '</h2><p>' . $this->t('Self-reported discovery sources from member profiles.') . '</p>',
        ],
        'chart' => $discovery_sources_chart,
        'info' => $this->buildChartInfo([
          $this->t('Source: field_member_discovery on active default member profiles with membership roles (defaults: current_member, member).'),
          $this->t('Processing: Aggregates responses and rolls options with fewer than five members into "Other".'),
          $this->t('Definitions: Missing responses surface as "Not captured"; encourage staff to populate this field for richer recruitment insights.'),
        ]),
      ];
    }

    $recentWindowEnd = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
    $recentWindowStart = $recentWindowEnd->modify('-3 months');
    $recentInterestRows = $this->dataService->getRecentInterestDistribution($recentWindowStart, $recentWindowEnd);
    if (!empty($recentInterestRows)) {
      $interestLabels = array_map(fn(array $row) => $row['label'], $recentInterestRows);
      $interestCounts = array_map(fn(array $row) => $row['count'], $recentInterestRows);
      $startLabel = $recentWindowStart->format('M j, Y');
      $endLabel = $recentWindowEnd->format('M j, Y');

      $build['recent_member_interests'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('New member interests (last 3 months)'),
        '#description' => $this->t('Interest areas selected on member profiles created between @start and @end.', [
          '@start' => $startLabel,
          '@end' => $endLabel,
        ]),
      ];
      $build['recent_member_interests']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $interestCounts,
      ];
      $build['recent_member_interests']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $interestLabels),
      ];
      $build['recent_member_interests_info'] = $this->buildChartInfo([
        $this->t('Source: Default "main" member profiles created in the last 3 months with interest selections (field_member_interest).'),
        $this->t('Processing: Filters to published users, active membership roles, and aggregates distinct members per interest.'),
        $this->t('Definitions: Bins with fewer than two members roll into "Other" to avoid displaying sensitive counts.'),
      ]);
    }
    else {
      $build['recent_member_interests_empty'] = [
        '#markup' => $this->t('No interest data captured for new members in the last three months.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
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
