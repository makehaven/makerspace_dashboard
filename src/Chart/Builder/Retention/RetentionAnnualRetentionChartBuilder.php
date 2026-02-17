<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Annualized retention line chart.
 */
class RetentionAnnualRetentionChartBuilder extends RetentionCohortChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'annual_retention';
  protected const WEIGHT = 7;

  public function __construct(MembershipMetricsService $membershipMetrics, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($membershipMetrics, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $cohorts = $this->getCohorts();
    if (!$cohorts) {
      return NULL;
    }

    $labels = [];
    $annualized = [];
    foreach ($cohorts as $row) {
      $labels[] = (string) $row['year'];
      $annualized[] = round((float) $row['annualized_retention_percent'], 2);
    }
    if (!array_sum($annualized)) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Annualized retention %'),
          'data' => $annualized,
          'borderColor' => '#0f172a',
          'backgroundColor' => 'rgba(15,23,42,0.1)',
          'fill' => FALSE,
          'tension' => 0.2,
          'borderWidth' => 2,
          'pointRadius' => 3,
        ]],
      ],
      'options' => [
        'scales' => [
          'y' => [
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Annualized Retention Rate by Cohort'),
      (string) $this->t('Annualized retention rate estimating the average share of members retained each year since joining.'),
      $visualization,
      [
        (string) $this->t('Source: Same cohort dataset as the composition chart, using join dates from profile__field_member_join_date.'),
        (string) $this->t('Processing: Converts the share of members still active into an annualized retention rate (geometric mean) to normalize for cohort age.'),
        (string) $this->t('Definitions: Active roles default to current_member/member; cohorts without active members report 0% annualized retention.'),
      ],
    );
  }

}
