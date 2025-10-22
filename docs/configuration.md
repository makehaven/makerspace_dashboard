# Configuration Reference

The module exposes a single configuration object, `makerspace_dashboard.settings`, editable via the admin form at `/admin/config/makerspace/dashboard`. This document describes each setting and how it influences the dashboards.

## Utilization
| Key | Description | Default |
|-----|-------------|---------|
| `utilization.daily_window_days` | Number of days shown in the daily unique entry chart. Adjust to zoom in on recent traffic. | 90 |
| `utilization.rolling_window_days` | Number of days spanned by the rolling-average trend chart and related summaries. | 365 |

## Engagement
| Key | Description | Default |
|-----|-------------|---------|
| `engagement.cohort_window_days` | Lookback window for new member cohorts (join date filter). | 90 |
| `engagement.activation_window_days` | Number of days after joining to consider when calculating orientation/badge progress. | 90 |
| `engagement.orientation_badge_ids` | Taxonomy term IDs (comma separated) treated as orientation prerequisites (e.g. Maker Safety). | `[270]` |

## Tab Notes
| Key | Description |
|-----|-------------|
| `tab_notes.<section>` | Free-form markdown/text displayed above each tab for shared context (e.g. definitions, caveats). Supported sections: `utilization`, `demographics`, `engagement`, `events_membership`, `retention`, `financial`. |

## Adding New Settings
1. Update `config/install/makerspace_dashboard.settings.yml` with sensible defaults.
2. Extend the schema in `config/schema/makerspace_dashboard.schema.yml`.
3. Update the settings form (`DashboardSettingsForm`) to surface the new field(s) and add validation.
4. Inject the config value into the relevant service/section (prefer reading via the service layer).
5. Add cache tags (`config:makerspace_dashboard.settings`) to render arrays affected by the setting so the UI updates immediately after saves.

Following this workflow keeps runtime behaviour predictable and ensures configuration is exportable for CI/CD pipelines.
