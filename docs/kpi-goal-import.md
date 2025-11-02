# KPI Goal Snapshot Import

The KPI tables load their baseline and goal columns from the `makerspace_dashboard.kpis` configuration object. Editing dozens of values through the UI is tedious, so the module now ships with a Drush command that ingests a CSV snapshot and updates the config in one pass.

## CSV Format

Create a UTF-8 CSV file with the following headers:

```
section,kpi_id,label,base_2025,goal_2030,description
```

- `section` – Dashboard section machine name (e.g. `finance`, `retention`).
- `kpi_id` – KPI machine name from the strategic plan (e.g. `total_active_members`).
- `label` – Human-friendly label that appears in the table.
- `base_2025` / `goal_2030` – Numeric values for the base year and strategic goal.
- `description` – (Optional) calculation note shown in the KPI drawer.

Values are auto-cast to integers/floats when possible. Leave a cell blank to keep the current configuration value. No columns for annual actuals are provided—those numbers should come from the automated makerspace snapshot pipeline.

## Import Workflow

1. Grab the pre-filled template from the import page (`Download CSV template`)—it includes every existing KPI with the current label, baseline, goal, and description so you can edit in place.
2. Either upload the file at `/admin/config/makerspace/dashboard/kpi-import` (checkbox defaults to dry run) or run the Drush command below.
3. For Drush, start with a dry run to confirm the parser sees the rows you expect:

   ```
   drush makerspace-dashboard:import-kpi-goals /path/to/kpi-goals.csv --dry-run
   ```

4. If the preview looks good, rerun without `--dry-run` (or uncheck the dry-run box in the UI) to persist the values:

   ```
   drush makerspace-dashboard:import-kpi-goals /path/to/kpi-goals.csv
   ```

5. Commit the resulting config change to source control (`drush cex` on Pantheon/Lando environments).

The importer only updates the rows listed in the CSV. It preserves descriptions and manual annual values that already exist unless you override them in the file.

## Updating Goal Snapshots Going Forward

- Keep the authoritative KPI goals in a shared spreadsheet.
- On each annual planning cycle export the sheet as CSV and import it via the admin UI or Drush command.
- Snapshot files can be archived in a `docs/kpi-snapshots/` folder (ignored by Git) so there is an auditable paper trail of when each goal set was applied.
- Actual KPI results are recorded automatically by the `makerspace_snapshot` module; keep that data pipeline healthy instead of hand-entering numbers here.

See `docs/kpi-snapshot-roadmap.md` for details on wiring the actual metrics that will populate the new “Trailing 12 Mo” and “Trailing 3 Mo” columns.
