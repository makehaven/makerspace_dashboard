<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_member_success\Service\RecoveryMetrics;

/**
 * Builds intervention ROI metrics card.
 */
class RetentionInterventionROIChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'intervention_roi';
  protected const WEIGHT = 15;
  protected const TIER = 'supplemental';

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected ?RecoveryMetrics $recoveryMetrics,
    ?TranslationInterface $stringTranslation = NULL
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    if (!$this->recoveryMetrics) {
      return NULL;
    }

    $roi = $this->recoveryMetrics->getRetentionValue();
    $metrics = $this->recoveryMetrics->getAllMetrics();
    
    if (empty($roi)) {
      return NULL;
    }

    $value_saved = $roi['annual_value_saved'] ?? 0;
    $resolution_rate = $metrics['resolution_rate']['rate'] ?? 0;
    $members_at_risk = $roi['total_members_at_risk'] ?? 0;
    $avg_days = $metrics['avg_days_to_resolution'] ?? 0;

    $visualization = [
      'type' => 'table',
      'header' => [
        (string) $this->t('Metric'),
        (string) $this->t('Value'),
      ],
      'rows' => [
        [
          (string) $this->t('Annualized Dues of Resolved Members'),
          '$' . number_format($value_saved, 0),
        ],
        [
          (string) $this->t('Resolution Rate'),
          number_format((float) $resolution_rate, 1) . '%',
        ],
        [
          (string) $this->t('Members Contacted'),
          number_format($members_at_risk, 0),
        ],
        [
          (string) $this->t('Avg Days to Resolution'),
          number_format((float) $avg_days, 1),
        ],
      ],
      'empty' => (string) $this->t('Member outreach outcomes are not available yet.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Member Outreach Outcomes'),
      (string) $this->t('Outreach dispositions and associated dues across recorded contacts.'),
      $visualization,
      [
        (string) $this->t('Annualized dues: current recorded monthly dues × 12 for members with a resolved outreach outcome. This estimates associated dues, not collected revenue or savings attributable to outreach.'),
        (string) $this->t('Resolution Rate: (Resolved ÷ Contacted) × 100. A resolved case does not establish continued membership or a successful payment.'),
        (string) $this->t('Avg Days: Time from first contact to resolution.'),
      ],
    );
  }

}
