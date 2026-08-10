<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Feedback;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FeedbackDataService;

/**
 * Charts how many days each feedback channel has been silent.
 *
 * The bar length is the honest headline of this whole section: most of the
 * organisation's feedback mechanisms have not collected anything in months,
 * and that fact is invisible when each form is only ever looked at alone.
 */
class FeedbackChannelLivenessChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'feedback';
  protected const CHART_ID = 'channel_liveness';
  protected const WEIGHT = 20;

  /**
   * Bar colour per inferred liveness state.
   */
  protected const STATE_COLORS = [
    'live' => '#16a34a',
    'fading' => '#f59e0b',
    'dormant' => '#f97316',
    'dead' => '#dc2626',
    'never used' => '#7f1d1d',
    'retired' => '#94a3b8',
  ];

  /**
   * The feedback aggregator.
   */
  protected FeedbackDataService $feedbackData;

  /**
   * Constructs the builder.
   */
  public function __construct(FeedbackDataService $feedback_data, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->feedbackData = $feedback_data;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $sources = $this->feedbackData->getSources();
    if (!$sources) {
      return NULL;
    }

    $rows = [];
    foreach ($sources as $source) {
      if (empty($source['available'])) {
        continue;
      }
      $state = (string) ($source['liveness']['value'] ?? '');
      if ($state === 'retired') {
        // Deliberately closed channels are not a coverage failure and would
        // distort the picture if plotted alongside neglected ones.
        continue;
      }
      $rows[] = [
        'label' => $source['label'],
        // A channel that has never been used has no silence to measure; the
        // full window is the honest stand-in, flagged by its own colour.
        'days' => $source['days_silent'] ?? 365,
        'state' => $state,
      ];
    }

    if (!$rows) {
      return NULL;
    }

    usort($rows, static fn(array $a, array $b) => $b['days'] <=> $a['days']);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => array_column($rows, 'label'),
        'datasets' => [
          [
            'label' => (string) $this->t('Days since last submission'),
            'data' => array_column($rows, 'days'),
            'backgroundColor' => array_map(
              static fn(array $row) => self::STATE_COLORS[$row['state']] ?? '#94a3b8',
              $rows
            ),
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
      (string) $this->t('Days Since Each Channel Last Heard Anything'),
      (string) $this->t('One bar per feedback channel, coloured by its liveness verdict. Longer is worse.'),
      $visualization,
      [
        (string) $this->t('Evidence tier: bar lengths are Observed (time since the newest row). The colours are a Think-level judgement, applying cadence-adjusted staleness thresholds.'),
        (string) $this->t('Processing: channels that have never received a submission are plotted at the full 365-day window because there is no silence to measure; they carry their own colour.'),
        (string) $this->t('Definitions: retired channels (deliberately closed, such as a prior year’s member survey) are excluded so they do not read as neglect.'),
        (string) $this->t('Would be wrong if: the underlying activity stopped — no classes ran, so no post-class feedback is expected. Check the paired activity counts before treating a long bar as a failure.'),
      ],
    );
  }

}
