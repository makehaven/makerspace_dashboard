<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\makerspace_dashboard\Chart\Builder\Overview\OverviewReserveFundsChartBuilder;

/**
 * The Overview reserve coverage chart, shown on the Finance section too.
 */
class FinanceReserveFundsChartBuilder extends OverviewReserveFundsChartBuilder {

  protected const SECTION_ID = 'finance';
  protected const CHART_ID = 'reserve_funds';
  protected const TIER = 'key';
  protected const WEIGHT = 40;

}
