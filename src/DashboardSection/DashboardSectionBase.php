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
   * Supported time range presets.
   */
  protected const RANGE_PRESETS = [
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
   * Builds a CSV download link for a chart.
   *
   * @param string $section_id
   *   The ID of the section.
   * @param string $chart_id
   *   The ID of the chart.
   *
   * @return array
   *   A render array for the download link.
   */
  protected function buildCsvDownloadLink(string $section_id, string $chart_id): array {
    $url = \Drupal\Core\Url::fromRoute('makerspace_dashboard.download_chart_csv', [
      'section_id' => $section_id,
      'chart_id' => $chart_id,
    ]);

    return [
      '#type' => 'link',
      '#title' => $this->t('Download CSV'),
      '#url' => $url,
      '#attributes' => [
        'class' => ['csv-download-link'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getGoogleSheetChartMetadata(): array {
    return [];
  }

  /**
   * Returns a single chart render array for a given chart id.
   */
  public function buildChart(string $chartId, array $filters = []): ?array {
    $build = $this->build($filters);
    return $build[$chartId] ?? NULL;
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

  /**
   * Builds a container with a standardized empty-state message.
   *
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $message
   *   The message to display.
   *
   * @return array
   *   Render array containing the empty-state markup.
   */
  protected function buildRangeEmptyContent($message): array {
    return [
      '#type' => 'container',
      'empty' => [
        '#markup' => $message,
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
      ],
    ];
  }

  /**
   * Wraps chart content with a standardized range selector UI.
   *
   * @param string $chartId
   *   Identifier for the chart inside the section.
   * @param array $chartContent
   *   Render array representing the chart and any supporting markup.
   * @param array $availableRanges
   *   Keys for allowed range presets.
   * @param string $activeRange
   *   Currently selected range key.
   *
   * @return array
   *   Wrapped render array including controls and content.
   */
  protected function wrapChartWithRangeControls(string $chartId, array $chartContent, array $availableRanges, string $activeRange): array {
    $allowed = $this->getRangePresets($availableRanges);
    if (!$allowed) {
      return $chartContent;
    }

    $content = $chartContent;
    if (!isset($content['#type'])) {
      $content = [
        '#type' => 'container',
        '#attributes' => [],
      ] + $chartContent;
    }
    if (!isset($content['#attributes']) || !is_array($content['#attributes'])) {
      $content['#attributes'] = [];
    }
    if (empty($content['#attributes']['class']) || !is_array($content['#attributes']['class'])) {
      $content['#attributes']['class'] = [];
    }
    $content['#attributes']['class'][] = 'makerspace-dashboard-range-content';

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['makerspace-dashboard-range-chart'],
        'data-section' => $this->getId(),
        'data-chart-id' => $chartId,
        'data-active-range' => $activeRange,
      ],
      'controls' => $this->buildRangeControls($allowed, $activeRange),
      'content' => $content,
      '#attached' => [
        'library' => ['makerspace_dashboard/dashboard'],
      ],
    ];
  }

  /**
   * Builds the control bar for range selection.
   */
  protected function buildRangeControls(array $allowedRanges, string $activeRange): array {
    $controls = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['makerspace-dashboard-range-controls'],
        'role' => 'toolbar',
        'aria-label' => (string) $this->t('Select time range'),
      ],
    ];

    foreach ($allowedRanges as $rangeKey => $info) {
      $isActive = $rangeKey === $activeRange;
      $classes = ['makerspace-dashboard-range-button'];
      if ($isActive) {
        $classes[] = 'is-active';
      }

      $controls[$rangeKey] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => (string) $info['label'],
        '#attributes' => [
          'type' => 'button',
          'class' => $classes,
          'data-range' => $rangeKey,
          'aria-pressed' => $isActive ? 'true' : 'false',
          'title' => (string) $this->t('Show last @range', ['@range' => $info['label']]),
        ],
      ];
    }

    return $controls;
  }

  /**
   * Selects the active range based on filters and defaults.
   */
  protected function resolveSelectedRange(array $filters, string $chartId, string $defaultRange, array $allowedRanges): string {
    $allowed = array_keys($this->getRangePresets($allowedRanges));
    $selected = $filters['ranges'][$chartId] ?? $defaultRange;
    if (!in_array($selected, $allowed, TRUE)) {
      return $defaultRange;
    }
    return $selected;
  }

  /**
   * Returns the available presets restricted to supplied keys.
   */
  protected function getRangePresets(?array $allowedKeys = NULL): array {
    $presets = [];
    foreach (self::RANGE_PRESETS as $key => $info) {
      if ($allowedKeys === NULL || in_array($key, $allowedKeys, TRUE)) {
        $presets[$key] = [
          'label' => $this->t($info['label']),
          'modifier' => $info['modifier'],
        ];
      }
    }
    return $presets;
  }

  /**
   * Calculates start/end bounds for a given range key.
   */
  protected function calculateRangeBounds(string $rangeKey, \DateTimeImmutable $endDate): array {
    $presets = $this->getRangePresets();
    $preset = $presets[$rangeKey] ?? NULL;
    if (!$preset) {
      $preset = $presets['1y'];
    }

    $end = $endDate;
    $modifier = $preset['modifier'];
    $start = $modifier ? $end->modify($modifier) : NULL;

    return [
      'start' => $start,
      'end' => $end,
    ];
  }

}
