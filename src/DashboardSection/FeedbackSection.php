<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\FeedbackDataService;

/**
 * Cross-cuts every feedback channel and the signals that corroborate them.
 *
 * The section is deliberately ordered so the least comfortable band comes
 * first. A feedback dashboard that opens with what members said invites the
 * reader to treat those quotes as representative; this one opens with how few
 * members said anything, so every theme below is read in the light of its
 * actual sample. Each figure carries an Observe / Substantiate / Think /
 * Assume badge, and a claim that failed the evidence guard renders as a defect
 * rather than quietly reading like a fact.
 */
class FeedbackSection extends DashboardSectionBase {

  /**
   * The feedback aggregator.
   */
  protected FeedbackDataService $feedbackData;

  /**
   * Date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructs the feedback section.
   */
  public function __construct(FeedbackDataService $feedback_data, DateFormatterInterface $date_formatter, ChartBuilderManager $chart_builder_manager) {
    parent::__construct(NULL, $chart_builder_manager);
    $this->feedbackData = $feedback_data;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'feedback';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Feedback');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;

    $build['intro'] = [
      '#markup' => '<p>' . $this->t('Every channel through which MakeHaven collects feedback, in one place, with the aggregate signals that would move if a theme were real. Read the coverage band before the themes: it tells you how much of the membership these submissions actually represent.') . '</p>',
      '#weight' => $weight++,
    ];

    $build['evidence_legend'] = $this->buildEvidenceLegend();
    $build['evidence_legend']['#weight'] = $weight++;

    $build['coverage'] = $this->buildCoverageBand();
    $build['coverage']['#weight'] = $weight++;

    $build['sources'] = $this->buildSourceInventory();
    $build['sources']['#weight'] = $weight++;

    $build['paired_signals'] = $this->buildPairedSignals();
    $build['paired_signals']['#weight'] = $weight++;

    $build['ledger'] = $this->buildLedgerBand();
    $build['ledger']['#weight'] = $weight++;

    foreach ($this->buildTieredChartContainers($filters) as $tier => $container) {
      $container['#weight'] = $weight++;
      $build['tier_' . $tier] = $container;
    }

    $build['#attached']['library'][] = 'makerspace_dashboard/dashboard';
    $build['#cache'] = [
      'max-age' => 900,
      'contexts' => ['timezone'],
      'tags' => [
        'makerspace_dashboard:section:feedback',
        'webform_submission_list',
        'node_list',
        'user_list',
      ],
    ];

    return $build;
  }

