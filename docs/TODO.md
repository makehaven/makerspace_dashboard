1. Governance Section
(Current: GovernanceSection.php is a placeholder.)

To-Do:

Implement GovernanceSection.php to build charts instead of showing a placeholder.

Create a new GovernanceDataService to fetch data. This data (e.g., committee participation, volunteer roles) may need to be sourced from CiviCRM, Drupal user roles, or a new tracking system.


Objective #1: Maintain a Diverse and Competent Board 


New Chart: "Board Diversity" (KPI: Board ethnic and gender diversity ).

How to create: This will likely require a new method in your GovernanceDataService that reads from a manually maintained configuration or a protected data source that tracks board demographics.


Objective #2: Increase Volunteer Engagement and Leadership Development 


New Chart: "Count of Active Titled Recurring Volunteers" (KPI ).

How to create: Create a new GovernanceDataService::getActiveVolunteers() method. This service will need to query for users with specific roles (e.g., "Facilitator," "Shop Tech") or CiviCRM relationships.


New Chart: "Active Committee Participation" (KPI ).

How to create: This requires a new GovernanceDataService::getCommitteeParticipation() method, likely querying CiviCRM groups or relationships to find non-staff members attending meetings.

2. Finance Section
(Current: FinanceSection.php is implemented but uses estimates.)

To-Do:


Enhance FinancialDataService: The strategic plan  and README mention Chargebee, Stripe, PayPal, and Xero. The service should be enhanced to (where possible) pull authoritative data from these sources rather than relying solely on profile field estimates.

Re-group existing charts under their corresponding objectives.


Objective #1: Increase Financial Resilience 


New Chart: "Operating Reserve" (KPI: Reserve funds ).

How to create: Add a new FinancialDataService::getReserveFunds() method. This will likely query your accounting system (like Xero) for the balance of specific accounts. It could be a simple "gauge" or "big number" metric showing "X months of OpEx."


Objective #2: Diversify and Grow Recurring & Renewing Revenue 

Move Charts: Move the existing "Monthly recurring revenue trend," "Payment mix," and "Chargebee plan distribution" charts here.


New Chart: "Earned Income vs. Core Costs" (KPI: Earned Income Sustaining Core ).

How to create: This is complex. It requires the enhanced FinancialDataService to pull all income and expenses, categorize them (earned revenue, fundraising revenue, core costs, program costs), and display the percentage.


New Chart: "Revenue by Business Line" (KPI: Revenue Positive Lines of Business ).

How to create: Requires the enhanced FinancialDataService to break down revenue and (if possible) expenses by lines like "Storage," "Desk Rental," "Classes," etc.

3. Infrastructure Section
(Current: InfrastructureSection.php is a placeholder.)

To-Do:

Implement InfrastructureSection.php.


Create a new SurveyDataService: The KPIs rely on "polls", which implies a survey or feedback system (e.g., Drupal webforms). This new service would read from that data.


Objective #1: Ensure Safe and Reliable Operations 

Move Charts: The "Utilization" charts currently in RetentionSection.php (Monthly member entries, Rolling average, Visit frequency, Weekday profile, First entry time) should be moved here. They are a direct measure of facility operation and usage.


New Chart: "Shop Budget Adherence" (KPI ).

How to create: This requires a new method in the enhanced FinancialDataService to pull budget vs. actual for shop-specific expense lines.


Objective #2: Enhance Member Experience and Accessibility 


New Charts: "Member Satisfaction (Equipment)" and "Member Satisfaction (Facility)" (KPIs ).

How to create: Use the new SurveyDataService to pull and aggregate responses from member satisfaction polls.

4. Outreach Section
(Current: OutreachSection.php is implemented but only shows one chart.)


Objective #1: Convert Visitors and Participants into Members 


New Chart: "Total First Time Workshop Participants" (KPI ).

How to create: This data is available. Use EventsMembershipDataService::getMonthlyRegistrationsByType(), sum the counts, and display them (e.g., as a monthly bar chart).


