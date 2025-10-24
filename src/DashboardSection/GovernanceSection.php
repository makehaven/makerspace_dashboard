<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\GoogleSheetClientService;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Governance dashboard section.
 */
class GovernanceSection extends DashboardSectionBase {

  /**
   * The Google Sheet client service.
   *
   * @var \Drupal\makerspace_dashboard\Service\GoogleSheetClientService
   */
  protected $googleSheetClient;

  /**
   * Constructs a new GovernanceSection object.
   *
   * @param \Drupal\makerspace_dashboard\Service\GoogleSheetClientService $google_sheet_client
   *   The Google Sheet client service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(GoogleSheetClientService $google_sheet_client, TranslationInterface $string_translation) {
    $this->googleSheetClient = $google_sheet_client;
    $this->setStringTranslation($string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'governance';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Governance');
  }

  /**
   * {@inheritdoc}
   */
  public function getGoogleSheetChartMetadata(): array {
    return [
      'label' => $this->t('Board Roster'),
      'tab_name' => 'Board-Roster',
    ];
  }

  public function build(array $filters = []): array {
    $build = [];

    // === 1. Fetch ACTUAL Data ===
    $actual_raw_data = $this->googleSheetClient->getSheetData('Board-Roster');

    // === 2. Fetch GOAL Data ===
    $goal_raw_data = $this->googleSheetClient->getSheetData('Goals-Percent');

    if (empty($actual_raw_data) || count($actual_raw_data) < 2) {
      $build['no_data'] = [
        '#markup' => $this->t('Could not load chart data from the "Board-Roster" tab. Please ensure the tab exists and has a header row and at least one data row.'),
      ];
      return $build;
    }
    if (empty($goal_raw_data) || count($goal_raw_data) < 2) {
      $build['no_data'] = [
        '#markup' => $this->t('Could not load chart data from the "Goals-Percent" tab. Please ensure the tab exists and has "Goal_ID" and "Value" columns.'),
      ];
      return $build;
    }

    // === 3. Define the Machine Name to Label Map ===
    $ethnicity_map = [
      'asian' => 'Asian',
      'black' => 'Black or African American',
      'mena' => 'Middle Eastern or North African',
      'nhpi' => 'Native Hawaiian or Pacific Islander',
      'white' => 'White/Caucasian',
      'hispanic' => 'Hispanic or Latino',
      'aian' => 'American Indian or Alaska Native',
      'multi' => 'Other / Multi',
      'not_specified' => 'Not Specified',
    ];
    $ethnicity_machine_names = array_keys($ethnicity_map);

    // === 4. Process ACTUAL Data (Get Counts) ===
    $header_row = array_shift($actual_raw_data);
    $header_row = array_map('trim', $header_row);

    $col_gender = array_search('Gender', $header_row);
    $col_birthdate = array_search('Birthdate', $header_row);
    $ethnicity_columns = [];
    foreach ($header_row as $index => $col_name) {
      if (in_array($col_name, $ethnicity_machine_names)) {
        $ethnicity_columns[$col_name] = $index;
      }
    }

    if ($col_gender === FALSE || $col_birthdate === FALSE || empty($ethnicity_columns)) {
      $build['no_data_processed'] = [
        '#markup' => $this->t('Found the "Board-Roster" tab, but the headers are incorrect. Expected "Gender", "Birthdate", and ethnicity machine names (e.g., "asian").'),
      ];
      return $build;
    }

    $total_members = count($actual_raw_data);

    // Initialize Actual Counts
    $actual_gender_counts = ['Male' => 0, 'Female' => 0, 'Non-Binary' => 0, 'Other/Unknown' => 0];
    $actual_age_counts = ['<30' => 0, '30-39' => 0, '40-49' => 0, '50-59' => 0, '60+' => 0, 'Unknown' => 0];
    $actual_ethnicity_counts = [];
    foreach (array_keys($ethnicity_columns) as $machine_name) {
      $actual_ethnicity_counts[$ethnicity_map[$machine_name]] = 0;
    }
    $actual_ethnicity_counts[$ethnicity_map['not_specified']] = 0;

    $now = new \DateTime('now');

    foreach ($actual_raw_data as $row) {
      // Process Gender
      if (isset($row[$col_gender])) {
          $gender = trim($row[$col_gender]);
          if (isset($actual_gender_counts[$gender])) {
            $actual_gender_counts[$gender]++;
          }
          else {
            $actual_gender_counts['Other/Unknown']++;
          }
      } else {
          $actual_gender_counts['Other/Unknown']++;
      }


      // Process Age
      try {
        if (empty($row[$col_birthdate])) {
            throw new \Exception('Birthdate is empty');
        }
        $birthdate = new \DateTime($row[$col_birthdate]);
        $age = $birthdate->diff($now)->y;
        if ($age < 30) $actual_age_counts['<30']++;
        elseif ($age <= 39) $actual_age_counts['30-39']++;
        elseif ($age <= 49) $actual_age_counts['40-49']++;
        elseif ($age <= 59) $actual_age_counts['50-59']++;
        elseif ($age >= 60) $actual_age_counts['60+']++;
      }
      catch (\Exception $e) {
        $actual_age_counts['Unknown']++;
      }

      // Process Ethnicity
      $ethnicity_specified = FALSE;
      foreach ($ethnicity_columns as $machine_name => $index) {
        if (isset($row[$index]) && !empty(trim($row[$index]))) {
          $label = $ethnicity_map[$machine_name];
          $actual_ethnicity_counts[$label]++;
          $ethnicity_specified = TRUE;
        }
      }
      if (!$ethnicity_specified) {
        $actual_ethnicity_counts[$ethnicity_map['not_specified']]++;
      }
    }

    // --- Convert Actual Counts to Percentages ---
    $actual_gender_pct = array_map(function($count) use ($total_members) {
      return $total_members > 0 ? $count / $total_members : 0;
    }, $actual_gender_counts);

    $actual_age_pct = array_map(function($count) use ($total_members) {
      return $total_members > 0 ? $count / $total_members : 0;
    }, $actual_age_counts);

    $actual_ethnicity_pct = array_map(function($count) use ($total_members) {
      // Ethnicity is multi-select, so percentage can be > 100%.
      // We calculate percentage of *members*, not percentage of *selections*.
      return $total_members > 0 ? $count / $total_members : 0;
    }, $actual_ethnicity_counts);


    // === 5. Process GOAL Data ===
    // Convert the raw goal data into a simple [Goal_ID => Value] lookup array.
    $goals_data = [];
    array_shift($goal_raw_data); // Remove header
    foreach ($goal_raw_data as $row) {
      if (!empty($row[0]) && isset($row[1])) {
        $goals_data[$row[0]] = (float) $row[1];
      }
    }

    // Build GOAL percentage arrays, pulling from the $goals_data lookup.
    $goal_gender_pct = [
      'Male' => $goals_data['goal_gender_male'] ?? 0,
      'Female' => $goals_data['goal_gender_female'] ?? 0,
      'Non-Binary' => $goals_data['goal_gender_nonbinary'] ?? 0,
      'Other/Unknown' => 0,
    ];
    $goal_age_pct = [
      '<30' => $goals_data['goal_age_lt30'] ?? 0,
      '30-39' => $goals_data['goal_age_30_39'] ?? 0,
      '40-49' => $goals_data['goal_age_40_49'] ?? 0,
      '50-59' => $goals_data['goal_age_50_59'] ?? 0,
      '60+' => $goals_data['goal_age_60plus'] ?? 0,
      'Unknown' => 0,
    ];
    $goal_ethnicity_pct = [];
    foreach ($ethnicity_map as $machine_name => $label) {
      $goal_ethnicity_pct[$label] = $goals_data['goal_ethnicity_' . $machine_name] ?? 0;
    }

    // === 6. Build Render Array ===
    $goal_color = 'rgba(255, 99, 132, 0.6)';
    $actual_color = 'rgba(54, 162, 235, 0.6)';

    // --- Chart options to format Y-axis as percentage ---
    $percentage_axis_options = [
      'plugins' => ['title' => ['display' => TRUE]],
      'scales' => [
        'yAxes' => [[
          'ticks' => [
            'beginAtZero' => TRUE,
            'callback' => 'function(value) { return value * 100 + "%"; }',
          ],
        ]],
      ],
      'tooltips' => [
        'callbacks' => [
          'label' => 'function(tooltipItem, data) {
            var label = data.datasets[tooltipItem.datasetIndex].label || "";
            if (label) {
              label += ": ";
            }
            label += (tooltipItem.yLabel * 100).toFixed(0) + "%";
            return label;
          }',
        ],
      ],
    ];

