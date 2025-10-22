<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;

/**
 * Provides demographic breakdowns without exposing individual identities.
 */
class DemographicsSection extends DashboardSectionBase {

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
    return 'demographics';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Demographics');
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

    $locality_rows = $this->dataService->getLocalityDistribution();
    if (!empty($locality_rows)) {
      $locality_labels = array_map(fn(array $row) => $row['label'], $locality_rows);
      $locality_counts = array_map(fn(array $row) => $row['count'], $locality_rows);

      $build['town_distribution'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Members by town'),
        '#description' => $this->t('Top hometowns for active members; smaller groups are aggregated into “Other”.'),
      ];
      $build['town_distribution']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $locality_counts,
      ];
      $build['town_distribution']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $locality_labels,
      ];
      $build['town_distribution_info'] = $this->buildChartInfo([
        $this->t('Source: Active "main" member profiles joined to the address locality field for users holding active membership roles (defaults: @roles).', ['@roles' => 'current_member, member']),
        $this->t('Processing: Counts distinct members per town and collapses values under the minimum threshold into "Other (< 5)".'),
        $this->t('Definitions: Only published users with a default profile are included; blank addresses appear as "Unknown / not provided".'),
      ]);
    }
    else {
      $build['town_distribution_empty'] = [
        '#markup' => $this->t('No address data available for current members.'),
      ];
    }

    $gender_rows = $this->dataService->getGenderDistribution();
    if (!empty($gender_rows)) {
      $gender_labels = array_map(fn(array $row) => $row['label'], $gender_rows);
      $gender_counts = array_map(fn(array $row) => $row['count'], $gender_rows);

      $build['gender_mix'] = [
        '#type' => 'chart',
        '#chart_type' => 'pie',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Gender identity mix'),
        '#description' => $this->t('Aggregated from primary member profile gender selections.'),
      ];
      $build['gender_mix']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $gender_counts,
      ];
      $build['gender_mix']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $gender_labels,
      ];
      $build['gender_mix_info'] = $this->buildChartInfo([
        $this->t('Source: Active "main" member profiles mapped to field_member_gender for members with active roles (defaults: @roles).', ['@roles' => 'current_member, member']),
        $this->t('Processing: Distinct member counts per gender value with buckets under five members merged into "Other (< 5)".'),
        $this->t('Definitions: Missing or blank values surface as "Not provided".'),
      ]);
    }
    else {
      $build['gender_mix_empty'] = [
        '#markup' => $this->t('No gender data available for current members.'),
      ];
    }

    $interest_rows = $this->dataService->getInterestDistribution();
    if (!empty($interest_rows)) {
      $interest_labels = array_map(fn(array $row) => $row['label'], $interest_rows);
      $interest_counts = array_map(fn(array $row) => $row['count'], $interest_rows);

      $build['interest_distribution'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Member interests'),
        '#description' => $this->t('Top member interests, based on profile selections.'),
      ];
      $build['interest_distribution']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $interest_counts,
      ];
      $build['interest_distribution']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $interest_labels,
      ];
      $build['interest_distribution_info'] = $this->buildChartInfo([
        $this->t('Source: Active "main" member profiles joined to field_member_interest for users with active membership roles (defaults: @roles).', ['@roles' => 'current_member, member']),
        $this->t('Processing: Aggregates distinct members per interest, returns the top ten values, and respects the configured minimum count. Unknowns display as "Not provided".'),
        $this->t('Definitions: Only published accounts with a default profile and status = 1 are considered.'),
      ]);
    }
    else {
      $build['interest_distribution_empty'] = [
        '#markup' => $this->t('No member interest data available.'),
      ];
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list'],
    ];

    return $build;
  }

}
