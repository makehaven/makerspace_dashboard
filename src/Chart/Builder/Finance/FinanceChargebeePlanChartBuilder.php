<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Builds the Chargebee plan distribution chart.
 */
class FinanceChargebeePlanChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'chargebee_plans';
  protected const WEIGHT = 40;

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected FinancialDataService $financialDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $plans = $this->financialDataService->getChargebeePlanDistribution();
    if (empty($plans)) {
      return NULL;
    }

    $labels = array_map('strval', array_keys($plans));
    $values = array_map('intval', array_values($plans));

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'pie',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Users'),
          'data' => $values,
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => [
            'position' => 'top',
          ],
          'datalabels' => [
            'formatter' => $this->chartCallback('dataset_share_percent', [
              'decimals' => 1,
            ]),
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Chargebee Plan Distribution'),
      (string) $this->t('Active users grouped by Chargebee plan assignment.'),
      $visualization,
      [
        (string) $this->t('Source: user profile field_user_chargebee_plan for published users.'),
        (string) $this->t('Processing: Counts distinct users per plan; empty values appear as "Unassigned".'),
        (string) $this->t('Definitions: Reflects CRM state only—verify against Chargebee exports before acting on billing changes.'),
      ],
    );
  }

}
