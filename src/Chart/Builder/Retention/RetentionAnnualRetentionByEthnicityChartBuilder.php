<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Annualized retention segmented by ethnicity.
 */
class RetentionAnnualRetentionByEthnicityChartBuilder extends RetentionSegmentedAnnualRetentionChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'annual_retention_ethnicity';
  protected const WEIGHT = 91;

  /**
   * {@inheritdoc}
   */
  public function __construct(MembershipMetricsService $membershipMetrics, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($membershipMetrics, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDimension(): string {
    return 'ethnicity';
  }

  /**
   * {@inheritdoc}
   */
  protected function getChartTitle(): string {
    return (string) $this->t('Cohort Retention by Ethnicity');
  }

  /**
   * {@inheritdoc}
   */
  protected function getChartDescription(): string {
    return (string) $this->t('Annualized retention rate for the largest self-reported ethnicity groups, using the same cohort window as the overall metric.');
  }

  /**
   * {@inheritdoc}
   */
  protected function getNotes(): array {
    return array_merge(parent::getNotes(), [
      (string) $this->t('Includes the top @count ethnicity selections (by total members with a main profile).', ['@count' => $this->getSeriesLimit()]),
      (string) $this->t('Members can select multiple ethnicities; each group is analyzed independently.'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function getSeriesLimit(): int {
    return 5;
  }

}

