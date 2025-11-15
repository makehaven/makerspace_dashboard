<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Service\EngagementDataService;

/**
 * Provides lazy-loading helpers for engagement snapshot charts.
 */
abstract class EducationEngagementChartBuilderBase extends EducationChartBuilderBase {

  protected ?array $snapshot = NULL;

  protected ?array $cohortRange = NULL;

  public function __construct(
    protected EngagementDataService $engagementDataService,
    protected TimeInterface $time,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * Returns the cached engagement snapshot, loading when necessary.
   */
  protected function getSnapshot(array $filters = []): array {
    if (isset($filters['engagement_snapshot']) && is_array($filters['engagement_snapshot'])) {
      $this->snapshot = $filters['engagement_snapshot'];
      $this->cohortRange = $filters['engagement_cohort_range'] ?? NULL;
      return $this->snapshot;
    }

    if ($this->snapshot !== NULL) {
      return $this->snapshot;
    }

    $now = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    $range = $this->engagementDataService->getDefaultRange($now);
    $this->snapshot = $this->engagementDataService->getEngagementSnapshot($range['start'], $range['end']);
    $this->cohortRange = $range;
    return $this->snapshot;
  }

  /**
   * Exposes the activation window setting.
   */
  protected function getActivationWindowDays(array $filters = []): int {
    if (isset($filters['engagement_activation_days'])) {
      return (int) $filters['engagement_activation_days'];
    }
    return $this->engagementDataService->getActivationWindowDays();
  }

  /**
   * Returns the cached cohort range used for the snapshot.
   *
   * @return array|null
   *   Array with 'start' and 'end' keys or NULL when unavailable.
   */
  protected function getCohortRange(array $filters = []): ?array {
    $this->getSnapshot($filters);
    return $this->cohortRange;
  }

}
