-- Member-survey outcomes joined to the badge ledger.
-- The survey is the only source of self-reported entrepreneurship outcomes until
-- civicrm_value_entrepreneurs_19.pipeline_stage_52 is populated. See ../equity-report.md.
--
-- Response bias: respondents are ~2x as engaged as the membership. Report these as
-- "share of respondents", never "share of members". The badge gradient is the robust part.

DROP TEMPORARY TABLE IF EXISTS survey;
CREATE TEMPORARY TABLE survey (
  sid INT PRIMARY KEY, uid INT,
  art VARCHAR(16), prototype VARCHAR(16), entrepreneurial VARCHAR(16), income VARCHAR(16),
  nps INT, badges INT
);
INSERT INTO survey
SELECT s.sid, s.uid,
  MAX(IF(d.name='art', d.value, NULL)),
  MAX(IF(d.name='prototype', d.value, NULL)),
  MAX(IF(d.name='entrepreneurial', d.value, NULL)),
  MAX(IF(d.name='income', d.value, NULL)),
  MAX(IF(d.name='net_prompt' AND d.value REGEXP '^[0-9]+$', CAST(d.value AS UNSIGNED), NULL)),
  (SELECT COUNT(*) FROM node__field_member_to_badge mb
     JOIN node_field_data nd ON nd.nid = mb.entity_id AND nd.type = 'badge_request'
     JOIN node__field_badge_status bs ON bs.entity_id = nd.nid
          AND bs.field_badge_status_value = 'active'
   WHERE mb.field_member_to_badge_target_id = s.uid)
FROM webform_submission s
JOIN webform_submission_data d ON d.sid = s.sid
WHERE s.webform_id = '2025_member_survey'
GROUP BY 1, 2;

SELECT '=== 1. response counts and attribution ===' AS section;
SELECT COUNT(*) responses, SUM(uid > 0) identified,
       ROUND(AVG(IF(uid > 0, badges, NULL)), 1) avg_badges_respondents
FROM survey;

SELECT '=== 2. outcome rates (share of respondents, NOT of members) ===' AS section;
SELECT 'made art' outcome, SUM(art='yes') yes_, SUM(art='no') no_,
       ROUND(100*SUM(art='yes')/NULLIF(SUM(art IN ('yes','no')),0),1) pct FROM survey
UNION ALL SELECT 'entrepreneurial', SUM(entrepreneurial='yes'), SUM(entrepreneurial='no'),
       ROUND(100*SUM(entrepreneurial='yes')/NULLIF(SUM(entrepreneurial IN ('yes','no')),0),1) FROM survey
UNION ALL SELECT 'prototyped', SUM(prototype='yes'), SUM(prototype='no'),
       ROUND(100*SUM(prototype='yes')/NULLIF(SUM(prototype IN ('yes','no')),0),1) FROM survey
UNION ALL SELECT 'generated income', SUM(income='yes'), SUM(income='no'),
       ROUND(100*SUM(income='yes')/NULLIF(SUM(income IN ('yes','no')),0),1) FROM survey;

-- The headline: badges predict income, but NOT entrepreneurial self-identification.
SELECT '=== 3. outcome rate by badge bucket ===' AS section;
SELECT CASE WHEN badges<=4 THEN '0-4' WHEN badges<=9 THEN '5-9'
            WHEN badges<=19 THEN '10-19' ELSE '20+' END bucket,
  COUNT(*) n,
  ROUND(100*SUM(income='yes')/COUNT(*),1) pct_income,
  ROUND(100*SUM(prototype='yes')/COUNT(*),1) pct_prototype,
  ROUND(100*SUM(art='yes')/COUNT(*),1) pct_art,
  ROUND(100*SUM(entrepreneurial='yes')/COUNT(*),1) pct_entrepreneurial
FROM survey WHERE uid > 0
GROUP BY 1 ORDER BY FIELD(bucket,'0-4','5-9','10-19','20+');

SELECT '=== 4. mean badges by reported outcome ===' AS section;
SELECT 'income: yes' g, COUNT(*) n, ROUND(AVG(badges),1) avg_badges FROM survey WHERE uid>0 AND income='yes'
UNION ALL SELECT 'income: no', COUNT(*), ROUND(AVG(badges),1) FROM survey WHERE uid>0 AND income='no'
UNION ALL SELECT 'entrepreneurial: yes', COUNT(*), ROUND(AVG(badges),1) FROM survey WHERE uid>0 AND entrepreneurial='yes'
UNION ALL SELECT 'entrepreneurial: no', COUNT(*), ROUND(AVG(badges),1) FROM survey WHERE uid>0 AND entrepreneurial='no';

-- NPS is at ceiling and flat. Do not claim a satisfaction/engagement gradient.
SELECT '=== 5. NPS by badge bucket (expect flat) ===' AS section;
SELECT CASE WHEN badges<=4 THEN '0-4' WHEN badges<=9 THEN '5-9'
            WHEN badges<=19 THEN '10-19' ELSE '20+' END bucket,
  COUNT(*) n, ROUND(AVG(nps),2) avg_nps, ROUND(100*SUM(nps>=9)/COUNT(*),1) pct_promoter
FROM survey WHERE uid > 0 AND nps IS NOT NULL
GROUP BY 1 ORDER BY FIELD(bucket,'0-4','5-9','10-19','20+');

SELECT '=== 6. org-wide NPS ===' AS section;
SELECT ROUND(100*(SUM(nps>=9) - SUM(nps<=6))/COUNT(*)) nps_score,
       SUM(nps>=9) promoters, SUM(nps BETWEEN 7 AND 8) passives, SUM(nps<=6) detractors, COUNT(*) n
FROM survey WHERE nps IS NOT NULL;

-- Art/commercial overlap, corroborating the profile-goal figure from an independent instrument.
SELECT '=== 7. art x commercial overlap ===' AS section;
SELECT SUM(art='yes') artists,
       SUM(art='yes' AND (entrepreneurial='yes' OR prototype='yes' OR income='yes')) both_,
       SUM(entrepreneurial='yes' OR prototype='yes' OR income='yes') commercial,
       COUNT(*) respondents
FROM survey;

-- Qualitative: what members say they need, and how MakeHaven produced income.
SELECT '=== 8. verbatim needs and income narratives ===' AS section;
SELECT d.name AS question, REPLACE(REPLACE(d.value, '\n', ' '), '\r', ' ') AS response
FROM webform_submission_data d
WHERE d.webform_id = '2025_member_survey'
  AND d.name IN ('what_supports_do_you_need_to_be_successful_in_your_entrepreneuri',
                 'how_did_makehaven_help_you_generate_income',
                 'please_tell_us_about_your_prototype')
  AND CHAR_LENGTH(TRIM(d.value)) > 15;
