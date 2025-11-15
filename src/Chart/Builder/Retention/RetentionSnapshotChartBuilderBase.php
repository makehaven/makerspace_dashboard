<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;

/**
 * Shared helpers for retention snapshot trend builders.
 */
abstract class RetentionSnapshotChartBuilderBase extends ChartBuilderBase {

  /**
   * Snapshot data service.
   */
  protected SnapshotDataService $snapshotData;

  /**
   * Date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructs the base builder.
   */
  public function __construct(SnapshotDataService $snapshot_data, DateFormatterInterface $date_formatter, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->snapshotData = $snapshot_data;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * Calculates a coverage note for snapshot-based charts.
   */
  protected function buildCoverageNote(array $snapshots): ?string {
    if (!$snapshots) {
      return NULL;
    }

    $first = reset($snapshots);
    $last = end($snapshots);
    $firstDate = $first['snapshot_date'] ?? NULL;
    $lastDate = $last['snapshot_date'] ?? NULL;
    if (!$firstDate instanceof \DateTimeInterface || !$lastDate instanceof \DateTimeInterface) {
      return NULL;
    }

    return (string) $this->t('Coverage: @start — @end (@count snapshots).', [
      '@start' => $this->dateFormatter->format($firstDate->getTimestamp(), 'custom', 'M j, Y'),
      '@end' => $this->dateFormatter->format($lastDate->getTimestamp(), 'custom', 'M j, Y'),
      '@count' => count($snapshots),
    ]);
  }

  /**
   * Builds the base notes for snapshot charts.
   */
  protected function getSnapshotNotes(): array {
    return [
      (string) $this->t('Source: makerspace_snapshot membership_totals snapshots joined with ms_fact_org_snapshot (members_active).'),
      (string) $this->t('Processing: snapshots flagged as tests are excluded before aggregation.'),
    ];
  }

  /**
   * Helper to chunk an array to the requested max length.
   */
  protected function trimSeries(array $series, int $limit): array {
    return $limit > 0 ? array_slice($series, -$limit) : $series;
  }

  /**
   * Formats a date using the formatter service.
   */
  protected function formatDate(\DateTimeInterface $date, string $pattern): string {
    return $this->dateFormatter->format($date->getTimestamp(), 'custom', $pattern);
  }

}
