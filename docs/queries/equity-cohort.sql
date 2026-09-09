-- Equity & Access Report — cohort table and derived metrics.
-- See ../equity-report.md. Run: lando mysql pantheon < equity-cohort.sql
--
-- Source rules enforced here:
--   join date  = profile.created            (NOT field_member_join_date, dead 2024-10-07)
--   ethnicity  = civicrm_value_demographics_15.ethnicity_46, \x01-delimited, multi-select
--   member     = user__roles 'member'
--   GEMS       = user_tags terms under parent tid 3319

DROP TEMPORARY TABLE IF EXISTS cohort;
CREATE TEMPORARY TABLE cohort (
  uid INT PRIMARY KEY,
  joined DATE,
  tenure_yrs DECIMAL(6,3),
  is_current TINYINT,
  is_staff TINYINT,
  gems TINYINT,
  answered TINYINT,
  white_only TINYINT,
  black TINYINT,
  hisp TINYINT,
  asian TINYINT,
  bipoc TINYINT,
  b90 INT,            -- badges earned in first 90 days
  badges INT,         -- lifetime active badges
  visit_days INT      -- distinct days badged in since 2025-01-01
);

INSERT INTO cohort
SELECT
  p.uid,
  DATE(FROM_UNIXTIME(p.created)),
  GREATEST(ROUND(DATEDIFF(CURDATE(), FROM_UNIXTIME(p.created))/365.25, 3), 0.05),
  EXISTS(SELECT 1 FROM user__roles r
         WHERE r.entity_id = p.uid AND r.roles_target_id = 'member'),
  EXISTS(SELECT 1 FROM user__roles r
         WHERE r.entity_id = p.uid
           AND r.roles_target_id IN ('instructor','facilitator','administrator','manager')),
  EXISTS(SELECT 1 FROM user__field_user_tags ut
         JOIN taxonomy_term__parent tp ON tp.entity_id = ut.field_user_tags_target_id
         WHERE ut.entity_id = p.uid AND tp.parent_target_id = 3319),
  (d.ethnicity_46 IS NOT NULL AND d.ethnicity_46 <> ''),
  (d.ethnicity_46 LIKE '%white%'
     AND NOT (d.ethnicity_46 REGEXP 'black|hispanic|asian|native|pacific|middleeast|other')),
  d.ethnicity_46 LIKE '%black%',
  d.ethnicity_46 LIKE '%hispanic%',
  d.ethnicity_46 LIKE '%asian%',
  (d.ethnicity_46 REGEXP 'black|hispanic|asian|native|pacific|middleeast|other'),
  (SELECT COUNT(*) FROM node__field_member_to_badge mb
     JOIN node_field_data nd ON nd.nid = mb.entity_id AND nd.type = 'badge_request'
     JOIN node__field_badge_status bs ON bs.entity_id = nd.nid
          AND bs.field_badge_status_value = 'active'
   WHERE mb.field_member_to_badge_target_id = p.uid
     AND nd.created BETWEEN p.created AND p.created + 7776000),
  (SELECT COUNT(*) FROM node__field_member_to_badge mb
     JOIN node_field_data nd ON nd.nid = mb.entity_id AND nd.type = 'badge_request'
     JOIN node__field_badge_status bs ON bs.entity_id = nd.nid
          AND bs.field_badge_status_value = 'active'
   WHERE mb.field_member_to_badge_target_id = p.uid),
  (SELECT COUNT(DISTINCT DATE(FROM_UNIXTIME(l.created)))
     FROM access_control_log__field_access_request_user u
     JOIN access_control_log_field_data l ON l.id = u.entity_id
          AND l.type = 'access_control_request'
   WHERE u.field_access_request_user_target_id = p.uid
     AND l.created >= UNIX_TIMESTAMP('2025-01-01'))
FROM profile p
LEFT JOIN civicrm_uf_match m ON m.uf_id = p.uid
LEFT JOIN civicrm_value_demographics_15 d ON d.entity_id = m.contact_id
WHERE p.type = 'main' AND p.status = 1 AND p.is_default = 1;

-- Coverage: prove the CiviCRM source is the better one before using it.
SELECT '=== 1. ethnicity coverage ===' AS section;
SELECT 'all profiles' AS grp, COUNT(*) n, ROUND(100*SUM(answered)/COUNT(*),1) pct_answered FROM cohort
UNION ALL SELECT 'current members', SUM(is_current),
  ROUND(100*SUM(is_current AND answered)/SUM(is_current),1) FROM cohort
