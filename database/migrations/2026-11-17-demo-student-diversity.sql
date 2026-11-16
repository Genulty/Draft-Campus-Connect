-- Campus Connect: the demo student (Julio Larrea, 100001) completes the Degree Audit's Diversity requirement.
-- Every Diversity course has a prerequisite, so he takes both steps in past terms:
--   Spring 2025  PSY110 Introduction to Psychology  existing CRN 10181 (had a free seat), grade B+
--                (also meets his General Education Social Science requirement)
--   Spring 2026  PSY225 Social Psychology           new CRN 10945, section 003, grade A-
-- Both existing Spring 2026 PSY225 sections were full, so CRN 10945 is a new section: Valentina Cohen (200042,
-- Psychology, no other Spring 2026 classes), Mon/Wed 1:00-2:20 PM (slot 4) in ACB-101, with four classmates who
-- passed PSY110 earlier: 100050, 100124, 100142 and 100158.
-- Every rule still holds: prerequisites, credit limits (Julio has 16 credits in Spring 2025 and 15 in Spring 2026),
-- no time clashes, a free room, faculty in their department and within load, and at least 5 students.
-- Past classes keep their grades in Enrollment and Student_History and their teacher in Faculty_History.
-- credits_Earned goes up by the credits passed: 100001 by 6 (50 to 56), the four classmates by 3.
-- Run after 2026-11-17-degree-audit-rules.sql. Safe to run more than once.

USE `campus_connect`;
START TRANSACTION;

SET @hhu_first_run = NOT EXISTS (SELECT 1 FROM Course_Section WHERE CRN = 10945);

-- New Spring 2026 section of PSY225 (open seats are set by the database triggers)
INSERT INTO Course_Section (CRN, course_ID, section_No, faculty_ID, time_Slot_ID, room_ID, semester_ID, max_Seats)
SELECT 10945, 'PSY225', '003', 200042, 4, 'ACB-101', 'SP26', 10 FROM DUAL WHERE @hhu_first_run;
INSERT INTO Faculty_History (faculty_ID, CRN, course_ID, semester_ID)
SELECT 200042, 10945, 'PSY225', 'SP26' FROM DUAL WHERE @hhu_first_run;

-- Grades: Julio in PSY110 (Spring 2025) and everyone in the new PSY225 section
INSERT INTO Enrollment (student_ID, CRN, grade)
SELECT v.student_ID, v.CRN, v.grade FROM (
          SELECT 100001 AS student_ID, 10181 AS CRN, 'B+' AS grade
UNION ALL SELECT 100001, 10945, 'A-'
UNION ALL SELECT 100050, 10945, 'B+'
UNION ALL SELECT 100124, 10945, 'B'
UNION ALL SELECT 100142, 10945, 'A-'
UNION ALL SELECT 100158, 10945, 'B-') v
WHERE @hhu_first_run;
INSERT INTO Student_History (student_ID, CRN, course_ID, semester_ID, grade)
SELECT e.student_ID, e.CRN, cs.course_ID, cs.semester_ID, e.grade FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN
WHERE @hhu_first_run AND ((e.student_ID = 100001 AND e.CRN = 10181) OR e.CRN = 10945);

-- Earned credits
UPDATE Full_Time_Undergraduate SET credits_Earned = credits_Earned + 6 WHERE @hhu_first_run AND student_ID = 100001;
UPDATE Full_Time_Undergraduate SET credits_Earned = credits_Earned + 3 WHERE @hhu_first_run AND student_ID IN (100050, 100124, 100142, 100158);

COMMIT;

-- Expected: PSY110 (SP25, B+) and PSY225 (SP26, A-), and 56 credits earned
SELECT h.semester_ID, h.course_ID, h.grade FROM Student_History h WHERE h.student_ID = 100001 AND h.course_ID IN ('PSY110', 'PSY225');
SELECT credits_Earned FROM Full_Time_Undergraduate WHERE student_ID = 100001;
