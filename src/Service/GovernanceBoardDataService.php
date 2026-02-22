<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Loads and normalizes board composition data from CiviCRM and Google Sheets.
 */
class GovernanceBoardDataService {

  protected GoogleSheetClientService $sheetClient;

  protected Connection $database;

  /**
   * Cached composition array.
   */
  protected ?array $composition = NULL;

  /**
   * ID of the CiviCRM group containing current board members.
   */
  protected const BOARD_GROUP_ID = 95;

  /**
   * Constructs the service.
   */
  public function __construct(GoogleSheetClientService $sheetClient, Connection $database) {
    $this->sheetClient = $sheetClient;
    $this->database = $database;
  }

  /**
   * Returns the parsed board composition dataset.
   */
  public function getBoardComposition(): array {
    if ($this->composition !== NULL) {
      return $this->composition;
    }

    // 1. Fetch Goals from Google Sheet.
    $goals = $this->sheetClient->getSheetData('Goals-Percent');
    if (empty($goals) || count($goals) < 2) {
      throw new RuntimeException('Unable to load Goals-Percent sheet data.');
    }

    // 2. Fetch Actuals from CiviCRM.
    $roster = $this->fetchBoardRosterFromCivi();
    $totalMembers = count($roster);

    $genderCounts = ['Male' => 0, 'Female' => 0, 'Non-Binary' => 0, 'Other/Unknown' => 0];
    $ageCounts = ['<30' => 0, '30-39' => 0, '40-49' => 0, '50-59' => 0, '60+' => 0, 'Unknown' => 0];
    
    $ethnicityMap = [
      'asian' => 'Asian',
      'black' => 'Black or African American',
      'middleeast' => 'Middle Eastern or North African',
      'pacific' => 'Native Hawaiian or Pacific Islander',
      'white' => 'White/Caucasian',
      'hispanic' => 'Hispanic or Latino',
      'native' => 'American Indian or Alaska Native',
      'other' => 'Other / Multi',
      'not_specified' => 'Not Specified',
    ];

    $ethnicityCounts = array_fill_keys(array_values($ethnicityMap), 0);
    $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));

    foreach ($roster as $member) {
      // Gender Mapping.
      $genderId = (int) ($member['gender_id'] ?? 0);
      switch ($genderId) {
        case 1: $genderCounts['Female']++; break;
        case 2: $genderCounts['Male']++; break;
        case 4: 
        case 5: 
        case 6:
          $genderCounts['Non-Binary']++; break;
        default:
          $genderCounts['Other/Unknown']++; break;
      }

      // Age buckets.
      if (!empty($member['birth_date'])) {
        try {
          $birth = new DateTimeImmutable($member['birth_date'], new DateTimeZone('UTC'));
          $age = $birth->diff($now)->y;
          if ($age < 30) $ageCounts['<30']++;
          elseif ($age <= 39) $ageCounts['30-39']++;
          elseif ($age <= 49) $ageCounts['40-49']++;
          elseif ($age <= 59) $ageCounts['50-59']++;
          else $ageCounts['60+']++;
        }
        catch (\Exception $e) {
          $ageCounts['Unknown']++;
        }
      } else {
        $ageCounts['Unknown']++;
      }

      // Ethnicity mapping.
      $rawEth = (string) ($member['ethnicity'] ?? '');
      $ethValues = array_filter(explode(',', str_replace(["\x01", "\x02"], ',', $rawEth)));
      if (empty($ethValues)) {
        $ethnicityCounts['Not Specified']++;
      } else {
        foreach ($ethValues as $val) {
          $val = strtolower(trim($val));
          if (isset($ethnicityMap[$val])) {
            $ethnicityCounts[$ethnicityMap[$val]]++;
          } else {
            $ethnicityCounts['Other / Multi']++;
          }
        }
      }
    }

    // 3. Process Goal Data from Sheet.
    $goalLookup = [];
    array_shift($goals); // remove header
    foreach ($goals as $row) {
      if (!empty($row[0]) && isset($row[1])) {
        $key = strtolower(trim((string) $row[0]));
        $value = $row[1];
        if (is_string($value) && str_contains($value, '%')) {
          $value = str_replace('%', '', $value);
        }
        $goalLookup[$key] = is_numeric($value) ? (float) $value : 0.0;
      }
    }

    $goalGender = [
      'Male' => $this->normalizeGoalPercentValue($goalLookup['goal_gender_male'] ?? 0),
      'Female' => $this->normalizeGoalPercentValue($goalLookup['goal_gender_female'] ?? 0),
      'Non-Binary' => $this->normalizeGoalPercentValue($goalLookup['goal_gender_nonbinary'] ?? 0),
      'Other/Unknown' => $this->normalizeGoalPercentValue($goalLookup['goal_gender_other'] ?? 0),
    ];
    $goalGender = $this->normalizeGoalGenderTargets($goalGender);
    
    $goalAge = [
      '<30' => $this->normalizeGoalPercentValue($goalLookup['goal_age_lt30'] ?? 0),
      '30-39' => $this->normalizeGoalPercentValue($goalLookup['goal_age_30_39'] ?? 0),
      '40-49' => $this->normalizeGoalPercentValue($goalLookup['goal_age_40_49'] ?? 0),
      '50-59' => $this->normalizeGoalPercentValue($goalLookup['goal_age_50_59'] ?? 0),
      '60+' => $this->normalizeGoalPercentValue($goalLookup['goal_age_60plus'] ?? 0),
      'Unknown' => 0,
    ];

    $goalEthnicity = [];
    // Map internal ethnicity keys back to sheet keys if they exist.
    $sheetEthKeys = [
      'asian' => 'Asian',
      'black' => 'Black or African American',
      'middleeast' => 'Middle Eastern or North African',
      'pacific' => 'Native Hawaiian or Pacific Islander',
      'white' => 'White/Caucasian',
      'hispanic' => 'Hispanic or Latino',
      'native' => 'American Indian or Alaska Native',
      'other' => 'Other / Multi',
      'not_specified' => 'Not Specified',
    ];
    foreach ($sheetEthKeys as $machine => $label) {
      $goalEthnicity[$label] = $this->normalizeGoalPercentValue($this->resolveEthnicityGoalValue($goalLookup, $machine));
    }

    $this->composition = [
      'gender' => [
        'actual_counts' => $genderCounts,
        'actual_pct' => $this->normalizePercentages($genderCounts, $totalMembers),
        'goal_pct' => $goalGender,
      ],
      'age' => [
        'actual_counts' => $ageCounts,
        'actual_pct' => $this->normalizePercentages($ageCounts, $totalMembers),
        'goal_pct' => $goalAge,
      ],
      'ethnicity' => [
        'actual_counts' => $ethnicityCounts,
        'actual_pct' => $this->normalizePercentages($ethnicityCounts, $totalMembers),
        'goal_pct' => $goalEthnicity,
      ],
      'total_members' => $totalMembers,
      'source_url' => $this->sheetClient->getGoogleSheetUrl(),
    ];

    return $this->composition;
  }

  /**
   * Fetches current board member demographics from CiviCRM.
   */
  protected function fetchBoardRosterFromCivi(): array {
    $query = $this->database->select('civicrm_group_contact', 'gc');
    $query->innerJoin('civicrm_contact', 'c', 'c.id = gc.contact_id');
    $query->leftJoin('civicrm_value_demographics_15', 'd', 'd.entity_id = c.id');
    
    $query->fields('c', ['id', 'gender_id', 'birth_date']);
    $query->fields('d', ['ethnicity_46']);
    
    $query->condition('gc.group_id', self::BOARD_GROUP_ID);
    $query->condition('gc.status', 'Added');
    $query->condition('c.is_deleted', 0);

    $results = $query->execute();
    $roster = [];
    foreach ($results as $row) {
      $roster[] = [
        'gender_id' => $row->gender_id,
        'birth_date' => $row->birth_date,
        'ethnicity' => $row->ethnicity_46,
      ];
    }
    return $roster;
  }

  /**
   * Returns the source Google Sheet URL.
   */
  public function getSourceUrl(): string {
    return $this->sheetClient->getGoogleSheetUrl();
  }

  /**
   * Normalizes counts to decimal percentages.
   */
  protected function normalizePercentages(array $counts, int $total): array {
    if ($total <= 0) {
      return array_fill_keys(array_keys($counts), 0);
    }
    $normalized = [];
    foreach ($counts as $label => $count) {
      $normalized[$label] = round(((float) $count) / $total, 4);
    }
    return $normalized;
  }

  /**
   * Ensures goal gender targets include the non-male share when only male is provided.
   */
  protected function normalizeGoalGenderTargets(array $targets): array {
    $male = isset($targets['Male']) ? (float) $targets['Male'] : 0.0;
    $nonMaleTotal = (isset($targets['Female']) ? (float) $targets['Female'] : 0.0)
      + (isset($targets['Non-Binary']) ? (float) $targets['Non-Binary'] : 0.0)
      + (isset($targets['Other/Unknown']) ? (float) $targets['Other/Unknown'] : 0.0);

    if ($nonMaleTotal <= 0 && $male > 0 && $male < 1) {
      $targets['Female'] = max(0, 1 - $male);
    }

    return $targets;
  }

  /**
   * Normalizes sheet-sourced goal percentages whether they are stored as 0-1 or 0-100.
   */
  protected function normalizeGoalPercentValue($value): float {
    $numeric = (float) $value;
    if ($numeric > 1) {
      $numeric = $numeric / 100;
    }
    return max(0.0, min(1.0, $numeric));
  }

  /**
   * Resolves ethnicity goal values, preferring board-specific keys.
   */
  protected function resolveEthnicityGoalValue(array $goalLookup, string $machine): float {
    $boardKey = 'goal_board_ethnicity_' . $machine;
    $generalKey = 'goal_ethnicity_' . $machine;
    if (isset($goalLookup[$boardKey])) {
      return $goalLookup[$boardKey];
    }
    return $goalLookup[$generalKey] ?? 0;
  }

}
