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
  protected const WEIGHT = 72;
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
          (string) $this->t('Annual Value Saved'),
          '$' . number_format($value_saved, 0),
        ],
        [
          (string) $this->t('Resolution Rate'),
          number_format((float) $resolution_rate, 1) . '%',
        ],
        [
          (string) $this->t('Members at Risk'),
          number_format($members_at_risk, 0),
        ],
        [
          (string) $this->t('Avg Days to Resolution'),
          number_format((float) $avg_days, 1),
        ],
      ],
      'empty' => (string) $this->t('Intervention ROI metrics are not available yet.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Intervention ROI Summary'),
      (string) $this->t('Key performance indicators for member outreach program.'),
      $visualization,
      [
        (string) $this->t('Annual Value Saved: Sum of monthly payments × 12 for resolved members.'),
        (string) $this->t('Resolution Rate: (Resolved ÷ Contacted) × 100.'),
        (string) $this->t('Avg Days: Time from first contact to resolution.'),
      ],
    );
  }

}
