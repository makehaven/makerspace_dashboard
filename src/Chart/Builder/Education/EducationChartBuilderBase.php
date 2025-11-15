<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Support\RangeSelectionTrait;

/**
 * Base class for Education chart builders.
 */
abstract class EducationChartBuilderBase extends ChartBuilderBase {

  use RangeSelectionTrait;

  protected const SECTION_ID = 'education';

  /**
   * {@inheritdoc}
   */
  public function __construct(?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
  }

  /**
   * Builds metadata describing available ranges and the current selection.
   */
  protected function buildRangeMetadata(string $activeRange, array $allowedRanges): array {
    return [
      'active' => $activeRange,
      'options' => $this->getRangePresets($allowedRanges),
    ];
  }

}
