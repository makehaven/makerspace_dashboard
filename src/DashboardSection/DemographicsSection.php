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
        '#labels' => $gender_labels,
      ];
    }
    else {
      $build['gender_mix_empty'] = [
        '#markup' => $this->t('No gender data available for current members.'),
      ];
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list'],
    ];

    return $build;
  }

}
