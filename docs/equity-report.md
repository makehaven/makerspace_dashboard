# Equity & Access Report — reproducible method

This document reproduces the analysis behind MakeHaven's grant reporting on
participation equity: **who joins, from which neighborhoods, and what happens to them
once they are members.** It was written for the Yale & New Haven Community Fund
application (Aug 2026) and is expected to be re-run annually.

Everything here is runnable against a production database pull:

```bash
lando pull-db
lando mysql pantheon < docs/queries/<file>.sql
```

Read [`data-sources.md`](data-sources.md) for tables and joins, and
[`metric-definitions.md`](metric-definitions.md) for how each derived metric is defined —
that file is what keeps a re-run comparable to this one. The four source rules below are the
ones people get wrong.

## Source rules (get these right or the numbers are wrong)

| Concept | Use | Never use |
|---|---|---|
| Join date | `profile.created` (main profile, UNIX ts) | `profile__field_member_join_date` — dead since 2024-10-07 |
| Ethnicity | `civicrm_value_demographics_15.ethnicity_46` | `profile__field_member_ethnicity` — 67% coverage vs 92% |
| Address | `civicrm_address` (`is_primary = 1`) coalesced with the profile field | the profile field alone — 70% coverage vs 94% |
| Neighborhood | point-in-polygon on official boundaries | ZIP — `06511` contains both East Rock and Newhallville |
| Current member | `user__roles.roles_target_id = 'member'` | any membership-type taxonomy alone |

Two conventions to state wherever results are published:

- **Ethnicity is multi-select.** Counts are "alone or in combination" and do not sum to
  the member total. `ethnicity_46` is `\x01`-delimited — match with `LIKE '%black%'`,
  never `=`. Where a comparison needs a disjoint reference group, "white only" means
  `LIKE '%white%'` AND NOT matching any other category.
- **Cohort windows.** Use profiles created 2022-01-01 → 2024-12-31 for retention work:
  old enough to have an outcome, recent enough to reflect current operations. Current-member
  demographic cells are small (77 Black, 62 Hispanic) — quote them as illustration, with n,
  and let the cohort figures carry the argument.

## The metrics

`docs/queries/equity-cohort.sql` builds one temporary table (`cohort`) with a row per
member profile — join date, current-member flag, ethnicity flags, first-90-day badge
count, lifetime badges, visit-days, GEMS membership — and then derives every figure below
from it. Add new cuts there rather than writing fresh joins.

| Metric | Definition | Aug 2026 value |
|---|---|---|
| First-90-day badge velocity | `active` badge_requests where `nd.created BETWEEN p.created AND p.created + 7776000` | white 6.52, Black 3.34 |
| Retention | holds the `member` role today, by join cohort | white 23.8%, Black 12.6%, Hispanic 12.1% |
| Activation | ≥ 2 active badges (one past orientation) | — |
| Visit-days | `COUNT(DISTINCT DATE(...))` on `access_control_log_field_data` where `type = 'access_control_request'` | Black 28.9, white 20.3 |
| Cost-driven churn | `profile__field_member_end_reason = 'cost'` as a share of that group's exits | Black 20.7%, white 11.7% |
| GEMS effect | cohort members tagged under `user_tags` parent tid **3319** | 11.14 vs 5.01 first-90 badges |

**Always exclude staff from utilisation cuts.** Instructors, facilitators, admins and
managers visit daily and will swamp any per-member average:

```sql
AND NOT EXISTS (
  SELECT 1 FROM user__roles r2
  WHERE r2.entity_id = p.uid
    AND r2.roles_target_id IN ('instructor','facilitator','administrator','manager')
)
AND COALESCE(mt_name, 'Standard') NOT IN ('Provided','Corporate')
```

**Known instrumentation break.** Door-access logging changed in July 2026: events jump
from ~5,400/month to 33,742 (Jul) and 95,359 (Aug) while unique visitors stay flat at
~350/month. Report unique visitors or pre-July-2026 event totals; never the raw 2026
event count.

## Neighborhood analysis

The per-capita neighborhood figures are the strongest output of this report and the
fiddliest to rebuild. `docs/queries/neighborhood-rates.py` does the whole thing.

1. **Export member points** — geocoded primary CiviCRM addresses, joined via
   `civicrm_uf_match`, bounded to `41.20–41.40 N, -73.05–-72.80 W`.
   `geo_code_1` = latitude, `geo_code_2` = longitude. 3,527 of 3,824 addresses are geocoded.
2. **Fetch boundaries** — City of New Haven's 20 official neighborhoods, ArcGIS item
   `31e6058fbf434a68bd23b5ff092afa14`:
   ```
   https://services1.arcgis.com/7uJv7I3kgh2y7Pe0/arcgis/rest/services/New_Haven_Neighborhoods/FeatureServer/0/query
     ?where=1%3D1&outFields=*&outSR=4326&f=geojson
   ```
