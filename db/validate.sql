-- Checks that the loaded data follows the System Manual's rules. Every row should show 0 violations.
--   mysql campus_connect < db/validate.sql
USE campus_connect;
SET @today = '2026-10-06';
-- Every weekly meeting of every section (its lecture, plus its lab when it has one) with its day and times.
-- Two meetings clash when they share a day and their times overlap: a 150-minute lab period overlaps
-- several lecture periods, so comparing period IDs is not enough.
WITH meeting AS (
  SELECT cs.CRN, cs.semester_ID, cs.faculty_ID, cs.room_ID AS room, cs.time_Slot_ID, 'Lecture' AS kind FROM Course_Section cs
  UNION ALL
  SELECT cs.CRN, cs.semester_ID, cs.faculty_ID, l.lab_ID, l.time_Slot_ID, 'Lab' FROM Course_Section cs JOIN Course_Section_Lab l ON l.CRN = cs.CRN
), mt AS (
  SELECT m.*, d.day_ID, p.start_Time, p.end_Time FROM meeting m
  JOIN Time_Slot_Day d ON d.time_Slot_ID = m.time_Slot_ID
  JOIN Time_Slot_Period tp ON tp.time_Slot_ID = m.time_Slot_ID JOIN Period p ON p.period_ID = tp.period_ID
), clash AS (
  SELECT a.CRN AS crn_a, b.CRN AS crn_b, a.faculty_ID AS fac_a, b.faculty_ID AS fac_b, a.room AS room_a, b.room AS room_b,
         a.kind AS kind_a, b.kind AS kind_b
  FROM mt a JOIN mt b ON b.semester_ID = a.semester_ID AND b.day_ID = a.day_ID
    AND a.start_Time < b.end_Time AND b.start_Time < a.end_Time AND (a.CRN < b.CRN OR (a.CRN = b.CRN AND a.kind < b.kind))
)
SELECT 'A-F5 faculty teaches two sections at the same time' AS rule_checked, COUNT(DISTINCT crn_a, crn_b) AS violations
  FROM clash WHERE crn_a <> crn_b AND fac_a = fac_b
UNION ALL SELECT 'A-F6 two sections in one room at the same time', COUNT(DISTINCT crn_a, crn_b)
  FROM clash WHERE crn_a <> crn_b AND room_a = room_b
UNION ALL SELECT 'A-F7 full-time faculty teaching more than 2 sections', COUNT(*)
  FROM (SELECT cs.faculty_ID FROM Course_Section cs JOIN Faculty f ON f.faculty_ID = cs.faculty_ID
        WHERE f.faculty_Type = 'Full-time' GROUP BY cs.faculty_ID, cs.semester_ID HAVING COUNT(*) > 2) x
UNION ALL SELECT 'F-R7 part-time faculty not teaching exactly 1 section', COUNT(*)
  FROM Faculty f CROSS JOIN Semester s WHERE f.faculty_Type = 'Part-time'
   AND (SELECT COUNT(*) FROM Course_Section cs WHERE cs.faculty_ID = f.faculty_ID AND cs.semester_ID = s.semester_ID) <> 1
UNION ALL SELECT 'A-F4 faculty teaching outside their departments', COUNT(*)
  FROM Course_Section cs JOIN Course c ON c.course_ID = cs.course_ID
  WHERE NOT EXISTS (SELECT 1 FROM Faculty_Department fd WHERE fd.faculty_ID = cs.faculty_ID AND fd.dept_ID = c.dept_ID)
UNION ALL SELECT 'F-F6/F-F7 too many departments', COUNT(*)
  FROM (SELECT fd.faculty_ID FROM Faculty_Department fd JOIN Faculty f ON f.faculty_ID = fd.faculty_ID
        GROUP BY fd.faculty_ID, f.faculty_Type HAVING COUNT(*) > IF(f.faculty_Type = 'Full-time', 3, 1)) x
UNION ALL SELECT 'A-F9 section with more than 10 students', COUNT(*)
  FROM (SELECT CRN FROM Enrollment GROUP BY CRN HAVING COUNT(*) > 10) x
