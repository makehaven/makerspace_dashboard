<?php

namespace Drupal\makerspace_dashboard\Service;

use RuntimeException;

/**
 * Loads and normalizes board composition data from Google Sheets.
 */
class GovernanceBoardDataService {

  protected GoogleSheetClientService $sheetClient;

  /**
   * Cached composition array.
   */
  protected ?array $composition = NULL;

  /**
   * Constructs the service.
   */
  public function __construct(GoogleSheetClientService $sheetClient) {
    $this->sheetClient = $sheetClient;
  }

  /**
   * Returns the parsed board composition dataset.
   */
  public function getBoardComposition(): array {
    if ($this->composition !== NULL) {
      return $this->composition;
    }

    $roster = $this->sheetClient->getSheetData('Board-Roster');
    $goals = $this->sheetClient->getSheetData('Goals-Percent');
    if (empty($roster) || count($roster) < 2) {
      throw new RuntimeException('Unable to load Board-Roster sheet data.');
    }
    if (empty($goals) || count($goals) < 2) {
      throw new RuntimeException('Unable to load Goals-Percent sheet data.');
    }

    $header = array_map('trim', array_shift($roster));
    $colGender = array_search('Gender', $header, TRUE);
    $colBirthdate = array_search('Birthdate', $header, TRUE);

    $ethnicityMap = [
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

    $ethnicityColumns = [];
    foreach ($header as $index => $name) {
      $key = strtolower(trim($name));
      if (isset($ethnicityMap[$key])) {
        $ethnicityColumns[$key] = $index;
      }
    }

    if ($colGender === FALSE || $colBirthdate === FALSE || empty($ethnicityColumns)) {
      throw new RuntimeException('Board-Roster sheet is missing required columns.');
    }

    $totalMembers = count($roster);

    $genderCounts = ['Male' => 0, 'Female' => 0, 'Non-Binary' => 0, 'Other/Unknown' => 0];
    $ageCounts = ['<30' => 0, '30-39' => 0, '40-49' => 0, '50-59' => 0, '60+' => 0, 'Unknown' => 0];
    $ethnicityCounts = [];
    foreach ($ethnicityColumns as $key => $_) {
      $ethnicityCounts[$ethnicityMap[$key]] = 0;
    }
    $ethnicityCounts[$ethnicityMap['not_specified']] = 0;

    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    foreach ($roster as $row) {
      // Gender.
      $genderValue = isset($row[$colGender]) ? trim((string) $row[$colGender]) : '';
      if ($genderValue === '' || !isset($genderCounts[$genderValue])) {
        $genderCounts['Other/Unknown']++;
      }
      else {
        $genderCounts[$genderValue]++;
      }

      // Age bucket.
      try {
        if (empty($row[$colBirthdate])) {
          throw new RuntimeException('Missing birthdate');
        }
        $birth = new \DateTimeImmutable($row[$colBirthdate], new \DateTimeZone('UTC'));
        $age = $birth->diff($now)->y;
        if ($age < 30) {
          $ageCounts['<30']++;
        }
        elseif ($age <= 39) {
          $ageCounts['30-39']++;
        }
        elseif ($age <= 49) {
          $ageCounts['40-49']++;
        }
        elseif ($age <= 59) {
          $ageCounts['50-59']++;
        }
        else {
          $ageCounts['60+']++;
        }
      }
      catch (\Exception $e) {
        $ageCounts['Unknown']++;
      }

      // Ethnicity (multi-select).
      $specified = FALSE;
      foreach ($ethnicityColumns as $machine => $index) {
        if (!empty($row[$index])) {
          $label = $ethnicityMap[$machine];
          $ethnicityCounts[$label]++;
          $specified = TRUE;
        }
      }
      if (!$specified) {
        $ethnicityCounts[$ethnicityMap['not_specified']]++;
      }
    }

    // Goal data.
    $goalLookup = [];
    array_shift($goals);
    foreach ($goals as $row) {
      if (!empty($row[0]) && isset($row[1])) {
        $goalLookup[trim((string) $row[0])] = (float) $row[1];
      }
    }

    $goalGender = [
      'Male' => $goalLookup['goal_gender_male'] ?? 0,
      'Female' => $goalLookup['goal_gender_female'] ?? 0,
      'Non-Binary' => $goalLookup['goal_gender_nonbinary'] ?? 0,
      'Other/Unknown' => 0,
    ];
    $goalAge = [
      '<30' => $goalLookup['goal_age_lt30'] ?? 0,
      '30-39' => $goalLookup['goal_age_30_39'] ?? 0,
      '40-49' => $goalLookup['goal_age_40_49'] ?? 0,
      '50-59' => $goalLookup['goal_age_50_59'] ?? 0,
      '60+' => $goalLookup['goal_age_60plus'] ?? 0,
      'Unknown' => 0,
    ];
    $goalEthnicity = [];
    foreach ($ethnicityMap as $machine => $label) {
      $goalEthnicity[$label] = $goalLookup['goal_ethnicity_' . $machine] ?? 0;
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

}