New Chart: "Total New Member Signups" (KPI ).

How to create: This data is available. Use MembershipMetricsService::getFlow() and chart the "incoming" data. This chart already exists in RetentionSection and could be duplicated here.


New Chart: "Total $ of New Recurring Revenue" (KPI ).

How to create: This requires enhancing FinancialDataService to isolate and sum revenue from new members each month.


Objective #2: Build Awareness and Attract New Audiences 

Move Chart: The existing "How members discovered us" chart fits perfectly here.

5. Retention Section
(Current: RetentionSection.php is well-developed but includes utilization charts that should be moved.)

To-Do:

Move Utilization Charts: As mentioned in #3 (Infrastructure), move all charts derived from UtilizationDataService (Monthly member entries, Rolling average, Frequency, etc.) to the InfrastructureSection.


Objective #1: Improve Member Retention 

Keep Charts: The existing "Monthly recruitment vs churn," "Net membership change," "Ending memberships by reason," "Cohort composition," and "Average annual retention" charts (all from MembershipMetricsService) belong here.


New Chart: "First Year Member Retention" (KPI ).

How to create: This can be a new metric derived from MembershipMetricsService::getAnnualCohorts(). You can show the retention percentage for the most recent complete-year cohort.


New Chart: "Active Participation Rate" (KPI: % Members with 1+ card read in previous quarter ).

How to create: This requires a new method in UtilizationDataService. It would be (Total Active Members - getVisitFrequencyBuckets()['no_visits']) / Total Active Members, ensuring the bucket window is set to the last quarter.


New Chart: "Total Active Members" (KPI ).

How to create: This is a simple metric. Get the count from UtilizationDataService::loadActiveMemberUids().


New Chart: "Annual Membership Revenue" (KPI ).

How to create: Requires the enhanced FinancialDataService to sum all membership-related revenue.


Objective #2: Strengthen the New Member Experience 


New Chart: "New Member Badge Attainment" (KPI: % with 3+ Badges 3 months after Signup ).

How to create: This requires a new method in EngagementDataService. It would use fetchBadgeEvents(), iterate through members who joined 3+ months ago, and count how many earned 3+ badges within their first 3 months.


Objective #3: Build Member Connections, Belonging and Inclusive Representation 


New Charts: "Membership Diversity (% BIPOC)" and "Membership Gender Diversity" (KPIs ).

How to create: These charts are a key part of the DeiSection. You can either duplicate them here or move them. See To-Do #9 (DEI) for implementation, which requires a new getEthnicityDistribution() method in DemographicsDataService.

6. Education Section
(Current: EducationSection.php is well-developed.)


Objective #1: Expand Participation and Strengthen Educational Systems 

Keep Charts: The existing "Event registrations by type" and "Average revenue per registration" charts fit here.


New Chart: "Program & Institutional Completions" (KPI ).

How to create: This requires enhancing EventsMembershipDataService to filter for specific "program" or "institutional" event types, rather than just all workshops.


Objective #2: Ensure Teaching Excellence and Inclusive Representation 


New Chart: "Participant Net Promoter Score (NPS)" (KPI ).

