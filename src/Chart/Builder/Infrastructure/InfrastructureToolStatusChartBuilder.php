<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Infrastructure;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\InfrastructureDataService;

/**
 * Builds the tool status distribution chart.
 */
class InfrastructureToolStatusChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'infrastructure';
  protected const CHART_ID = 'tool_status_breakdown';
  protected const WEIGHT = 10;

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected InfrastructureDataService $dataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $statusCounts = $this->dataService->getToolStatusCounts();
    if (empty($statusCounts)) {
      return NULL;
    }

    $labels = array_map('strval', array_keys($statusCounts));
    $values = array_values($statusCounts);
    $datasetLabel = (string) $this->t('Tools');
    $toolsLabel = addslashes((string) $this->t('tools'));

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => $datasetLabel,
          'data' => $values,
          'backgroundColor' => 'rgba(14,165,233,0.35)',
          'borderColor' => '#0ea5e9',
          'borderWidth' => 1,
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => 'function(context){ const value = context.parsed.y ?? context.raw; return value.toLocaleString() + " ' . $toolsLabel . '"; }',
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'beginAtZero' => TRUE,
            'ticks' => [
              'precision' => 0,
              'callback' => 'function(value){ return Number(value ?? 0).toLocaleString(); }',
            ],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Count of tools'),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Tools by Status'),
      (string) $this->t('Counts published equipment records by their current status taxonomy term.'),
      $visualization,
      [
        (string) $this->t('Source: Published item nodes joined to field_item_status and taxonomy term labels.'),
        (string) $this->t('Processing: Counts each tool once regardless of category; status “Unspecified” captures items missing a taxonomy assignment.'),
        (string) $this->t('Definitions: Status vocabulary is maintained in taxonomy.vocabulary.item_status—align naming conventions to keep the legend concise.'),
      ]
    );
  }

}
