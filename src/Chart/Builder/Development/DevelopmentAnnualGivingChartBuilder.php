<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Development;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\DevelopmentDataService;

/**
 * Builds annual contributed-revenue and donor-count trends.
 */
class DevelopmentAnnualGivingChartBuilder extends DevelopmentChartBuilderBase {

  protected const CHART_ID = 'annual_giving';
  protected const WEIGHT = 10;

  public function __construct(
    protected DevelopmentDataService $developmentDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $rows = array_reverse($this->developmentDataService->getAnnualGivingSummary(6));
    if (!$rows) {
      return NULL;
    }

    $labels = array_map(static fn(array $row): string => (string) $row['year'], $rows);
    $amounts = array_map(static fn(array $row): float => (float) $row['total_amount'], $rows);
    $donors = array_map(static fn(array $row): int => (int) $row['donors'], $rows);

    return $this->newDefinition(
      (string) $this->t('Annual Giving'),
      (string) $this->t('Contributed revenue and unique donors by calendar year.'),
      [
        'type' => 'chart',
        'library' => 'chartjs',
        'chartType' => 'bar',
        'data' => [
          'labels' => $labels,
          'datasets' => [
            [
              'label' => (string) $this->t('Contributed revenue'),
              'data' => $amounts,
              'backgroundColor' => 'rgba(37, 99, 235, 0.3)',
              'borderColor' => '#2563eb',
              'yAxisID' => 'revenue',
            ],
            [
              'label' => (string) $this->t('Unique donors'),
              'data' => $donors,
              'type' => 'line',
              'borderColor' => '#16a34a',
              'backgroundColor' => '#16a34a',
              'yAxisID' => 'donors',
            ],
          ],
        ],
        'options' => [
          'scales' => [
            'revenue' => ['position' => 'left', 'beginAtZero' => TRUE],
            'donors' => ['position' => 'right', 'beginAtZero' => TRUE, 'grid' => ['drawOnChartArea' => FALSE]],
          ],
          'plugins' => [
            'tooltip' => ['callbacks' => ['label' => $this->chartCallback('series_value', [
              'perAxis' => [
                'revenue' => ['format' => 'currency', 'currency' => 'USD', 'decimals' => 0],
                'donors' => ['format' => 'integer'],
              ],
            ])]],
          ],
        ],
      ],
      [
        (string) $this->t('Source: CiviCRM completed contributions; snapshot facts are used when available and direct contribution totals fill missing years.'),
        (string) $this->t('The current calendar year is partial and should not be compared with completed years without considering the reporting date.'),
      ],
    );
  }

}
