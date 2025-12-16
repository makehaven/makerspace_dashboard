<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Builds the retention (average tenure) by plan type chart.
 */
class FinanceRetentionByPlanChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'retention_by_plan';
  protected const WEIGHT = 33;

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
    $data = $this->financialDataService->getRetentionByPlanType();

    if (empty($data)) {
      return NULL;
    }

    // Filter out 'Unassigned' if needed, but might be useful to see.
    // Keeping top 10 or similar might be good if there are too many plans.
    // For now, let's just show all.

    $labels = array_keys($data);
    $values = array_values($data);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Avg Tenure (Months)'),
          'data' => $values,
          'backgroundColor' => 'rgba(249, 115, 22, 0.25)', // Orange-ish
          'borderColor' => '#f97316',
          'borderWidth' => 1,
        ]],
      ],
      'options' => [
        'indexAxis' => 'y', // Horizontal bar chart might be better for long plan names
        'plugins' => [
          'legend' => ['display' => FALSE],
        ],
        'scales' => [
          'x' => [
            'title' => [
              'display' => TRUE,
              'text' => 'Months',
            ],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Source: Active users with assigned Chargebee plans.'),
      (string) $this->t('Metric: Average number of months since "Member Join Date" for currently active members on this plan.'),
      (string) $this->t('Interpretation: Higher values indicate plans associated with longer-term members.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Average Member Tenure by Plan Type'),
      (string) $this->t('Comparing how long members on different billing plans have been with the organization.'),
      $visualization,
      $notes
    );
  }

}
