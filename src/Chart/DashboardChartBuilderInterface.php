<?php

namespace Drupal\makerspace_dashboard\Chart;

/**
 * Defines the contract individual chart builders must follow.
 */
interface DashboardChartBuilderInterface {

  /**
   * Gets the section this chart belongs to.
   */
  public function getSectionId(): string;

  /**
   * Gets the chart identifier unique within the section.
   */
  public function getChartId(): string;

  /**
   * Builds and returns the chart definition.
   *
   * @param array $filters
   *   Optional runtime filters (date ranges, cohorts, etc.).
   *
   * @return \Drupal\makerspace_dashboard\Chart\ChartDefinition|null
   *   Chart definition or NULL when the visualization has no data.
   */
  public function build(array $filters = []): ?ChartDefinition;

}
