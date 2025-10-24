<?php

namespace Drupal\makerspace_dashboard\Service;

/**
 * Manages and collects services tagged as Google Sheet charts.
 */
class GoogleSheetChartManager {

  /**
   * An array of the chart services.
   *
   * @var array
   */
  protected $charts;

  /**
   * Constructs a GoogleSheetChartManager object.
   *
   * @param \Traversable $charts
   *   An iterator of services tagged with 'makerspace_dashboard.google_sheet_chart'.
   */
  public function __construct(\Traversable $charts) {
    $this->charts = iterator_to_array($charts);
  }

  /**
   * Gets all the chart services.
   *
   * @return array
   *   An array of chart service objects.
   */
  public function getCharts(): array {
    return $this->charts;
  }

}
