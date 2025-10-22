# Service Responsibilities

Each dashboard section relies on a dedicated service for database access and caching. This document summarizes the services, their dependencies, and what they return so future contributors (including AI tooling) can extend or reuse them confidently.

## UtilizationDataService
- **Class**: `Drupal\makerspace_dashboard\Service\UtilizationDataService`
- **Constructor args**: `Connection $database`, `CacheBackendInterface $cache`, `TimeInterface $time`, optional TTL + member role list.
- **Core Methods**:
  - `getDailyUniqueEntries(int $startTimestamp, int $endTimestamp): array` – returns `['Y-m-d' => unique_count]` for the window, scoped to users with roles `member` or `current_member`.
  - `getVisitFrequencyBuckets(int $startTimestamp, int $endTimestamp): array` – returns counts keyed by frequency buckets (monthly, weekly, etc.).
- **Notes**: Queries `access_control_log_field_data` + `access_control_log__field_access_request_user` and `user__roles`. Cache tags: `access_control_log_list`, `user_list`.

## DemographicsDataService
- **Class**: `Drupal\makerspace_dashboard\Service\DemographicsDataService`
- **Constructor args**: `Connection`, `CacheBackendInterface`, optional member roles, TTL.
- **Core Methods**:
  - `getLocalityDistribution($minimum = 5, $limit = 8)` – aggregates member counts by profile address locality.
  - `getGenderDistribution($minimum = 5)` – aggregates member counts by gender field, rolling smaller buckets into “Other”.
- **Notes**: Uses profile tables (`profile`, `profile__field_member_address`, etc.) and `users_field_data`. Cache tags: `profile_list`, `user_list`.

## MembershipMetricsService
- **Class**: `Drupal\makerspace_dashboard\Service\MembershipMetricsService`
- **Constructor args**: `Connection`, `CacheBackendInterface`, `TimeInterface`, optional member role list.
- **Core Methods**:
  - `getFlow(DateTimeImmutable $start, DateTimeImmutable $end, string $granularity)` – returns arrays of incoming/ending members grouped by membership type and period (`day|month|quarter|year`).
  - `getAnnualCohorts(int $startYear, int $endYear)` – returns yearly cohorts with joined/active/inactive counts and retention percentages.
- **Notes**: Relies on profile join/end date fields and membership type taxonomy; caches tagged with `profile_list`, `user_list`.

## EngagementDataService
- **Class**: `Drupal\makerspace_dashboard\Service\EngagementDataService`
- **Constructor args**: `Connection`, `ConfigFactoryInterface`, `TimeInterface`.
- **Core Methods**:
  - `getDefaultRange(DateTimeImmutable $now)` – returns a cohort range using configurable lookback days.
  - `getEngagementSnapshot(DateTimeImmutable $start, DateTimeImmutable $end)` – returns a funnel (joined/orientation/first badge/tool-enabled) and a histogram of days-to-first-badge plus cohort stats.
  - `getActivationWindowDays()` / `getCohortWindowDays()` – helper accessors wired to config.
- **Notes**: Pulls from `profile__field_member_join_date` for cohort members and `node_field_data` + badge request field tables for badge completions. Respects `engagement.orientation_badge_ids` when ignoring orientation badges for first-badge timing.

## Section Wiring
- `DashboardSectionManager` assembles all sections tagged with `makerspace_dashboard.section` and injects them into the controller.
- Each section class receives only the services it needs: e.g., utilization uses `UtilizationDataService`, retention uses `MembershipMetricsService`, engagement uses `EngagementDataService`, etc.
- All sections add cache metadata (`#cache['tags']`) tied to their underlying services so changes to user/profile/config invalidate the rendered output automatically.

Keep this catalog updated whenever new services are added (e.g., upcoming Events or Financial services). Document constructor args, key methods, tables touched, and cache semantics so future automation can reason about data flow without reading the full implementation.
