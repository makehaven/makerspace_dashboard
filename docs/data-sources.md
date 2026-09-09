# Data Sources Overview

> **Reproducing the annual equity & access report?** Start at
> [`equity-report.md`](equity-report.md) — runnable method, SQL in [`queries/`](queries/),
> and the correctness checks. **Before computing any derived metric, read
> [`metric-definitions.md`](metric-definitions.md)** — it fixes the definitions of badge
> velocity, activation, retention, visit-days and the demographic conventions, so two
> reports don't silently disagree. This file is the table reference.

This module reads from several canonical tables to produce aggregated makerspace KPIs. The list below documents the primary entities, relevant joins, and filtering rules. Use it as the starting point for any new charts or analytics.

## Access Control / Utilization

| Purpose | Table | Key Columns | Notes |
|---------|-------|-------------|-------|
| Door activity events | `access_control_log_field_data` | `id`, `type`, `created` | Only rows with `type = 'access_control_request'` are considered. `created` is a UNIX timestamp. **Logging changed in July 2026** — events jump from ~5,400/month to 33,742 (Jul) and 95,359 (Aug) while unique visitors stay flat at ~350/month. Count distinct visit-*days* per member, or unique monthly visitors; never publish raw 2026 event counts. |
| Access user reference | `access_control_log__field_access_request_user` | `entity_id`, `field_access_request_user_target_id` | Join to log table for badge owner (`entity_id = id`). |
| Membership role check | `user__roles` | `entity_id`, `roles_target_id` | Join on `entity_id = uid` and require role `member` or `current_member`. |

## Profiles / Demographics

> **Ethnicity and address now live in CiviCRM, not Drupal.** The `profile__field_member_ethnicity`
> and `profile__field_member_address` tables are legacy and materially incomplete. Reporting that
> uses them under-counts members and skews recent cohorts. See the two tables below.

| Purpose | Table | Key Columns | Notes |
|---------|-------|-------------|-------|
| Primary member profile | `profile` | `profile_id`, `uid`, `type`, `status`, `is_default` | Use bundle `main`, `status = 1`, `is_default = 1` to avoid historical drafts. |
| Profile joins | `profile__<field_name>` | `entity_id`, `delta` | Standard profile field storage tables—see below for specific machine names. |
| Drupal user → CiviCRM contact | `civicrm_uf_match` | `uf_id` (Drupal `uid`), `contact_id` | **Required join for all CiviCRM-sourced demographics.** |

### Ethnicity — use CiviCRM

| Purpose | Table | Key Columns | Notes |
|---------|-------|-------------|-------|
| Ethnicity (canonical) | `civicrm_value_demographics_15` | `entity_id` (CiviCRM `contact_id`), `ethnicity_46` | Custom group `Demographics` (id 15), custom field id 46. Option group 112. |
| Ethnicity (legacy) | `profile__field_member_ethnicity` | `field_member_ethnicity_value` | **Do not use for new reporting.** Retained for historical rows only. |

`ethnicity_46` is a CiviCRM **checkbox** field: values are stored multi-valued, wrapped and
separated by `\x01` control characters (e.g. `\x01black\x01hispanic\x01`). Match with
`LIKE '%black%'`, never with `=`. Because a member may select several values, counts are
"alone or in combination" and **will not sum to the member total** — state that wherever you
publish a breakdown.

Option values: `asian`, `black`, `middleeast`, `hispanic`, `native`, `pacific`, `white`,
`other` (Not Listed), `decline` (Prefer not to answer).

Coverage comparison as of 2026-08-27 — the reason this matters:

| Population | CiviCRM `ethnicity_46` | Legacy `profile__field_member_ethnicity` |
|---|---|---|
| All main profiles (3,807) | 81.4% | 73.0% |
| Current members (856) | **91.8%** | 67.2% |
| Joined 2025 or later (785) | **88.9%** | 47.9% |

### Address & geography — prefer CiviCRM

| Purpose | Table | Key Columns | Notes |
|---------|-------|-------------|-------|
| Member address (canonical) | `civicrm_address` | `contact_id`, `city`, `postal_code`, `geo_code_1` (lat), `geo_code_2` (lon) | Filter `is_primary = 1`. Join via `civicrm_uf_match`. |
| Member address (legacy) | `profile__field_member_address` | `field_member_address_locality`, `field_member_address_postal_code` | Fall back to this only when the CiviCRM address is missing. |

