<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Entrepreneurship;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Service\EntrepreneurshipDataService;
use Drupal\makerspace_dashboard\Support\RangeSelectionTrait;

/**
 * Shared helpers for entrepreneurship charts.
 */
abstract class EntrepreneurshipChartBuilderBase extends ChartBuilderBase {

  use RangeSelectionTrait;

  protected const SECTION_ID = 'entrepreneurship';

  /**
   * Constructs the base builder.
   */
  public function __construct(
    protected EntrepreneurshipDataService $entrepreneurshipData,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * Returns a timezone-aware "now" instance.
   */
  protected function now(): \DateTimeImmutable {
    return new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
  }

  /**
   * Builds range metadata array for the React renderer.
   */
  protected function buildRangeMetadata(string $activeRange, array $allowedRanges): array {
    return [
      'active' => $activeRange,
      'options' => $this->getRangePresets($allowedRanges),
    ];
  }

}
