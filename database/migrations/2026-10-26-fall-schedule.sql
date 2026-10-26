-- Campus Connect: FA26 seven-period/100-minute schedule migration
-- Source: live phpMyAdmin screenshots, October 26, 2026.
-- Apply ONLY to a separate database restored from the pre-change schema/data.
-- DO NOT run this on the live production database; its schedule is already updated.
-- Review with the repository owner before merging/deploying.
-- Existing records are retained. This script does not drop tables or delete rows.
-- REQUIREMENTS: Existing database schema and FA26 course sections, foreign keys and day IDs M,W,T,R.
-- WARNING: Inserts use NOT EXISTS; if an ID already exists with different values, stop and investigate.

USE `campus_connect`;

-- Guard queries (run/read before migration if applying to a different environment):
-- SELECT * FROM Period WHERE period_ID BETWEEN 11 AND 17;
-- SELECT * FROM Time_Slot WHERE time_Slot_ID BETWEEN 21 AND 34;
-- SELECT COUNT(*) FROM Course_Section WHERE semester_ID = 'FA26';

START TRANSACTION;

-- Seven periods. For period 17, the live scheduling results showed 20:10-21:50.
INSERT INTO Period (period_ID, start_Time, end_Time)
SELECT v.id, v.st, v.et FROM (
  SELECT 11 id, '08:00:00' st, '09:40:00' et UNION ALL
  SELECT 12, '09:50:00', '11:30:00' UNION ALL
  SELECT 13, '11:40:00', '13:20:00' UNION ALL
  SELECT 14, '14:40:00', '16:20:00' UNION ALL
  SELECT 15, '16:30:00', '18:10:00' UNION ALL
  SELECT 16, '18:20:00', '20:00:00' UNION ALL
  SELECT 17, '20:10:00', '21:50:00'
) v WHERE NOT EXISTS (SELECT 1 FROM Period p WHERE p.period_ID = v.id);

-- Time slot rows 21-34 (two meeting patterns, seven 100-minute periods)
INSERT INTO Time_Slot (time_Slot_ID)
SELECT v.id FROM (
  SELECT 21 id UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24
  UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28
  UNION ALL SELECT 29 UNION ALL SELECT 30 UNION ALL SELECT 31 UNION ALL SELECT 32
  UNION ALL SELECT 33 UNION ALL SELECT 34
) v WHERE NOT EXISTS (SELECT 1 FROM Time_Slot t WHERE t.time_Slot_ID = v.id);

-- Link slots to the two meeting days; 21-26,33 = MW; 27-32,34 = TR.
INSERT INTO Time_Slot_Day (time_Slot_ID, day_ID)
SELECT ts.time_Slot_ID, d.day_ID
FROM Time_Slot ts
JOIN Day d ON (
 (ts.time_Slot_ID IN (21,22,23,24,25,26,33) AND d.day_ID IN ('M','W')) OR
 (ts.time_Slot_ID IN (27,28,29,30,31,32,34) AND d.day_ID IN ('T','R'))
)
WHERE ts.time_Slot_ID BETWEEN 21 AND 34
AND NOT EXISTS (SELECT 1 FROM Time_Slot_Day x WHERE x.time_Slot_ID=ts.time_Slot_ID AND x.day_ID=d.day_ID);

-- Link time slots to the correct period ID.
INSERT INTO Time_Slot_Period (time_Slot_ID, period_ID)
SELECT ts.time_Slot_ID,
  CASE
    WHEN ts.time_Slot_ID BETWEEN 21 AND 26 THEN ts.time_Slot_ID - 10
    WHEN ts.time_Slot_ID BETWEEN 27 AND 32 THEN ts.time_Slot_ID - 16
    ELSE 17
  END
FROM Time_Slot ts
WHERE ts.time_Slot_ID BETWEEN 21 AND 34
AND NOT EXISTS (SELECT 1 FROM Time_Slot_Period x WHERE x.time_Slot_ID=ts.time_Slot_ID);

-- The next mapping is the COMPLETE set of 68 CRN-to-slot assignments.
-- Update ONLY matching FA26 course sections. No other semester is modified.
CREATE TEMPORARY TABLE fa26_schedule_mapping (CRN INT PRIMARY KEY, target_slot INT NOT NULL);
INSERT INTO fa26_schedule_mapping (CRN, target_slot) VALUES
(10433, 25),
(10434, 27),
(10436, 21),
(10437, 22),
(10438, 26),
(10439, 23),
(10442, 30),
(10443, 32),
(10444, 25),
(10446, 32),
(10447, 27),
(10451, 21),
(10452, 21),
(10454, 28),
(10456, 28),
(10457, 32),
(10458, 23),
(10459, 32),
(10460, 28),
(10461, 22),
(10462, 29),
(10463, 23),
(10465, 28),
(10469, 29),
(10470, 30),
(10471, 30),
(10473, 30),
(10475, 21),
(10476, 27),
(10479, 28),
(10480, 22),
(10481, 27),
(10483, 24),
(10487, 23),
(10488, 33),
(10489, 31),
(10490, 27),
(10493, 26),
(10494, 29),
(10497, 31),
(10498, 23),
(10499, 22),
(10500, 29),
(10501, 25),
(10504, 24),
(10505, 31),
(10506, 30),
(10507, 23),
(10508, 31),
(10509, 32),
(10510, 24),
(10511, 31),
(10513, 26),
(10515, 27),
(10516, 28),
(10517, 23),
(10518, 34),
(10523, 31),
(10524, 26),
(10525, 33),
(10526, 28),
(10528, 34),
(10529, 21),
(10533, 31),
(10534, 28),
(10535, 27),
(10536, 25),
(10537, 22);

-- SAFETY CHECK before running the UPDATE in a different environment:
-- SELECT m.CRN FROM fa26_schedule_mapping m LEFT JOIN Course_Section cs
--   ON cs.CRN=m.CRN AND cs.semester_ID='FA26' WHERE cs.CRN IS NULL;
-- Expected 0 rows. Also verify the destination schema has 68 FA26 sections.

UPDATE Course_Section cs
JOIN fa26_schedule_mapping m ON cs.CRN = m.CRN
SET cs.time_Slot_ID = m.target_slot
WHERE cs.semester_ID = 'FA26' AND NOT (cs.time_Slot_ID <=> m.target_slot);

-- Validation (expected 68 sections / 68 matched / 0 unmatched):
SELECT COUNT(*) AS mapped_sections,
       SUM(cs.time_Slot_ID = m.target_slot) AS matching_sections,
       SUM(NOT (cs.time_Slot_ID <=> m.target_slot)) AS mismatches
FROM fa26_schedule_mapping m
LEFT JOIN Course_Section cs ON cs.CRN=m.CRN AND cs.semester_ID='FA26';

-- IMPORTANT: Validate room, faculty, student AND Friday lab conflicts before production use.
-- The lecture schedule matching does not prove labs are conflict-free.
COMMIT;
