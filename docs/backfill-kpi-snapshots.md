# KPI Snapshot Backfill Runbook

This runbook documents how to backfill annual KPI rows in `ms_fact_kpi_snapshot`
for dashboard annual tables without rewriting full historical snapshots.

Command:

```bash
drush makerspace-dashboard:backfill-kpi-snapshots
```

Alias:

```bash
drush msd:backfill-kpi-snapshots
```

## What It Backfills

The command targets existing annual `membership_totals` snapshots in `ms_snapshot`
and upserts KPI rows into `ms_fact_kpi_snapshot` for those snapshot IDs.

By default, it backfills only the conservative KPI set:

- `total_new_member_signups`
- `workshop_attendees`
- `total_first_time_workshop_participants`
- `education_nps`
- `workshop_participants_bipoc`

Risky KPI (opt-in only):

- `active_instructors_bipoc`

Use risky KPIs only if you are comfortable with historical drift from role/state
changes over time.

## Safety Model

- Default mode is dry-run (no writes).
- Writes happen only when `--apply` is passed.
- Rows are upserted via merge on `(snapshot_id, kpi_id)`.
- Only selected KPIs are touched.
- Existing snapshots are not deleted/recreated.

## Options

- `--from-year=YYYY` First year (inclusive). Default: previous year.
- `--to-year=YYYY` Last year (inclusive). Default: `from-year`.
- `--snapshot-types=annually,annual` Snapshot types to target.
- `--kpis=kpi1,kpi2` Explicit KPI IDs to backfill.
- `--include-risky` Adds risky KPI IDs to default list.
- `--apply` Persist changes. Omit for dry-run.

## Recommended Workflow (Dev)

1. Clear caches:

```bash
lando drush cr
```

2. Dry-run:

```bash
lando drush msd:backfill-kpi-snapshots --from-year=2024 --to-year=2025
```

3. Review output:
- snapshot count per year
- KPI row count per year
- total KPI rows

4. Apply:

```bash
lando drush msd:backfill-kpi-snapshots --from-year=2024 --to-year=2025 --apply
```

5. Rebuild caches and verify UI:

```bash
lando drush cr
```

Verify in:
- `/makerspace-dashboard/outreach`
- `/makerspace-dashboard/education`
- `/makerspace-dashboard/overview`

## Recommended Workflow (Live)

Run the same sequence used in dev:

1. Dry-run first.
2. Review totals and KPI list.
3. Run with `--apply`.
4. Rebuild caches.
5. Validate dashboard sections.

Example (generic Drush target):

```bash
drush @live msd:backfill-kpi-snapshots --from-year=2024 --to-year=2025
drush @live msd:backfill-kpi-snapshots --from-year=2024 --to-year=2025 --apply
drush @live cr
```

If your live workflow uses Terminus or another wrapper, keep the same command
arguments and dry-run/apply order.

## When To Use Manual Import Instead

Prefer manual import or hand-managed entries when a KPI depends on mutable
historical state that may have shifted (roles, taxonomy mappings, changed
business logic), and exact historical reproducibility is not guaranteed.

## Suggested First Live Run

Start narrow:

```bash
drush @live msd:backfill-kpi-snapshots --from-year=2025 --to-year=2025
```

Then apply:

```bash
drush @live msd:backfill-kpi-snapshots --from-year=2025 --to-year=2025 --apply
```

Then expand to older years after verification.
