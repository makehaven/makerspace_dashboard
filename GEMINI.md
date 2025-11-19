# Gemini AI Developer Guide

Welcome, AI developer! This guide provides context for developing new features, primarily new charts, for the Makerspace Dashboard module.

## Architecture Overview

This Drupal module displays a data dashboard. It has two main parts:

1.  **Backend (Drupal/PHP):** Handles data fetching, processing, and aggregation. It exposes chart data via a REST endpoint.
2.  **Frontend (React):** A single-page application that consumes the data from the backend and renders the charts and dashboard interface.

The key principle is extensibility. The system is designed to make adding new charts as simple as possible by creating and registering a few PHP classes.

## Core Concepts

-   **Dashboard Sections:** The dashboard is organized into sections (e.g., "Finance", "Education"). Each section is a PHP class in `src/DashboardSection/` that defines which charts it contains.
-   **Chart Builders:** Every chart is defined by a "Chart Builder" service. This is a PHP class that implements `DashboardChartBuilderInterface`. It is responsible for defining the chart's properties (title, type, etc.) and building its data set. These live in `src/Chart/Builder/`.
-   **Data Services:** Data is fetched from various sources (database, Google Sheets, etc.) by dedicated "Data Services". These services are injected into the Chart Builders that need them. They live in `src/Service/`.
-   **Service Discovery:** Chart Builders are automatically discovered by the `ChartBuilderManager` service using service tags defined in `makerspace_dashboard.services.yml`. This means you don't need to manually edit the manager to add a new chart.

## How to Create a New Chart

Follow these steps to add a new chart to the dashboard.

### Step 1: Locate or Create a Data Service

Your chart needs data.

-   **Existing Data Service:** Look in `src/Service/` to see if a service that already provides the data you need exists. For example, `FinancialDataService.php` provides financial data.
-   **New Data Service:** If your data source is new, create a new PHP class in `src/Service/`. This class will contain the logic for fetching and doing any initial processing of your data.

After creating a new service, you must register it in `makerspace_dashboard.services.yml`:

```yaml
# In makerspace_dashboard.services.yml
services:
  # ... other services
  makerspace_dashboard.my_new_data_service:
    class: Drupal\makerspace_dashboard\Service\MyNewDataService
    arguments: ['@database'] # Add dependencies here
```

### Step 2: Create the Chart Builder

This is the main component you will build.

1.  Create a new PHP class in the appropriate subdirectory of `src/Chart/Builder/` (e.g., `src/Chart/Builder/Finance/MyNewFinancialChartBuilder.php`).
2.  Your class must implement `\Drupal\makerspace_dashboard\Chart\DashboardChartBuilderInterface`. It's easiest to extend `ChartBuilderBase` which provides a good foundation.

Here is a template:

```php
<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Finance;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MyNewDataService; // Your data service
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MyNewFinancialChartBuilder extends ChartBuilderBase {

  /**
   * The new data service.
   *
   * @var \Drupal\makerspace_dashboard\Service\MyNewDataService
   */
  protected $myDataService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // This is where you inject your dependencies (like the data service).
    $instance = parent::create($container);
    $instance->myDataService = $container->get('makerspace_dashboard.my_new_data_service');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getChartDefinition(): ChartDefinition {
    return new ChartDefinition([
      'id' => 'my_new_financial_chart', // Unique machine name for the chart
      'title' => $this->t('My New Financial Chart'),
      'description' => $this->t('A description of what this chart shows.'),
      'type' => 'bar', // 'bar', 'pie', 'line', 'doughnut', 'table' etc.
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildChart(array $range): array {
    // 1. Get data from your service.
    $data = $this->myDataService->getSomeData($range);

    // 2. Process the data and format it for the charting library.
    // The final structure should match what Chart.js expects for the `data` object.
    $chart_data = [
      'labels' => [],
      'datasets' => [
        [
          'label' => 'Dataset 1',
          'data' => [],
          'backgroundColor' => [],
        ],
      ],
    ];
    
    // ... logic to populate $chart_data from $data ...

    return $chart_data;
  }
}
```

### Step 3: Register the Chart Builder Service

Open `makerspace_dashboard.services.yml` and add an entry for your new builder. **The `tags` part is essential for the system to find it.**

```yaml
# In makerspace_dashboard.services.yml
services:
  # ... other services
  makerspace_dashboard.chart_builder.my_new_financial_chart:
    class: Drupal\makerspace_dashboard\Chart\Builder\Finance\MyNewFinancialChartBuilder
    tags:
      - { name: makerspace_dashboard.chart_builder }
```

### Step 4: Add the Chart to a Dashboard Section

Finally, tell the dashboard which section your new chart should appear in.

1.  Open the appropriate section class in `src/DashboardSection/` (e.g., `FinanceSection.php`).
2.  Add your new chart's ID (from `getChartDefinition()`) to the array in the `getChartBuilderIds()` method.

```php
// In src/DashboardSection/FinanceSection.php

  public function getChartBuilderIds(): array {
    return [
      'finance_mrr_trend',
      'finance_payment_mix',
      // ... other charts in this section
      'my_new_financial_chart', // Add your new chart's ID here
    ];
  }
```

### Step 5: Clear Caches

Whenever you change service definitions or class structures in Drupal, you must clear the cache. You can do this with Drush:

```bash
drush cr
```

Your new chart should now appear on the dashboard!
