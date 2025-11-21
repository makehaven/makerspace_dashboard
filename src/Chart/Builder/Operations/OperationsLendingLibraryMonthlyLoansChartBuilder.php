<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Operations;

use Drupal\lending_library\Service\StatsCollectorInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Shows monthly lending library loans.
 */
class OperationsLendingLibraryMonthlyLoansChartBuilder extends OperationsChartBuilderBase {

  protected const CHART_ID = 'lending_monthly_loans';
  protected const WEIGHT = 10;

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
    $series = $stats['chart_data']['monthly_loans'] ?? [];
    if (empty($series)) {
      return NULL;
    }

    $labels = [];
    $values = [];
    foreach ($series as $point) {
      $labels[] = (string) ($point['label'] ?? '');
      $values[] = (int) round((float) ($point['value'] ?? 0));
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Loans'),
          'data' => $values,
          'borderColor' => '#0ea5e9',
          'backgroundColor' => 'rgba(14,165,233,0.15)',
          'tension' => 0.35,
          'fill' => TRUE,
          'pointRadius' => 3,
          'pointHoverRadius' => 5,
          'borderWidth' => 3,
        ]],
      ],
      'options' => [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['display' => FALSE],
        ],
        'scales' => [
          'y' => [
            'ticks' => ['precision' => 0],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Lending Library: Loans (Monthly)'),
      (string) $this->t('Trailing 12 month loan volume for the lending library.'),
      $visualization,
      [
        (string) $this->t('Source: lending_library stats collector monthly loan series.'),
        (string) $this->t('Processing: This chart uses the existing Lending Library dashboard dataset so values match the library admin view.'),
      ],
    );
  }

}
