<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_member_success\Service\RecoveryMetrics;

/**
 * Builds intervention resolution rate chart.
 */
class RetentionInterventionResolutionRateChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'intervention_resolution_rate';
  protected const WEIGHT = 73;
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

    $trends = $this->recoveryMetrics->getMonthlyTrends(12);
    
    if (empty($trends)) {
      return NULL;
    }

    $labels = [];
    $rates = [];
    $contacted = [];
    $resolved = [];

    foreach ($trends as $trend) {
      $labels[] = $trend['month'];
      $rates[] = (float) $trend['resolution_rate'];
      $contacted[] = (int) $trend['members_contacted'];
      $resolved[] = (int) $trend['resolved'];
    }

    if (!$labels) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Resolution Rate (%)'),
            'data' => $rates,
            'borderColor' => '#16a34a',
            'backgroundColor' => 'rgba(22,163,74,0.15)',
            'tension' => 0.3,
            'pointRadius' => 4,
            'fill' => FALSE,
            'borderWidth' => 3,
            'yAxisID' => 'y',
          ],
          [
            'label' => (string) $this->t('Members Contacted'),
            'data' => $contacted,
            'borderColor' => '#2563eb',
            'backgroundColor' => 'rgba(37,99,235,0.15)',
            'tension' => 0.3,
            'pointRadius' => 3,
            'fill' => FALSE,
            'borderWidth' => 2,
            'yAxisID' => 'y1',
          ],
        ],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['position' => 'top'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value_with_percent', ['format' => 'float']),
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'type' => 'linear',
            'display' => TRUE,
            'position' => 'left',
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Resolution Rate (%)'),
            ],
            'ticks' => [
              'callback' => $this->chartCallback('value_format', ['format' => 'percent', 'showLabel' => FALSE]),
            ],
          ],
          'y1' => [
            'type' => 'linear',
            'display' => TRUE,
            'position' => 'right',
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Members Contacted'),
            ],
            'grid' => [
              'drawOnChartArea' => FALSE,
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Intervention Resolution Rate'),
      (string) $this->t('Success rate of member outreach efforts over time.'),
      $visualization,
      [
        (string) $this->t('Source: `ms_member_outreach_log` table.'),
        (string) $this->t('Resolution: payment_updated or confirmed_cancel outcomes.'),
        (string) $this->t('Rate: (resolved members ÷ contacted members) × 100.'),
      ],
    );
  }

}
