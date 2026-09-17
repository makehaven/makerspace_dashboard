<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Who has actually introduced the most members.
 *
 * The reason this can exist at all is the resolved projection. Built on the
 * raw answers it would name the wrong winner: "lior trestman" and "lior" are
 * two rows for one person, and "climatehaven" is an organisation.
 */
class OutreachTopReferrersChartBuilder extends ReferralChartBuilderBase {

  protected const CHART_ID = 'top_referrers';
  protected const TIER = 'supplemental';
  protected const WEIGHT = 13;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $rows = $this->referralStats->topReferrers(10);
    if (!$rows) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => array_map(static fn(array $r) => $r['name'], $rows),
        'datasets' => [
          [
            'label' => (string) $this->t('Members introduced'),
            'data' => array_map(static fn(array $r) => $r['count'], $rows),
            'backgroundColor' => '#7c3aed',
          ],
        ],
      ],
      'options' => [
        'indexAxis' => 'y',
        'plugins' => ['legend' => ['display' => FALSE]],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Who Introduces the Most Members'),
      (string) $this->t('Members credited with introducing someone, counting resolved accounts rather than typed names.'),
      $visualization,
      [
        (string) $this->t('Source: field_member_referral, the resolved account, written only by a confirmed staff review.'),
        (string) $this->t('Processing: Counts distinct referred profiles per account. Only names staff have matched appear — someone who has introduced people whose answers are still unreviewed will be missing or undercounted here.'),
        (string) $this->t('Definitions: Organisations and unmatched text never appear, which is the difference between this and counting the raw answers.'),
      ],
    );
  }

}
