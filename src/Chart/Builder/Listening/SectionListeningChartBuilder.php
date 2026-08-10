<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Listening;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\ListeningDataService;

/**
 * Shows what people told us in one domain, inside that domain's own section.
 *
 * Registered once per dashboard section rather than subclassed, because the
 * only thing that varies is which section's channels to read. The section id
 * is injected, so adding listening to another section is a services.yml entry.
 *
 * The point of putting this here rather than in a dedicated feedback tab: a
 * feedback tab is visited by whoever built it, whereas the person looking at
 * workshop numbers is the person who should see what workshop attendees said.
 */
class SectionListeningChartBuilder extends ChartBuilderBase {

  protected const CHART_ID = 'listening_channels';
  protected const WEIGHT = 40;
  protected const TIER = 'supplemental';

  /**
   * The listening aggregator.
   */
  protected ListeningDataService $listeningData;

  /**
   * Dashboard section this instance belongs to.
   */
  protected string $sectionId;

  /**
   * Constructs the builder for one section.
   */
  public function __construct(ListeningDataService $listening_data, string $section_id, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->listeningData = $listening_data;
    $this->sectionId = $section_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getSectionId(): string {
    return $this->sectionId;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $sources = $this->listeningData->getSourcesForSection($this->sectionId);
    if (!$sources) {
      return NULL;
    }

    $labels = [];
    $recent = [];
    $silent = [];

    foreach ($sources as $source) {
      if (empty($source['available'])) {
        continue;
      }
      $state = (string) ($source['liveness']['value'] ?? '');
      if ($state === 'retired') {
        continue;
      }
      $labels[] = $source['label'];
      $recent[] = (int) ($source['recent'] ?? 0);
      // A channel collecting nothing is the finding here, so it stays on the
      // chart as a zero bar rather than being filtered out for looking empty.
      $silent[] = in_array($state, ['live', 'fading'], TRUE) ? 0 : 1;
    }

    if (!$labels) {
      return NULL;
    }

    $colors = array_map(
      static fn(int $isSilent) => $isSilent ? '#dc2626' : '#16a34a',
      $silent
    );

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Submissions in the last 365 days'),
            'data' => $recent,
            'backgroundColor' => $colors,
            'borderWidth' => 0,
          ],
        ],
      ],
      'options' => [
        'indexAxis' => 'y',
        'plugins' => ['legend' => ['display' => FALSE]],
        'scales' => ['x' => ['ticks' => ['precision' => 0]]],
        'responsive' => TRUE,
        'maintainAspectRatio' => FALSE,
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('What People Told Us'),
      (string) $this->t('Every channel we listen on in this area, and how much each collected in the last year. Red bars are channels that have gone quiet.'),
      $visualization,
      [
        (string) $this->t('Evidence tier: Observed. Bar lengths are counts of submissions, with no interpretation applied.'),
        (string) $this->t('Colour is a Think-level judgement: red marks a channel classified dormant, dead or never used by the cadence-adjusted staleness thresholds.'),
        (string) $this->t('A zero bar is deliberately kept on the chart rather than filtered out. A question we stopped asking is the finding, and dropping it would hide exactly what this is for.'),
        (string) $this->t('Full coverage detail, including who is submitting and whether channels are too thin to generalise from, is on the Listening health report.'),
      ],
    );
  }

}
