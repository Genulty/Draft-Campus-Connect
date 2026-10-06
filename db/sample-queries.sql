-- Queries to show the loaded data.   mysql campus_connect < db/sample-queries.sql
USE campus_connect;

-- Row count of every table
SELECT table_name, table_rows FROM information_schema.tables
WHERE table_schema = 'campus_connect' ORDER BY table_name;

-- A student's Fall 2026 schedule
SELECT c.CRN, c.course_ID, co.course_Name, c.section_No,
       CONCAT(u.first_Name, ' ', u.last_Name) AS instructor,
       GROUP_CONCAT(d.day_ID ORDER BY FIELD(d.day_ID,'M','T','W','R','F') SEPARATOR '') AS days,
       p.start_Time, p.end_Time, c.lab_ID
FROM Enrollment e
JOIN Class c USING (CRN)
JOIN Course co ON co.course_ID = c.course_ID
LEFT JOIN User u ON u.user_ID = c.faculty_ID
JOIN Time_Slot_Day d ON d.time_Slot_ID = c.time_Slot_ID
JOIN Time_Slot_Period tp ON tp.time_Slot_ID = c.time_Slot_ID
JOIN Period p ON p.period_ID = tp.period_ID
WHERE e.student_ID = 100001 AND e.semester_ID = 'FA26'
GROUP BY c.CRN, co.course_Name, instructor, p.start_Time, p.end_Time;

-- A student's transcript
SELECT h.semester_ID, h.course_ID, co.course_Name, co.course_Credits, h.grade
FROM Student_History h JOIN Course co USING (course_ID)
WHERE h.student_ID = 100001 ORDER BY h.semester_ID, h.course_ID;

-- Students per major
SELECT m.major_Name, COUNT(*) AS students
FROM Student s JOIN Major m USING (major_ID) GROUP BY m.major_Name ORDER BY students DESC;

-- Classes with open seats this semester
SELECT CRN, course_ID, section_No, capacity, available_Seats
FROM Class WHERE semester_ID = 'FA26' AND status = 'Open' AND available_Seats > 0
ORDER BY available_Seats DESC LIMIT 10;

-- Students with holds
SELECT sh.student_ID, CONCAT(u.first_Name, ' ', u.last_Name) AS name, h.hold_Type, sh.hold_Date
FROM Student_Hold sh JOIN Hold h USING (hold_ID) JOIN User u ON u.user_ID = sh.student_ID
ORDER BY sh.hold_Date;

-- Attendance rate per class (Fall 2026)
SELECT a.CRN, a.course_ID, ROUND(100 * AVG(a.attendance_Status = 'Present')) AS percent_present
FROM Attendance a GROUP BY a.CRN, a.course_ID ORDER BY percent_present LIMIT 10;
