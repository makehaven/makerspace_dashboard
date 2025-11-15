# Chart Builder Template

Use this template whenever you add a new visualization. It captures the preferred folder layout, naming conventions, and wiring needed for the progressively decoupled chart stack.

## 1. PHP Builder Skeleton

```php
<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Example;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\ExampleDataService;

/**
 * Example chart builder.
 */
class ExampleMetricChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'overview';       // Tab/section machine name.
  protected const CHART_ID = 'example_metric';   // Unique within the section.
  protected const WEIGHT = 60;                   // Optional ordering hint.

  public function __construct(
    protected ExampleDataService $dataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $series = $this->dataService->getExampleSeries();
    $labels = array_map('strval', $series['labels'] ?? []);
    $values = array_map('floatval', $series['values'] ?? []);

    if (!$labels || !array_filter($values)) {
      return NULL;
    }

    $primaryDataset = [
      'label' => (string) $this->t('Example metric'),
      'data' => $values,
      'borderColor' => '#2563eb',
      'backgroundColor' => 'rgba(37,99,235,0.2)',
      'fill' => FALSE,
      'pointRadius' => 3,
    ];

    $datasets = array_values(array_filter([
      $primaryDataset,
      $this->buildTrendDataset($values, (string) $this->t('Trend')),
    ]));

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['display' => FALSE],
          'tooltip' => [
            'mode' => 'index',
            'intersect' => FALSE,
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Example Metric Trend'),
      (string) $this->t('Explain what the viewer should learn from this chart.'),
      $visualization,
      [
        (string) $this->t('Source: Example data service pulling from civicrm_participant.'),
        (string) $this->t('Processing: Rolling 12-month window, excludes test records.'),
      ],
    );
  }

}
```

### Notes

- Always `array_map('strval', $labels)` and `array_map('floatval', $values)` to keep serialization predictable.
- Use the helper methods in `ChartBuilderBase` (`buildTrendDataset()`, `newDefinition()`, etc.) so options stay consistent.
- Return `NULL` when there is no data—sections will automatically skip empty charts.

## 2. Service Definition

```yaml
makerspace_dashboard.chart_builder.example_metric:
  class: Drupal\makerspace_dashboard\Chart\Builder\Example\ExampleMetricChartBuilder
  arguments:
    - '@makerspace_dashboard.example_data'
    - '@string_translation'
  tags:
    - { name: makerspace_dashboard.chart_builder }
```

> **Tip:** Keep builder arguments tightly scoped to avoid unnecessary database queries when the chart is not rendered.

## 3. Section Wiring Checklist

1. Ensure the owning section’s service definition injects `@makerspace_dashboard.chart_builder_manager`.
2. In the section’s `build()` method:
   ```php
   $charts = $this->buildChartsFromDefinitions($filters);
   foreach ($charts as $chart_id => $chart_render_array) {
     $chart_render_array['#weight'] = $weight++;
     $build[$chart_id] = $chart_render_array;
   }
   ```
3. If the section still uses legacy inline charts, migrate one chart at a time: add the builder, verify the React/CSV output, then delete the old inline render array.

By following this template every chart will:

- Share the same JSON contract consumed by the React app and CSV exporter.
- Respect consistent styling/options (legend placement, tooltips, etc.).
- Remain independently testable, making it easy to load via `/makerspace-dashboard/api/chart/{section}/{chart}` without rendering the whole section.
