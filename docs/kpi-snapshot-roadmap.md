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