Coalescing CiviCRM over the legacy profile field raises town coverage for current members from
~70% to **93.7%**. `geo_code_1`/`geo_code_2` are populated for 3,527 of 3,824 addresses.

`postal_code` is dirty and needs normalising before grouping: leading zeros are frequently
dropped (`6511`), and the column contains free text (`Unite`, `00000`, `99999`). Strip
non-digits and left-pad to five characters. Note `06512` is East Haven and `06516` is West
Haven — neither is New Haven, despite appearing in New Haven member sets.

For neighborhood-level analysis, ZIP is not good enough: `06511` spans both East Rock and
Newhallville, whose per-capita membership rates differ by roughly 26×. Use point-in-polygon
against the City of New Haven's 20 neighborhood boundaries instead
(ArcGIS item `31e6058fbf434a68bd23b5ff092afa14`). Census 2020 block population for per-capita
denominators is available keyless from TIGERweb layer 10 (`POP100`, `CENTLAT`, `CENTLON`);
assigning block centroids to those polygons reproduces New Haven's official 2020 population
of 134,023 exactly, which is a useful check that an implementation is correct.

### High-value Profile Fields
- `field_member_gender` (list of gender options)
- `field_member_goal` (multi-value: `skill_builder`, `artist`, `hobbyist`, `networker`, `inventor`, `seller`, `entrepreneur`, `other`)
- `field_member_occupation_type` (`employed`, `student`, `selfemployed`, `unemployed`, `retired`, `other`)
- `field_member_discovery` (acquisition channel), `field_member_end_reason` (churn reason)
- `field_member_interests`, `field_member_areas_interest`, `field_member_characteristics` (taxonomy references / list strings)

## Membership Cohorts

| Purpose | Table | Key Columns | Notes |
|---------|-------|-------------|-------|
| **Join dates (canonical)** | `profile` | `created` | **Use this.** UNIX timestamp of main-profile creation. Complete for all 3,807 profiles, 2012 → present. `MemberJoinLocationDataService` already uses it; see `docs/services.md`. |
| Join dates (legacy, do not use) | `profile__field_member_join_date` | `field_member_join_date_value` | **Abandoned 2024-10-07.** Populated for only ~70% of profiles and nothing since that date, because join date moved to profile creation. It silently yields plausible-but-wrong cohorts — every cohort built on it drops all 2025–26 members. |
| End dates | `profile__field_member_end_date` | `field_member_end_date_value` | Helps calculate churn per membership type. |
| Membership type | `profile__field_membership_type` + `taxonomy_term_field_data` | `field_membership_type_target_id` | Joins to taxonomy term names for reporting. |

## Badge / Engagement Workflow

| Purpose | Table | Key Columns | Notes |
|---------|-------|-------------|-------|
| Badge requests | `node_field_data` | `nid`, `type`, `status`, `created` | Only `type = badge_request` and published nodes. |
| Requesting member | `node__field_member_to_badge` | `field_member_to_badge_target_id` | Links badge request to Drupal user (`uid`). |
| Requested badge | `node__field_badge_requested` | `field_badge_requested_target_id` | Taxonomy term ID of the badge. |
| Badge status | `node__field_badge_status` | `field_badge_status_value` | **Only `active` counts as earned.** Values: `active` 23,514, `pending` 5,106, `duplicate` 1,379, `expired` 8, `Rejected` 5, `suspended` 1. Including `pending` inflates totals ~25%. |
| Badge taxonomy | `taxonomy_term_field_data` + `taxonomy_term__field_badge_access_control` | `tid`, `field_badge_access_control_value` | Access control flag differentiates tool-enabled badges (`true`). |

Orientation prerequisites are identified by `orientation_badge_ids` (configurable, defaults to term 270 – Maker Safety). First badge detection ignores orientation badge IDs to surface additional achievements.

## Cohorts, Subsidy Tiers & Partners (`user_tags`)

Programme membership, means-tested subsidy tier, and partner-organisation affiliation live in
the `user_tags` taxonomy on `user__field_user_tags` — **not** in any profile field. This is
easy to miss and there is no other source for it.

| Purpose | Table | Key Columns | Notes |
|---------|-------|-------------|-------|
| User tag assignment | `user__field_user_tags` | `entity_id` (uid), `field_user_tags_target_id` (tid) | |
| Tag hierarchy | `taxonomy_term__parent` | `entity_id` (tid), `parent_target_id` | **Cohorts are child terms.** Flat terms with no parent are unrelated markers (discount codes, household flags) — exclude them. |

