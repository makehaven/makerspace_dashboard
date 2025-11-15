<?php

namespace Drupal\makerspace_dashboard\Support;

/**
 * Provides shared definitions for dashboard date range presets.
 */
final class RangeConfig {

  /**
   * Returns the canonical range preset metadata keyed by machine name.
   */
  public static function definitions(): array {
    return [
      '1m' => [
        'label' => '1 month',
        'modifier' => '-1 month',
      ],
      '3m' => [
        'label' => '3 months',
        'modifier' => '-3 months',
      ],
      '1y' => [
        'label' => '1 year',
        'modifier' => '-1 year',
      ],
      '2y' => [
        'label' => '2 years',
        'modifier' => '-2 years',
      ],
      'all' => [
        'label' => 'All',
        'modifier' => NULL,
      ],
    ];
  }

}
