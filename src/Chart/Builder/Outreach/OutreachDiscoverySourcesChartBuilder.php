<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Component\Utility\Html;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Visualizes member discovery sources.
 */
class OutreachDiscoverySourcesChartBuilder extends OutreachChartBuilderBase {

  protected const CHART_ID = 'discovery_sources';
  protected const WEIGHT = 10;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $rows = $this->demographicsDataService->getDiscoveryDistribution();
    if (empty($rows)) {
      return NULL;
    }

    $labels = array_map(static fn(array $row) => (string) $row['label'], $rows);
    $counts = array_map(static fn(array $row) => (int) $row['count'], $rows);

    $legendEntries = [];
    foreach ($rows as $row) {
      $short = trim((string) ($row['label'] ?? ''));
      $long = trim((string) ($row['full_label'] ?? ''));
      if ($short === '' || $long === '' || $short === $long) {
        continue;
      }
      $legendEntries[] = sprintf('<strong>%s</strong>: %s', Html::escape($short), Html::escape($long));
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => $counts,
          'backgroundColor' => '#0284c7',
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Source: field_member_discovery on active default member profiles with membership roles (defaults: current_member, member).'),
      (string) $this->t('Processing: Aggregates responses and rolls options with fewer than five members into "Other".'),
      (string) $this->t('Definitions: Missing responses surface as "Not captured"; encourage staff to populate this field for richer recruitment insights.'),
    ];
    if (!empty($legendEntries)) {
      $notes[] = [
        '#markup' => '<strong>' . $this->t('Legend') . ':</strong><br>' . implode('<br>', $legendEntries),
      ];
    }

    return $this->newDefinition(
      (string) $this->t('How Members Discovered Us'),
      (string) $this->t('Self-reported discovery sources from member profiles.'),
      $visualization,
      $notes,
    );
  }

}