UNION ALL SELECT 'A-R46 started section with fewer than 5 students', COUNT(*)
  FROM Course_Section cs JOIN Semester s ON s.semester_ID = cs.semester_ID
  WHERE s.start_Date <= @today AND (SELECT COUNT(*) FROM Enrollment e WHERE e.CRN = cs.CRN) < 5
UNION ALL SELECT 'S-F11 student in two sections at the same time', COUNT(DISTINCT e1.student_ID, c.crn_a, c.crn_b)
  FROM clash c JOIN Enrollment e1 ON e1.CRN = c.crn_a JOIN Enrollment e2 ON e2.CRN = c.crn_b AND e2.student_ID = e1.student_ID
  WHERE c.crn_a <> c.crn_b
UNION ALL SELECT 'S-F5/S-F6 undergraduate/graduate course mismatch', COUNT(*)
  FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Course c ON c.course_ID = cs.course_ID
  JOIN Student s ON s.student_ID = e.student_ID WHERE c.course_Type <> s.student_Type
UNION ALL SELECT 'S-F7/S-F8 over the credit limit', COUNT(*)
  FROM (SELECT e.student_ID, cs.semester_ID, SUM(c.course_Credits) AS credits,
               COALESCE(ft.max_Credits, pt.max_Credits, IF(g.graduate_Student_Type = 'Full-time', 16, 8)) AS max_credits
        FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Course c ON c.course_ID = cs.course_ID
        LEFT JOIN Full_Time_Undergraduate ft ON ft.student_ID = e.student_ID
        LEFT JOIN Part_Time_Undergraduate pt ON pt.student_ID = e.student_ID
        LEFT JOIN Graduate_Student g ON g.student_ID = e.student_ID
        GROUP BY e.student_ID, cs.semester_ID, max_credits HAVING credits > max_credits) x
UNION ALL SELECT 'S-F4 prerequisite not met', COUNT(*)
  FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Semester s2 ON s2.semester_ID = cs.semester_ID
  JOIN Course_Prerequisite cp ON cp.course_ID = cs.course_ID
  WHERE NOT EXISTS (
    SELECT 1 FROM Student_History h JOIN Course_Section hc ON hc.CRN = h.CRN JOIN Semester s1 ON s1.semester_ID = h.semester_ID
    WHERE h.student_ID = e.student_ID AND hc.course_ID = cp.prerequisite_Course_ID AND s1.start_Date < s2.start_Date
      AND FIELD(h.grade,'F','D','D+','C-','C','C+','B-','B','B+','A-','A') >= FIELD(cp.min_Grade_Req,'F','D','D+','C-','C','C+','B-','B','B+','A-','A'))
UNION ALL SELECT 'S-F10 retaking a passed course', COUNT(*)
  FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Semester s2 ON s2.semester_ID = cs.semester_ID
  WHERE EXISTS (
    SELECT 1 FROM Student_History h JOIN Course_Section hc ON hc.CRN = h.CRN JOIN Semester s1 ON s1.semester_ID = h.semester_ID
    WHERE h.student_ID = e.student_ID AND hc.course_ID = cs.course_ID AND s1.start_Date < s2.start_Date AND h.grade <> 'F')
UNION ALL SELECT 'S-F3 registered while a hold was active', COUNT(*)
  FROM Student_Hold sh JOIN Enrollment e ON e.student_ID = sh.student_ID JOIN Course_Section cs ON cs.CRN = e.CRN
  JOIN Semester s ON s.semester_ID = cs.semester_ID WHERE sh.hold_Date <= s.add_End
UNION ALL SELECT 'F-F9 full-time faculty with more than 15 advisees', COUNT(*)
  FROM (SELECT faculty_ID FROM Advisor GROUP BY faculty_ID HAVING COUNT(*) > 15) x
UNION ALL SELECT 'F-F10 part-time faculty advising', COUNT(*)
  FROM Advisor a JOIN Faculty f ON f.faculty_ID = a.faculty_ID WHERE f.faculty_Type = 'Part-time'
UNION ALL SELECT 'A-F12 student with more than 2 advisors', COUNT(*)
  FROM (SELECT student_ID FROM Advisor GROUP BY student_ID HAVING COUNT(*) > 2) x
