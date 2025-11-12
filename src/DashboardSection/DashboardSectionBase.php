<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
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
  protected const KPI_DEFAULT_YEARS = ['2023', '2024', '2025', '2026', '2027', '2028', '2029', '2030'];

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
    // Main KPI table.
    $currentGoalYear = $this->determineDisplayGoalYear($kpi_data);
    $main_header = [
      $this->t('KPI Name'),
      $this->t('Goal 2030'),
      $this->t('Goal @year', ['@year' => $currentGoalYear]),
      $this->t('Current'),
      $this->t('Trend (12 month)'),
    ];

    $main_rows = [];
    foreach ($kpi_data as $kpi) {
      $main_rows[] = [
        $kpi['label'] ?? '',
        $this->formatKpiValue($kpi['goal_2030'] ?? NULL),
        $this->formatKpiValue($kpi['goal_current_year'] ?? NULL),
        $this->formatKpiValue($kpi['current'] ?? NULL),
        [
          'data' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['kpi-sparkline-wrapper']],
            'sparkline' => $this->buildSparkline($kpi['trend'] ?? []),
          ],
          'attributes' => ['class' => 'kpi-sparkline-cell'],
        ],
      ];
    }

    // Annual data table.
    $year_columns = self::KPI_DEFAULT_YEARS;
    $annual_header = [$this->t('KPI Name')];
    foreach ($year_columns as $year) {
      $annual_header[] = $this->t($year);
    }

    $annual_rows = [];
    foreach ($kpi_data as $kpi) {
      $row = [$kpi['label'] ?? ''];
      foreach ($year_columns as $year) {
        $row[] = $this->formatKpiValue($kpi['annual_values'][$year] ?? NULL);
      }
      $annual_rows[] = $row;
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

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['kpi-table-container']],
      'heading' => ['#markup' => '<h2>' . $this->t('Key Performance Indicators') . '</h2>'],
      'table' => [
        '#type' => 'table',
        '#header' => $main_header,
        '#rows' => $main_rows,
        '#empty' => $this->t('KPI data is not yet available.'),
        '#attributes' => ['class' => ['kpi-table']],
      ],
    ];

    if (!empty($annual_rows)) {
      $build['annual_data_details'] = [
        '#type' => 'details',
        '#title' => $this->t('Annual Data'),
        'annual_table' => [
          '#type' => 'table',
          '#header' => $annual_header,
          '#rows' => $annual_rows,
          '#empty' => $this->t('Annual KPI data is not yet available.'),
          '#attributes' => ['class' => ['kpi-annual-table']],
        ],
      ];
    }

    $build['notes'] = [
      '#type' => 'details',
      '#title' => $this->t('KPI Calculation Notes'),
      'list' => [
        '#theme' => 'item_list',
        '#items' => $notes,
      ],
    ];

    return $build;
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
    $numeric = array_values(array_filter($data, 'is_numeric'));
    if (count($numeric) < 2) {
      return ['#markup' => (string) $this->t('n/a')];
    }

    $width = 120;
    $height = 40;
    $max_value = max($numeric);
    $min_value = min($numeric);
    $range = $max_value - $min_value;
    if ($range == 0) {
      $range = 1;
    }

    $points = [];
    $count = count($numeric);
    for ($i = 0; $i < $count; $i++) {
      $x = ($width / ($count - 1)) * $i;
      $y = $height - (($numeric[$i] - $min_value) / $range) * $height;
      $points[] = "{$x},{$y}";
    }
    $polyline_points = implode(' ', $points);

    $svg = <<<SVG
<svg width="{$width}" height="{$height}" xmlns="http://www.w3.org/2000/svg" class="sparkline">
  <polyline points="{$polyline_points}" style="fill:none;stroke:#545454;stroke-width:2" />
