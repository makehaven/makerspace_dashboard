<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Builds the lifetime value by tenure chart.
 */
class FinanceLifetimeValueByTenureChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'ltv_by_tenure';
  protected const WEIGHT = 32;

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
    $data = $this->financialDataService->getLifetimeValueByTenure();

    if (empty($data)) {
      return NULL;
    }

    $labels = array_keys($data);
    $realized = array_column($data, 'realized');
    $projected = array_column($data, 'projected');
    $projectedMonths = array_column($data, 'projected_months');

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Realized Value (Paid to Date)'),
            'data' => $realized,
            'backgroundColor' => 'rgba(124, 58, 237, 0.7)',
            'borderColor' => '#7c3aed',
            'borderWidth' => 1,
            'stack' => 'stack1',
          ],
          [
            'label' => (string) $this->t('Projected Future Value'),
            'data' => $projected,
            'futureMonths' => $projectedMonths, // Custom data for tooltip
            'backgroundColor' => 'rgba(124, 58, 237, 0.25)',
            'borderColor' => '#7c3aed',
            'borderWidth' => 1,
            'stack' => 'stack1',
          ],
        ],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => TRUE, 'position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => "function(context) {
                let label = context.dataset.label || '';
                if (label) {
                  label += ': ';
                }
                let value = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(context.raw);
                if (context.dataset.futureMonths) {
                  let months = context.dataset.futureMonths[context.dataIndex];
                  return label + value + ' (+' + months + ' months projected)';
                }
                return label + value;
              }",
            ],
          ],
        ],
        'scales' => [
          'x' => [
            'stacked' => TRUE,
          ],
          'y' => [
            'stacked' => TRUE,
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Data Sources: "profile__field_member_payment_monthly" (Revenue) and "profile.created" (Tenure).'),
      (string) $this->t('Realized Value: Sum of (Months Active * Monthly Payment) for all members currently in each tenure bracket.'),
      (string) $this->t('Projected Value: (Avg Monthly Payment) * (Projected Future Months). Future months are derived from a 3-year smoothed churn curve of all historical member tenure records.'),
      (string) $this->t('0 Years Bucket: Represents a hypothetical new member paying the current average rate, projected using the year-0 churn rate.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Lifetime Value by Tenure (Realized + Projected)'),
      (string) $this->t('Total estimated value of members by tenure, combining what they have already paid with what they are expected to pay in the future.'),
      $visualization,
      $notes
    );
  }

}
