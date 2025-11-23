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
  - `getAnnualMemberReferralRate(): float` - gets the annual member referral rate.
  - `getAnnualMembershipDiversity(): float` - gets the annual membership diversity (% BIPOC).
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
  - `getAnnualWorkshopAttendees(): int` - gets the total number of workshop attendees for the year.
  - `getAnnualFirstTimeWorkshopParticipants(): int` - gets the total number of first-time workshop participants for the year.
  - `getAnnualEducationNps(): int` - gets the Net Promoter Score (NPS) for the education program for the year.
  - `getAnnualParticipantDemographics(): float` - gets the percentage of workshop participants who are BIPOC for the year.
  - `getAnnualInstructorDemographics(): float` - gets the percentage of active instructors who are BIPOC for the year.
- **Notes**: Queries CiviCRM tables (`civicrm_participant`, `civicrm_event`, `civicrm_uf_match`).

## FinancialDataService
- **Class**: `Drupal\makerspace_dashboard\Service\FinancialDataService`
- **Constructor args**: `Connection`, `CacheBackendInterface`, `DateFormatterInterface`.
- **Core Methods**:
  - `getMrrTrend(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array` – gets monthly recurring revenue (MRR) trend.
  - `getPaymentMix(): array` – gets payment mix data.
  - `getAverageMonthlyPaymentByType(): array` – computes average recorded monthly payment amount by membership type.
  - `getChargebeePlanDistribution(): array` – returns Chargebee plan distribution for active users.
  - `getAverageMonthlyOperatingExpense(): float` - gets the average monthly operating expense over the last 12 months.
  - `getEarnedIncomeSustainingCore(): float` - gets the earned income sustaining core percentage.
  - `getAnnualMemberRevenue(): float` - gets the annual member revenue.
  - `getAnnualNetIncomeProgramLines(): float` - gets the annual net income from program lines.
  - `getAdherenceToShopBudget(): float` - gets the adherence to the shop budget as a variance percentage.
  - `getAnnualIndividualGiving(): float` - gets the annual individual giving amount.
  - `getAnnualCorporateSponsorships(): float` - gets the annual corporate sponsorships amount.
  - `getNonGovernmentGrantsSecured(): int` - gets the number of non-government grants secured.
  - `getDonorRetentionRate(): float` - gets the donor retention rate.
  - `getNetIncomeEducationProgram(): float` - gets the net income from the education program.
- **Notes**: Queries profile tables and Chargebee-related user fields.

## MembershipMetricsService
- **Class**: `Drupal\makerspace_dashboard\Service\MembershipMetricsService`
- **Constructor args**: `Connection`, `CacheBackendInterface`, `TimeInterface`, optional member roles, TTL.
- **Core Methods**:
  - `getFlow(\DateTimeImmutable $start, \DateTimeImmutable $end, string $granularity = 'month'): array` – returns membership inflow/outflow counts grouped by type and period.
  - `getEndReasonsByPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end, string $granularity = 'month'): array` – returns membership end reasons grouped by period.
  - `getAnnualCohorts(int $startYear, int $endYear): array` – returns annual cohort retention metrics between the given years.
  - `getAnnualMemberNps(): int` - gets the annual Member Net Promoter Score (NPS).
  - `getAnnualRetentionPoc(): float` - gets the annual retention rate for POC members.
- **Notes**: Relies on profile join/end date fields and membership type taxonomy; caches tagged with `profile_list`, `user_list`.

## MembershipLocationDataService
- **Class**: `Drupal\makerspace_dashboard\Service\MembershipLocationDataService`
- **Constructor args**: `Connection`, `CacheBackendInterface`, `GeocodingService`, optional TTL.
- **Core Method**:
  - `getMemberLocations(): array` – returns an array of latitude/longitude pairs for active members, suitable for the Leaflet heat map.
- **Notes**: Looks up active Drupal users (roles `current_member`/`member`), resolves their linked CiviCRM contact via `civicrm_uf_match`, and reads the contact’s primary address (`civicrm_address`, `civicrm_state_province`). Stored latitude/longitude pairs are rounded to ~100 m (3 decimals) and then deterministically jittered within ~1 km so the API never pinpoints a home, yet still shows neighborhood-level patterns; the geocoding service runs only when no stored coordinates exist. Results are cached at `makerspace_dashboard:membership:locations` with tags `civicrm_contact_list`, `civicrm_address_list`, `user_list`; empty payloads are never cached so new locations appear as soon as data is ready.

## UtilizationDataService
- **Class**: `Drupal\makerspace_dashboard\Service\UtilizationDataService`
- **Constructor args**: `Connection`, `CacheBackendInterface`, `TimeInterface`, optional TTL, member roles.
- **Core Methods**:
  - `getDailyUniqueEntries(int $startTimestamp, int $endTimestamp): array` – aggregates daily unique member entries between timestamps.
  - `getVisitFrequencyBuckets(int $startTimestamp, int $endTimestamp): array` – builds visit frequency buckets based on distinct visit days per member.
  - `getFirstEntryBucketsByWeekday(int $startTimestamp, int $endTimestamp): array` – builds weekday/time-of-day buckets for first entries.
  - `getTimeOfDayBucketLabels(): array` – returns translated labels for time-of-day buckets.
  - `getAnnualActiveParticipation(): float` - gets the annual active participation percentage.
- **Notes**: Queries `access_control_log_field_data` + `access_control_log__field_access_request_user` and `user__roles`. Cache tags: `access_control_log_list`, `user_list`.

## KpiDataService
- **Class**: `Drupal\makerspace_dashboard\Service\KpiDataService`
- **Constructor args**: `ConfigFactoryInterface`, `FinancialDataService`, `GoogleSheetClientService`, `EventsMembershipDataService`, `DemographicsDataService`, `SnapshotDataService`, `MembershipMetricsService`, `UtilizationDataService`.
- **Core Methods**:
  - `getKpiData(string $section_id): array` – gets all KPI data for a given section.
- **Notes**: This service orchestrates calls to all other data services to gather and calculate KPI data. It also reads from the `makerspace_dashboard.kpis.yml` configuration file.

## Section Wiring
- `DashboardSectionManager` assembles all sections tagged with `makerspace_dashboard.section` and injects them into the controller.
- Each section class receives only the services it needs: e.g., utilization uses `UtilizationDataService`, retention uses `MembershipMetricsService`, engagement uses `EngagementDataService`, etc.
- All sections add cache metadata (`#cache['tags']`) tied to their underlying services so changes to user/profile/config invalidate the rendered output automatically.

Keep this catalog updated whenever new services are added. Document constructor args, key methods, tables touched, and cache semantics so future automation can reason about data flow without reading the full implementation.
