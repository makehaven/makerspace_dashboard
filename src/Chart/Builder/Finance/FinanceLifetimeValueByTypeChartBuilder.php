<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Builds the lifetime value by membership type chart.
 */
class FinanceLifetimeValueByTypeChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'ltv_by_type';
  protected const WEIGHT = 31;

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
    $data = $this->financialDataService->getLifetimeValueByMembershipType();
    
    // Filter out unknown types if desired, similar to Average Payment chart.
    $filtered = array_filter($data, function($key) {
        $normalized = strtolower(trim((string) $key));
        return $normalized !== 'unknown' && $normalized !== 'unknown / not provided';
    }, ARRAY_FILTER_USE_KEY);

    if (empty($filtered)) {
      return NULL;
    }

    $labels = array_keys($filtered);
    $values = array_values($filtered);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Estimated Lifetime Value'),
          'data' => $values,
          'backgroundColor' => 'rgba(22, 163, 74, 0.25)', // Green-ish
          'borderColor' => '#16a34a',
          'borderWidth' => 1,
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
        ],
        'scales' => [
          'y' => [
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
      (string) $this->t('Source: Active member profiles with valid join dates and monthly payment values.'),
      (string) $this->t('Calculation: (Months Active) * (Current Monthly Payment).'),
      (string) $this->t('Note: This is an estimate based on current payment rates and does not account for past rate changes or pauses.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Estimated Lifetime Value by Membership Type'),
      (string) $this->t('Average total revenue contribution per member, grouped by their current membership type.'),
      $visualization,
      $notes
    );
  }

}
