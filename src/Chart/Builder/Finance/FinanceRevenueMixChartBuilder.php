<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Stacked quarterly view of revenue by income stream.
 */
class FinanceRevenueMixChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'revenue_mix';
  protected const WEIGHT = 5;

  public function __construct(
    protected FinancialDataService $financialDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $trend = $this->financialDataService->getRevenueMixTrend(8);
    if (empty($trend['labels']) || empty($trend['series'])) {
      return NULL;
    }

    // Eight colours that stay apart for colour-blind readers when stacked
    // next to each other; the shared default had blue/indigo/violet that
    // looked alike and wrapped its ninth series back onto the first.
    $palette = [
      '#2a78d6',
      '#eb6834',
      '#1baf7a',
      '#eda100',
      '#e87ba4',
      '#008300',
      '#6250d6',
      '#e34948',
    ];
    $series = array_filter($trend['series'], static fn($values) => array_sum($values) > 0);
    // A ninth colour would repeat one, so the smallest streams fold into
    // "Other earned income" instead.
    $other = 'Other earned income';
    while (count($series) > count($palette) && isset($series[$other])) {
      $totals = array_map('array_sum', array_diff_key($series, [$other => TRUE]));
      $smallest = array_search(min($totals), $totals, TRUE);
      foreach ($series[$smallest] as $i => $value) {
        $series[$other][$i] = ($series[$other][$i] ?? 0) + $value;
      }
      unset($series[$smallest]);
    }

    $datasets = [];
    $index = 0;
    foreach ($series as $label => $values) {
      $color = $palette[$index % count($palette)];
      $datasets[] = [
        'label' => (string) $this->t($label),
        'data' => array_map(static fn($v) => round((float) $v, 2), $values),
        'backgroundColor' => $color,
        'borderColor' => $color,
        'stack' => 'revenue_mix',
      ];
      $index++;
    }

    if (empty($datasets)) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $trend['labels'],
        'datasets' => $datasets,
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'x' => ['stacked' => TRUE],
          'y' => [
            'stacked' => TRUE,
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
          'legend' => ['position' => 'bottom'],
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

    return $this->newDefinition(
      (string) $this->t('Revenue mix by income stream'),
      (string) $this->t('Quarterly revenue broken out by source so the finance team can see how the org\'s income is composed and how the mix is shifting over time.'),
      $visualization,
      [
        (string) $this->t('Source: Income-Statement Google Sheet (rows: income_membership, income_education, income_storage, income_workspaces, income_media, income_grants, income_individual_donations, income_corporate_donations, income_total).'),
        (string) $this->t('Processing: 8 trailing quarters; "Other earned income" = income_total minus the sum of named streams, so any line not yet broken out (event hosting, future store/lending) still shows up. Beyond eight streams the smallest (usually Media) folds into Other, so no two streams share a colour.'),
        (string) $this->t('Caveat: Quarterly granularity matches the source sheet. Updates to the sheet refresh within an hour.'),
      ],
    );
  }

}
