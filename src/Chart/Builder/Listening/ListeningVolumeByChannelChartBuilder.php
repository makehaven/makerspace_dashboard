<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Listening;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\ListeningDataService;

/**
 * Charts monthly feedback volume split by channel family.
 *
 * Plotting the families separately rather than as one total is the point: a
 * flat overall line has previously hidden one channel collapsing while another
 * grew. The shape of each band is what shows a channel dying.
 */
class ListeningVolumeByChannelChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'listening';
  protected const CHART_ID = 'volume_by_channel';
  protected const WEIGHT = 10;

  /**
   * Palette keyed by channel family, so colours stay stable across renders.
   */
  protected const FAMILY_COLORS = [
    'website' => '#2563eb',
    'program' => '#16a34a',
    'community' => '#7c3aed',
    'safety' => '#dc2626',
    'access' => '#f97316',
    'survey' => '#0d9488',
    'ai' => '#64748b',
    'facility' => '#a16207',
    'equipment' => '#0369a1',
    'exit' => '#be123c',
    'conduct' => '#4338ca',
    'content' => '#65a30d',
  ];

  /**
   * The feedback aggregator.
   */
  protected ListeningDataService $listeningData;

  /**
   * Constructs the builder.
   */
  public function __construct(ListeningDataService $listening_data, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->feedbackData = $listening_data;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $trend = $this->feedbackData->getVolumeTrend(24);
    $periods = $trend['periods'] ?? [];
    $families = $trend['families'] ?? [];

    if (!$periods || !$families) {
      return NULL;
    }

    $datasets = [];
    foreach ($families as $family => $counts) {
      $data = [];
      foreach ($periods as $period) {
        $data[] = (int) ($counts[$period] ?? 0);
      }
      if (!array_sum($data)) {
        // A family that collected nothing across the whole window would draw a
        // flat zero line and crowd the legend; the inventory table already
        // reports it as not collecting.
        continue;
      }
      $color = self::FAMILY_COLORS[$family] ?? '#94a3b8';
      $datasets[] = [
        'label' => ucfirst(str_replace('_', ' ', $family)),
        'data' => $data,
        'borderColor' => $color,
        'backgroundColor' => $color,
        'borderWidth' => 2,
        'pointRadius' => 2,
        'tension' => 0.2,
        'fill' => FALSE,
      ];
    }

    if (!$datasets) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $this->formatPeriodLabels($periods),
        'datasets' => $datasets,
      ],
      'options' => [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => ['legend' => ['position' => 'bottom']],
        'scales' => ['y' => ['ticks' => ['precision' => 0]]],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Listening Volume by Channel Family'),
      (string) $this->t('Monthly submissions across the last 24 months, split by the kind of channel that collected them.'),
      $visualization,
      [
        (string) $this->t('Evidence tier: Observed. These are counts of rows in the submission tables, with no interpretation applied.'),
        (string) $this->t('Source: ListeningDataService source registry — webform submissions, wish nodes, the appointment feedback field and the chatbot feedback table.'),
        (string) $this->t('Definitions: A family groups related channels (website, program, safety, access, community, survey, chatbot). Families that collected nothing in the whole window are omitted from the plot and listed in the channel inventory instead.'),
        (string) $this->t('Caution: volume measures how loudly a channel is used, not how representative it is. Read it with the coverage band above.'),
      ],
    );
  }

  /**
   * Turns YYYY-MM keys into short human labels.
   */
  protected function formatPeriodLabels(array $periods): array {
    $labels = [];
    foreach ($periods as $period) {
      $date = \DateTimeImmutable::createFromFormat('Y-m-d', $period . '-01');
      $labels[] = $date ? $date->format('M y') : $period;
    }
    return $labels;
  }

}
