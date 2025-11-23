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
    foreach (self::GREATER_NEW_HAVEN_TOWNS as $town) {
      $key = mb_strtolower($town['name']);
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

    $tableRows = array_map(function (array $row) use ($coverageShare): array {
      $adjustedPenetration = ($row['population'] > 0 && $coverageShare > 0)
        ? min(100, ($row['members'] / $coverageShare) / $row['population'] * 100)
        : 0;
      return [
        $row['town'],
        number_format($row['population']),
        number_format($row['members']),
        $this->formatPercent($row['penetration']),
        $this->formatPercent($adjustedPenetration),
      ];
    }, $listedRows);

    $visualization = [
      'type' => 'table',
      'header' => [
        (string) $this->t('Town'),
        (string) $this->t('Population (2020)'),
        (string) $this->t('Active Members'),
        (string) $this->t('% of Population'),
        (string) $this->t('Est. % (All Members)'),
      ],
      'rows' => $tableRows,
      'empty' => (string) $this->t('No hometown data found for the selected towns.'),
    ];

    $listedShare = $knownMemberTotal > 0 ? ($listedMemberTotal / $knownMemberTotal) * 100 : 0;

    return $this->newDefinition(
      (string) $this->t('Greater New Haven Saturation'),
      (string) $this->t('Population benchmarks for the core Greater New Haven towns compared to active member counts.'),
      $visualization,
      [
        (string) $this->t('@towns core towns in the Greater New Haven region using 2020 U.S. Census populations.', [
          '@towns' => count(self::GREATER_NEW_HAVEN_TOWNS),
        ]),
        (string) $this->t('Population source: 2020 U.S. Census QuickFacts for Connecticut municipalities.'),
        (string) $this->t('Counts include active users with a default "main" profile and membership roles where field_member_address_locality matches the listed town.'),
        (string) $this->t('Location coverage: @known of @total profiled members (~@coverage%) list a hometown. The estimated % column scales each town’s share assuming the remaining @unknown% without hometown data follow the same distribution.', [
          '@known' => number_format($knownMemberTotal),
          '@total' => number_format($profiledMembers),
          '@coverage' => number_format($coveragePercent, 1),
          '@unknown' => number_format(100 - $coveragePercent, 1),
        ]),
        (string) $this->t('Listed towns represent @members members (~@share% of members with known hometowns); use the % columns to spot saturation or outreach gaps.', [
          '@members' => number_format($listedMemberTotal),
          '@share' => number_format($listedShare, 1),
        ]),
      ],
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
