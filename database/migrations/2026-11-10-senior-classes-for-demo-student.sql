-- Campus Connect: give the demo student (Julio Larrea, 100001, a Senior CS major) senior-level Fall 2026 classes.
-- He keeps CS455 and swaps his three freshman intro classes for the most advanced classes he qualifies for:
--   MATH110 Calculus I            (CRN 10451)  ->  BUS340 Business Law     (CRN 10493, MW 6:00 PM, 300-level)
--   PSY110  Intro to Psychology   (CRN 10515)  ->  BIO220 Cell Biology     (CRN 10481, TR 8:00 AM + Friday lab)
--   ENG115  Intro to Literature   (CRN 10534)  ->  ENG225 British Literature (CRN 10536, MW 4:00 PM)
-- He has passed every prerequisite, the times do not clash, and the total is 13 credits.
-- Attendance for the new classes uses each section's own class dates (marked Present).
-- Safe to run more than once. It changes nothing unless all three new sections have a seat for him
-- (or he is already in them). Open seats are kept up to date by the database triggers.

USE `campus_connect`;
START TRANSACTION;

SET @ok = (SELECT COUNT(*) FROM Course_Section cs
  WHERE cs.CRN IN (10493, 10481, 10536) AND cs.semester_ID = 'FA26'
    AND (cs.available_Seats > 0 OR EXISTS (SELECT 1 FROM Enrollment e WHERE e.student_ID = 100001 AND e.CRN = cs.CRN))) = 3;

DELETE FROM Attendance WHERE @ok AND student_ID = 100001 AND CRN IN (10451, 10515, 10534);
DELETE FROM Enrollment WHERE @ok AND student_ID = 100001 AND CRN IN (10451, 10515, 10534);

-- (The CRNs are listed directly: the seat trigger updates Course_Section, so this statement can't read it.)
INSERT INTO Enrollment (student_ID, CRN, grade)
SELECT 100001, v.CRN, NULL FROM (SELECT 10493 AS CRN UNION ALL SELECT 10481 UNION ALL SELECT 10536) v
WHERE @ok AND NOT EXISTS (SELECT 1 FROM Enrollment e WHERE e.student_ID = 100001 AND e.CRN = v.CRN);

INSERT INTO Attendance (student_ID, CRN, attendance_Date, attendance_Status)
SELECT DISTINCT 100001, a.CRN, a.attendance_Date, 'Present' FROM Attendance a
WHERE @ok AND a.CRN IN (10493, 10481, 10536) AND a.student_ID <> 100001
  AND NOT EXISTS (SELECT 1 FROM Attendance x WHERE x.student_ID = 100001 AND x.CRN = a.CRN AND x.attendance_Date = a.attendance_Date);

-- Expected: CS455, BUS340, BIO220 and ENG225 (13 credits). If it still lists MATH110, PSY110 and ENG115,
-- a new section had no free seat and nothing was changed.
SELECT cs.CRN, cs.course_ID, c.course_Name, c.course_Credits FROM Enrollment e
JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Course c ON c.course_ID = cs.course_ID
WHERE e.student_ID = 100001 AND cs.semester_ID = 'FA26' ORDER BY cs.course_ID;

COMMIT;
