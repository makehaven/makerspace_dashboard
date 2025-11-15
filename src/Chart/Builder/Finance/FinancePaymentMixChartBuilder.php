<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Builds the payment mix distribution chart.
 */
class FinancePaymentMixChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'payment_mix';
  protected const WEIGHT = 20;

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
    $mix = $this->financialDataService->getPaymentMix();
    if (empty($mix)) {
      return NULL;
    }

    $labels = array_map('strval', array_keys($mix));
    $values = array_map('intval', array_values($mix));

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'pie',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => $values,
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => [
            'position' => 'top',
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Payment Method Mix'),
      (string) $this->t('Breaks down active members by their recorded payment method.'),
      $visualization,
      [
        (string) $this->t('Source: profile field_member_payment_method values on active members.'),
        (string) $this->t('Processing: Counts the first recorded payment method per member; multi-select entries contribute once per unique value.'),
        (string) $this->t('Definitions: Intended for directional mix shifts—not a substitute for revenue reporting.'),
      ],
    );
  }

}
