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

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Summarizes member demographics sourced from profile fields today, with hooks to migrate to CiviCRM contact data later.'),
    ];

    $discoveryRows = $this->dataService->getDiscoveryDistribution();
    if (!empty($discoveryRows)) {
      $discoveryLabels = array_map(fn(array $row) => $row['label'], $discoveryRows);
      $discoveryCounts = array_map(fn(array $row) => $row['count'], $discoveryRows);

      $build['discovery_sources'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('How members discovered us'),
        '#description' => $this->t('Self-reported discovery sources from member profiles.'),
      ];
      $build['discovery_sources']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $discoveryCounts,
      ];
      $build['discovery_sources']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $discoveryLabels),
      ];
      $build['discovery_sources_info'] = $this->buildChartInfo([
        $this->t('Source: field_member_discovery on active default member profiles with membership roles (defaults: current_member, member).'),
        $this->t('Processing: Aggregates responses and rolls options with fewer than five members into "Other".'),
        $this->t('Definitions: Missing responses surface as "Not captured"; encourage staff to populate this field for richer recruitment insights.'),
      ]);
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list'],
    ];

    return $build;
  }

}
