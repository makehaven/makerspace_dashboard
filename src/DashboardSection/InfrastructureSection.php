<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\InfrastructureDataService;
use Drupal\makerspace_dashboard\Service\KpiDataService;
use Drupal\makerspace_dashboard\Service\UtilizationWindowService;

/**
 * Displays tool availability and maintenance signals.
 */
class InfrastructureSection extends DashboardSectionBase {

  /**
   * Infrastructure data service.
   */
  protected InfrastructureDataService $dataService;

  /**
   * Date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * KPI data service.
   */
  protected KpiDataService $kpiDataService;

  /**
   * Utilization window metrics.
   */
  protected UtilizationWindowService $utilizationWindowService;

  /**
   * Constructs the section.
   */
  public function __construct(InfrastructureDataService $data_service, DateFormatterInterface $date_formatter, KpiDataService $kpi_data_service, UtilizationWindowService $utilization_window_service, ChartBuilderManager $chart_builder_manager) {
    parent::__construct(NULL, $chart_builder_manager);
    $this->dataService = $data_service;
    $this->dateFormatter = $date_formatter;
    $this->kpiDataService = $kpi_data_service;
    $this->utilizationWindowService = $utilization_window_service;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'infrastructure';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Infrastructure');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;

    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('infrastructure'));
    $build['kpi_table']['#weight'] = $weight++;

    $utilMetrics = $this->utilizationWindowService->getWindowMetrics();
    $rangeStart = $this->dateFormatter->format($utilMetrics['rolling_start']->getTimestamp(), 'custom', 'M j, Y');
    $rangeEnd = $this->dateFormatter->format($utilMetrics['end_of_day']->getTimestamp(), 'custom', 'M j, Y');
    $build['utilization_intro'] = $this->buildIntro($this->t('Aggregates door access requests into unique members per day to highlight peak usage windows. Showing @start – @end.', [
      '@start' => $rangeStart,
      '@end' => $rangeEnd,
    ]));
    $build['utilization_intro']['#weight'] = $weight++;

    $summaryItems = [
      $this->t('Total entries in last @days days: @total', ['@days' => $utilMetrics['summary']['days'], '@total' => $utilMetrics['summary']['total_entries']]),
      $this->t('Average per day: @avg', ['@avg' => $utilMetrics['summary']['average_per_day']]),
      $this->t('Busiest day: @day (@count members)', [
        '@day' => $utilMetrics['summary']['max_label'],
        '@count' => $utilMetrics['summary']['max_value'],
      ]),
    ];
    if ($utilMetrics['summary']['slope'] !== NULL) {
      $summaryItems[] = $this->t('Rolling trend: Δ = @per_day members/day', ['@per_day' => round($utilMetrics['summary']['slope'], 3)]);
    }
    $build['utilization_summary'] = [
      '#theme' => 'item_list',
      '#items' => $summaryItems,
      '#attributes' => ['class' => ['makerspace-dashboard-summary']],
      '#weight' => $weight++,
    ];

    $build['utilization_definition'] = [
      '#type' => 'markup',
      '#markup' => $this->t('An entry counts every access-control request. A unique entry counts each member once per day, even if they badge multiple times.'),
      '#prefix' => '<div class="makerspace-dashboard-definition">',
      '#suffix' => '</div>',
      '#weight' => $weight++,
    ];

    $charts = $this->buildChartsFromDefinitions($filters);
    if ($charts) {
      $build['charts_section_heading'] = [
        '#markup' => '<h2>' . $this->t('Charts') . '</h2>',
        '#weight' => $weight++,
      ];
      foreach ($charts as $chart_id => $chart_render_array) {
        $chart_render_array['#weight'] = $weight++;
        $build[$chart_id] = $chart_render_array;
      }
    }

    $attention = $this->dataService->getToolsNeedingAttention(12);
    if (!empty($attention)) {
      $rows = [];
      foreach ($attention as $record) {
        $linkMarkup = Link::fromTextAndUrl($record['title'], Url::fromRoute('entity.node.canonical', ['node' => $record['nid']]))->toString();
        $rows[] = [
          'tool' => [
            'data' => [
              '#markup' => $linkMarkup,
            ],
          ],
          'status' => [
            'data' => [
              '#markup' => $record['status'],
            ],
          ],
          'updated' => [
            'data' => [
              '#markup' => $this->dateFormatter->format($record['changed'], 'short'),
            ],
          ],
        ];
      }

      $build['attention_table'] = [
        '#type' => 'table',
        '#header' => [
          'tool' => $this->t('Tool'),
          'status' => $this->t('Status'),
          'updated' => $this->t('Last updated'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['makerspace-dashboard-table']],
        '#weight' => $weight++,
      ];
      $build['attention_table_info'] = $this->buildChartInfo([
        $this->t('Source: Same item dataset limited to non-operational statuses (maintenance, down, retired, etc.).'),
        $this->t('Processing: Sorts by latest update timestamp and trims to the most recent assets needing attention.'),
        $this->t('Definitions: Operational statuses are detected heuristically—ensure the taxonomy uses consistent wording for “Operational” vs “Down”.'),
      ], $this->t('Maintenance notes'));
      $build['attention_table_info']['#weight'] = $weight++;
    }
    else {
      $build['attention_empty'] = [
        '#markup' => $this->t('All tracked tools are currently marked operational.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
        '#weight' => $weight++,
      ];
    }

    $build['#cache'] = [
      'max-age' => 600,
      'tags' => ['node_list:item', 'taxonomy_term_list'],
      'contexts' => ['user.permissions'],
    ];

    return $build;
  }

}