UNION ALL SELECT 'A-F2 audit entry by a Read-Only admin', COUNT(*)
  FROM Audit_Log l JOIN Admin a ON a.admin_ID = l.admin_ID WHERE a.security_Level = 'Read-Only'
UNION ALL SELECT 'Attendance for a student not in the section', COUNT(*)
  FROM Attendance a LEFT JOIN Enrollment e ON e.student_ID = a.student_ID AND e.CRN = a.CRN WHERE e.CRN IS NULL
UNION ALL SELECT 'Transcript grade differs from enrollment grade', COUNT(*)
  FROM Student_History h JOIN Enrollment e ON e.student_ID = h.student_ID AND e.CRN = h.CRN WHERE h.grade <> e.grade
-- Columns kept up to date by triggers, and the Room / Faculty sub-tables
UNION ALL SELECT 'Course_Section.available_Seats out of date', COUNT(*)
  FROM Course_Section cs WHERE cs.available_Seats <> cs.max_Seats - (SELECT COUNT(*) FROM Enrollment e WHERE e.CRN = cs.CRN)
UNION ALL SELECT 'Copied course_ID differs from the section''s course', (
    (SELECT COUNT(*) FROM Enrollment x JOIN Course_Section cs ON cs.CRN = x.CRN WHERE x.course_ID <> cs.course_ID)
  + (SELECT COUNT(*) FROM Attendance x JOIN Course_Section cs ON cs.CRN = x.CRN WHERE x.course_ID <> cs.course_ID)
  + (SELECT COUNT(*) FROM Student_History x JOIN Course_Section cs ON cs.CRN = x.CRN WHERE x.course_ID <> cs.course_ID)
  + (SELECT COUNT(*) FROM Faculty_History x JOIN Course_Section cs ON cs.CRN = x.CRN WHERE x.course_ID <> cs.course_ID))
UNION ALL SELECT 'Login.user_Type differs from User.user_Type', COUNT(*)
  FROM Login l JOIN User u ON u.user_ID = l.user_ID WHERE l.user_Type <> u.user_Type
UNION ALL SELECT 'Student.major_ID not among the student''s majors', COUNT(*)
  FROM Student s WHERE NOT EXISTS (SELECT 1 FROM Student_Major sm WHERE sm.student_ID = s.student_ID AND sm.major_ID = s.major_ID)
UNION ALL SELECT 'Room without its Lecture/Lab/Office row', COUNT(*)
  FROM Room r WHERE (r.room_Type = 'Lecture' AND r.room_ID NOT IN (SELECT lecture_ID FROM Lecture))
     OR (r.room_Type = 'Lab' AND r.room_ID NOT IN (SELECT lab_ID FROM Lab))
     OR (r.room_Type = 'Office' AND r.room_ID NOT IN (SELECT office_ID FROM Office))
UNION ALL SELECT 'Lab meeting overlaps its own lecture', COUNT(DISTINCT crn_a)
  FROM clash WHERE crn_a = crn_b
UNION ALL SELECT 'Lab has fewer work stations than the section''s seats', COUNT(*)
  FROM Course_Section_Lab l JOIN Course_Section cs ON cs.CRN = l.CRN JOIN Lab lb ON lb.lab_ID = l.lab_ID
  WHERE lb.work_Stations < cs.max_Seats
UNION ALL SELECT 'Course section scheduled in an office', COUNT(*)
  FROM Course_Section cs JOIN Room r ON r.room_ID = cs.room_ID WHERE r.room_Type = 'Office'
UNION ALL SELECT 'Faculty without a matching Full_Time/Part_Time row', COUNT(*)
  FROM Faculty f WHERE NOT EXISTS (SELECT 1 FROM Full_Time_Faculty x WHERE x.faculty_ID = f.faculty_ID AND f.faculty_Type = 'Full-time')
                   AND NOT EXISTS (SELECT 1 FROM Part_Time_Faculty x WHERE x.faculty_ID = f.faculty_ID AND f.faculty_Type = 'Part-time');
