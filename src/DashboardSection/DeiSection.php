<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;

/**
 * Provides demographic breakdowns without exposing individual identities.
 */
class DeiSection extends DashboardSectionBase {

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
    return 'dei';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('DEI');
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

      $chart_id = 'town_distribution';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Members by town'),
        '#description' => $this->t('Top hometowns for active members; smaller groups are aggregated into “Other”.'),
      ];
      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $locality_counts,
      ];
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $locality_labels),
      ];

      $build[$chart_id] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['metric-container']],
        'chart' => $chart,
        'download' => $this->buildCsvDownloadLink($this->getId(), $chart_id),
        'info' => $this->buildChartInfo([
          $this->t('Source: Active "main" member profiles joined to the address locality field for users holding active membership roles (defaults: @roles).', ['@roles' => 'current_member, member']),
          $this->t('Processing: Counts distinct members per town and collapses values under the minimum threshold into "Other (< 5)".'),
          $this->t('Definitions: Only published users with a default profile are included; blank addresses appear as "Unknown / not provided".'),
        ]),
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

      $filteredLabels = [];
      $filteredCounts = [];
      $excluded = [];
      foreach ($gender_labels as $index => $label) {
        $normalized = mb_strtolower($label);
        if (in_array($normalized, ['not provided', 'prefer not to say'], TRUE)) {
          $excluded[$label] = ($excluded[$label] ?? 0) + ($gender_counts[$index] ?? 0);
          continue;
        }
        $filteredLabels[] = $label;
        $filteredCounts[] = $gender_counts[$index] ?? 0;
      }

      if (empty($filteredCounts)) {
        $filteredLabels = $gender_labels;
        $filteredCounts = $gender_counts;
        $excluded = [];
      }

      $chart_id = 'gender_mix';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'pie',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Gender identity mix'),
        '#description' => $this->t('Aggregated from primary member profile gender selections.'),
        '#data_labels' => TRUE,
        '#raw_options' => [
          'plugins' => [
            'legend' => [
              'position' => 'top',
            ],
            'datalabels' => [
              'color' => '#0f172a',
              'font' => ['weight' => 'bold'],
              'formatter' => "function(value, ctx) { var data = ctx.chart.data.datasets[0].data; var total = data.reduce(function(acc, curr){ return acc + curr; }, 0); if (!total) { return '0%'; } var pct = (value / total) * 100; return pct.toFixed(1) + '%'; }",
            ],
          ],
        ],
      ];
      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $filteredCounts,
      ];
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $filteredLabels),
      ];

      $genderInfoItems = [
        $this->t('Source: Active "main" member profiles mapped to field_member_gender for members with active roles (defaults: @roles).', ['@roles' => 'current_member, member']),
        $this->t('Processing: Distinct member counts per gender value with buckets under five members merged into "Other (< 5)".'),
      ];
      $genderInfoItems[] = $this->t('Definitions: Missing or blank values surface as "Not provided" and are excluded from the chart to highlight the proportional mix.');
      if (!empty($excluded)) {
        $notes = [];
        foreach ($excluded as $label => $count) {
          $notes[] = $this->t('@label: @count', ['@label' => $label, '@count' => $count]);
        }
        $genderInfoItems[] = $this->t('Excluded from chart (shown below for reference): @list', ['@list' => implode(', ', $notes)]);
      }

      $build[$chart_id] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['metric-container']],
        'chart' => $chart,
        'download' => $this->buildCsvDownloadLink($this->getId(), $chart_id),
        'info' => $this->buildChartInfo($genderInfoItems),
      ];
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

      $chart_id = 'interest_distribution';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Member interests'),
        '#description' => $this->t('Top member interests, based on profile selections.'),
      ];
      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $interest_counts,
      ];
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $interest_labels),
      ];

      $build[$chart_id] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['metric-container']],
        'chart' => $chart,
        'download' => $this->buildCsvDownloadLink($this->getId(), $chart_id),
        'info' => $this->buildChartInfo([
          $this->t('Source: Active "main" member profiles joined to field_member_interest for users with active membership roles (defaults: @roles).', ['@roles' => 'current_member, member']),
          $this->t('Processing: Aggregates distinct members per interest, returns the top ten values, and respects the configured minimum count. Unknowns display as "Not provided".'),
          $this->t('Definitions: Only published accounts with a default profile and status = 1 are considered.'),
        ]),
      ];
    }
    else {
      $build['interest_distribution_empty'] = [
        '#markup' => $this->t('No member interest data available.'),
      ];
    }

    $age_rows = $this->dataService->getAgeDistribution(13, 100);
    if (!empty($age_rows)) {
      $age_labels = array_map(fn(array $row) => $row['label'], $age_rows);
      $age_counts = array_map(fn(array $row) => $row['count'], $age_rows);

      $chart_id = 'age_distribution';
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Age distribution of active members'),
        '#description' => $this->t('Counts members by age based on field_member_birthday.'),
        '#raw_options' => [
          'scales' => [
            'x' => ['title' => ['display' => TRUE, 'text' => (string) $this->t('Age')]],
            'y' => ['title' => ['display' => TRUE, 'text' => (string) $this->t('Members')]],
          ],
          'elements' => ['line' => ['tension' => 0.15]],
          'plugins' => [
            'legend' => ['position' => 'top'],
          ],
        ],
      ];
      $chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Members'),
        '#data' => $age_counts,
        '#color' => '#f97316',
        '#settings' => [
          'borderColor' => '#f97316',
          'fill' => FALSE,
          'borderWidth' => 2,
          'pointRadius' => 2,
        ],
      ];
      $windowRadius = 2;
      $trendSeries = [];
      $bucketCount = count($age_counts);
      for ($i = 0; $i < $bucketCount; $i++) {
        $start = max(0, $i - $windowRadius);
        $end = min($bucketCount - 1, $i + $windowRadius);
        $length = ($end - $start + 1) ?: 1;
        $sum = 0;
        for ($j = $start; $j <= $end; $j++) {
          $sum += $age_counts[$j];
        }
        $trendSeries[] = round($sum / $length, 2);
      }
      $chart['series_trend'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Trend (5-point MA)'),
        '#data' => $trendSeries,
        '#color' => '#2563eb',
        '#settings' => [
          'borderColor' => '#2563eb',
          'fill' => FALSE,
          'borderWidth' => 2,
          'borderDash' => [6, 4],
          'pointRadius' => 0,
        ],
      ];
      $chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $age_labels),
      ];

      $build[$chart_id] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['metric-container']],
        'chart' => $chart,
        'download' => $this->buildCsvDownloadLink($this->getId(), $chart_id),
        'info' => $this->buildChartInfo([
          $this->t('Source: Birthdays recorded on active, default member profiles with valid dates.'),
          $this->t('Processing: Calculates age as of today in the site timezone and filters to ages 13–100.'),
          $this->t('Definitions: Age buckets reflect completed years; records with missing or out-of-range birthdays are excluded. A 5-point moving average (blue dashed line) smooths the curve.'),
        ]),
      ];
    }
    else {
      $build['age_distribution_empty'] = [
        '#markup' => $this->t('No birthday data available to chart ages.'),
      ];
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'tags' => ['profile_list', 'user_list'],
    ];

    return $build;
  }

}
