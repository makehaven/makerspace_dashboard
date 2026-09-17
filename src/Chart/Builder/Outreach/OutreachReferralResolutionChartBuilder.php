<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Where the stored referral answers stand.
 *
 * This is the chart that says whether the queue is being worked at all.
 */
class OutreachReferralResolutionChartBuilder extends ReferralChartBuilderBase {

  protected const CHART_ID = 'referral_resolution';
  protected const TIER = 'supplemental';
  protected const WEIGHT = 14;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $counts = $this->referralStats->resolutionBreakdown();
    if (array_sum($counts) === 0) {
      return NULL;
    }

    $labels = [
      (string) $this->t('Resolved to an account'),
      (string) $this->t('Waiting on a decision'),
      (string) $this->t('Not a member'),
      (string) $this->t('Account since deleted'),
    ];
    $data = [
      $counts['resolved'],
      $counts['pending'],
      $counts['external'],
      $counts['unmatched'],
    ];

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'doughnut',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'data' => $data,
            'backgroundColor' => ['#0284c7', '#f59e0b', '#94a3b8', '#ef4444'],
          ],
        ],
      ],
      // Slice labels belong in a chart_xaxis child, not on the series — the
      // Chart.js adapter throws legendItems.reduce otherwise (see CLAUDE.md).
      'xaxis' => ['#type' => 'chart_xaxis', '#labels' => $labels],
    ];

    return $this->newDefinition(
      (string) $this->t('Referral Answers: Resolved or Waiting'),
      (string) $this->t('Every stored answer to "who referred you", and whether anyone has decided who it means.'),
      $visualization,
      [
        (string) $this->t('Source: field_member_referring for the answers, makerspace_referral_review for the decisions, field_member_referral for what was resolved.'),
        (string) $this->t('Processing: One row per profile, counted against its most recent decision, so a re-decided answer counts once.'),
        (string) $this->t('Definitions: "Waiting on a decision" is the number that costs money — until a name is matched, nobody is thanked and no credit is applied.'),
      ],
    );
  }

}
