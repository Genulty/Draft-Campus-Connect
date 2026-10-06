-- Checks that the loaded data follows the System Manual's rules. Every row should show 0 violations.
--   mysql campus_connect < db/validate.sql
USE campus_connect;
SET @today = '2026-10-06';
SELECT 'A-F5 faculty teaches two sections at the same time' AS rule_checked, COUNT(*) AS violations
  FROM (SELECT faculty_ID FROM Course_Section GROUP BY faculty_ID, time_Slot_ID, semester_ID HAVING COUNT(*) > 1) x
UNION ALL SELECT 'A-F6 two sections in one room at the same time', COUNT(*)
  FROM (SELECT room_ID FROM Course_Section GROUP BY room_ID, time_Slot_ID, semester_ID HAVING COUNT(*) > 1) x
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
UNION ALL SELECT 'S-F11 student in two sections at the same time', COUNT(*)
  FROM (SELECT e.student_ID FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN
        GROUP BY e.student_ID, cs.time_Slot_ID, cs.semester_ID HAVING COUNT(*) > 1) x
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
  FROM Student_History h JOIN Enrollment e ON e.student_ID = h.student_ID AND e.CRN = h.CRN WHERE h.grade <> e.grade;
