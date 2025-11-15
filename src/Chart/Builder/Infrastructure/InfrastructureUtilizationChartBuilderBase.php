<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Infrastructure;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Service\UtilizationWindowService;

/**
 * Shared helpers for utilization chart builders.
 */
abstract class InfrastructureUtilizationChartBuilderBase extends ChartBuilderBase {

  protected UtilizationWindowService $windowService;

  /**
   * Cached metrics.
   */
  protected ?array $metrics = NULL;

  public function __construct(UtilizationWindowService $windowService, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->windowService = $windowService;
  }

  protected function getMetrics(): array {
    if ($this->metrics === NULL) {
      $this->metrics = $this->windowService->getWindowMetrics();
    }
    return $this->metrics;
  }

}
