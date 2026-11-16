-- Campus Connect: the demo student (Julio Larrea, 100001) completes Mathematics Proficiency with MATH110 Calculus I.
-- Every past MATH110 section he could have joined was full, clashed with his classes or put him over 16 credits,
-- so this adds a Fall 2024 section:
--   CRN 10946, MATH110 section 003, Elena Mensah (200011, Mathematics, no other Fall 2024 classes),
--   Tue/Thu 1:00-2:20 PM (slot 11) in ACB-103.
--   Julio earns a B+ (the requirement needs a C or better). Four classmates who had not taken MATH110:
--   100031, 100040, 100088 and 100108.
-- MATH110 also counts for General Education Mathematics.
-- Every rule still holds: no prerequisites, credit limits (Julio has 15 credits in Fall 2024), no time clashes,
-- a free room, faculty in their department and within load, and at least 5 students.
-- Past classes keep their grades in Enrollment and Student_History and their teacher in Faculty_History.
-- credits_Earned goes up by 3 for all five students (Julio 56 to 59).
-- Run after 2026-11-17-demo-student-diversity.sql. Safe to run more than once.

USE `campus_connect`;
START TRANSACTION;

SET @hhu_first_run = NOT EXISTS (SELECT 1 FROM Course_Section WHERE CRN = 10946);

-- New Fall 2024 section of MATH110 (open seats are set by the database triggers)
INSERT INTO Course_Section (CRN, course_ID, section_No, faculty_ID, time_Slot_ID, room_ID, semester_ID, max_Seats)
SELECT 10946, 'MATH110', '003', 200011, 11, 'ACB-103', 'FA24', 10 FROM DUAL WHERE @hhu_first_run;
INSERT INTO Faculty_History (faculty_ID, CRN, course_ID, semester_ID)
SELECT 200011, 10946, 'MATH110', 'FA24' FROM DUAL WHERE @hhu_first_run;

-- Grades
INSERT INTO Enrollment (student_ID, CRN, grade)
SELECT v.student_ID, 10946, v.grade FROM (
          SELECT 100001 AS student_ID, 'B+' AS grade
UNION ALL SELECT 100031, 'B'
UNION ALL SELECT 100040, 'A-'
UNION ALL SELECT 100088, 'C+'
UNION ALL SELECT 100108, 'B') v
WHERE @hhu_first_run;
INSERT INTO Student_History (student_ID, CRN, course_ID, semester_ID, grade)
SELECT e.student_ID, e.CRN, 'MATH110', 'FA24', e.grade FROM Enrollment e WHERE @hhu_first_run AND e.CRN = 10946;

-- Earned credits
UPDATE Full_Time_Undergraduate SET credits_Earned = credits_Earned + 3
WHERE @hhu_first_run AND student_ID IN (100001, 100031, 100040, 100088, 100108);

COMMIT;

-- Expected: MATH110 (FA24, B+) and 59 credits earned
SELECT h.semester_ID, h.course_ID, h.grade FROM Student_History h WHERE h.student_ID = 100001 AND h.course_ID = 'MATH110';
SELECT credits_Earned FROM Full_Time_Undergraduate WHERE student_ID = 100001;
