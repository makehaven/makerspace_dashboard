<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Dei;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;

/**
 * Shared helpers for DEI chart builders.
 */
abstract class DeiChartBuilderBase extends ChartBuilderBase {

  protected const SECTION_ID = 'dei';

  public function __construct(
    protected DemographicsDataService $demographicsDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * Builds a simple moving average smoothing series.
   */
  protected function movingAverage(array $values, int $radius = 2): array {
    $count = count($values);
    if ($count === 0) {
      return [];
    }

    $averages = [];
    for ($i = 0; $i < $count; $i++) {
      $start = max(0, $i - $radius);
      $end = min($count - 1, $i + $radius);
      $length = ($end - $start + 1) ?: 1;
      $sum = 0;
      for ($j = $start; $j <= $end; $j++) {
        $sum += $values[$j];
      }
      $averages[] = round($sum / $length, 2);
    }
    return $averages;
  }

}
