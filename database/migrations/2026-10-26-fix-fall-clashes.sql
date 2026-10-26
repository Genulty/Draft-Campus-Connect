-- Campus Connect: fix the 3 Fall 2026 student time clashes left by 2026-10-26-fall-schedule.sql.
-- Run AFTER that migration. Safe on the live database: each UPDATE only changes the section if it
-- is still at the time slot it had after that migration, so running this twice changes nothing.
--
--   Student 100057:           MATH110-001 (CRN 10451) and MATH115-001 (CRN 10452) both met MW 8:00-9:40
--   Students 100010, 100124:  MATH110-002 (CRN 10461) and BUS220-002 (CRN 10499) both met MW 9:50-11:30
--
-- Both moved sections stay on Monday/Wednesday (so their attendance dates still match) and go to
-- slot 24, MW 2:40-4:20 PM, which is free for every enrolled student, the teacher and the room.

USE `campus_connect`;
START TRANSACTION;

UPDATE Course_Section SET time_Slot_ID = 24 WHERE CRN = 10452 AND semester_ID = 'FA26' AND time_Slot_ID = 21;  -- MATH115-001
UPDATE Course_Section SET time_Slot_ID = 24 WHERE CRN = 10499 AND semester_ID = 'FA26' AND time_Slot_ID = 22;  -- BUS220-002

-- Expected: both rows show time_Slot_ID 24.
SELECT CRN, course_ID, section_No, time_Slot_ID FROM Course_Section WHERE CRN IN (10452, 10499);

COMMIT;
