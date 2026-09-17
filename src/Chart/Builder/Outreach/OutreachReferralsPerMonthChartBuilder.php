<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Referrals named each month, against the ones actually resolved.
 *
 * Two series on purpose. The gap between them is the review backlog, and it
 * reads as a backlog without anyone having to explain it — which matters,
 * because the queue held 545 unanswered names against 2 decisions ever
 * recorded when this was built.
 */
class OutreachReferralsPerMonthChartBuilder extends ReferralChartBuilderBase {

  protected const CHART_ID = 'referrals_monthly';
  protected const TIER = 'key';
  protected const WEIGHT = 12;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $rows = $this->referralStats->referralsByMonth(12);

    // An empty chart is worse than no chart.
    $named_total = array_sum(array_column($rows, 'named'));
    if ($named_total === 0) {
      return NULL;
    }

    $labels = [];
    foreach (array_keys($rows) as $period) {
      $labels[] = (new \DateTimeImmutable($period . '-01'))->format('M Y');
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Named a referrer'),
            'data' => array_values(array_column($rows, 'named')),
            'backgroundColor' => '#94a3b8',
          ],
          [
            'label' => (string) $this->t('Resolved to an account'),
            'data' => array_values(array_column($rows, 'resolved')),
            'backgroundColor' => '#0284c7',
          ],
        ],
      ],
      'options' => ['plugins' => ['legend' => ['display' => TRUE]]],
    ];

    return $this->newDefinition(
      (string) $this->t('Referrals Named vs Resolved'),
      (string) $this->t('New members who said a member referred them, and how many of those names staff have matched to an account.'),
      $visualization,
      [
        (string) $this->t('Source: field_member_referring (the answer) and field_member_referral (the resolved account) on main profiles, bucketed by profile creation date.'),
        (string) $this->t('Processing: Counts distinct profiles, not distinct name strings — 547 answers collapse into 429 strings, so counting text splits one person across spellings.'),
        (string) $this->t('Definitions: The gap between the two bars is the review backlog. A name nobody has confirmed earns nobody a thank-you and no credit.'),
      ],
    );
  }

}
