<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Builds the average recorded monthly payment chart.
 */
class FinanceAverageMonthlyPaymentChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'average_payment';
  protected const WEIGHT = 30;

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
    $data = $this->financialDataService->getAverageMonthlyPaymentByType();
    $types = $data['types'] ?? [];
    if (empty($types)) {
      return NULL;
    }

    $labels = array_map('strval', array_keys($types));
    $values = array_map(static fn(array $row) => round((float) ($row['average'] ?? 0), 2), $types);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Average $ / month'),
          'data' => $values,
          'backgroundColor' => 'rgba(37, 99, 235, 0.25)',
          'borderColor' => '#2563eb',
          'borderWidth' => 1,
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => 'function(context){ const value = context.parsed.y ?? context.raw; return "$" + Number(value ?? 0).toFixed(2); }',
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'ticks' => [
              'callback' => 'function(value){ return "$" + Number(value ?? 0).toFixed(0); }',
            ],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Source: field_member_payment_monthly on active default member profiles with status = 1.'),
      (string) $this->t('Processing: Averages non-zero monthly payment entries per membership type; blank values are excluded.'),
      (string) $this->t('Definitions: Provides directional signals only—reconcile against billing exports before reporting revenue.'),
    ];

    if (isset($data['overall_average'])) {
      $notes[] = (string) $this->t('Overall recorded average: $@amount per month', ['@amount' => number_format((float) $data['overall_average'], 2)]);
    }
    if (!empty($data['total_revenue'])) {
      $notes[] = (string) $this->t('Projected monthly revenue from recorded entries: $@total', ['@total' => number_format((float) $data['total_revenue'], 2)]);
    }

    return $this->newDefinition(
      (string) $this->t('Average Recorded Monthly Payment by Membership Type'),
      (string) $this->t('Uses recorded monthly payment fields to estimate typical recurring revenue by membership category.'),
      $visualization,
      $notes,
    );
  }

}
