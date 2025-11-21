<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Operations;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\lending_library\Service\StatsCollectorInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Visualizes how loan volume and value trend together.
 */
class OperationsLendingLibraryLoanValueChartBuilder extends OperationsChartBuilderBase {

  protected const CHART_ID = 'lending_value_vs_items';
  protected const WEIGHT = 15;

  protected StatsCollectorInterface $statsCollector;

  public function __construct(StatsCollectorInterface $statsCollector, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->statsCollector = $statsCollector;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $stats = $this->statsCollector->collect();
    $series = $stats['chart_data']['loan_value_vs_items'] ?? [];
    if (empty($series)) {
      return NULL;
    }

    $labels = [];
    $loanCounts = [];
    $uniqueItems = [];
    $valueTotals = [];
    foreach ($series as $point) {
      $labels[] = (string) ($point['label'] ?? '');
      $loanCounts[] = (int) ($point['loan_count'] ?? 0);
      $uniqueItems[] = (int) ($point['unique_items'] ?? 0);
      $valueTotals[] = round((float) ($point['total_value'] ?? 0), 2);
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'type' => 'bar',
            'label' => (string) $this->t('Loans'),
            'data' => $loanCounts,
            'backgroundColor' => 'rgba(14,165,233,0.45)',
            'borderColor' => '#0ea5e9',
            'borderWidth' => 1,
            'yAxisID' => 'yLoans',
          ],
          [
            'type' => 'bar',
            'label' => (string) $this->t('Unique items'),
            'data' => $uniqueItems,
            'backgroundColor' => 'rgba(56,189,248,0.35)',
            'borderColor' => '#38bdf8',
            'borderWidth' => 1,
            'yAxisID' => 'yLoans',
          ],
          [
            'type' => 'line',
            'label' => (string) $this->t('Value on loan ($)'),
            'data' => $valueTotals,
            'borderColor' => '#22c55e',
            'backgroundColor' => 'rgba(34,197,94,0.15)',
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
            'borderWidth' => 3,
            'fill' => FALSE,
            'tension' => 0.3,
            'yAxisID' => 'yValue',
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'yLoans' => [
            'position' => 'left',
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('# of items loaned'),
            ],
          ],
          'yValue' => [
            'position' => 'right',
            'grid' => ['drawOnChartArea' => FALSE],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Total value borrowed ($)'),
            ],
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

    return $this->newDefinition(
      (string) $this->t('Loan value versus volume'),
      (string) $this->t('Tracks how many items are checked out alongside the total replacement value borrowed each month.'),
      $visualization,
      [
        (string) $this->t('Source: lending_library stats collector monthly withdraw dataset.'),
        (string) $this->t('Processing: Each transaction contributes its item replacement value during the month it is checked out.'),
      ],
    );
  }

}
