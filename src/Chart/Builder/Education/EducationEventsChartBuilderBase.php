<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Service\EventsMembershipDataService;

/**
 * Shared helpers for event-driven Education charts.
 */
abstract class EducationEventsChartBuilderBase extends EducationChartBuilderBase {

  public function __construct(
    protected EventsMembershipDataService $eventsMembershipDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * Builds a rolling window using the supplied month count (defaults to 12).
   */
  protected function buildRollingWindow(int $months = 12): array {
    $end = $this->now();
    $start = $end->modify(sprintf('-%d months', max(1, $months)));
    return [
      'start' => $start,
      'end' => $end,
    ];
  }

  /**
   * Returns a DateTimeImmutable anchored to the current timezone.
   */
  protected function now(): \DateTimeImmutable {
    return new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
  }

}
