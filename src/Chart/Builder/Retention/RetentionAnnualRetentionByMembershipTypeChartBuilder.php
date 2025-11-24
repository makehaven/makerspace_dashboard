<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Annualized retention segmented by membership type.
 */
class RetentionAnnualRetentionByMembershipTypeChartBuilder extends RetentionSegmentedAnnualRetentionChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'annual_retention_membership_type';
  protected const WEIGHT = 93;

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
    return 'membership_type';
  }

  /**
   * {@inheritdoc}
   */
  protected function getChartTitle(): string {
    return (string) $this->t('Cohort Retention by Membership Type');
  }

  /**
   * {@inheritdoc}
   */
  protected function getChartDescription(): string {
    return (string) $this->t('Compares annualized retention rates across the top membership plans (based on members with a main profile).');
  }

  /**
   * {@inheritdoc}
   */
  protected function getNotes(): array {
    return array_merge(parent::getNotes(), [
      (string) $this->t('Membership type labels come from the field_member_membership_type taxonomy on the main profile.'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function getSeriesLimit(): int {
    return 5;
  }

}

