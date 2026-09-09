-- Exports geocoded member points for neighborhood-rates.py.
-- Run: lando mysql pantheon < member-geography.sql > pts.tsv
SELECT p.uid, a.geo_code_1, a.geo_code_2,
  EXISTS(SELECT 1 FROM user__roles r
         WHERE r.entity_id = p.uid AND r.roles_target_id = 'member') AS is_current,
  YEAR(FROM_UNIXTIME(p.created)) AS yr
FROM profile p
JOIN civicrm_uf_match m ON m.uf_id = p.uid
JOIN civicrm_address a ON a.contact_id = m.contact_id AND a.is_primary = 1
WHERE p.type = 'main' AND p.status = 1 AND p.is_default = 1
  AND a.geo_code_1 IS NOT NULL AND a.geo_code_2 IS NOT NULL
  AND a.geo_code_1 BETWEEN 41.20 AND 41.40
  AND a.geo_code_2 BETWEEN -73.05 AND -72.80;