UNION ALL SELECT 'joined 2025+', SUM(joined>='2025-01-01'),
  ROUND(100*SUM(joined>='2025-01-01' AND answered)/SUM(joined>='2025-01-01'),1) FROM cohort;

-- Retention and badge velocity. 2022-24 = mature cohorts.
SELECT '=== 2. retention + first-90 velocity, 2022-24 cohorts ===' AS section;
SELECT g, ppl, first90, pct_retained FROM (
  SELECT 'White only' g, COUNT(*) ppl, ROUND(AVG(b90),2) first90,
         ROUND(100*SUM(is_current)/COUNT(*),1) pct_retained, 1 srt
    FROM cohort WHERE joined>='2022-01-01' AND joined<'2025-01-01' AND white_only
  UNION ALL SELECT 'Asian', COUNT(*), ROUND(AVG(b90),2), ROUND(100*SUM(is_current)/COUNT(*),1), 2
    FROM cohort WHERE joined>='2022-01-01' AND joined<'2025-01-01' AND asian
  UNION ALL SELECT 'Black', COUNT(*), ROUND(AVG(b90),2), ROUND(100*SUM(is_current)/COUNT(*),1), 3
    FROM cohort WHERE joined>='2022-01-01' AND joined<'2025-01-01' AND black
  UNION ALL SELECT 'Hispanic', COUNT(*), ROUND(AVG(b90),2), ROUND(100*SUM(is_current)/COUNT(*),1), 4
    FROM cohort WHERE joined>='2022-01-01' AND joined<'2025-01-01' AND hisp
) t ORDER BY srt;

-- The mechanism: onboarding velocity predicts survival.
SELECT '=== 3. first-90 badges -> retention ===' AS section;
SELECT CASE WHEN b90=0 THEN '0' WHEN b90=1 THEN '1' WHEN b90<=4 THEN '2-4'
            WHEN b90<=9 THEN '5-9' ELSE '10+' END bucket,
       COUNT(*) ppl, ROUND(100*SUM(is_current)/COUNT(*),1) pct_retained
FROM cohort WHERE joined>='2022-01-01' AND joined<'2025-01-01'
GROUP BY 1 ORDER BY FIELD(bucket,'0','1','2-4','5-9','10+');

-- Residual gap: velocity explains part of the gap, not all of it. Report this.
SELECT '=== 4. retention at matched velocity ===' AS section;
SELECT CASE WHEN b90<=1 THEN 'low (0-1)' WHEN b90<=4 THEN 'mid (2-4)' ELSE 'high (5+)' END bucket,
  SUM(white_only) w_n,
  ROUND(100*SUM(white_only AND is_current)/NULLIF(SUM(white_only),0),1) w_ret,
  SUM(black OR hisp) bh_n,
  ROUND(100*SUM((black OR hisp) AND is_current)/NULLIF(SUM(black OR hisp),0),1) bh_ret
FROM cohort WHERE joined>='2022-01-01' AND joined<'2025-01-01' AND answered
GROUP BY 1 ORDER BY FIELD(bucket,'low (0-1)','mid (2-4)','high (5+)');

-- Utilisation. Staff MUST be excluded or they swamp the averages.
SELECT '=== 5. visit-days, current members, staff excluded ===' AS section;
SELECT g, ppl, avg_visit_days FROM (
  SELECT 'White only' g, COUNT(*) ppl, ROUND(AVG(visit_days),1) avg_visit_days, 1 srt
    FROM cohort WHERE is_current AND NOT is_staff AND white_only
  UNION ALL SELECT 'Black', COUNT(*), ROUND(AVG(visit_days),1), 2
    FROM cohort WHERE is_current AND NOT is_staff AND black
  UNION ALL SELECT 'Hispanic', COUNT(*), ROUND(AVG(visit_days),1), 3
    FROM cohort WHERE is_current AND NOT is_staff AND hisp
  UNION ALL SELECT 'Asian', COUNT(*), ROUND(AVG(visit_days),1), 4
    FROM cohort WHERE is_current AND NOT is_staff AND asian
) t ORDER BY srt;

