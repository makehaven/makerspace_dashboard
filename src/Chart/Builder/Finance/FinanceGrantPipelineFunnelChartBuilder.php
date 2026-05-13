<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\DevelopmentDataService;

/**
 * Grant pipeline funnel: dollars at each stage from research through decision.
 */
class FinanceGrantPipelineFunnelChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'grant_pipeline_funnel';
  protected const WEIGHT = 60;

  public function __construct(
    protected DevelopmentDataService $developmentData,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $stages = $this->developmentData->getGrantPipelineFunnel(12);
    if (empty($stages)) {
      return NULL;
    }
    $totalAmount = 0.0;
    foreach ($stages as $row) {
      $totalAmount += (float) $row['amount'];
    }
    if ($totalAmount <= 0) {
      return NULL;
    }

    $stageColors = [
      'researching' => '#94a3b8',
      'inquiry' => '#60a5fa',
      'writing' => '#3b82f6',
      'waiting' => '#1d4ed8',
      'won' => '#16a34a',
      'lost' => '#dc2626',
    ];

    $labels = [];
    $values = [];
    $counts = [];
    $colors = [];
    foreach ($stages as $row) {
      $labels[] = (string) $this->t($row['label']);
      $values[] = round((float) $row['amount'], 2);
      $counts[] = (int) $row['count'];
      $colors[] = $stageColors[$row['stage']] ?? '#6b7280';
    }

    $datasets = [[
      'label' => (string) $this->t('Requested $'),
      'data' => $values,
      'backgroundColor' => $colors,
      'borderColor' => $colors,
    ]];

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'responsive' => TRUE,
        'indexAxis' => 'y',
        'scales' => [
          'x' => [
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
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'currency',
                'currency' => 'USD',
                'decimals' => 0,
              ]),
            ],
          ],
        ],
      ],
    ];

    $countSummary = [];
    foreach ($stages as $row) {
      $countSummary[] = sprintf('%s: %d', $row['label'], $row['count']);
    }

    return $this->newDefinition(
      (string) $this->t('Grant pipeline funnel ($)'),
      (string) $this->t('Requested grant dollars at each stage from research to decision. Pipeline stages (research → submitted) are point-in-time totals; Won/Lost sum the trailing 12 months for an apples-to-apples view of recent performance.'),
      $visualization,
      [
        (string) $this->t('Source: civicrm_value_funding_7 (request_amount_24, grant_status_14, date_due_21).'),
        (string) $this->t('Counts: @counts', ['@counts' => implode(' · ', $countSummary)]),
        (string) $this->t('Caveat: Won/Lost reflect the request amount, not the awarded amount (no separate awarded-amount field exists today). Adding one would make the funnel sharper.'),
      ],
    );
  }

}