How to create: This requires the new SurveyDataService (from #3) to pull and calculate NPS from workshop evaluations.


New Chart: "Instructor Diversity (% BIPOC)" (KPI ).

How to create: This requires a new data source to track instructor demographics, likely queried by the new GovernanceDataService or an enhanced EventsMembershipDataService.


Objective #3: Build Learning Pathways from First Class to Mastery 

Keep Charts: The existing "Badge activation funnel," "Days to first badge," and "Badge awards by time since join" charts (from EngagementDataService) fit perfectly here.


Objective #4: Broaden Access and Partnerships in Education 


Keep Charts: The existing "Event-to-membership conversion" and "Average days from event to membership" charts fit here, as they directly address the KPI to "MEASURE CONVERSION TO MEMBERSHIP".


New Chart: "Workshop Participant Diversity (% BIPOC)" (KPI ).

How to create: This requires enhancing EventsMembershipDataService to join CiviCRM participant data with demographic data (either from CiviCRM or the Drupal profile via uf_match).

7. Entrepreneurship Section
(Current: EntrepreneurshipSection.php is a placeholder.)

To-Do:

Implement EntrepreneurshipSection.php.

Create a new EntrepreneurshipDataService. The plan mentions tracking milestones and occupancy.



Objective #1: Build and Sustain the Entrepreneurship Infrastructure 


New Chart: "Entrepreneurship Milestones" (KPI implied in ).


How to create: The new service needs to query the system (e.g., CiviCRM, a spreadsheet, or new entity type) where entrepreneurship participants and their milestones (e.g., "Prototype Complete," "Mentor Engaged") are tracked.


Objective #2: Operate a Thriving Incubation and Learning Environment 


New Chart: "Incubator Workspace Occupancy" (KPI implied in ).


How to create: The new service needs to query the source of truth for workspace rentals (e.g., Chargebee plans, profile fields) and display occupancy vs. capacity.

8. Development Section
(Current: DevelopmentSection.php is a placeholder.)

To-Do:

Implement DevelopmentSection.php.

Enhance FinancialDataService or create DevelopmentDataService to pull fundraising data (donations, grants) from your CRM (e.g., CiviCRM) or accounting system, distinguishing it from earned revenue.


Objective #1: Grow Annual Corporate Sponsorship 


New Chart: "Annual Corporate Sponsorships" (KPI ).

How to create: Use the new/enhanced service to sum all revenue tagged as "corporate sponsorship" by year or month.


Objective #2: Increase Individual Donor Participation 


New Chart: "Annual Individual Giving" (KPI ).

How to create: Use the new/enhanced service to sum all revenue tagged as "individual donation."


Objective #3: Cultivate Durable $1,000+ Major Donors 


New Chart: "Donor Retention Rate" (KPI ).

How to create: This requires the new service to query donation records, identify donors from "last year," and check if they also donated "this year."


Objective #4: Build a Strong, Consistent Grant Pipeline 


New Chart: "Non-Government Grants Secured" (KPI ).

How to create: Use the new/enhanced service to sum all revenue tagged as "grant."

9. DEI Section

(Current: DeiSection.php is implemented and the plan notes DEI is integrated. This section can serve as the central hub for all diversity-related KPIs.)


Objective #1: Diversify Participation in Education Programs 


New Chart: "Workshop Participant Diversity (% BIPOC)" (KPI ). (See Education To-Do #8).


New Chart: "Instructor Diversity (% BIPOC)" (KPI ). (See Education To-Do #5).


Objective #2: Maintain a Diverse and Competent Board 


New Chart: "Board Diversity" (KPI ). (See Governance To-Do #3).


Objective #3: Increase Physical and Digital Accessibility 

(No new charts; this objective is about facility/website audits, not quantitative KPIs in the same way.)


Objective #4: Increase the Diversity of MakeHaven’s Membership and Participants 

Keep Chart: The existing "Gender identity mix" chart (from DemographicsDataService::getGenderDistribution()) fits here.


New Chart: "Membership Diversity (% BIPOC)" (KPI: % Members BIPOC ).

How to create: This is a key missing piece. Create a new DemographicsDataService::getEthnicityDistribution() method, similar to getGenderDistribution(), that queries the field_member_ethnicity profile field and aggregates the results.


New Chart: "POC Member Retention" (KPI: Retention POC ).

How to create: This requires enhancing MembershipMetricsService::getAnnualCohorts() to accept demographic filters, allowing you to compare retention rates for different groups.


New Chart: "Community Diversity Sentiment" (KPI: % of members feel like the community is diverse ).

How to create: This requires the new SurveyDataService (from #3) to pull this specific question from a satisfaction survey.

Keep Charts: The "Members by town" and "Age distribution" charts can also live here as they provide important demographic context.