    $build['#prefix'] = '<div style="display: grid; grid-template-columns: 1fr; gap: 30px;">';
    $build['#suffix'] = '</div>';

    // --- Chart 1: Gender Identity (Grouped Bar) ---
    $build['gender_chart'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#legend_position' => 'top',
      '#data' => [
        'labels' => array_keys($actual_gender_pct),
        'datasets' => [
          [
            'label' => $this->t('Goal %'),
            'data' => array_values($goal_gender_pct),
            'backgroundColor' => $goal_color,
          ],
          [
            'label' => $this->t('Actual %'),
            'data' => array_values($actual_gender_pct),
            'backgroundColor' => $actual_color,
          ],
        ],
      ],
      '#options' => array_merge_recursive($percentage_axis_options, [
        'plugins' => ['title' => ['text' => $this->t('Board Gender Identity (Goal vs. Actual)')]],
      ]),
    ];

    // --- Chart 2: Age Range (Grouped Bar) ---
    $build['age_chart'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#legend_position' => 'top',
      '#data' => [
        'labels' => array_keys($actual_age_pct),
        'datasets' => [
          [
            'label' => $this->t('Goal %'),
            'data' => array_values($goal_age_pct),
            'backgroundColor' => $goal_color,
          ],
          [
            'label' => $this->t('Actual %'),
            'data' => array_values($actual_age_pct),
            'backgroundColor' => $actual_color,
          ],
        ],
      ],
      '#options' => array_merge_recursive($percentage_axis_options, [
        'plugins' => ['title' => ['text' => $this->t('Board Age Range (Goal vs. Actual)')]],
      ]),
    ];

