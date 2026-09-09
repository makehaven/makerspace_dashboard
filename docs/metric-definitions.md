# Metric definitions

Canonical definitions for the derived metrics used in equity, engagement and outcome
reporting. **If two reports disagree, it is almost always because one of these was computed
differently** — badge counts that include `pending`, a cohort that used the dead join-date
field, a utilisation average that left staff in.

Companion documents: [`data-sources.md`](data-sources.md) for tables and joins,
[`equity-report.md`](equity-report.md) for the annual report method and current values,
[`queries/`](queries/) for runnable SQL.

## Population filters

| Term | Definition | Why it matters |
|---|---|---|
| **Member profile** | `profile` where `type='main' AND status=1 AND is_default=1` | Without `is_default` you pick up historical drafts and double-count people. |
| **Current member** | Holds the Drupal role `member` (`user__roles`) | **864** hold the role as of 2026-08-27; **856** of those also have a main profile. Reporting that joins to `profile` will therefore say 856 — state which you used. The 8-person gap is members without a completed profile, not an error. Do not use a membership-type taxonomy value as a proxy for membership; it is set independently and drifts. |
| **Staff / comped** | Holds `instructor`, `facilitator`, `administrator`, or `manager`; or membership type `Provided` or `Corporate` | **Exclude from every behavioural average.** 52 of the 68 `Provided` memberships (76%) belong to someone with a staff role, and staff visit near-daily. Leaving them in was the cause of an early spurious result. |
| **Join date** | `profile.created` (UNIX timestamp) | Complete for all 3,807 profiles, 2012→present. Never `field_member_join_date` — dead since 2024-10-07. |
| **Cohort window** | Profiles created `2022-01-01` → `2024-12-31` | Old enough to have a retention outcome, recent enough to reflect current operations. Use for anything measuring outcomes. |

## Badge metrics

A badge is **earned** only when its `badge_request` node has
`field_badge_status = 'active'`. `pending` (5,106 rows) and `duplicate` (1,379) are not
achievements; including them inflates every count by roughly a quarter.

| Metric | Definition |
|---|---|
| **Lifetime badges** | Count of `active` badge_requests linked to the user via `node__field_member_to_badge`. |
| **First-90-day badges** (badge velocity) | Same, restricted to `nd.created BETWEEN p.created AND p.created + 7776000`. 7776000 = 90 days in seconds. **This is the headline engagement metric** — it predicts retention better than anything else measured. |
| **Activated** | ≥ 2 lifetime badges. The threshold is 2, not 1, because nearly everyone earns the Door badge; one badge means "walked in", two means "learned something". |
| **Orientation badges** | Term 270 (Maker Safety) and the Door badge are prerequisites, not achievements. Exclude them when reporting "first real badge". |

## Retention

**Retention = holds the `member` role today, by join cohort.** It is a survival measure, not
a churn rate, and it is only meaningful within a fixed cohort window — a 2026 joiner has not
had the chance to leave yet.

Do **not** compute retention or outcome gaps by comparing current members to each other. That
conditions on survival and hides the very effect being measured. This produced a false
"activation gap" finding in the first version of the equity report: pooling current and former
members suggested 44.7% of Black members never progressed past orientation, when restricting
correctly to current members shows activation rates are near-equal (85.4% vs 91.0%). The real
disparity is in **retention**, not activation.

## Utilisation

| Metric | Definition |
|---|---|
| **Visit-day** | A distinct `DATE()` on which the member appears in `access_control_log_field_data` with `type='access_control_request'`. Count distinct days, never raw events. |
| **Unique monthly visitors** | Distinct members with ≥ 1 visit-day in the month. ~350/month and stable. **This is the safe utilisation metric to publish.** |

> **Instrumentation break — July 2026.** Door events jump from ~5,400/month to 33,742 (Jul)
> and 95,359 (Aug) while unique visitors stay flat. This is a logging change, not growth.
> Never publish raw 2026 event counts; use unique visitors, or event totals through June 2026.

## Demographic conventions

Ethnicity (`civicrm_value_demographics_15.ethnicity_46`) is a **multi-select** checkbox.

- Counts are **"alone or in combination"** — a member selecting Black and White counts in both.
  **They do not sum to the member total.** State this wherever a breakdown is published.
- **"White only"** means matches `%white%` AND matches none of
  `black|hispanic|asian|native|pacific|middleeast|other`. Use this when a disjoint reference
  group is needed. It is deliberately conservative: it shrinks the reference group and makes
  measured gaps slightly harder to produce, so a gap that survives it is real.
