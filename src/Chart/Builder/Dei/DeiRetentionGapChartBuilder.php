<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Dei;

use Drupal\makerspace_dashboard\Chart\Builder\Retention\RetentionAnnualRetentionByEthnicityChartBuilder;

/**
 * Retention by ethnicity, shown first on DEI: the gap is the section's story.
 */
class DeiRetentionGapChartBuilder extends RetentionAnnualRetentionByEthnicityChartBuilder {

  protected const SECTION_ID = 'dei';
  protected const CHART_ID = 'retention_gap';
  protected const TIER = 'key';
  protected const WEIGHT = 10;

}
