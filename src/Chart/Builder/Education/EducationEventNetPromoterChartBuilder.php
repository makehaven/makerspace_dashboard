<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Plots the rolling event net promoter score.
 */
class EducationEventNetPromoterChartBuilder extends EducationEvaluationChartBuilderBase {

  protected const CHART_ID = 'event_net_promoter';
  protected const WEIGHT = 26;
  protected const TIER = 'supplemental';

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $bounds = $this->calculateRangeBounds($activeRange, $this->now());
    if (!$bounds['start']) {
      $bounds['start'] = $bounds['end']->modify('-1 year');
    }

    $series = $this->evaluationDataService->getNetPromoterSeries($bounds['start'], $bounds['end']);
    if (empty(array_filter($series['nps'] ?? [], static fn($value) => $value !== NULL))) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $series['labels'],
        'datasets' => [[
          'label' => (string) $this->t('Net Promoter Score'),
          'data' => $series['nps'],
          'borderColor' => '#16a34a',
          'backgroundColor' => 'rgba(22,163,74,0.15)',
          'tension' => 0.3,
          'fill' => TRUE,
          'pointRadius' => 3,
          'pointHoverRadius' => 5,
          'spanGaps' => TRUE,
          'makerspaceCounts' => $series['counts'] ?? [],
        ]],
      ],
      'options' => [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'y' => [
            'suggestedMin' => -100,
            'suggestedMax' => 100,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Score (-100 to 100)'),
            ],
          ],
        ],
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'decimal',
                'decimals' => 1,
              ]),
              'afterLabel' => $this->chartCallback('dataset_members_count', []),
            ],
          ],
        ],
      ],
    ];

    $overall = $series['overall'] ?? [];
    $notes = [
      (string) $this->t('Window: @start – @end', [
        '@start' => $bounds['start']->format('M j, Y'),
        '@end' => $bounds['end']->format('M j, Y'),
      ]),
      (string) $this->t('Source: Event feedback form question "How likely are you to recommend this event to others?"'),
      (string) $this->t('Processing: 5-star ratings are treated as promoters, 4-star as passives, and 1-3 stars as detractors. NPS = promoter% – detractor%.'),
    ];
    if (!empty($overall['responses'])) {
      $notes[] = (string) $this->t('Overall: @nps NPS from @count responses (Promoters @prom%, Detractors @det%).', [
        '@nps' => $overall['nps'],
        '@count' => $overall['responses'],
        '@prom%' => $overall['promoter_rate'],
        '@det%' => $overall['detractor_rate'],
      ]);
    }

    return $this->newDefinition(
      (string) $this->t('Net Promoter Score (Events)'),
      (string) $this->t('Shows whether promoters outnumber detractors in recent event evaluations.'),
      $visualization,
      $notes,
      $this->buildRangeMetadata($activeRange, self::RANGE_OPTIONS),
    );
  }

}