-- GEMS. NOTE: participants self-select and are selected, so this is an
-- upper bound on the treatment effect, not a causal estimate. See equity-report.md.
SELECT '=== 6. GEMS vs non-GEMS, 2022-24 cohorts ===' AS section;
SELECT IF(gems,'GEMS','non-GEMS') grp, COUNT(*) ppl,
  ROUND(AVG(b90),2) first90, ROUND(AVG(badges),1) lifetime_badges,
  ROUND(100*SUM(b90>=5)/COUNT(*),1) pct_5plus_90d,
  ROUND(100*SUM(is_current)/COUNT(*),1) pct_retained
FROM cohort WHERE joined>='2022-01-01' AND joined<'2025-01-01' GROUP BY 1;

SELECT '=== 7. GEMS effect within BIPOC members, 2022-24 ===' AS section;
SELECT IF(gems,'GEMS','non-GEMS') grp, COUNT(*) ppl,
  ROUND(AVG(b90),2) first90, ROUND(100*SUM(is_current)/COUNT(*),1) pct_retained
FROM cohort WHERE joined>='2022-01-01' AND joined<'2025-01-01' AND bipoc GROUP BY 1;

-- Churn reasons, by group. Cost hits Black members hardest.
SELECT '=== 8. cost-driven churn ===' AS section;
SELECT g, exits, ROUND(100*cost/exits,1) pct_cost FROM (
  SELECT 'White only' g, COUNT(*) exits,
         SUM(er.field_member_end_reason_value='cost') cost, 1 srt
    FROM cohort c JOIN profile p ON p.uid=c.uid AND p.type='main' AND p.is_default=1
    JOIN profile__field_member_end_reason er ON er.entity_id=p.profile_id
   WHERE c.white_only
  UNION ALL SELECT 'Black', COUNT(*), SUM(er.field_member_end_reason_value='cost'), 2
    FROM cohort c JOIN profile p ON p.uid=c.uid AND p.type='main' AND p.is_default=1
    JOIN profile__field_member_end_reason er ON er.entity_id=p.profile_id
   WHERE c.black
  UNION ALL SELECT 'Hispanic', COUNT(*), SUM(er.field_member_end_reason_value='cost'), 3
    FROM cohort c JOIN profile p ON p.uid=c.uid AND p.type='main' AND p.is_default=1
    JOIN profile__field_member_end_reason er ON er.entity_id=p.profile_id
   WHERE c.hisp
) t ORDER BY srt;

-- Entrepreneurship: declared intent vs recorded support.
SELECT '=== 9. entrepreneurship service gap ===' AS section;
SELECT 'current members w/ commercial intent' k,
  (SELECT COUNT(DISTINCT p.uid) FROM profile p
     JOIN profile__field_member_goal g ON g.entity_id=p.profile_id
     JOIN user__roles r ON r.entity_id=p.uid AND r.roles_target_id='member'
    WHERE p.type='main' AND p.status=1 AND p.is_default=1
      AND g.field_member_goal_value IN ('seller','entrepreneur','inventor')) v
UNION ALL SELECT '... who own a business node',
  (SELECT COUNT(DISTINCT p.uid) FROM profile p
     JOIN profile__field_member_goal g ON g.entity_id=p.profile_id
     JOIN user__roles r ON r.entity_id=p.uid AND r.roles_target_id='member'
     JOIN node_field_data b ON b.uid=p.uid AND b.type='business'
    WHERE p.type='main' AND p.status=1 AND p.is_default=1
      AND g.field_member_goal_value IN ('seller','entrepreneur','inventor'))
UNION ALL SELECT 'ventures with a pipeline stage',
  (SELECT COUNT(*) FROM civicrm_value_entrepreneurs_19
    WHERE pipeline_stage_52 IS NOT NULL AND pipeline_stage_52 <> '')
UNION ALL SELECT 'entrepreneur needs recorded',
  (SELECT COUNT(*) FROM civicrm_value_entrepreneurs_18
    WHERE entrepreneur_needs_53 IS NOT NULL AND entrepreneur_needs_53 <> '')
UNION ALL SELECT 'consultation activities',
  (SELECT COUNT(*) FROM civicrm_value_entrepreneurs_1)
UNION ALL SELECT 'inventor_pipeline_state rows',
  (SELECT COUNT(*) FROM profile__field_inventor_pipeline_state);
