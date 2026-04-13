# KPI Snapshot Roadmap

The KPI tables now render baseline, per-year actuals, trailing averages, and sparkline trends. This document captures the data plan for each KPI so future work can layer in real metrics quickly.

## Snapshot Conventions

- **Annual values:** Persisted in `ms_fact_kpi_snapshot` when snapshot subscribers provide metrics. Configuration may still hold legacy placeholders, but the importer now focuses solely on baseline and goal targets.
- **Trailing 12 / 3 month columns:** Calculated on-the-fly using the most recent monthly snapshots where possible. When a KPI is not yet wired we leave these blank.
- **Trend sparkline:** Last twelve datapoints for the KPI’s core metric. If data is missing, the UI shows `n/a` instead of a broken chart.

## Section-by-Section Plan

| Section | KPI | Current Wiring | Snapshot Source | Next Step |
|---------|-----|----------------|-----------------|-----------|
| Overview / Retention | `total_active_members` | ✅ Live | `ms_fact_org_snapshot.members_active` (monthly) | Expand `makerspace_snapshot` annual job to persist the December active count so the importer is only needed for historical years. |
| Overview / Education | `workshop_attendees` | ⚠️ Placeholder | CiviCRM `civicrm_participant` filtered to “Ticketed Workshop” | Add monthly aggregation helper in `EventsMembershipDataService`, revise snapshots to store year totals and unique first timers. |
| Finance | `reserve_funds_months` | ⚠️ Placeholder | Xero cash balance + expense export via Google Sheet sync | Extend `FinancialDataService` to pull the balances and expose a monthly figure so we can compute trailing averages in-module. |
| Governance | Diversity KPIs | Manual placeholders in config | Manual until governance roster moves into CiviCRM | Create protected storage (e.g., CiviCRM custom fields) then add a `GovernanceDataService` to aggregate counts before snapshots run. |
| Outreach | `total_new_member_signups` | ⚠️ Placeholder | `ms_fact_org_snapshot.joins` | Teach the snapshot importer to sum monthly `joins` per year and persist to `annual_values`. |
| Outreach | `member_referral_rate` | ⚠️ Placeholder | Drupal profile discovery taxonomy values | Extend `DemographicsDataService` with a yearly filter, update snapshot job to persist the ratio. |
| Outreach | `tours_to_member_conversion`, `guest_waiver_conversion`, `event_participant_to_member_conversion` | ✅ Live (rolling) | `FunnelDataService` (CiviCRM contacts mapped to Drupal main profile `created` date) | Add monthly snapshot subscribers that persist eligible denominator, already-member excludes, and conversions; annual KPI should average monthly conversion rates. |
| Retention | `first_year_member_retention`, `active_participation` | ⚠️ Placeholder | `MembershipMetricsService` cohorts + access logs | Add cohort calculator that writes yearly retention percentages to the annual snapshot table. |
| Infrastructure | Satisfaction KPIs | Manual | Webform survey exports | Add `SurveyDataService` to summarize responses by calendar year. |
| Entrepreneurship / Development | All KPIs | Manual | TBD but expected to live in CiviCRM custom entities / contribution tables | Stand up data services + snapshot queries once operational tracking lands. |
| DEI | `membership_diversity_bipoc`, `retention_poc` | ⚠️ Placeholder | Combination of retention + demographics services | After retention and demographics updates, reuse those calculations to drive DEI summary rows. |

## Implementation Notes

1. **Snapshot schema additions:** The `makerspace_snapshot` module now writes into `ms_fact_kpi_snapshot` for any KPI surfaced via `hook_makerspace_snapshot_collect_kpi`. Use this as the canonical store for dashboard metrics going forward.
2. **Annual snapshot runner:** Create an annual cron job or Drush command that aggregates the prior 12 monthly records into `annual_values`. This keeps the dashboard fast and avoids recalculating long-range summaries on every request.
3. **Importer parity:** Even after live wiring, keep the CSV importer around. It provides a manual override when backfilling or when a data system is temporarily offline.
4. **Testing hooks:** As metrics become live, add unit tests around the new service methods (e.g., membership trends) and include a fixture snapshot dataset so regression tests can validate the trailing-average math.

