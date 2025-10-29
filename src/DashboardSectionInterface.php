<?php

namespace Drupal\makerspace_dashboard;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the contract for Makerspace dashboard sections.
 */
interface DashboardSectionInterface {

  /**
   * Gets the machine name for the section.
   */
  public function getId(): string;

  /**
   * Gets the translated label for the section.
   */
  public function getLabel(): TranslatableMarkup;

  /**
   * Builds the render array representing the section contents.
   *
   * @param array $filters
   *   Optional runtime filters (date ranges, cohorts, etc.).
   */
  public function build(array $filters = []): array;

  /**
   * Builds and returns a single chart render array for the section.
   *
   * @param string $chartId
   *   Identifier of the chart within the section render array.
   * @param array $filters
   *   Filters to apply when generating the chart.
   *
   * @return array|null
   *   The chart render array or NULL if unavailable.
   */
  public function buildChart(string $chartId, array $filters = []): ?array;

}
