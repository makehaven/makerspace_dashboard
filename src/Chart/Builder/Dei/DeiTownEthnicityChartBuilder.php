<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Dei;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Support\PublicCounts;

/**
 * Displays stacked ethnicity composition for top towns.
 */
class DeiTownEthnicityChartBuilder extends DeiChartBuilderBase {

  protected const CHART_ID = 'town_ethnicity';
  protected const TIER = 'key';
  protected const WEIGHT = 30;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $distribution = $this->demographicsDataService->getTownEthnicityDistribution(8, 5);
    $towns = $distribution['towns'] ?? [];
    $ethnicities = $distribution['ethnicity_labels'] ?? [];
    if (!$towns || !$ethnicities) {
      return NULL;
    }

    $series = [];
    foreach ($ethnicities as $label) {
      $series[$label] = [];
      foreach ($towns as $town) {
        $series[$label][] = (int) ($town['distribution'][$label] ?? 0);
      }
    }
    $safe = PublicCounts::rollUpColumns($series);

    $datasets = [];
    $palette = $this->defaultColorPalette();
    foreach (array_values(array_keys($safe['series'])) as $index => $label) {
      $datasets[] = [
        'label' => $label,
        'data' => $safe['series'][$label],
        'backgroundColor' => $palette[$index % count($palette)],
        'stack' => 'town_ethnicity',
      ];
    }
    if (array_filter($safe['combined'])) {
      $datasets[] = [
        'label' => (string) $this->t('Groups under 5 in that town'),
        'data' => array_values($safe['combined']),
        'backgroundColor' => '#9ca3af',
        'stack' => 'town_ethnicity',
      ];
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => array_map(static fn(array $town) => $town['label'], $towns),
        'datasets' => $datasets,
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'x' => [
            'stacked' => TRUE,
            'ticks' => ['autoSkip' => FALSE],
          ],
          'y' => [
            'stacked' => TRUE,
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Active members'),
            ],
          ],
        ],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'title' => [
            'display' => TRUE,
            'text' => (string) $this->t('Ethnic composition of top towns'),
          ],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'suffix' => ' ' . (string) $this->t('members'),
              ]),
            ],
          ],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Shows the @count towns with the most active members, stacked by CiviCRM ethnicity responses (grouping additional towns under the “Other” threshold).', [
        '@count' => count($towns),
      ]),
      (string) $this->t('Source: Active member profiles joined with CiviCRM contacts, primary addresses, and demographic custom fields.'),
      (string) $this->t('Members without an ethnicity response are counted under “Unspecified”.'),
      (string) $this->t('To protect privacy, any group with fewer than 5 members in a town is combined into “Groups under 5 in that town”.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Top towns by membership & ethnicity'),
      (string) $this->t('Compares the ethnic composition of the busiest towns represented in the membership roster.'),
      $visualization,
      $notes
    );
  }

}
