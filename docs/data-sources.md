# Data Sources Overview

This module reads from several canonical tables to produce aggregated makerspace KPIs. The list below documents the primary entities, relevant joins, and filtering rules. Use it as the starting point for any new charts or analytics.

## Access Control / Utilization

| Purpose | Table | Key Columns | Notes |
|---------|-------|-------------|-------|
| Door activity events | `access_control_log_field_data` | `id`, `type`, `created` | Only rows with `type = 'access_control_request'` are considered. `created` is a UNIX timestamp. |
| Access user reference | `access_control_log__field_access_request_user` | `entity_id`, `field_access_request_user_target_id` | Join to log table for badge owner (`entity_id = id`). |
| Membership role check | `user__roles` | `entity_id`, `roles_target_id` | Join on `entity_id = uid` and require role `member` or `current_member`. |

## Profiles / Demographics

| Purpose | Table | Key Columns | Notes |
|---------|-------|-------------|-------|
| Primary member profile | `profile` | `profile_id`, `uid`, `type`, `status`, `is_default` | Use bundle `main`, `status = 1`, `is_default = 1` to avoid historical drafts. |
| Profile joins | `profile__<field_name>` | `entity_id`, `delta` | Standard profile field storage tables—see below for specific machine names. |
| Demographic fields | `profile__field_member_address`, `profile__field_member_gender`, `profile__field_member_ethnicity`, etc. | `field_*` columns | Refer to `config/field.field.profile.main.*` for allowed values and semantics. |

### High-value Profile Fields
- `field_member_address` (address locality for town aggregations)
- `field_member_gender` (list of gender options)
- `field_member_ethnicity` (multi-value ethnicity checkboxes)
- `field_member_goal`, `field_member_interest`, `field_member_characteristics` (taxonomy references / list strings)

## Membership Cohorts

| Purpose | Table | Key Columns | Notes |
|---------|-------|-------------|-------|
| Join dates | `profile__field_member_join_date` | `field_member_join_date_value` | Stored as `YYYY-MM-DD`. The membership metrics service pulls between two dates. |
| End dates | `profile__field_member_end_date` | `field_member_end_date_value` | Helps calculate churn per membership type. |
| Membership type | `profile__field_membership_type` + `taxonomy_term_field_data` | `field_membership_type_target_id` | Joins to taxonomy term names for reporting. |

## Badge / Engagement Workflow

| Purpose | Table | Key Columns | Notes |
|---------|-------|-------------|-------|
| Badge requests | `node_field_data` | `nid`, `type`, `status`, `created` | Only `type = badge_request` and published nodes. |
| Requesting member | `node__field_member_to_badge` | `field_member_to_badge_target_id` | Links badge request to Drupal user (`uid`). |
| Requested badge | `node__field_badge_requested` | `field_badge_requested_target_id` | Taxonomy term ID of the badge. |
| Badge status | `node__field_badge_status` | `field_badge_status_value` | `active` entries indicate successful completion. |
| Badge taxonomy | `taxonomy_term_field_data` + `taxonomy_term__field_badge_access_control` | `tid`, `field_badge_access_control_value` | Access control flag differentiates tool-enabled badges (`true`). |

Orientation prerequisites are identified by `orientation_badge_ids` (configurable, defaults to term 270 – Maker Safety). First badge detection ignores orientation badge IDs to surface additional achievements.

## Configuration (`makerspace_dashboard.settings`)

| Key | Default | Purpose |
|-----|---------|---------|
| `utilization.daily_window_days` | `90` | Days shown in the daily unique chart. |
| `utilization.rolling_window_days` | `365` | Days spanned by the rolling-average trend. |
| `engagement.cohort_window_days` | `90` | Lookback window for new-member cohorts. |
| `engagement.activation_window_days` | `90` | Time allowed for badges to count toward activation metrics. |
| `engagement.orientation_badge_ids` | `[270]` | Taxonomy term IDs representing orientation prerequisites. |
| `tab_notes.*` | `''` | Free-form notes displayed above each tab. |

## Reusable Patterns
- **Date ranges**: Services typically receive start/end `DateTimeImmutable` objects, fetch raw rows, and then aggregate in PHP for flexibility.
- **Caching**: All heavy queries funnel through a cache backend (default bin) with tags (`user_list`, `profile_list`, `access_control_log_list`, etc.) so Drupal invalidates results when relevant content changes.
- **Role filtering**: Active membership is defined as users holding `member` or `current_member` roles. Update `UtilizationDataService::$memberRoles` if new roles represent active members.

## Next Candidates for Instrumentation
- CiviCRM participation tables (`civicrm_participant`, `civicrm_event`) for events→membership conversions.
- Payment metadata (`profile__field_member_payment_status`, Chargebee sync tables) for financial dashboards.
- Tool usage logs (if stored separately) to cross-check badge utilization.

Keep this document current whenever new metrics or joins are introduced. Include table names, join columns, and business rules so downstream automation (including AI) can reason about the data without reverse engineering the code.

## External Data Sources

This section documents data sources that live outside of the Drupal/CiviCRM database.

### Google Sheets
- **Service:** `GoogleSheetClientService` (hypothetical, to be implemented)
- **Authentication:** OAuth 2.0 Service Account credentials stored in Drupal's key/secrets management.

#### Board & Governance Data
- **Sheet Name:** `Makerspace Board & Governance Roster`
- **Tab:** `Governance`
- **Columns:**
    - `Name` (string): Full name of the individual.
    - `Role` (string): e.g., "Board Member", "Shop Tech", "Instructor", "Volunteer".
    - `Committee` (string): Name of the committee they serve on (if any).
    - `Diversity - Gender` (string): Self-identified gender.
    - `Diversity - BIPOC` (string): "Yes" or "No".
    - `Start Date` (string): YYYY-MM-DD format.
- **Purpose:** Used by `GovernanceDataService` to build charts for the "Governance" dashboard section.
