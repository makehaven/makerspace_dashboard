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
   * Default KPI year columns.
   *
   * @var string[]
   */
  protected const KPI_DEFAULT_YEARS = ['2026', '2027', '2028', '2029', '2030'];

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
      'sid' => $section_id,
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
   * Builds the intro text block for a section.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $intro_text
   *   The introductory text to display.
   *
   * @return array
   *   A render array for the intro block.
   */
  protected function buildIntro(\Drupal\Core\StringTranslation\TranslatableMarkup $intro_text): array {
    return [
      '#markup' => '<p>' . $intro_text . '</p>',
    ];
  }

  /**
   * Builds the KPI table for a section.
   *
   * @param array $rows
   *   An array of rows to populate the table with.
   *
   * @return array
   *   A render array for the KPI table.
   */
  protected function buildKpiTable(array $kpi_data = []): array {
    $yearColumns = [];
    foreach ($kpi_data as $kpi) {
      if (!empty($kpi['annual_values']) && is_array($kpi['annual_values'])) {
        foreach (array_keys($kpi['annual_values']) as $year) {
          if ((int) $year < 2025) {
            continue;
          }
          $yearColumns[(string) $year] = TRUE;
        }
      }
    }

    $yearColumns = array_keys($yearColumns);
    $yearColumns = array_unique(array_merge(self::KPI_DEFAULT_YEARS, $yearColumns));
    usort($yearColumns, static function (string $a, string $b): int {
      return (int) $a <=> (int) $b;
    });

    $rows = [];
    foreach ($kpi_data as $kpi) {
      $row = [
        $kpi['label'] ?? '',
        $this->formatKpiValue($kpi['base_2025'] ?? NULL),
      ];
      foreach ($yearColumns as $year) {
        $row[] = $this->formatKpiValue($kpi['annual_values'][$year] ?? NULL);
      }
      $row[] = $this->formatKpiValue($kpi['ttm_12'] ?? NULL);
      $row[] = $this->formatKpiValue($kpi['ttm_3'] ?? NULL);
      $row[] = $this->formatKpiValue($kpi['goal_2030'] ?? NULL);
      $row[] = $this->buildSparkline($kpi['trend'] ?? []);
      $rows[] = $row;
    }

    $notes = [];
    foreach ($kpi_data as $kpi) {
      $noteParts = [];
      if (!empty($kpi['description'])) {
        $noteParts[] = $kpi['description'];
      }
      if (!empty($kpi['last_updated'])) {
        $noteParts[] = '<em>' . $this->t('Last snapshot: @date', ['@date' => $kpi['last_updated']]) . '</em>';
      }
      if ($noteParts) {
        $notes[] = [
          '#markup' => '<strong>' . ($kpi['label'] ?? '') . ':</strong> ' . implode(' ', $noteParts),
        ];
      }
    }

    $header = [
      $this->t('KPI Name'),
      $this->t('Base (2025)'),
    ];
    foreach ($yearColumns as $year) {
      $header[] = $this->t('@year', ['@year' => $year]);
    }
    $header[] = $this->t('Trailing 12 Mo');
    $header[] = $this->t('Trailing 3 Mo');
    $header[] = $this->t('2030 (Goal)');
    $header[] = $this->t('12-Month Trend');

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['kpi-table-container']],
      'heading' => ['#markup' => '<h2>' . $this->t('Key Performance Indicators') . '</h2>'],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('KPI data is not yet available.'),
        '#attributes' => ['class' => ['kpi-table']],
      ],
      'notes' => [
        '#type' => 'details',
        '#title' => $this->t('KPI Calculation Notes'),
        'list' => [
          '#theme' => 'item_list',
          '#items' => $notes,
        ],
      ],
    ];
  }

  /**
   * Builds a sparkline chart.
   *
   * @param array $data
   *   The data for the sparkline.
   *
   * @return array
   *   A render array for the sparkline chart.
   */
  protected function buildSparkline(array $data): array {
    $numeric = array_values(array_filter($data, static function ($value) {
      return is_numeric($value);
    }));
    if (empty($numeric)) {
      return [
        '#markup' => (string) $this->t('n/a'),
      ];
    }

    return [
      '#type' => 'chart',
      '#chart_type' => 'sparkline',
      '#data' => $numeric,
    ];
  }

  /**
   * Formats a KPI value for display.
   *
   * @param mixed $value
   *   The raw value.
   *
   * @return string
   *   The formatted value.
   */
  protected function formatKpiValue($value): string {
    if ($value === NULL || $value === '' || $value === 'n/a') {
      return (string) $this->t('n/a');
    }
    if (is_numeric($value)) {
      $float = (float) $value;
      $abs = abs($float);
      $precision = 0;
      if ($abs < 10) {
        $precision = 2;
      }
      elseif ($abs < 100) {
        $precision = 1;
      }
      return number_format($float, $precision);
    }
    return (string) $value;
  }

  /**
   * Builds a container for a chart.
   *
   * @param string $chart_id
   *   The ID of the chart.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The title of the chart.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $description
   *   The description of the chart.
   * @param array $chart
   *   The chart render array.
   * @param array $info
   *   An array of items for the chart info block.
   *
   * @return array
   *   A render array for the chart container.
   */
  protected function buildChartContainer(string $chart_id, \Drupal\Core\StringTranslation\TranslatableMarkup $title, \Drupal\Core\StringTranslation\TranslatableMarkup $description, array $chart, array $info, array $rangeControls = []): array {
    $container = [
      '#type' => 'container',
      '#attributes' => ['class' => ['metric-container']],
      'title' => ['#markup' => '<h3>' . $title . '</h3>'],
      'description' => ['#markup' => '<p>' . $description . '</p>'],
    ];

    if (!empty($rangeControls)) {
      $container['range_controls'] = $rangeControls;
    }

    $container['chart'] = $chart;
    $container['info'] = $this->buildChartInfo($info);
    $container['download'] = $this->buildCsvDownloadLink($this->getId(), $chart_id);

    return $container;
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

    $chartContent['range_controls'] = $this->buildRangeControls($allowed, $activeRange);
    $chartContent['#attached']['library'][] = 'makerspace_dashboard/dashboard';
    $chartContent['#attributes']['data-section'] = $this->getId();
    $chartContent['#attributes']['data-chart-id'] = $chartId;
    $chartContent['#attributes']['data-active-range'] = $activeRange;

    return $chartContent;
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
