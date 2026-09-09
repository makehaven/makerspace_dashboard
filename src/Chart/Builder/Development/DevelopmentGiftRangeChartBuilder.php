<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Development;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\DevelopmentDataService;

/**
 * Builds the current-year donor and revenue distribution by gift range.
 */
class DevelopmentGiftRangeChartBuilder extends DevelopmentChartBuilderBase {

  protected const CHART_ID = 'gift_ranges';
  protected const WEIGHT = 20;
  protected const TIER = 'supplemental';

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
    $summary = $this->developmentDataService->getGiftRangeBreakdown();
    $rows = array_values(array_filter($summary['ranges'] ?? [], static fn(array $row): bool => (int) ($row['donors'] ?? 0) > 0));
    if (!$rows) {
      return NULL;
    }

    return $this->newDefinition(
      (string) $this->t('Giving by Donor Range'),
      (string) $this->t('How donor participation and contributed revenue are distributed across annual giving levels.'),
      [
        'type' => 'chart',
        'library' => 'chartjs',
        'chartType' => 'bar',
        'data' => [
          'labels' => array_map(static fn(array $row): string => (string) $row['label'], $rows),
          'datasets' => [
            [
              'label' => (string) $this->t('Donors'),
              'data' => array_map(static fn(array $row): int => (int) $row['donors'], $rows),
              'backgroundColor' => 'rgba(124, 58, 237, 0.3)',
              'borderColor' => '#7c3aed',
              'yAxisID' => 'donors',
            ],
            [
              'label' => (string) $this->t('Revenue'),
              'data' => array_map(static fn(array $row): float => (float) $row['amount'], $rows),
              'backgroundColor' => 'rgba(13, 148, 136, 0.3)',
              'borderColor' => '#0d9488',
              'yAxisID' => 'revenue',
            ],
          ],
        ],
        'options' => [
          'scales' => [
            'donors' => ['position' => 'left', 'beginAtZero' => TRUE],
            'revenue' => ['position' => 'right', 'beginAtZero' => TRUE, 'grid' => ['drawOnChartArea' => FALSE]],
          ],
          'plugins' => [
            'tooltip' => ['callbacks' => ['label' => $this->chartCallback('series_value', [
              'perAxis' => [
                'donors' => ['format' => 'integer'],
                'revenue' => ['format' => 'currency', 'currency' => 'USD', 'decimals' => 0],
              ],
            ])]],
          ],
        ],
      ],
      [
        (string) $this->t('Source: CiviCRM completed contributions grouped by each donor’s calendar-year total.'),
        (string) $this->t('Period: @year through the latest available month.', ['@year' => $summary['year'] ?? date('Y')]),
      ],
    );
  }

}