- **BIPOC** means matching any of that same non-white list.
- Report coverage alongside any demographic figure — 91.8% of current members have answered,
  so ~8% are simply absent, not "unknown ethnicity".

## Commercial intent and outcomes

| Term | Definition |
|---|---|
| **Commercial intent** | `field_member_goal` includes any of `seller`, `entrepreneur`, `inventor`. 316 current members. |
| **Artist** | `field_member_goal` includes `artist`. 379 current members. |
| **Artist/commercial overlap** | Members matching both. 170 of 379 artists (45%). Stable at 44–45% across every cohort measured since 2012 — the empirical basis for treating art and enterprise as one population rather than two programmes. |
| **Reported income** | Survey self-report (`income = 'yes'`), not a ledger figure. Always report as a **share of respondents**, never of members. |

**Do not read entrepreneurs' lower badge counts as failure.** Members pursuing a venture hold
fewer badges (19.9 vs 28.9) because they specialise in the two or three tools their product
needs — and they convert to income at **47.2% against 9.1%**, five times the rate. Within that
group a tool baseline is what matters: income conversion runs **23.5% under 15 badges and
68.4% at 15+**, against **0.0%** for non-entrepreneurial members under 15 badges.

## Neighborhood participation rate

**Members per 10,000 residents**, by point-in-polygon assignment to the City of New Haven's 20
official neighborhood boundaries, with 2020 Census block population as the denominator.

- **Never use ZIP.** `06511` contains both East Rock (102.5 per 10,000) and Newhallville (3.9)
  — a 26× difference that ZIP-level reporting cannot see, and the reason this gap went
  unnoticed for years.
- **Index** = neighborhood rate ÷ citywide rate (26.2 as of Aug 2026).
- **Parity shortfall** = for each neighborhood below the citywide rate, the members needed to
  reach it. Currently 146 across 12 neighborhoods — the derivation behind the "150 residents"
  target in grant applications.
- **Correctness check:** assigned block population must total **134,023**. See
  [`equity-report.md`](equity-report.md).

## Cohorts, subsidy tiers and partners (`user_tags`)

The `user_tags` vocabulary on `user__field_user_tags` carries programme and subsidy membership
that exists nowhere else. Cohort terms are **children** of a parent term; flat terms with no
parent are unrelated markers (discount codes, household flags) and should be ignored.

| Parent term | tid | Holds |
|---|---|---|
| GEMS | **3319** | 15 cohort terms — Guided Exploration of Makerspace Skills, ~121 participants |
| Partner Organization Affiliation | 3320 | Gateway programs, ClimateHaven, NHPS Adult Ed, Yale School of Art, VA Errera Center, Escape, CCAM, Turnbridge |
| Reduced Price | 3318 | 4 subsidy tiers (below) |
| New Haven Public Schools | 2992 | NHPS cohorts |
| Foundations of Fabrication | 2761 | FoF cohorts |

**The Reduced Price tags are a finer income signal than the membership-type taxonomy** and are
the right source for means-tested reporting — membership type only says "Sliding Scale", while
these say which threshold:

| Tag | Tagged | Current members |
|---|---|---|
| `sliding-scale-federal-poverty-line` | 93 | 37 |
| `sliding-scale-200-federal-poverty-line` | 59 | 19 |
| `sliding-scale-living-wage` | 31 | 17 |
| `consideration` | 63 | 11 |

**GEMS caveat.** Participants apply and are selected, so GEMS-vs-rest comparisons measure
selection plus programme. Treat any GEMS effect as an **upper bound, not a causal estimate**,
until seats are allocated by lottery with the waitlist tracked.

## Metrics deliberately not used

| Metric | Why not |
|---|---|
| Satisfaction / NPS as an engagement outcome | Tested and flat: 9.43 / 9.73 / 9.63 / 9.53 across badge buckets. NPS is at ceiling (+82 in 2025, +89 in 2026), so it cannot move with engagement. Report NPS as an organisational-health figure on its own; do not claim a gradient. |
| Raw door-event counts after June 2026 | Instrumentation change — see above. |
| Any cohort built on `field_member_join_date` | Dead since 2024-10-07; drops every 2025–26 member while still returning plausible-looking numbers. |
| Badge counts including `pending` | Not achievements; inflates totals ~25%. |

Values in this document are as of **2026-08-27**.
