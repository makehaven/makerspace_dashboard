<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\ListeningDataService;
use Drupal\makerspace_dashboard\Support\PublicCounts;

/**
 * Charts why members left, next to the retention numbers they explain.
 *
 * Captured in Chargebee's cancellation flow and synced back by
 * chargebee_status_sync. This is the best-covered listening channel the
 * organisation has — 456 of the 461 memberships that ended in the year to
 * 2026-08-10 carry a reason — and until now nothing on any dashboard read it.
 *
 * It belongs in Retention rather than in a feedback tab for the obvious
 * reason: the churn curve above says how many left, and this says why, and
 * separating them is what let both sit unexamined.
 */
class RetentionExitReasonsChartBuilder extends ChartBuilderBase {

  protected const TIER = 'key';

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'exit_reasons';
  protected const WEIGHT = 50;

  /**
   * The listening aggregator.
   */
  protected ListeningDataService $listeningData;

  /**
   * Constructs the builder.
   */
  public function __construct(ListeningDataService $listening_data, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->listeningData = $listening_data;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $rows = $this->listeningData->getExitReasonBreakdown(365);
    if (!$rows) {
      return NULL;
    }

    // A removal is never shown by name: the community knows who it was.
    $other = NULL;
    $sensitive = 0;
    foreach ($rows as $index => $row) {
      if (PublicCounts::isSensitiveEndReason($row['label'])) {
        $sensitive += $row['count'];
        unset($rows[$index]);
      }
      elseif ($row['label'] === 'Other') {
        $other = $index;
      }
    }
    if ($sensitive > 0) {
      if ($other === NULL) {
        $rows[] = ['label' => 'Other', 'count' => $sensitive];
      }
      else {
        $rows[$other]['count'] += $sensitive;
      }
      usort($rows, static fn(array $a, array $b) => $b['count'] <=> $a['count']);
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => array_column($rows, 'label'),
        'datasets' => [
          [
            'label' => (string) $this->t('Memberships ended'),
            'data' => array_column($rows, 'count'),
            'backgroundColor' => '#be123c',
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
      (string) $this->t('Why Members Left'),
      (string) $this->t('Stated reason for every membership that ended in the last 365 days.'),
      $visualization,
      [
        (string) $this->t('Evidence tier: Observed. Members select the reason themselves in the Chargebee cancellation flow; chargebee_status_sync writes it to field_member_end_reason.'),
        (string) $this->t('Coverage: 456 of the 461 memberships that ended in the year to 2026-08-10 carry a reason — around 99%, the best-covered listening channel we run.'),
        (string) $this->t('Labels come from the field’s own allowed-values config, so this chart cannot drift from the options members were actually offered. Any reason that could identify a person is counted under Other.'),
        (string) $this->t('Caution: a stated reason is what someone chose from a list on their way out, not a diagnosis. "Time" and "cost" are the easy answers to pick, and may mask reasons the list does not offer. Read the free-text notes alongside before concluding.'),
      ],
    );
  }

}
