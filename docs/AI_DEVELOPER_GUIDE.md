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

### 3. Implement the Dashboard Section
This is where you build the chart itself.

- **Create a new `*Section.php` file** in `src/DashboardSection/`.
    - **File:** `src/DashboardSection/NewSectionName.php`
    - **Action:** Extend `DashboardSectionBase`.
- **Define the service in YAML.**
    - **File:** `makerspace_dashboard.services.yml`
    - **Action:** Add a new service definition for your section.
        - Give it the `makerspace_dashboard.section` tag.
        - Inject the data service(s) you need as `arguments`.
- **Implement the `build()` method.**
    - **File:** `src/DashboardSection/NewSectionName.php`
    - **Action:**
        1. Call the data service to get your data.
        2. Build a Drupal Render Array using the `charts` module API.
        3. **Important:** Set the legend position to `top` for Pie/Doughnut charts to avoid library bugs.
        4. **Important:** Explicitly cast all chart labels to strings (`array_map('strval', ...)`).
        5. Add the appropriate cache tags from your data service.
        6. Return the render array.

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
-   `buildChartContainer(string $chart_id, TranslatableMarkup $title, TranslatableMarkup $description, array $chart, array $info): array`: Builds a container for a chart, including a title, description, the chart itself, data source information, and a CSV download link.

### The KPI Table Pattern

The KPI table is a standardized element that should be included at the top of each dashboard section. The data for this table is provided by the `KpiDataService`, which aggregates data from all other data services and the `makerspace_dashboard.kpis.yml` configuration file.

To add the KPI table to a section, you must:

1.  Inject the `KpiDataService` into the section's constructor.
2.  Call the `buildKpiTable()` method in the section's `build()` method, passing the result of `KpiDataService::getKpiData()` as the argument.
3.  Update the section's service definition in `makerspace_dashboard.services.yml` to include the new `KpiDataService` dependency.

### 4. Add Custom JavaScript (If Necessary)
If your chart requires client-side interactivity beyond what the `charts` module provides:

- **Create a new JS file.**
    - **File:** `js/new-feature.js`
- **Define a new library.**
    - **File:** `makerspace_dashboard.libraries.yml`
    - **Action:** Add a new library definition and declare your JS file and any dependencies (e.g., `core/drupal`, `core/jquery`).
- **Attach the library.**
    - **File:** `src/DashboardSection/NewSectionName.php`
    - **Action:** In your `build()` method, add the `'#attached'` key to your render array to attach your new library.

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
