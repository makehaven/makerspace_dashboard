# AI Developer Guide

Welcome, developer! This is the standard "recipe" for adding a new chart or section to the Makerspace Dashboard. Follow these steps to ensure your work is consistent, reliable, and easy for others (including AI assistants) to understand and maintain.

## The Standard Recipe for a New Chart

### 1. Define the Chart in the Roadmap
- **File:** `docs/TODO.md`
- **Action:** Before you write any code, add an entry for your new chart to the roadmap. Describe its purpose and the strategic objective it supports. This is the "why" behind your work.

### 2. Define the Data "Menu"
An AI cannot guess where data comes from. You must update the "menus" so the AI knows what its options are.

- **Is the data from Drupal/CiviCRM?**
    - **File:** `docs/services.md`
    - **Action:** If you are adding a new function to an existing `Service` class, document its signature, purpose, and the tables it queries.
- **Is the data from an external source (e.g., Google Sheet, external API)?**
    - **File:** `docs/data-sources.md`
    - **Action:** Add a new section for the external source. Document the resource (e.g., sheet name, API endpoint) and the expected columns or fields.
- **Is this a brand new data source?**
    - **Action:** Create a new `*DataService.php` file in `src/Service/`. Follow the existing services as a template (e.g., use caching, inject dependencies).
    - **Action:** Add your new service to `docs/services.md`.

### 3. Implement the Chart Builder
Each visualization now lives in a dedicated chart builder class so that querying, shaping, and rendering logic stay reusable and easy to test.

- **Start from the template.**
  - **File:** `docs/chart-builder-template.md`
  - **Action:** Copy the PHP + YAML snippets into `src/Chart/Builder/<Section>/<ChartName>ChartBuilder.php` and adjust the namespace, injected services, and visualization payload.
- **Extend `ChartBuilderBase`.**
  - Set `SECTION_ID`, `CHART_ID`, and (optionally) `WEIGHT`.
  - Inject only the services you need (e.g., a data service plus, if required, the translation service).
  - Return a `ChartDefinition` with title, description, notes, visualization data, and optional range metadata.
- **Register the builder.**
  - **File:** `makerspace_dashboard.services.yml`
  - **Action:** Add a service definition tagged with `makerspace_dashboard.chart_builder`.
- **Expose the builder in its section.**
  - Ensure the section’s service definition injects `@makerspace_dashboard.chart_builder_manager`.
  - In `build()`, call `$this->buildChartsFromDefinitions($filters)` to render every registered builder.
  - **Legacy note:** If a section still constructs render arrays inline, migrate one chart at a time by adding builders and gradually deleting the old inline code.
- **CSV downloads only appear for single Chart.js datasets.** Container definitions (e.g., goal/actual pairs) suppress the link; if you need CSV support, expose a single combined dataset via the builder.

## Standardized Dashboard Structure

To ensure a consistent user experience, all dashboard sections should follow a standardized structure. The `DashboardSectionBase` class provides helper methods to build these standard elements.

The standard structure is as follows:

1.  **Introduction:** A brief text block that explains the purpose of the section.
2.  **Key Performance Indicators (KPIs):** A table that displays the most important metrics for the section.
3.  **Data Tables:** Additional data tables that provide more detailed information.
4.  **Charts:** A collection of charts that visualize the data.

The `build()` method in each section should be organized in this order, using the `#weight` property to ensure the correct rendering order.

### Helper Methods

The `DashboardSectionBase` class provides the following helper methods to build the standardized elements:

-   `buildIntro(TranslatableMarkup $intro_text): array`: Builds the introductory text block.
-   `buildKpiTable(array $kpi_data = []): array`: Builds the KPI table. The `$kpi_data` argument should be the return value of `KpiDataService::getKpiData()`.
-   `buildChartContainer(string $chart_id, TranslatableMarkup $title, TranslatableMarkup $description, array $chart, array $info): array`: Builds the server-rendered wrapper for a chart, including a React placeholder, description, data-source notes, and the CSV download link. The `$chart` render array you pass in is serialized to JSON so the React bundle can draw the visualization.

### The KPI Table Pattern

The KPI table is a standardized element that should be included at the top of each dashboard section. The data for this table is provided by the `KpiDataService`, which aggregates data from all other data services and the `makerspace_dashboard.kpis.yml` configuration file.

To add the KPI table to a section, you must:

1.  Inject the `KpiDataService` into the section's constructor.
2.  Call the `buildKpiTable()` method in the section's `build()` method, passing the result of `KpiDataService::getKpiData()` as the argument.
3.  Update the section's service definition in `makerspace_dashboard.services.yml` to include the new `KpiDataService` dependency.

### Chart Builder Workflow Checklist

1. Update `docs/TODO.md` with the purpose of your new chart.
2. Document or extend the relevant data service in `docs/services.md` / `docs/data-sources.md`.
3. Create the builder (`src/Chart/Builder/...`) using `docs/chart-builder-template.md`.
4. Register the builder service with the `makerspace_dashboard.chart_builder` tag.
5. Confirm the owning section receives `ChartBuilderManager` and renders `$this->buildChartsFromDefinitions()`.
6. Verify the JSON output at `/makerspace-dashboard/api/chart/{section}/{chart}` and download the CSV to ensure datasets export correctly.

### 4. Update the React Bundle (If Necessary)
Most interactions are handled by the shared React app under `js/react-app`. If you need new client-side behavior:

- **Add or update a component.**
    - **File:** `js/react-app/src/**/*.tsx`
    - **Action:** Extend the existing `DashboardChart`/`ChartRenderer` components so they understand your new data structure.
- **Run the build.**
    - `cd js/react-app && npm install && npm run build`
    - This regenerates `js/react-app/dist/dashboard.js`, which Drupal loads via the `makerspace_dashboard/react_app` library.

## How to Use This Guide with an AI

When you have followed these steps, you can make a very specific and effective request to an AI assistant.

**Example Prompt:**

> "Please implement the 'Board Diversity' chart from the `docs/TODO.md` roadmap.
>
> 1.  Follow the recipe in `docs/AI_DEVELOPER_GUIDE.md`.
> 2.  Create a new `GovernanceSection.php` file.
> 3.  It needs to get its data from the `Governance` tab of the Google Sheet, as defined in `docs/data-sources.md`.
> 4.  Create a new `GovernanceDataService` that uses the `GoogleSheetClientService` to fetch this data."

This provides all the context, patterns, and specific instructions the AI needs to follow the "standard way" you defined, dramatically increasing the chance of a successful result on the first try.
