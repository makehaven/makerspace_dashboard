# Makerspace Dashboard

Aggregated dashboards that summarize makerspace health across utilization, engagement, retention, events, and financial outlook without exposing personally identifiable information.

## Key Concepts

- **Data sources** – Utilizes ECK access control logs for door entry counts, Drupal profile fields (e.g. town, gender, ethnicity) today, and can pivot to CiviCRM contacts when fields migrate. Financial summaries blend Chargebee, Stripe storage, and PayPal revenue exports.
- **Charting** – Section classes still describe datasets with the Charts module render-array API, but the actual rendering now happens in a progressively decoupled React app that consumes `/makerspace-dashboard/api/chart/...` JSON responses.
- **Privacy guardrails** – Only aggregated metrics render. Future data services should enforce minimum row counts before displaying values and bucket low-volume segments into catch-all categories.
- **Extensibility** – Each tab is a tagged service implementing `DashboardSectionInterface`. Add new insights by registering additional section services or extending existing ones with configurable filters.
- **Membership metrics** – `MembershipMetricsService` aggregates join/end events and yearly cohorts from profile metadata so retention charts stay fast even as history grows.
- **Configuration** – Adjust chart windows and tab-level notes at `/admin/config/makerspace/dashboard`; notes render above the corresponding tab for added context.
- **Engagement settings** – Configure cohort/activation windows and orientation badge term IDs to drive the New Member Engagement charts.

## Key Profile Fields (Drupal)

Reference machine names pulled from exported config for quick aggregation:

- `field_member_characteristics` (`list_string`, multi) – allowed values: `veteran`, `disability`, `displaced`.
- `field_member_gender` (`list_string`) – `female`, `male`, `other`, `transgender`, `self_describe`, `decline`.
- `field_member_ethnicity` (`list_string`, multi) – `asian`, `black`, `middleeast`, `hispanic`, `native`, `islander`, `white`, `other`, `decline`.
- `field_member_discovery` (`list_string`, multi) – values such as `search`, `storefront`, `event`, `member`, etc.; free-text detail in `field_member_discovery_event_det`.
- `field_member_goal` (`list_string`, multi) with optional `field_member_goal_other`.
- `field_member_interests` (`entity_reference` → taxonomy term, multi) and `field_member_areas_interest` (same).
- `field_member_occupation_type` (`list_string`) – employment categories.
- `field_member_payment_method` (`list_string`, up to 2 values) – `ChargeBee`, `Paypal`, `Wepay`, `Invoice`, `Other`; augmented by `field_member_payment_status` (taxonomy reference) and `field_member_payment_monthly` (decimal).
- `field_membership_type` (`entity_reference` → taxonomy) and `field_member_monthly_payment` / `field_member_payment_state` (deprecated indicators but still stored).
- Date fields: `field_member_birthday`, `field_member_join_date` (deprecated), `field_member_end_date`, `field_member_reactivation_date`.
- End-of-membership metadata: `field_member_end_reason` (`list_string`) + notes field.

These will be mirrored against CiviCRM contact data (via `field_member_crm_id`) as demographics migrate.

## Next Steps

1. Wire chart data to queries against `access_control_log` entities and profile/CiviCRM tables.
2. Introduce configurable date ranges, cohort definitions, and demographic field mappings through a settings form exported to config.
3. Convert heavier sections to `#lazy_builder` callbacks (or secondary routes) so expensive queries only execute when panels open.
4. Define cache contexts/tags to keep charts fast while honoring real-time updates from Chargebee role revisions.
5. Add kernel tests covering aggregation services and minimum-count privacy enforcement.

## Front-end Build

The React bundle that renders charts lives in `js/react-app`.

```bash
cd web/modules/custom/makerspace_dashboard/js/react-app
npm install
npm run build
```

The resulting `dist/dashboard.js` file is referenced by the `makerspace_dashboard/react_app` library and loaded automatically whenever a chart placeholder is displayed.

## Tooling

- `/admin/config/makerspace/dashboard/kpi-import` – Upload a CSV snapshot and (optionally) dry-run KPI goal metadata updates (label / baseline / goal / description) before committing them.
- `drush makerspace-dashboard:import-kpi-goals /path/to/file.csv` – Bulk update KPI baseline, goal, and annual values from a spreadsheet export. See `docs/kpi-goal-import.md` for the CSV format.
- `makerspace_snapshot` captures live KPI metrics via `hook_makerspace_snapshot_collect_kpi()` and persists them in `ms_fact_kpi_snapshot`, which the dashboard pulls into each KPI table.
