<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Lists Greater New Haven towns with population vs. member counts.
 */
class OutreachRegionalSaturationChartBuilder extends OutreachChartBuilderBase {

  protected const CHART_ID = 'regional_saturation';
  protected const WEIGHT = 5;

  /**
   * Key Greater New Haven municipalities with 2020 Census populations.
   */
  private const GREATER_NEW_HAVEN_TOWNS = [
    ['name' => 'New Haven', 'population' => 134023],
    ['name' => 'Hamden', 'population' => 61169],
    ['name' => 'Meriden', 'population' => 60850],
    ['name' => 'West Haven', 'population' => 55292],
    ['name' => 'Milford', 'population' => 50558],
    ['name' => 'Wallingford', 'population' => 44396],
    ['name' => 'Branford', 'population' => 28273],
    ['name' => 'East Haven', 'population' => 27923],
    ['name' => 'North Haven', 'population' => 24253],
    ['name' => 'Guilford', 'population' => 22073],
    ['name' => 'Madison', 'population' => 17691],
    ['name' => 'Orange', 'population' => 14280],
    ['name' => 'North Branford', 'population' => 13544],
    ['name' => 'Woodbridge', 'population' => 9087],
    ['name' => 'Bethany', 'population' => 5297],
  ];

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $localityCounts = $this->demographicsDataService->getLocalityCounts();
    if (empty(self::GREATER_NEW_HAVEN_TOWNS)) {
      return NULL;
    }

    $listedRows = [];
    $listedMemberTotal = 0;
    $coreTownKeys = [];
    foreach (self::GREATER_NEW_HAVEN_TOWNS as $town) {
      $key = mb_strtolower($town['name']);
      $coreTownKeys[$key] = TRUE;
      $members = (int) ($localityCounts[$key]['count'] ?? 0);
      $population = (int) $town['population'];
      $penetration = $population > 0 ? ($members / $population) * 100 : 0;
      $listedRows[] = [
        'town' => $town['name'],
        'population' => $population,
        'members' => $members,
        'penetration' => $penetration,
      ];
      $listedMemberTotal += $members;
    }

    $knownMemberTotal = 0;
    foreach ($localityCounts as $key => $row) {
      if ($key === '__unknown') {
        continue;
      }
      $knownMemberTotal += (int) ($row['count'] ?? 0);
    }
    $unknownCount = (int) ($localityCounts['__unknown']['count'] ?? 0);
    $profiledMembers = $knownMemberTotal + $unknownCount;
    $coverageShare = $profiledMembers > 0 ? $knownMemberTotal / $profiledMembers : 0;
    $coveragePercent = $coverageShare * 100;

    $na = (string) $this->t('N/A');

    $tableRows = [];
    foreach ($listedRows as $row) {
      $adjustedPenetration = ($row['population'] > 0 && $coverageShare > 0)
        ? min(100, ($row['members'] / $coverageShare) / $row['population'] * 100)
        : 0;
      $membershipShare = $profiledMembers > 0 ? ($row['members'] / $profiledMembers) * 100 : 0;
      $tableRows[] = [
        $row['town'],
        number_format($row['population']),
        number_format($row['members']),
        $this->formatPercent($membershipShare, 1),
        $this->formatPercent($row['penetration']),
        $this->formatPercent($adjustedPenetration),
      ];
    }

    $otherMembers = max(0, $knownMemberTotal - $listedMemberTotal);
    if ($otherMembers > 0) {
      $otherShare = $profiledMembers > 0 ? ($otherMembers / $profiledMembers) * 100 : 0;
      $tableRows[] = [
        (string) $this->t('Other provided localities (outside core towns)'),
        $na,
        number_format($otherMembers),
        $this->formatPercent($otherShare, 1),
        $na,
        $na,
      ];
    }

    if ($unknownCount > 0) {
      $unknownShare = $profiledMembers > 0 ? ($unknownCount / $profiledMembers) * 100 : 0;
      $tableRows[] = [
        (string) $this->t('No locality provided'),
        $na,
        number_format($unknownCount),
        $this->formatPercent($unknownShare, 1),
        $na,
        $na,
      ];
    }

    if ($profiledMembers > 0) {
      $tableRows[] = [
        (string) $this->t('Total active members counted'),
        $na,
        number_format($profiledMembers),
        $this->formatPercent(100, 1),
        $na,
        $na,
      ];
    }

    $visualization = [
      'type' => 'table',
      'header' => [
        (string) $this->t('Profile locality'),
        (string) $this->t('Population (2020)'),
        (string) $this->t('Active Members'),
        (string) $this->t('% of Active Members'),
        (string) $this->t('% of Population'),
        (string) $this->t('Est. % (All Members)'),
      ],
      'rows' => $tableRows,
      'empty' => (string) $this->t('No profile locality data found for the selected towns.'),
    ];

