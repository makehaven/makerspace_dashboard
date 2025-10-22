<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\makerspace_dashboard\DashboardSectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Provides shared helpers for Makerspace dashboard sections.
 */
abstract class DashboardSectionBase implements DashboardSectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs a new dashboard section.
   */
  public function __construct(?TranslationInterface $string_translation = NULL) {
    if ($string_translation) {
      $this->setStringTranslation($string_translation);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    return [];
  }

  /**
   * Builds a reusable details element describing chart data sources.
   *
   * @param array $items
   *   List of bullet strings describing the chart.
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup|null $title
   *   Optional accordion title.
   *
   * @return array
   *   A render array for a collapsed details block.
   */
  protected function buildChartInfo(array $items, $title = NULL): array {
    $header = $title ?? $this->t('Data notes');

    return [
      '#type' => 'details',
      '#title' => $header,
      '#open' => FALSE,
      '#attributes' => ['class' => ['makerspace-dashboard-info']],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
    ];
  }

}
