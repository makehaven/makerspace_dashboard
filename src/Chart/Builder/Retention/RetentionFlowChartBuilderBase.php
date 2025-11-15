<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Service\RetentionFlowDataService;

/**
 * Shared helpers for retention flow chart builders.
 */
abstract class RetentionFlowChartBuilderBase extends ChartBuilderBase {

  /**
   * Flow data service.
   */
  protected RetentionFlowDataService $flowDataService;

  /**
   * Date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Cached window data.
   */
  protected ?array $windowData = NULL;

  /**
   * Constructs the base builder.
   */
  public function __construct(RetentionFlowDataService $flowDataService, DateFormatterInterface $dateFormatter, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->flowDataService = $flowDataService;
    $this->dateFormatter = $dateFormatter;
  }

  /**
   * Gets the aggregated flow window data.
   */
  protected function getWindowData(): array {
    if ($this->windowData === NULL) {
      $this->windowData = $this->flowDataService->getFlowWindow();
    }
    return $this->windowData;
  }

  /**
   * Formats monthly labels from a period key list.
   */
  protected function formatMonthLabels(array $periodKeys): array {
    $labels = [];
    foreach ($periodKeys as $period) {
      $labels[] = $this->dateFormatter->format(strtotime($period), 'custom', 'M Y');
    }
    return $labels;
  }

}