Use this checklist to prioritize the next incremental commits—the dashboard can light up progressively as each row is backed by reliable data.

## Live vs Snapshot: When to Use Which

A snapshot is the right tool when **the answer changes depending on when you ask it, and you can't reconstruct the past**. Use snapshots when:

1. **State is ephemeral** — "who was an active member on 2025-06-30?" is unanswerable later if roles or statuses get overwritten. Same for tool availability, current plan level, active instructor list.
2. **The source is lossy or volatile** — external APIs (Calendly, Chargebee) where only current state is readable.
3. **Computing history is expensive** — if reconstructing 12 months of a KPI means 12 heavy queries per dashboard render, precompute once per month.

**Don't** use snapshots when the underlying data is append-mostly with reliable timestamps. CiviCRM activities, participants, donations, user `created` are all reconstructable anytime — one indexed grouped query returns the series.

### KPI method pattern

For KPIs backed by timestamped history, the method should:

1. Compute `$current`, `$trend`, `$ttm12`, `$ttm3` from the **live** data service first.
2. Call `extractKpiSnapshotAnnualOverrides($kpiId)` to let snapshots contribute `annual_overrides` to the historical table (useful for frozen year-end values from the importer).
3. Pass everything to `buildKpiResult()`.

See `getKpiEducationNpsData`, `getKpiShopUtilizationData`, `getKpiWorkshopAttendeesData` as reference implementations.

Anti-pattern to avoid: reading the snapshot series first and only falling back to live when empty. Sparse snapshot rows produce averages over 2–3 points instead of 12, silently displaying wrong numbers. This bit `kpi_tours` and 5 other KPIs in early 2026 — all now converted to live-first.

## Snapshot Pipeline for Volatile-State KPIs

The following KPIs depend on state that cannot be fully reconstructed from timestamped history (ethnicity, gender, active-member status at a point in time):

- `kpi_workshop_participants_bipoc`
- `kpi_active_instructors_bipoc`
- `kpi_shop_utilization`
- `kpi_active_participation`
- `kpi_active_participation_bipoc`
- `kpi_active_participation_female_nb`

These are captured at each monthly `membership_totals` snapshot via `makerspace_dashboard_makerspace_snapshot_collect_kpi()` in `makerspace_dashboard.module`. The hook freezes the log-based numerator and roster-based denominator at snapshot time so historical values don't drift as current state changes.

### Reader contract

`KpiDataService::extractKpiSnapshotMonthlySeries($kpiId, $minPoints = 6)` returns a dense monthly series (one value per year-month, duplicates collapsed preferring the latest source) when at least `$minPoints` months are available, otherwise NULL so the caller can fall back to a live computation. Each of the 6 KPI methods prefers this snapshot-backed series when present.

### Historical backfill caveat

Running `drush makerspace-snapshot:snapshot --snapshot-date=YYYY-MM-01 --snapshot-type=monthly` for a historical month computes the KPI values using the **current** user roster and current demographic/gender state against the **historical** access-log window. The visit counts are accurate; the denominators and classifications are approximations. This is close enough at MakeHaven's scale because member state changes slowly, but the approximation is inherent to any historical backfill of volatile state. New monthly snapshots captured going forward are the real thing.

If you need to re-backfill with fresh data, the command is idempotent per `(definition, snapshot_date, source)` — it clears and replaces fact rows. Multiple sources for the same month are tolerated by the reader via year-month deduplication.

### Known environment gotcha

`SnapshotService::calculateStorageOccupancy()` checks for `storage_unit` / `storage_assignment` tables but not their related field tables. In dev environments with a partial `storage_manager` schema, the snapshot run throws an SQL error after KPI data has already been persisted. The KPI rows are fine — the error is cosmetic for the backfill use case. Fixable upstream by adding a field-table existence check or wrapping the storage query in try/catch.
