<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\makerspace_dashboard\Service\InfrastructureDataService;

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
   * Constructs the section.
   */
  public function __construct(InfrastructureDataService $data_service, DateFormatterInterface $date_formatter) {
    parent::__construct();
    $this->dataService = $data_service;
    $this->dateFormatter = $date_formatter;
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

    // This build method is organized into two main parts:
    // 1. Tool Status Breakdown: A chart showing the distribution of tools by their current status.
    // 2. Tools Needing Attention: A table listing tools that require maintenance or are otherwise non-operational.

    $statusCounts = $this->dataService->getToolStatusCounts();
    $totalTools = array_sum($statusCounts);

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Monitor tool availability by status and surface assets needing attention. Total tracked tools: @count.', [
        '@count' => $totalTools,
      ]),
    ];

    if (!empty($statusCounts)) {
      $labels = array_keys($statusCounts);
      $counts = array_values($statusCounts);

      $tool_status_breakdown_chart = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#raw_options' => [
          'options' => [
            'plugins' => [
              'legend' => ['display' => FALSE],
              'tooltip' => [
                'callbacks' => [
                  'label' => 'function(context){ const value = context.parsed.y ?? context.raw; return value.toLocaleString() + " tools"; }',
                ],
              ],
            ],
            'scales' => [
              'y' => [
                'ticks' => [
                  'precision' => 0,
                  'callback' => 'function(value){ return value.toLocaleString(); }',
                ],
              ],
            ],
          ],
        ],
      ];
      $tool_status_breakdown_chart['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Tools'),
        '#data' => $counts,
        '#color' => '#0ea5e9',
      ];
      $tool_status_breakdown_chart['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $labels),
      ];
      $tool_status_breakdown_chart['yaxis'] = [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Count of tools'),
      ];
      $build['tool_status_breakdown_metric'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['metric-container']],
        'heading' => [
          '#markup' => '<h2>' . $this->t('Tools by Status') . '</h2><p>' . $this->t('Counts published equipment records by their current status taxonomy term.') . '</p>',
        ],
        'chart' => $tool_status_breakdown_chart,
        'info' => $this->buildChartInfo([
          $this->t('Source: Published item nodes joined to field_item_status and taxonomy term labels.'),
          $this->t('Processing: Counts each tool once regardless of category; status “Unspecified” captures items missing a taxonomy assignment.'),
          $this->t('Definitions: Status vocabulary is maintained in taxonomy.vocabulary.item_status—align naming conventions to keep the legend concise.'),
        ]),
      ];
    }
    else {
      $build['tool_status_breakdown_empty'] = [
        '#markup' => $this->t('No tool records were found. Populate item nodes with status assignments to enable this chart.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
      ];
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

      $build['attention_table_metric'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['metric-container']],
        'heading' => [
          '#markup' => '<h2>' . $this->t('Tools Needing Attention') . '</h2>',
        ],
        'table' => [
          '#type' => 'table',
          '#header' => [
            'tool' => $this->t('Tool'),
            'status' => $this->t('Status'),
            'updated' => $this->t('Last updated'),
          ],
          '#rows' => $rows,
          '#attributes' => ['class' => ['makerspace-dashboard-table']],
        ],
        'info' => $this->buildChartInfo([
          $this->t('Source: Same item dataset limited to non-operational statuses (maintenance, down, retired, etc.).'),
          $this->t('Processing: Sorts by latest update timestamp and trims to the most recent assets needing attention.'),
          $this->t('Definitions: Operational statuses are detected heuristically—ensure the taxonomy uses consistent wording for “Operational” vs “Down”.'),
        ], $this->t('Maintenance notes')),
      ];
    }
    else {
      $build['attention_empty'] = [
        '#markup' => $this->t('All tracked tools are currently marked operational.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
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