Parent terms and their tids:

| Parent | tid | Children | Holds |
|---|---|---|---|
| GEMS | **3319** | 15 | Guided Exploration of Makerspace Skills cohorts; ~121 participants |
| Partner Organization Affiliation | 3320 | 9 | gateway-programs (75), ClimateHaven (25), Escape (13), NHPS Adult Ed, Yale School of Art, VA Errera Center, CCAM, Turnbridge |
| Reduced Price | 3318 | 4 | Means-tested subsidy tiers — see below |
| New Haven Public Schools | 2992 | 3 | NHPS cohorts |
| Foundations of Fabrication | 2761 | 2 | FoF cohorts |

The **Reduced Price** tags are a finer income signal than `field_membership_type`, which only
records "Sliding Scale". Prefer these for any means-tested or equity reporting:

| Tag | Tagged | Current members |
|---|---|---|
| `sliding-scale-federal-poverty-line` | 93 | 37 |
| `sliding-scale-200-federal-poverty-line` | 59 | 19 |
| `sliding-scale-living-wage` | 31 | 17 |
| `consideration` | 63 | 11 |

`CohortStatsService` in `makerspace_programs_dashboard` already walks this hierarchy — reuse it
rather than re-deriving the joins.

> **Membership-type caution.** `Provided` is not a member category: **52 of its 68 holders (76%)
> have a staff role** (instructor/facilitator/administrator/manager). Exclude `Provided` and
> `Corporate` from any per-member behavioural average, or staff visit frequency will swamp it.

## Member Surveys (self-reported outcomes)

The **only** source of self-reported outcomes — income generated, art made, prototypes built,
satisfaction. Structured entrepreneurship fields (below) are almost entirely empty, so until
they are populated the surveys carry this reporting.

| Purpose | Table | Key Columns | Notes |
|---------|-------|-------------|-------|
| Submissions | `webform_submission` | `sid`, `webform_id`, `uid`, `created` | `uid` is set for logged-in respondents — this is what makes joining to the badge ledger possible. |
| Answers | `webform_submission_data` | `sid`, `name` (element key), `value` | One row per question per submission. |

| Form | Responses | Identified | Notable element keys |
|---|---|---|---|
| `2025_member_survey` | 111 | 95 | `art`, `prototype`, `entrepreneurial`, `income`, `net_prompt`, `how_did_makehaven_help_you_generate_income`, `what_supports_do_you_need_to_be_successful_in_your_entrepreneuri`, `member_testimony` |
| `2026_member_survey` | 71 | — | `activity` (multi-select), `net_prompt`, `narrative_success`, `narrative_feedback` |

> **Response bias — always disclose.** 13% response rate, and respondents are roughly twice as
> engaged as the membership (25.6 badges vs 12.6 for all current members). Report survey
> results as **"share of respondents"**, never "share of members". Within-survey gradients are
> robust to this; headline rates are not.

The free-text fields are the strongest qualitative material MakeHaven holds — member income
narratives and stated entrepreneurial needs. `queries/survey-outcomes.sql` extracts them.

## Entrepreneurship (instrumented, largely unpopulated)

| Purpose | Table | Key Columns | Rows |
|---------|-------|-------------|------|
| Venture details | `civicrm_value_entrepreneurs_19` | `entity_id` (participant id), `venture_name_51`, `pipeline_stage_52`, `product_venture_summary_54` | 603 rows, **15** with a pipeline stage |
| Entrepreneur characteristics | `civicrm_value_entrepreneurs_18` | `entrepreneur_needs_53`, `entrepreneur_experiences_56`, `entrepreneur_goals_57` | 603 rows, **16** with needs recorded |
| Consultation activities | `civicrm_value_entrepreneurs_1` | `pipeline_stage_2`, `entrepreneur_needs_3` | **0** |
| Member business | `node_field_data` type `business` | | 68 nodes, 61 distinct owners |
| Entrepreneur profile | `profile` bundle `entrepreneur` | | 15 |
| Inventor fields | `profile__field_inventor_pipeline_state`, `..._needs`, `..._product_summary` | | **0 each — built, never used** |

316 current members declare commercial intent; 19 have any recorded venture support.
`pipeline_stage_52` is the minimum viable instrument — it is the only field supporting a
before/after claim. **Populate it before promising entrepreneurship outcomes to a funder.**

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
