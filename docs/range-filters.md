# Range Filter Pitfalls & Checklist

Working through the Education “Top Event Interests” range bug surfaced several recurring issues. Capture them here so the next person doesn’t lose an afternoon tracing the same problems.

## 1. React state vs. server drift

- **Symptom**: Range picker flashes to a new selection, but instantly snaps back to default.
- **Cause**: Multiple fetches in flight; late responses overwrite the user’s latest choice.
- **Fix**: Track the “pending” selection in `DashboardChart.tsx`, only sync from the API when no newer selection is pending, and treat the server-provided `range.active` as initial data rather than a source of truth.
- **Verification**: Watch the browser network tab while clicking ranges. Ensure React only updates the chart after a fresh response arrives and doesn’t revert when a stale response resolves.

## 2. Drupal dynamic cache ignoring query args

- **Symptom**: Network requests show `?range=3m`, but the JSON payload always reflects the previous range (often `range.active: "1y"`).
- **Cause**: `CacheableJsonResponse` was being stored in Drupal’s Dynamic Page Cache without differentiating on `range`.
- **Fix**: Add `url.query_args:range` (and any other relevant args) to the cache contexts when building the JSON response. Explicitly set `max-age: 0` to avoid stale reuse.
- **Verification**: After a `drush cr`, fetch `/makerspace-dashboard/api/chart/{section}/{chart}?range=X` for multiple ranges and confirm `range.active` and the datasets differ. Tail `/tmp/makerspace_chart_api.log` to make sure the builder receives the requested range.

## 3. PHP changes need cache clears

- **Symptom**: PHP modifications (e.g., builder logging) appear to have no effect.
- **Cause**: Drupal’s opcode cache/dynamic cache still serves the old code.
- **Fix**: Run `lando drush cr` (or `drush cr` on the target environment) any time PHP logic changes, especially inside controllers/builders/services.
- **Verification**: Add temporary logging to `/tmp/*.log` to see the updated code execute, then remove once verified.

## 4. Data services respecting ranges

- **Symptom**: API responds with the right `range.active`, but the numeric series never change.
- **Cause**: Service layer functions (e.g., `getEventInterestBreakdown()`) query per-range data but cache results with keys that omit the start timestamp, leading to reused “all time” data.
- **Fix**: Ensure cache IDs include both start/end timestamps (or the range key), and that SQL queries apply the provided `$start_date/$end_date`. Clear Drupal caches after adjusting the service.
- **Verification**: Compare raw curl responses for two ranges and confirm the `dataset.data` arrays differ.

## Debug checklist

1. **Browser devtools** – Does the API request include the right `range`? Does the JSON response change?
2. **Server logs** – Tail `/tmp/makerspace_chart_api.log` to confirm the builder receives the filter. Add temporary logging in the builder/service if needed.
3. **Cache contexts** – If responses look cached, ensure the controller’s `CacheableMetadata` includes `url.query_args:range`.
4. **Drupal caches** – After PHP changes, run `drush cr`.
5. **Diff API payloads** – Save `curl` output for two ranges and `diff` them to verify data changes.

Documenting these steps should prevent future range-related regressions and shave hours off troubleshooting. Whenever a new chart gets range controls, refer back here to ensure both the front end and back end honor the selected window.