    // --- Chart 3: Ethnicity (Grouped Bar) ---
    // Filter out categories with 0 for both goal and actual
    $ethnicity_labels = [];
    $goal_eth_data = [];
    $actual_eth_data = [];
    foreach($actual_ethnicity_pct as $label => $actual_pct) {
      $goal_pct = $goal_ethnicity_pct[$label] ?? 0;
      if ($actual_pct > 0 || $goal_pct > 0) {
        $ethnicity_labels[] = $label;
        $goal_eth_data[] = $goal_pct;
        $actual_eth_data[] = $actual_pct;
      }
    }

    $build['ethnicity_heading'] = [
      '#markup' => '<h3>Board Ethnicity</h3><p><em>Note: Members can select multiple categories, so "Actual %" can total over 100%.</em></p>',
    ];
    $build['ethnicity_chart'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#legend_position' => 'top',
      '#data' => [
        'labels' => $ethnicity_labels,
        'datasets' => [
          [
            'label' => $this->t('Goal %'),
            'data' => $goal_eth_data,
            'backgroundColor' => $goal_color,
          ],
           [
            'label' => $this->t('Actual %'),
            'data' => $actual_eth_data,
            'backgroundColor' => $actual_color,
          ],
        ],
      ],
      '#options' => array_merge_recursive($percentage_axis_options, [
        'plugins' => ['title' => ['text' => $this->t('Board Ethnicity (Goal vs. Actual)')]],
      ]),
    ];

    return $build;
  }
}