3. **Fetch population** — Census 2020 blocks from TIGERweb layer 10, which carries
   `POP100` and `CENTLAT`/`CENTLON` directly. **No API key required** (the
   `api.census.gov` block endpoint does require one — use TIGERweb instead):
   ```
   https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/tigerWMS_Census2020/MapServer/10/query
     ?where=STATE='09' AND COUNTY='009'
     &geometry=-73.05,41.20,-72.80,41.40&geometryType=esriGeometryEnvelope&inSR=4326
     &spatialRel=esriSpatialRelIntersects
     &outFields=GEOID,CENTLAT,CENTLON,POP100,HU100&returnGeometry=false
     &resultRecordCount=5000&f=json
   ```
4. **Assign both** member points and block centroids to neighborhoods by ray-casting
   point-in-polygon, then divide.

> **Correctness check — do not skip.** Summing `POP100` over blocks assigned to the 20
> neighborhoods must equal **134,023**, New Haven's official 2020 population. If it does
> not, the polygon handling is wrong (most likely multipolygon rings or a lon/lat swap).

Aug 2026 result: 351 current members in New Haven, **26.2 per 10,000** citywide, ranging
from Wooster Square at 119.4 (×4.56) to West Rock at 2.2 (×0.08). Grouped by DataHaven's
2020 racial classification: majority-white neighborhoods 66.9 per 10,000, majority-Black
14.8, majority-Latino 7.1 — a **9.4×** spread.

## Entrepreneurship — instrumented but not populated

Structured entrepreneurship tracking exists and is almost entirely empty. As of Aug 2026,
316 current members declare commercial intent (`field_member_goal` in `seller`,
`entrepreneur`, `inventor`) and:

| Field | Rows |
|---|---|
| `profile__field_inventor_pipeline_state` | **0** |
| `profile__field_inventor_needs` | **0** |
| `profile__field_inventor_product_summary` | **0** |
| `civicrm_value_entrepreneurs_1` (consultations) | **0** |
| `civicrm_value_entrepreneurs_19.pipeline_stage_52` | 15 |
| `civicrm_value_entrepreneurs_18.entrepreneur_needs_53` | 16 |
| `profile` bundle `entrepreneur` | 15 |
| `business` nodes | 68 (61 distinct owners) |

Only 19 of the 316 members with commercial intent own a business record. **Any grant
promising entrepreneurship outcomes needs these fields populated before the reporting
period begins** — the pipeline-stage field is the minimum viable instrument, since it is
the only one that supports a before/after claim.

## Member surveys — the outcome instrument

Until the venture pipeline is populated, the **member survey webforms are the only source of
self-reported outcomes**, and they are richer than the CiviCRM fields. Join
`webform_submission` (which carries `uid` for logged-in respondents) to
`webform_submission_data`.

| Form | Responses | Identified | Notable keys |
|---|---|---|---|
| `2025_member_survey` | 111 | 95 | `art`, `prototype`, `entrepreneurial`, `income`, `net_prompt`, `how_did_makehaven_help_you_generate_income`, `what_supports_do_you_need_to_be_successful_in_your_entrepreneuri` |
| `2026_member_survey` | 71 | — | `activity` (multi-select), `net_prompt`, `narrative_success`, `narrative_feedback` |

2025 results, and the badge gradient behind them (`docs/queries/survey-outcomes.sql`):

| Outcome | Share of respondents | 0–4 badges → 20+ badges |
|---|---|---|
| Made art | 59% | 28.6% → 68.0% |
| Entrepreneurial activity | 39% | 42.9% → **32.0%** (inverts) |
| Prototyped a product | 16% | 7.1% → 20.0% |
| **Generated income** | **21%** | **7.1% → 34.0%** |

Two findings worth preserving:

- **Entrepreneurs specialise, and tool access converts them.** Members pursuing a venture hold
  *fewer* badges (19.9 vs 28.9) but report income at **47.2% against 9.1%** — five times the rate.
  Within that group, income conversion runs **23.5% under 15 badges and 68.4% at 15+**; for
  non-entrepreneurial members under 15 badges it is **0.0%**. Do not read the lower badge count as
  failure to convert: they learn the two or three tools their product needs. This is the
  highest-leverage relationship in the dataset.
- **NPS does not vary with badges** — 9.43 / 9.73 / 9.63 / 9.53 across buckets, because it is
  already at ceiling. Organisation-wide NPS is **+82 (2025)** and **+89 (2026, zero detractors)**.
  Do not claim a satisfaction-vs-engagement gradient; it is not there.

> **Response bias.** 111 responses is a 13% response rate and respondents are roughly twice as
> engaged as the membership (≈25 badges vs **12.6** for all current members; 53% hold 20+ badges
> vs 21.1%). Always report these as "share of respondents", never "share of members". The
> within-survey gradient is robust to this; the headline rates are not.

## Re-running this report

1. `lando pull-db`
2. `lando mysql pantheon < docs/queries/equity-cohort.sql` — cohort, retention, velocity, GEMS
3. `lando mysql pantheon < docs/queries/member-geography.sql` — exports points to TSV
4. `python3 docs/queries/neighborhood-rates.py` — fetches boundaries + population, prints rates
5. Check the 134,023 population total before using any output.
6. Update the values in this document, so the next person can tell what moved.

Figures in this document are as of **2026-08-27**.