</svg>
SVG;

    return [
      '#markup' => Markup::create($svg),
    ];
  }

  /**
   * Returns the current calendar year used for goal comparisons.
   */
  protected function getCurrentGoalYear(): int {
    return (int) (new \DateTimeImmutable())->format('Y');
  }

  /**
   * Determines which goal year label to display for the table.
   */
  protected function determineDisplayGoalYear(array $kpi_data): int {
    $currentYear = $this->getCurrentGoalYear();
    foreach ($kpi_data as $kpi) {
      if (!empty($kpi['goal_current_year_label'])) {
        return (int) $kpi['goal_current_year_label'];
      }
    }
    return $currentYear;
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
  protected function buildChartContainer(string $chart_id, \Drupal\Core\StringTranslation\TranslatableMarkup $title, \Drupal\Core\StringTranslation\TranslatableMarkup $description, array $chart, array $info): array {
    $placeholder_id = $this->buildChartDomId($chart_id);
    $metadata = [
      'sectionId' => $this->getId(),
      'chartId' => $chart_id,
      'title' => (string) $title,
      'description' => (string) $description,
      'notes' => $this->stringifyInfoItems($info),
      'downloadUrl' => Url::fromRoute('makerspace_dashboard.download_chart_csv', [
        'sid' => $this->getId(),
        'chart_id' => $chart_id,
      ])->toString(),
      'range' => NULL,
      'visualization' => $this->serializeRenderable($chart),
    ];

    $container = [
      '#type' => 'container',
      '#attributes' => ['class' => ['metric-container']],
      '#react_placeholder_id' => $placeholder_id,
      '#makerspace_chart' => $metadata,
      'title' => ['#markup' => '<h3>' . $title . '</h3>'],
      'description' => ['#markup' => '<p>' . $description . '</p>'],
      'chart_placeholder' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => $placeholder_id,
          'class' => ['makerspace-dashboard-react-chart'],
          'data-section-id' => $this->getId(),
          'data-chart-id' => $chart_id,
          'data-react-id' => $placeholder_id,
        ],
        'fallback' => [
          '#markup' => '<div class="makerspace-dashboard-react-chart__status">' . $this->t('Loading chart…') . '</div>',
        ],
      ],
      'info' => $this->buildChartInfo($info),
      'download' => $this->buildCsvDownloadLink($this->getId(), $chart_id),
    ];

    $container['#attached']['library'][] = 'makerspace_dashboard/react_app';
    $container['#attached']['drupalSettings']['makerspaceDashboardReact']['placeholders'][$placeholder_id] = [
      'sectionId' => $this->getId(),
      'chartId' => $chart_id,
    ];

    return $container;
  }

  /**
   * {@inheritdoc}
   */
  public function getGoogleSheetChartMetadata(): array {
    return [];
  }

  /**
   * Builds a DOM-safe identifier for a chart placeholder.
   */
  protected function buildChartDomId(string $chartId): string {
    $base = sprintf('makerspace-dashboard-chart-%s-%s', $this->getId(), $chartId);
    return Html::getId($base);
  }

  /**
   * Converts info items into plain strings for metadata export.
   */
  protected function stringifyInfoItems(array $info): array {
    $items = [];
    foreach ($info as $item) {
      if (is_array($item) && isset($item['#markup'])) {
        $items[] = (string) $item['#markup'];
      }
      else {
        $items[] = (string) $item;
      }
    }
    return $items;
  }

  /**
   * Serializes a render array into a React-friendly data structure.
   */
  protected function serializeRenderable(array $element): array {
    $type = $element['#type'] ?? NULL;

    if ($type === 'chart') {
      return $this->serializeChartElement($element);
    }

    if ($type === 'table') {
      return [
        'type' => 'table',
        'header' => array_map([$this, 'convertToString'], $element['#header'] ?? []),
        'rows' => $this->convertTableRows($element['#rows'] ?? []),
        'empty' => isset($element['#empty']) ? $this->convertToString($element['#empty']) : NULL,
      ];
    }

    if ($type === 'container') {
      $children = [];
      foreach ($element as $key => $child) {
        if (is_string($key) && str_starts_with($key, '#')) {
          continue;
        }
        if (is_array($child)) {
          $children[$key] = $this->serializeRenderable($child);
        }
      }

      return [
        'type' => 'container',
        'attributes' => $this->normalizeForJson($element['#attributes'] ?? []),
        'children' => $children,
      ];
    }

    if (isset($element['#markup'])) {
      return [
        'type' => 'markup',
        'markup' => $this->convertToString($element['#markup']),
      ];
    }

    return [
      'type' => 'unknown',
    ];
  }

  /**
   * Normalizes a chart render array into Chart.js data/options.
   */
  protected function serializeChartElement(array $chart): array {
    $library = $chart['#chart_library'] ?? 'chartjs';
    if ($library === 'google') {
      return $this->serializeGoogleChartElement($chart);
    }

    $labels = $chart['xaxis']['#labels'] ?? $chart['#labels'] ?? [];

    return [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => $chart['#chart_type'] ?? 'line',
      'data' => [
        'labels' => array_map([$this, 'convertToString'], $labels),
        'datasets' => $this->serializeChartDatasets($chart),
      ],
      'options' => $this->normalizeForJson($chart['#raw_options']['options'] ?? $chart['#options'] ?? []),
    ];
  }

  /**
   * Converts chart_data children into datasets.
   */
  protected function serializeChartDatasets(array $chart): array {
    $datasets = [];
    foreach ($chart as $key => $child) {
      if (!is_array($child) || ($child['#type'] ?? NULL) !== 'chart_data') {
        continue;
      }
      $datasets[] = $this->serializeChartDataset($child, is_string($key) ? $key : 'series');
    }
    return $datasets;
  }

  /**
   * Serializes a single chart dataset definition.
   */
  protected function serializeChartDataset(array $dataset, string $key): array {
    $normalized = [
      'label' => isset($dataset['#title']) ? $this->convertToString($dataset['#title']) : $this->humanizeKey($key),
      'data' => array_values($dataset['#data'] ?? []),
    ];

    if (isset($dataset['#color'])) {
      $normalized['borderColor'] = $dataset['#color'];
      $normalized['backgroundColor'] = $dataset['#color'];
    }

    if (!empty($dataset['#settings'])) {
      $normalized = array_merge($normalized, $this->normalizeForJson($dataset['#settings']));
    }

    if (!empty($dataset['#options'])) {
      $normalized = array_merge($normalized, $this->normalizeForJson($dataset['#options']));
    }

    if (isset($dataset['#labels'])) {
      $normalized['labels'] = array_map([$this, 'convertToString'], $dataset['#labels']);
    }

    return $normalized;
  }

  /**
   * Serializes Google Charts definitions into Chart.js payloads.
   */
  protected function serializeGoogleChartElement(array $chart): array {
    $type = $chart['#chart_type'] ?? 'pie';
    if ($type === 'pie' && isset($chart['pie_data'])) {
      $labels = $chart['pie_data']['#labels'] ?? [];
      $values = $this->scaleIfFractional($chart['pie_data']['#data'] ?? []);
      return [
        'type' => 'chart',
        'library' => 'chartjs',
        'chartType' => 'pie',
        'data' => [
          'labels' => array_map([$this, 'convertToString'], $labels),
          'datasets' => [[
            'label' => isset($chart['pie_data']['#title']) ? $this->convertToString($chart['pie_data']['#title']) : $this->convertToString($chart['#title'] ?? $this->t('Distribution')),
            'data' => $values,
            'backgroundColor' => $chart['#options']['colors'] ?? [],
          ]],
        ],
        'options' => [
          'plugins' => [
            'legend' => ['position' => 'bottom'],
          ],
        ],
      ];
    }

    if ($type === 'bar' && isset($chart['series_data']['#data'])) {
      $rows = $chart['series_data']['#data'];
      if (!$rows) {
        return [
          'type' => 'chart',
          'library' => 'chartjs',
          'chartType' => 'bar',
          'data' => ['labels' => [], 'datasets' => []],
          'options' => [],
        ];
      }

      $header = array_map([$this, 'convertToString'], array_shift($rows));
      $labels = [];
      $datasets = [];
      $palette = $this->defaultColorPalette();
      for ($i = 1; $i < count($header); $i++) {
        $datasets[$i] = [
          'label' => $header[$i],
          'data' => [],
          'backgroundColor' => $palette[($i - 1) % count($palette)],
        ];
      }

      foreach ($rows as $row) {
        if (!$row) {
          continue;
        }
        $labels[] = $this->convertToString($row[0] ?? '');
        for ($i = 1; $i < count($header); $i++) {
          $value = isset($row[$i]) ? (float) $row[$i] : 0;
          $datasets[$i]['data'][] = $value <= 1 ? round($value * 100, 2) : $value;
        }
      }

      // Re-index datasets numerically.
      $datasets = array_values($datasets);

      return [
        'type' => 'chart',
        'library' => 'chartjs',
        'chartType' => 'bar',
        'data' => [
          'labels' => $labels,
          'datasets' => $datasets,
        ],
        'options' => [
          'interaction' => ['mode' => 'index', 'intersect' => FALSE],
          'plugins' => [
            'legend' => ['position' => 'bottom'],
          ],
          'responsive' => TRUE,
          'maintainAspectRatio' => FALSE,
        ],
      ];
    }

    return [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => $type,
      'data' => ['labels' => [], 'datasets' => []],
      'options' => [],
    ];
  }

  /**
   * Provides a fallback color palette for generated datasets.
   */
  protected function defaultColorPalette(): array {
    return [
      '#2563eb',
      '#16a34a',
      '#f97316',
      '#dc2626',
      '#7c3aed',
      '#0d9488',
    ];
  }

  /**
   * Converts rows into stringable arrays.
   */
  protected function convertTableRows(array $rows): array {
    $converted = [];
    foreach ($rows as $row) {
      if (is_array($row)) {
        $converted[] = array_map([$this, 'convertToString'], $row);
      }
      else {
        $converted[] = [$this->convertToString($row)];
      }
    }
    return $converted;
  }

  /**
   * Converts values to strings when needed.
   */
  protected function convertToString($value): string {
    if ($value instanceof \Stringable) {
      return (string) $value;
    }
    if (is_array($value)) {
      if (isset($value['#markup'])) {
        return (string) $value['#markup'];
      }
      if (isset($value['#plain_text'])) {
        return (string) $value['#plain_text'];
      }
      $flattened = [];
      foreach ($value as $child) {
        if (is_scalar($child) || $child instanceof \Stringable || is_array($child)) {
          $flattened[] = $this->convertToString($child);
        }
      }
      $text = trim(implode(' ', array_filter($flattened)));
      if ($text !== '') {
        return $text;
      }
      return (string) json_encode($this->normalizeForJson($value));
    }
    return (string) $value;
  }

  /**
   * Normalizes nested arrays for JSON serialization.
   */
  protected function normalizeForJson($value) {
    if (is_array($value)) {
      $normalized = [];
      foreach ($value as $key => $item) {
        $normalized[$key] = $this->normalizeForJson($item);
      }
      return $normalized;
    }
    if ($value instanceof \Stringable) {
      return (string) $value;
    }
    if ($value instanceof \DateTimeInterface) {
      return $value->format(\DateTimeInterface::RFC3339);
    }
    return $value;
  }

  /**
   * Creates a human readable label from a key.
   */
  protected function humanizeKey(string $key): string {
    return ucwords(str_replace('_', ' ', $key));
  }

  /**
   * Scales decimal values to percentages when appropriate.
   */
  protected function scaleIfFractional(array $values): array {
    if (!$values) {
      return $values;
    }
    $max = max(array_map('abs', $values));
    if ($max <= 1) {
      return array_map(static fn($value) => round(((float) $value) * 100, 2), $values);
    }
    return array_map('floatval', $values);
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

    $normalized = [];
    foreach ($allowed as $rangeKey => $definition) {
      $normalized[$rangeKey] = [
        'label' => (string) $definition['label'],
      ];
    }

    if (isset($chartContent['#makerspace_chart'])) {
      $chartContent['#makerspace_chart']['range'] = [
        'active' => $activeRange,
        'options' => $normalized,
      ];
    }

    $placeholderId = $chartContent['#react_placeholder_id'] ?? NULL;
    if ($placeholderId && isset($chartContent['chart_placeholder']['#attributes'])) {
      $chartContent['chart_placeholder']['#attributes']['data-default-range'] = $activeRange;
      $chartContent['#attached']['drupalSettings']['makerspaceDashboardReact']['placeholders'][$placeholderId]['ranges'] = [
        'active' => $activeRange,
        'options' => $normalized,
      ];
    }

    return $chartContent;
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
