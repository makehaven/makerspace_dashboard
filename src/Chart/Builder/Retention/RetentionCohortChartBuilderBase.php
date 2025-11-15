<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Shared helpers for retention cohort charts.
 */
abstract class RetentionCohortChartBuilderBase extends ChartBuilderBase {

  protected MembershipMetricsService $membershipMetrics;

  protected ?array $cohortData = NULL;

  public function __construct(MembershipMetricsService $membershipMetrics, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->membershipMetrics = $membershipMetrics;
  }

  protected function getCohorts(): array {
    if ($this->cohortData === NULL) {
      $currentYear = (int) date('Y');
      $this->cohortData = $this->membershipMetrics->getAnnualCohorts(2012, $currentYear);
    }
    return $this->cohortData;
  }

}
