# Module TODO & Development Roadmap

This document outlines the development tasks required to fully align the Makerspace Dashboard module with the 2025-2030 Strategic Plan.

The primary goal is to transition all dashboard sections from placeholders or estimates to live data visualizations that directly track the Key Performance Indicators (KPIs) identified in the plan.

## 1. Core Development Tasks

### 1.1. Implement Placeholder Sections
The following `DashboardSection` plugins are currently placeholders and need to be fully implemented to query their respective services and build charts.

- ~~`src/DashboardSection/GovernanceSection.php`~~ (board composition charts now use builders + Google Sheets service)
- `src/DashboardSection/InfrastructureSection.php`
- `src/DashboardSection/EntrepreneurshipSection.php`
- `src/DashboardSection/DevelopmentSection.php`

### 1.2. Create New Data Services
To support the new sections, we must create new services to abstract data fetching.

- **`GovernanceDataService`**: To fetch data on board, committees, and volunteers. (See Data Sourcing).
- **`SurveyDataService`**: To fetch data from member/participant surveys (e.g., NPS, Satisfaction).
- **`EntrepreneurshipDataService`**: To track incubator occupancy and program milestones.
- **`DevelopmentDataService`**: To fetch fundraising data (donations, grants), distinct from earned revenue.

### 1.3. Enhance Existing Services
Existing services need significant enhancements to meet strategic plan requirements.

- **`FinancialDataService`**: This is a high priority. The current service relies on estimates. It must be refactored to query authoritative sources:
    - CiviCRM Contribution tables for membership and donation revenue.
    - Accounting system (e.g., Xero) or payment gateways (Stripe, Chargebee) for consolidated revenue, expenses, and reserve funds.
- **`DemographicsDataService`**:
    - Add a `getEthnicityDistribution()` method to query the `user__field_member_ethnicity` Drupal profile field (as noted in the README).
- **`MembershipMetricsService`**:
    - Enhance cohort methods (like `getAnnualCohorts()`) to accept demographic filters (e.g., ethnicity, gender) to calculate retention rates for specific groups (KPI: Retention POC).
- **`EventsMembershipDataService`**:
    - Add filters to distinguish between event types (e.g., 'Workshop', 'Institutional Program').
    - Add joins to demographic data (from Drupal profile via `uf_match`) to calculate participant diversity.

### 1.4. Finish Chart Builder Migration
The React chart renderer now expects each visualization to be defined via `ChartDefinition` objects produced by dedicated chart builder classes. Finance and Overview have been migrated; the remaining sections still construct render arrays inline, which makes CSV downloads inconsistent and multiplies effort when we change chart styles.

- **Action:** For each section below, port its charts to builder classes (see `docs/chart-builder-template.md`) and ensure the section injects `ChartBuilderManager`:
    - ~~Governance (goal vs. actual layouts still rely on inline render arrays).~~
    - ~~Education~~, ~~Outreach~~, Development, Entrepreneurship.
- **Action:** Once all sections emit `ChartDefinition` metadata, remove the legacy serialization path (`DashboardSectionBase::serializeRenderable()`) and simplify the CSV controller accordingly.

## 2. Data Sourcing Strategy (Drupal & CiviCRM)

Many new KPIs require data that is not currently tracked or is tracked outside the existing services. We must decide *where* this data will live.

- **For Volunteers & Committees (Governance):**
    - **Recommendation:** Use CiviCRM. Create **CiviCRM Groups** (e.g., "Active Volunteers," "Governance Committee") or **CiviCRM Relationships** (e.g., "Board Member is," "Shop Tech for").
    - **Action:** The new `GovernanceDataService` should query these CiviCRM tables, *not* rely on less-structured Drupal roles.

- **For Board & Instructor Diversity (Governance/Education):**
    - **Recommendation:** This is sensitive data for a small group. A new, protected **Drupal Custom Entity** or a new **CiviCRM Custom Field Group** for specific contacts (instructors, board) is appropriate.
    - **Action:** The `GovernanceDataService` and/or `EventsMembershipDataService` must be built to query this new source.

- **For Satisfaction & NPS (Infrastructure/Education):**
    - **Recommendation:** Use the Drupal **Webform** module. Create satisfaction surveys and post-workshop evaluations.
    - **Action:** The new `SurveyDataService` should query the `webform_submission` and `webform_submission_data` tables to aggregate responses.

- **For Entrepreneurship (Entrepreneurship):**
    - **Recommendation:** Track incubator participants and their milestones. This fits well as a **CiviCRM Custom Entity** or a simple **Drupal Custom Entity**.
    - **Action:** The new `EntrepreneurshipDataService` will query these custom tables.

- **For Development/Fundraising (Development):**
    - **Recommendation:** This data *must* be in CiviCRM. Use CiviCRM **Financial Types** to separate 'Donation', 'Grant', 'Sponsorship' from 'Membership Dues'.
    - **Action:** The new `DevelopmentDataService` will query `civicrm_contribution` and filter by these Financial Types.

## 3. UI & Structural Refactoring

- **Group Charts by Objective:**
    - **Action:** In each `DashboardSection::build()` method, add sub-headings (e.g., `<h2>`) or collapsible `<details>` elements for each Objective from the strategic plan. Place the relevant charts under the correct objective.
- **Move Utilization Charts:**
    - **Action:** Move all charts from `RetentionSection.php` that use the `UtilizationDataService` (e.g., "Monthly member entries," "Visit frequency") to `InfrastructureSection.php`, as they align with "Objective #1: Ensure Safe and Reliable Operations."

## 4. Configuration

- **Action:** Update `DashboardSettingsForm.php` to make the module configurable and avoid hard-coding IDs. Add settings for:
    - CiviCRM Group IDs for volunteers/committees.
    - CiviCRM Event Type IDs (for filtering workshops).
    - CiviCRM Financial Type IDs (for donations, grants).
    - Webform IDs for surveys.

## 5. New Chart Roadmap (Summary)

This is a summary of new charts needed, organized by the strategic plan.

- **1. Governance:**
    - Board Diversity (% BIPOC, Gender).
    - Count of Active Titled Volunteers.
    - Committee Participation.
- **2. Finance:**
    - Operating Reserve (e.g., "Months of OpEx").
    - Earned Income vs. Core Costs %.
    - Revenue by Business Line (Membership, Classes, Storage, etc.).
- **3. Infrastructure:**
    - *Move all Utilization charts here.*
    - Member Satisfaction (Equipment) (from survey).
    - Member Satisfaction (Facility) (from survey).
    - Shop Budget Adherence.
- **4. Outreach:**
    - Total First Time Workshop Participants.
    - Total $ of New Recurring Revenue.
- **5. Retention:**
    - *Keep churn, cohort, and net change charts.*
    - First Year Member Retention %.
    - Active Participation Rate (% members with 1+ visit/quarter).
- **6. Education:**
    - *Keep badge funnel/timing charts.*
    - Participant Net Promoter Score (NPS) (from survey).
    - Instructor Diversity (% BIPOC).
    - Workshop Participant Diversity (% BIPOC).
- **7. Entrepreneurship:**
    - Incubator Workspace Occupancy %.
    - Entrepreneurship Milestones (e.g., chart of counts by milestone).
- **8. Development:**
    - Annual Corporate Sponsorship $.
    - Annual Individual Giving $.
    - Major Donor Retention Rate %.
- **9. DEI (Roll-up Section):**
    - Membership Diversity (% BIPOC).
    - POC Member Retention Rate (vs. avg).
    - Community Diversity Sentiment (from survey).
