<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_member_success\Service\RecoveryMetrics;

/**
 * Builds intervention channel effectiveness chart.
 */
class RetentionInterventionChannelEffectivenessChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'intervention_channel_effectiveness';
  protected const WEIGHT = 74;
  protected const TIER = 'supplemental';

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected ?RecoveryMetrics $recoveryMetrics,
    ?TranslationInterface $stringTranslation = NULL
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    if (!$this->recoveryMetrics) {
      return NULL;
    }

    $metrics = $this->recoveryMetrics->getAllMetrics();
    $channels = $metrics['channel_effectiveness'] ?? [];
    
    if (empty($channels)) {
      return NULL;
    }

    $labels = [];
    $rates = [];
    $contacted = [];
    $colors = [
      'phone' => '#16a34a',
      'email' => '#2563eb',
      'in-person' => '#7c3aed',
      'text' => '#f59e0b',
      'other' => '#6b7280',
    ];

    foreach ($channels as $method => $stats) {
      $labels[] = ucfirst($method);
      $rates[] = (float) $stats['rate'];
      $contacted[] = (int) $stats['total'];
    }

    if (!$labels) {
      return NULL;
    }

    // Generate colors for each bar
    $barColors = [];
    foreach (array_keys($channels) as $method) {
      $barColors[] = $colors[$method] ?? '#6b7280';
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Success Rate (%)'),
            'data' => $rates,
            'backgroundColor' => $barColors,
            'borderWidth' => 0,
          ],
        ],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', ['format' => 'percent']),
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'beginAtZero' => TRUE,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Success Rate (%)'),
            ],
            'ticks' => [
              'callback' => $this->chartCallback('value_format', ['format' => 'percent', 'showLabel' => FALSE]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Intervention Channel Effectiveness'),
      (string) $this->t('Comparison of success rates by contact method.'),
      $visualization,
      [
        (string) $this->t('Source: `ms_member_outreach_log` table grouped by contact_method.'),
        (string) $this->t('Higher bars indicate more effective outreach channels.'),
        (string) $this->t('Use this to optimize staff time allocation.'),
      ],
    );
  }

}
