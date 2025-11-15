<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;

/**
 * Shared helpers for outreach chart builders.
 */
abstract class OutreachChartBuilderBase extends ChartBuilderBase {

  protected const SECTION_ID = 'outreach';

  public function __construct(
    protected DemographicsDataService $demographicsDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

}
