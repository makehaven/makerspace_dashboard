# Service Responsibilities

Each dashboard section relies on a dedicated service for database access and caching. This document summarizes the services, their dependencies, and what they return so future contributors (including AI tooling) can extend or reuse them confidently.

## DemographicsDataService
- **Class**: `Drupal\makerspace_dashboard\Service\DemographicsDataService`
- **Constructor args**: `Connection`, `CacheBackendInterface`, optional member roles, TTL.
- **Core Methods**:
  - `getLocalityDistribution(int $minimum = 5, int $limit = 8): array` – aggregates member counts by profile address locality.
  - `getGenderDistribution(int $minimum = 5): array` – aggregates member counts by gender field, rolling smaller buckets into “Other”.
  - `getInterestDistribution(int $minimum = 5, int $limit = 10): array` – aggregates member counts by interest field.
  - `getDiscoveryDistribution(int $minimum = 5): array` – aggregates member counts by discovery source field.
  - `getAgeDistribution(int $minimumAge = 0, int $maximumAge = 100): array` – aggregates member counts by age.
- **Notes**: Uses profile tables (`profile`, `profile__field_member_address`, etc.) and `users_field_data`. Cache tags: `profile_list`, `user_list`.

## EngagementDataService
- **Class**: `Drupal\makerspace_dashboard\Service\EngagementDataService`
- **Constructor args**: `Connection`, `ConfigFactoryInterface`, `TimeInterface`.
- **Core Methods**:
  - `getActivationWindowDays(): int` – returns the activation window in days.
  - `getCohortWindowDays(): int` – returns the cohort lookback window in days.
  - `getDefaultRange(\DateTimeImmutable $now): array` – returns a date range for the default cohort.
  - `getOrientationBadgeIds(): array` – returns the orientation badge term IDs.
  - `getEngagementSnapshot(\DateTimeImmutable $start, \DateTimeImmutable $end): array` – returns an engagement snapshot for the given cohort range.
- **Notes**: Pulls from `profile__field_member_join_date` for cohort members and `node_field_data` + badge request field tables for badge completions. Respects `engagement.orientation_badge_ids` when ignoring orientation badges for first-badge timing.

## EventsMembershipDataService
- **Class**: `Drupal\makerspace_dashboard\Service\EventsMembershipDataService`
- **Constructor args**: `Connection`, `CacheBackendInterface`.
- **Core Methods**:
  - `getEventToMembershipConversion(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array` – fetches event-to-membership conversion data.
  - `getAverageTimeToJoin(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array` – gets average time from event to membership.
  - `getMonthlyRegistrationsByType(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array` – returns monthly registration counts grouped by event type.
  - `getAverageRevenuePerRegistration(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array` – returns average paid amount per registration grouped by event type and month.
- **Notes**: Queries CiviCRM tables (`civicrm_participant`, `civicrm_event`, `civicrm_uf_match`).

## FinancialDataService
- **Class**: `Drupal\makerspace_dashboard\Service\FinancialDataService`
- **Constructor args**: `Connection`, `CacheBackendInterface`, `DateFormatterInterface`.
- **Core Methods**:
  - `getMrrTrend(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array` – gets monthly recurring revenue (MRR) trend.
  - `getPaymentMix(): array` – gets payment mix data.
  - `getAverageMonthlyPaymentByType(): array` – computes average recorded monthly payment amount by membership type.
  - `getChargebeePlanDistribution(): array` – returns Chargebee plan distribution for active users.
- **Notes**: Queries profile tables and Chargebee-related user fields.

## MembershipMetricsService
- **Class**: `Drupal\makerspace_dashboard\Service\MembershipMetricsService`
- **Constructor args**: `Connection`, `CacheBackendInterface`, `TimeInterface`, optional member roles, TTL.
- **Core Methods**:
  - `getFlow(\DateTimeImmutable $start, \DateTimeImmutable $end, string $granularity = 'month'): array` – returns membership inflow/outflow counts grouped by type and period.
  - `getEndReasonsByPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end, string $granularity = 'month'): array` – returns membership end reasons grouped by period.
  - `getAnnualCohorts(int $startYear, int $endYear): array` – returns annual cohort retention metrics between the given years.
- **Notes**: Relies on profile join/end date fields and membership type taxonomy; caches tagged with `profile_list`, `user_list`.

## UtilizationDataService
- **Class**: `Drupal\makerspace_dashboard\Service\UtilizationDataService`
- **Constructor args**: `Connection`, `CacheBackendInterface`, `TimeInterface`, optional TTL, member roles.
- **Core Methods**:
  - `getDailyUniqueEntries(int $startTimestamp, int $endTimestamp): array` – aggregates daily unique member entries between timestamps.
  - `getVisitFrequencyBuckets(int $startTimestamp, int $endTimestamp): array` – builds visit frequency buckets based on distinct visit days per member.
  - `getFirstEntryBucketsByWeekday(int $startTimestamp, int $endTimestamp): array` – builds weekday/time-of-day buckets for first entries.
  - `getTimeOfDayBucketLabels(): array` – returns translated labels for time-of-day buckets.
- **Notes**: Queries `access_control_log_field_data` + `access_control_log__field_access_request_user` and `user__roles`. Cache tags: `access_control_log_list`, `user_list`.

## Section Wiring
- `DashboardSectionManager` assembles all sections tagged with `makerspace_dashboard.section` and injects them into the controller.
- Each section class receives only the services it needs: e.g., utilization uses `UtilizationDataService`, retention uses `MembershipMetricsService`, engagement uses `EngagementDataService`, etc.
- All sections add cache metadata (`#cache['tags']`) tied to their underlying services so changes to user/profile/config invalidate the rendered output automatically.

Keep this catalog updated whenever new services are added. Document constructor args, key methods, tables touched, and cache semantics so future automation can reason about data flow without reading the full implementation.
