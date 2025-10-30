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

    // --- Helper for a grouped bar chart ---
    $build_grouped_bar_chart = function(string $title, array $data) {
      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'google',
        '#title' => $this->t($title),
        '#legend_position' => 'top',
        '#options' => ['vAxis' => ['format' => 'percent']],
      ];
      // For a data table format, the first column provides the x-axis labels.
      // No separate '#chart_xaxis' is needed.
      $chart['series_data'] = ['#type' => 'chart_data', '#data' => $data];
      return $chart;
    };

    // --- Helper for pie charts ---
    $build_pie_chart = function(string $title, array $data) {
      $labels = array_keys($data);
      $values = array_values($data);

      $chart = [
        '#type' => 'chart',
        '#chart_type' => 'pie',
        '#chart_library' => 'google',
        '#title' => $this->t($title),
        '#legend_position' => 'none',
        '#options' => [
          'pieSliceText' => 'percentage',
          'chartArea' => ['width' => '90%', 'height' => '90%'],
          'fontSize' => 16,
          'pieSliceTextStyle' => [
            'color' => 'black',
          ],
          'colors' => [], // This will be populated dynamically.
        ],
      ];

      // Assign custom colors based on labels.
      $color_map = [
        'Female' => '#dc3912', // Red
        'Male' => '#3366cc', // Blue
        'Non-Binary' => '#ff9900', // Orange
        'Other/Unknown' => '#990099', // Purple
      ];

      $chart['#options']['colors'] = [];
      foreach ($labels as $label) {
        $chart['#options']['colors'][] = $color_map[$label] ?? '#dddddd';
      }
      $chart['pie_data'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Distribution'),
        '#labels' => $labels,
        '#data' => $values,
      ];
      return $chart;
    };

    // --- Helper for comparison tables ---
    $build_table = function(string $category_label, array $goal_data, array $actual_data) {
      $header = [$this->t($category_label), $this->t('Goal'), $this->t('Actual')];
      $rows = [];
      foreach ($goal_data as $key => $goal_value) {
        // Filter out rows where both values are 0 to keep tables clean.
        $actual_value = $actual_data[$key] ?? 0;
        if ($goal_value == 0 && $actual_value == 0) {
          continue;
        }
        $rows[] = [
          $key,
          round($goal_value * 100) . '%',
          round($actual_value * 100) . '%',
        ];
      }
      return [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#attributes' => ['class' => ['governance-comparison-table']],
      ];
    };

    // Use #weight to control the rendering order of the elements.
    $weight = 0;

    $build['intro']['#weight'] = $weight++;

    $build['kpi_table'] = $this->buildKpiTable();
    $build['kpi_table']['#weight'] = $weight++;

    $build['data_section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['data-section-container']],
      'heading' => ['#markup' => '<h2>' . $this->t('Data Tables') . '</h2>'],
      'gender_table' => $build_table('Gender', $goal_gender_pct, $actual_gender_pct),
      'age_table' => $build_table('Age Range', $goal_age_pct, $actual_age_pct),
      '#weight' => $weight++,
    ];

    $build['charts_section_heading'] = [
      '#markup' => '<h2>' . $this->t('Charts') . '</h2>',
      '#weight' => $weight++,
    ];

    // Chart: Gender Identity.
    $gender_chart = [
      '#type' => 'container',
      '#attributes' => ['class' => ['pie-chart-pair-container']],
      'goal' => $build_pie_chart('Goal %', $goal_gender_pct),
      'actual' => $build_pie_chart('Actual %', $actual_gender_pct),
    ];
    $build['board_gender_identity'] = $this->buildChartContainer(
      'board_gender_identity',
      $this->t('Board Gender Identity'),
      $this->t('This chart shows the breakdown of board members by gender identity, comparing our current composition to our diversity goals.'),
      $gender_chart,
      [['#markup' => $this->t('Data Source: <a href=":url" target="_blank">Board Roster & Goals (Google Sheet)</a>', [':url' => $this->googleSheetClient->getGoogleSheetUrl()])]]
    );
    $build['board_gender_identity']['#weight'] = $weight++;

    // Chart: Age Range.
    $age_chart = [
      '#type' => 'container',
      '#attributes' => ['class' => ['pie-chart-pair-container']],
      'goal' => $build_pie_chart('Goal %', $goal_age_pct),
      'actual' => $build_pie_chart('Actual %', $actual_age_pct),
    ];
    $build['board_age_range'] = $this->buildChartContainer(
      'board_age_range',
      $this->t('Board Age Range'),
      $this->t('This chart shows the age distribution of the board, comparing our current composition to our diversity goals.'),
      $age_chart,
      [['#markup' => $this->t('Data Source: <a href=":url" target="_blank">Board Roster & Goals (Google Sheet)</a>', [':url' => $this->googleSheetClient->getGoogleSheetUrl()])]]
    );
    $build['board_age_range']['#weight'] = $weight++;

    // Chart: Ethnicity.
    $ethnicity_data = [['Ethnicity', 'Goal %', 'Actual %']];
    foreach ($actual_ethnicity_pct as $label => $actual_pct) {
      $goal_pct = $goal_ethnicity_pct[$label] ?? 0;
      if ($actual_pct > 0 || $goal_pct > 0) {
        $ethnicity_data[] = [$label, $goal_pct, $actual_pct];
      }
    }
    $ethnicity_chart = $build_grouped_bar_chart(
      'Board Ethnicity (Goal vs. Actual)',
      $ethnicity_data
    );
    $build['board_ethnicity'] = $this->buildChartContainer(
      'board_ethnicity',
      $this->t('Board Ethnicity'),
      $this->t('This chart shows the ethnic diversity of the board. Members can select multiple categories, so "Actual %" can total over 100%.'),
      $ethnicity_chart,
      [['#markup' => $this->t('Data Source: <a href=":url" target="_blank">Board Roster & Goals (Google Sheet)</a>', [':url' => $this->googleSheetClient->getGoogleSheetUrl()])]]
    );
    $build['board_ethnicity']['#weight'] = $weight++;

    // Attach the main charts library.
    $build['#attached']['library'][] = 'charts/chart';

    return $build;
  }
}
