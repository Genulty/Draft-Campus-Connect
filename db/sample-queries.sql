-- Queries to show the loaded data.   mysql campus_connect < db/sample-queries.sql
USE campus_connect;

-- Row count of every table
SELECT table_name, table_rows FROM information_schema.tables
WHERE table_schema = 'campus_connect' ORDER BY table_name;

-- S-R4: a student's current-semester (Fall 2026) schedule
SELECT cs.CRN, cs.course_ID, c.course_Name, cs.section_No,
       CONCAT(u.first_Name, ' ', u.last_Name) AS instructor,
       GROUP_CONCAT(d.day_ID ORDER BY FIELD(d.day_ID,'M','T','W','R','F') SEPARATOR '') AS days,
       p.start_Time, p.end_Time, r.building_ID, r.room_No
FROM Enrollment e
JOIN Course_Section cs ON cs.CRN = e.CRN
JOIN Course c ON c.course_ID = cs.course_ID
JOIN User u ON u.user_ID = cs.faculty_ID
JOIN Room r ON r.room_ID = cs.room_ID
JOIN Time_Slot_Day d ON d.time_Slot_ID = cs.time_Slot_ID
JOIN Time_Slot_Period tp ON tp.time_Slot_ID = cs.time_Slot_ID
JOIN Period p ON p.period_ID = tp.period_ID
WHERE e.student_ID = 100001 AND cs.semester_ID = 'FA26'
GROUP BY cs.CRN, c.course_Name, instructor, p.start_Time, p.end_Time, r.building_ID, r.room_No;

-- S-R8: a student's unofficial transcript
SELECT h.semester_ID, cs.course_ID, c.course_Name, c.course_Credits, h.grade
FROM Student_History h
JOIN Course_Section cs ON cs.CRN = h.CRN
JOIN Course c ON c.course_ID = cs.course_ID
WHERE h.student_ID = 100001
ORDER BY (SELECT start_Date FROM Semester s WHERE s.semester_ID = h.semester_ID), cs.course_ID;

-- F-R10: roster for each section the test faculty member teaches this semester
SELECT cs.CRN, cs.course_ID, e.student_ID, CONCAT(u.first_Name, ' ', u.last_Name) AS student
FROM Course_Section cs
JOIN Enrollment e ON e.CRN = cs.CRN
JOIN User u ON u.user_ID = e.student_ID
WHERE cs.faculty_ID = 200001 AND cs.semester_ID = 'FA26'
ORDER BY cs.CRN, student;

-- F-R12: a faculty member's advisees
SELECT a.student_ID, CONCAT(u.first_Name, ' ', u.last_Name) AS student, a.date_Of_Appointment
FROM Advisor a JOIN User u ON u.user_ID = a.student_ID
WHERE a.faculty_ID = 200001;

-- S-R6: students with active holds
SELECT sh.student_ID, CONCAT(u.first_Name, ' ', u.last_Name) AS name, h.hold_Type, sh.hold_Date
FROM Student_Hold sh JOIN Hold h ON h.hold_ID = sh.hold_ID JOIN User u ON u.user_ID = sh.student_ID
ORDER BY sh.hold_Date;

-- F-R19: Fall 2026 sections with available seats
SELECT cs.CRN, cs.course_ID, cs.section_No, cs.max_Seats,
       cs.max_Seats - COUNT(e.student_ID) AS available_Seats
FROM Course_Section cs LEFT JOIN Enrollment e ON e.CRN = cs.CRN
WHERE cs.semester_ID = 'FA26'
GROUP BY cs.CRN ORDER BY available_Seats DESC LIMIT 10;

-- SD-R9: undergraduate and graduate student counts
SELECT student_Type, COUNT(*) AS students FROM Student GROUP BY student_Type;

-- SD-R10: grade distribution by department (no student identified, SD-R12)
SELECT c.dept_ID, h.grade, COUNT(*) AS grades
FROM Student_History h
JOIN Course_Section cs ON cs.CRN = h.CRN
JOIN Course c ON c.course_ID = cs.course_ID
GROUP BY c.dept_ID, h.grade ORDER BY c.dept_ID, FIELD(h.grade,'A','A-','B+','B','B-','C+','C','C-','D+','D','F');

-- A-R44: admin audit log
SELECT l.log_ID, l.admin_ID, l.user_ID, l.action_Type, l.field_Name, l.old_Value, l.new_Value, l.date_Time
FROM Audit_Log l ORDER BY l.date_Time DESC LIMIT 10;
