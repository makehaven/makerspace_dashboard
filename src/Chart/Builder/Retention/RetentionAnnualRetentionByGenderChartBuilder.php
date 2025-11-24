<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Annualized retention segmented by gender identity.
 */
class RetentionAnnualRetentionByGenderChartBuilder extends RetentionSegmentedAnnualRetentionChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'annual_retention_gender';
  protected const WEIGHT = 92;

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
    return 'gender';
  }

  /**
   * {@inheritdoc}
   */
  protected function getChartTitle(): string {
    return (string) $this->t('Cohort Retention by Gender Identity');
  }

  /**
   * {@inheritdoc}
   */
  protected function getChartDescription(): string {
    return (string) $this->t('Annualized retention rate across members’ self-selected gender identities, highlighting the most common responses.');
  }

  /**
   * {@inheritdoc}
   */
  protected function getNotes(): array {
    return array_merge(parent::getNotes(), [
      (string) $this->t('Limited to members who provided a gender value in their main profile.'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function getSeriesLimit(): int {
    return 4;
  }

}