  /**
   * Renders the Observe / Substantiate / Think / Assume key.
   *
   * This sits above the numbers rather than in a footnote because the whole
   * point of the lens is that the reader applies it while reading, not after.
   */
  protected function buildEvidenceLegend(): array {
    $items = [];
    foreach ($this->feedbackData->getEvidenceLegend() as $tier => $info) {
      $items[] = [
        '#markup' => '<span class="feedback-evidence feedback-evidence--' . Html::escape($tier) . '">'
        . Html::escape($info['label']) . '</span> '
        . Html::escape($info['definition']),
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('How to read these numbers (Observe / Substantiate / Think / Assume)'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['feedback-evidence-legend']],
      'intro' => [
        '#markup' => '<p>' . $this->t('Every figure below is badged with the kind of statement it is. Counts and judgements are not the same claim, and a dashboard that renders them identically invites false confidence. Anything above <em>Observed</em> has to state what it rests on and what would prove it wrong; if it does not, it is shown as <em>Unsupported</em> — a defect in our analysis, not a finding about the makerspace.') . '</p>',
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
    ];
  }

  /**
   * Builds the coverage band: does the listening system reach anybody?
   */
  protected function buildCoverageBand(): array {
    $coverage = $this->feedbackData->getCoverage();

    $tiles = [
      '#type' => 'container',
      '#attributes' => ['class' => ['feedback-tile-grid']],
    ];
    foreach (['reach', 'member_share', 'distinct_submitters', 'concentration', 'channels_not_collecting'] as $key) {
      if (isset($coverage[$key])) {
        $tiles[$key] = $this->buildClaimTile($coverage[$key]);
      }
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['feedback-band', 'feedback-band--coverage']],
      'heading' => ['#markup' => '<h2>' . $this->t('Coverage — how much of the membership is this?') . '</h2>'],
      'description' => [
        '#markup' => '<p>' . $this->t('Measured over the last 365 days across every channel in the inventory below.') . '</p>',
      ],
      'tiles' => $tiles,
    ];

    if (isset($coverage['voice_verdict'])) {
      $build['voice_verdict'] = $this->buildClaimCallout($coverage['voice_verdict']);
    }

    if (isset($coverage['sourcing_verdict'])) {
      $build['verdict'] = $this->buildClaimCallout($coverage['sourcing_verdict']);
    }

    return $build;
  }

  /**
   * Builds the per-channel inventory table.
   */
  protected function buildSourceInventory(): array {
    $sources = $this->feedbackData->getSources();

    $header = [
      $this->t('Channel'),
      $this->t('How it collects'),
      $this->t('State'),
      $this->t('Last 365 days'),
      $this->t('All time'),
      $this->t('Distinct submitters'),
      $this->t('Last submission'),
      $this->t('Response rate'),
    ];

    $rows = [];
    foreach ($sources as $source) {
      $liveness = $source['liveness'];
      $state = (string) $liveness['value'];

      $rows[] = [
        'data' => [
          ['data' => ['#markup' => '<strong>' . Html::escape($source['label']) . '</strong>']],
          Html::escape($this->describeMode($source)),
          ['data' => $this->buildLivenessCell($liveness)],
          $source['recent'] === NULL ? '—' : number_format($source['recent']),
          $source['total'] === NULL ? '—' : number_format($source['total']),
          $source['distinct_uids'] === NULL ? '—' : number_format($source['distinct_uids']),
          $source['last_created']
            ? $this->dateFormatter->format($source['last_created'], 'custom', 'j M Y')
            : $this->t('never'),
          ['data' => $this->buildDenominatorCell($source)],
        ],
        'class' => ['feedback-source-row', 'feedback-source-row--' . Html::getClass($state)],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['feedback-band', 'feedback-band--sources']],
      'heading' => ['#markup' => '<h2>' . $this->t('Channel inventory') . '</h2>'],
      'description' => [
        '#markup' => '<p>' . $this->t('Every feedback mechanism the organisation runs. A channel that collects nothing is not neutral — it is a question we stopped asking, and its silence is easily mistaken for contentment.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No feedback channels are registered.'),
        '#attributes' => ['class' => ['feedback-source-table']],
      ],
      'note' => [
        '#markup' => '<p class="feedback-note">' . $this->t('State is an inference from time since the last submission, adjusted for what cadence the channel is supposed to have. An annual survey that has been quiet for six months is behaving normally; the website drawer doing the same is not.') . '</p>',
      ],
    ];
  }

  /**
   * Describes how a channel gathers input.
   */
  protected function describeMode(array $source): string {
    $mode = $source['mode'] === 'solicited'
      ? (string) $this->t('we ask')
      : (string) $this->t('they volunteer');
    $cadence = str_replace('_', ' ', $source['cadence']);
    return sprintf('%s, %s', $mode, $cadence);
  }

  /**
   * Renders a liveness verdict with its evidence badge and reasoning.
   */
  protected function buildLivenessCell(array $claim): array {
    $state = (string) $claim['value'];
    $markup = '<span class="feedback-state feedback-state--' . Html::getClass($state) . '">'
      . Html::escape(ucfirst($state)) . '</span> '
      . $this->renderEvidenceBadge($claim);

    if ($claim['basis'] !== '') {
      $markup .= '<span class="feedback-basis" title="' . Html::escape($claim['basis']) . '">ⓘ</span>';
    }

    return ['#markup' => Markup::create($markup)];
  }

  /**
   * Renders the response-rate cell, or an explicit unknown.
   */
  protected function buildDenominatorCell(array $source): array {
    if (empty($source['denominator'])) {
      return [
        '#markup' => '<span class="feedback-unknown" title="'
        . Html::escape((string) $this->t('We cannot count how many people had the chance to respond, so the response rate is unknown rather than zero.'))
        . '">' . $this->t('unknown') . '</span>',
      ];
    }

    $denominator = $source['denominator'];
    return [
      '#markup' => '<strong>' . $this->feedbackData->formatPercent($denominator['rate']) . '</strong>'
      . '<span class="feedback-denominator"> ' . $this->t('of @count @label', [
        '@count' => number_format($denominator['total']),
        '@label' => $denominator['label'],
      ]) . '</span>',
    ];
  }

  /**
   * Builds the behavioural counterweight band.
   */
  protected function buildPairedSignals(): array {
    $signals = $this->feedbackData->getPairedSignals();

    $rows = [];
    foreach ($signals as $claim) {
      $rows[] = [
        'data' => [
          ['data' => ['#markup' => '<strong>' . Html::escape($claim['label']) . '</strong>']],
          ['data' => ['#markup' => '<span class="feedback-value">' . Html::escape($this->formatClaimValue($claim)) . '</span>']],
          ['data' => $this->renderEvidenceCell($claim)],
          Html::escape($claim['detail'] !== '' ? $claim['detail'] : $claim['basis']),
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['feedback-band', 'feedback-band--signals']],
      'heading' => ['#markup' => '<h2>' . $this->t('Paired signals — what moves without anyone filing feedback') . '</h2>'],
      'description' => [
        '#markup' => '<p>' . $this->t('Behavioural numbers that would shift if a reported theme is real. Pair every theme with one of these before acting on it: a theme with no paired signal is a hypothesis, and should be labelled as one rather than promoted to a finding because it was stated confidently.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Signal'),
          $this->t('Current'),
          $this->t('Evidence'),
          $this->t('What it pairs with'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No paired signals are available in this environment.'),
        '#attributes' => ['class' => ['feedback-signal-table']],
      ],
    ];
  }

  /**
   * Builds the loop-closure band from the public ledger.
   */
  protected function buildLedgerBand(): array {
    $ledger = $this->feedbackData->getLedgerThroughput();
    if (!$ledger) {
      return [
        '#type' => 'container',
        'note' => ['#markup' => '<p>' . $this->t('The public feedback ledger is not available in this environment.') . '</p>'],
      ];
    }

    $tiles = [
      '#type' => 'container',
      '#attributes' => ['class' => ['feedback-tile-grid']],
      'total' => $this->buildClaimTile($ledger['total']),
      'closed' => $this->buildClaimTile($ledger['closed_rate']),
    ];

    $statusItems = [];
    foreach ($ledger['by_status'] as $status => $count) {
      $statusItems[] = $this->t('@status: @count', [
        '@status' => str_replace('_', ' ', (string) $status),
        '@count' => number_format((int) $count),
      ]);
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['feedback-band', 'feedback-band--ledger']],
      'heading' => ['#markup' => '<h2>' . $this->t('Loop closure — does submitting feedback get you an answer?') . '</h2>'],
      'description' => [
        '#markup' => '<p>' . $this->t('Drawn from the member-visible ledger at /feedback-status. This is the closest measurable proxy we have for feedback culture: people who get an answer submit again, and people who do not, stop.') . '</p>',
      ],
      'tiles' => $tiles,
      'verdict' => $this->buildClaimCallout($ledger['loop_verdict']),
      'breakdown' => [
        '#type' => 'details',
        '#title' => $this->t('Ledger items by status'),
        'list' => ['#theme' => 'item_list', '#items' => $statusItems],
      ],
    ];
  }

  /**
   * Renders a single claim as a stat tile.
   */
  protected function buildClaimTile(array $claim): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['feedback-tile', 'feedback-tile--' . Html::getClass($claim['tier'])],
      ],
      'value' => [
        '#markup' => '<div class="feedback-tile__value">' . Html::escape($this->formatClaimValue($claim)) . '</div>',
      ],
      'label' => [
        '#markup' => '<div class="feedback-tile__label">' . Html::escape($claim['label']) . '</div>',
      ],
      'badge' => [
        '#markup' => '<div class="feedback-tile__badge">' . $this->renderEvidenceBadge($claim) . '</div>',
      ],
      'detail' => $claim['detail'] !== ''
        ? ['#markup' => '<div class="feedback-tile__detail">' . Html::escape($claim['detail']) . '</div>']
        : [],
    ];
  }

  /**
   * Renders an interpretive claim as a callout with its basis and falsifier.
   *
   * Inferences get more room than counts on purpose: a reader should be able to
   * disagree with the reasoning without having to re-derive the numbers.
   */
  protected function buildClaimCallout(array $claim): array {
    $items = [];
    if ($claim['corroborator'] !== '') {
      $items[] = ['#markup' => '<strong>' . $this->t('Corroborated by:') . '</strong> ' . Html::escape($claim['corroborator'])];
    }
    if ($claim['basis'] !== '') {
      $items[] = ['#markup' => '<strong>' . $this->t('Rests on:') . '</strong> ' . Html::escape($claim['basis'])];
    }
    if ($claim['falsifier'] !== '') {
      $items[] = ['#markup' => '<strong>' . $this->t('Would be proved wrong by:') . '</strong> ' . Html::escape($claim['falsifier'])];
    }
    if ($claim['detail'] !== '') {
      $items[] = ['#markup' => Html::escape($claim['detail'])];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['feedback-callout', 'feedback-callout--' . Html::getClass($claim['tier'])],
      ],
      'heading' => [
        '#markup' => '<div class="feedback-callout__heading">'
        . $this->renderEvidenceBadge($claim) . ' '
        . Html::escape($claim['label'])
        . '</div>',
      ],
      'body' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
    ];
  }

  /**
   * Renders the evidence tier as an inline badge.
   */
  protected function renderEvidenceBadge(array $claim): string {
    return '<span class="feedback-evidence feedback-evidence--' . Html::getClass($claim['tier']) . '">'
      . Html::escape($claim['tier_label'])
      . '</span>';
  }

  /**
   * Renders the evidence badge plus its supporting statements as a table cell.
   */
  protected function renderEvidenceCell(array $claim): array {
    $markup = $this->renderEvidenceBadge($claim);
    if ($claim['falsifier'] !== '') {
      $markup .= '<div class="feedback-falsifier">' . $this->t('Wrong if: @falsifier', [
        '@falsifier' => $claim['falsifier'],
      ]) . '</div>';
    }
    return ['#markup' => Markup::create($markup)];
  }

  /**
   * Formats a claim value according to its declared format.
   */
  protected function formatClaimValue(array $claim): string {
    $value = $claim['value'];
    if ($value === NULL) {
      return '—';
    }

    switch ($claim['format']) {
      case 'percent':
        return $this->feedbackData->formatPercent(is_numeric($value) ? (float) $value : NULL);

      case 'integer':
        return is_numeric($value) ? number_format((float) $value) : (string) $value;

      case 'boolean':
        return $value ? (string) $this->t('yes') : (string) $this->t('no');
    }

    return (string) $value;
  }

}