    $otherLocalities = [];
    foreach ($localityCounts as $key => $row) {
      if ($key === '__unknown' || isset($coreTownKeys[$key])) {
        continue;
      }
      $count = (int) ($row['count'] ?? 0);
      if ($count <= 0) {
        continue;
      }
      $label = (string) ($row['label'] ?? $row['locality'] ?? $key);
      $otherLocalities[] = [
        'label' => $label,
        'count' => $count,
      ];
    }

    usort($otherLocalities, static fn(array $a, array $b) => $b['count'] <=> $a['count']);
    $topOtherLocalities = array_slice($otherLocalities, 0, 5);
    $topOtherTotal = array_sum(array_map(static fn(array $place) => $place['count'], $topOtherLocalities));
    $remainingOther = max(0, $otherMembers - $topOtherTotal);

    $otherSummaryRows = [];
    foreach ($topOtherLocalities as $place) {
      $share = $knownMemberTotal > 0 ? ($place['count'] / $knownMemberTotal) * 100 : 0;
      $otherSummaryRows[] = [
        $place['label'],
        number_format($place['count']),
        $this->formatPercent($share, 1),
      ];
    }

    if ($remainingOther > 0) {
      $share = $knownMemberTotal > 0 ? ($remainingOther / $knownMemberTotal) * 100 : 0;
      $otherSummaryRows[] = [
        (string) $this->t('All other provided localities'),
        number_format($remainingOther),
        $this->formatPercent($share, 1),
      ];
    }

    $otherSummaryTable = NULL;
    if (!empty($otherSummaryRows)) {
      $otherSummaryTable = [
        'type' => 'table',
        'header' => [
          (string) $this->t('Provided locality'),
          (string) $this->t('Active members (non-core)'),
          (string) $this->t('Share of known locations'),
        ],
        'rows' => $otherSummaryRows,
        'empty' => (string) $this->t('No other provided localities found outside the listed core towns.'),
      ];
    }

    if ($otherSummaryTable) {
      $visualization = [
        'type' => 'container',
        'attributes' => ['class' => ['pie-chart-pair-container']],
        'children' => [
          'core_localities' => $visualization,
          'other_localities' => $otherSummaryTable,
        ],
      ];
    }

    $listedShare = $knownMemberTotal > 0 ? ($listedMemberTotal / $knownMemberTotal) * 100 : 0;

    $notes = [
      (string) $this->t('@towns core towns in the Greater New Haven region using 2020 U.S. Census populations.', [
        '@towns' => count(self::GREATER_NEW_HAVEN_TOWNS),
      ]),
      (string) $this->t('Population source: 2020 U.S. Census QuickFacts for Connecticut municipalities.'),
      (string) $this->t('Counts include active users with a default "main" profile and membership roles where field_member_address_locality matches the listed locality.'),
      (string) $this->t('Location coverage: @known of @total profiled members (~@coverage%) provide a locality in their profile address. The estimated % column scales each town’s share assuming the remaining @unknown% without location data follow the same distribution.', [
        '@known' => number_format($knownMemberTotal),
        '@total' => number_format($profiledMembers),
        '@coverage' => number_format($coveragePercent, 1),
        '@unknown' => number_format(100 - $coveragePercent, 1),
      ]),
      (string) $this->t('Listed core towns represent @members members (~@share% of members with a known locality); use the % columns to spot saturation or outreach gaps.', [
        '@members' => number_format($listedMemberTotal),
        '@share' => number_format($listedShare, 1),
      ]),
      (string) $this->t('Rows for "Other provided localities", "No locality provided", and the total make the Active Members column add up to @total so you can see the full active membership represented in the table.', [
        '@total' => number_format($profiledMembers),
      ]),
    ];

    if ($otherSummaryTable) {
      $notes[] = (string) $this->t('The companion summary lists the top @count locations in the "Other" bucket so you can see which nearby towns drive the remaining addresses.', [
        '@count' => count($topOtherLocalities),
      ]);
    }

    return $this->newDefinition(
      (string) $this->t('Greater New Haven Saturation'),
      (string) $this->t('Population benchmarks for the core Greater New Haven towns compared to active member counts.'),
      $visualization,
      $notes,
      NULL,
      NULL,
      ['tags' => ['profile_list', 'user_list']],
    );
  }

  /**
   * Formats a percentage for display.
   */
  protected function formatPercent(float $value, int $precision = 2): string {
    return number_format($value, $precision) . '%';
  }

}
