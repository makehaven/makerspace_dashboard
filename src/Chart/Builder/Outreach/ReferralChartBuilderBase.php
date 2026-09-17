<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_referrals\Service\ReferralStats;

/**
 * Shared base for the referral charts on the Outreach section.
 *
 * Takes `makerspace_referrals.stats` rather than the demographics service the
 * other outreach builders use, following the same direction of dependency as
 * `lending_library.stats_collector`: the dashboard depends on the owning
 * module, never the other way round.
 *
 * Every one of these counts **resolved referrer accounts, not strings**. The
 * free-text answers collapse 547 rows into 429 distinct names — one member is
 * split across two spellings and an organisation sits in the standings — so a
 * chart built on the raw text names the wrong top referrer.
 */
abstract class ReferralChartBuilderBase extends ChartBuilderBase {

  protected const SECTION_ID = 'outreach';

  public function __construct(
    protected ReferralStats $referralStats,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

}
