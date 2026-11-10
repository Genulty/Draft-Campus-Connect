-- Campus Connect: new senior-level CS and Math courses, and a CS/Math Fall 2026 schedule for the demo student.
-- Adds CS460 Artificial Intelligence, CS470 Cybersecurity Fundamentals, CS480 Senior Capstone and
-- MATH360 Cryptography (prerequisite CS220, so CS majors qualify), each with one Fall 2026 section of 5 students.
-- Julio Larrea (100001) drops BUS340, BIO220 and ENG225 and takes all four, with CS455: 15 credits of CS and Math.
-- Every rule still holds: prerequisites passed, credit limits, no time clashes, professors in the course's
-- department and within their teaching load, free rooms, and at least 5 students in each running section.
-- Attendance for the new sections uses the Fall 2026 Monday/Wednesday class dates already in the data.
-- Run after 2026-11-10-senior-classes-for-demo-student.sql. Safe to run more than once.

USE `campus_connect`;
START TRANSACTION;

-- New courses and their prerequisites
INSERT INTO Course (course_ID, course_Name, dept_ID, course_Credits, course_Desc, course_Type)
SELECT 'CS460', 'Artificial Intelligence', 'CS', 3, 'Search, knowledge representation, probabilistic reasoning and an introduction to machine learning.', 'Undergraduate' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course WHERE course_ID = 'CS460');
INSERT INTO Course (course_ID, course_Name, dept_ID, course_Credits, course_Desc, course_Type)
SELECT 'CS470', 'Cybersecurity Fundamentals', 'CS', 3, 'Threats and attacks, cryptographic tools, network and system security, and secure software practices.', 'Undergraduate' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course WHERE course_ID = 'CS470');
INSERT INTO Course (course_ID, course_Name, dept_ID, course_Credits, course_Desc, course_Type)
SELECT 'CS480', 'Senior Capstone', 'CS', 3, 'A semester-long team project: requirements, design, implementation and presentation of a software system.', 'Undergraduate' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course WHERE course_ID = 'CS480');
INSERT INTO Course (course_ID, course_Name, dept_ID, course_Credits, course_Desc, course_Type)
SELECT 'MATH360', 'Cryptography', 'MATH', 3, 'Number theory and modular arithmetic, classical and public-key ciphers, and digital signatures.', 'Undergraduate' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course WHERE course_ID = 'MATH360');
INSERT INTO Course_Prerequisite (course_ID, prerequisite_Course_ID, min_Grade_Req)
SELECT 'CS460', 'CS330', 'C' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Prerequisite WHERE course_ID = 'CS460' AND prerequisite_Course_ID = 'CS330');
INSERT INTO Course_Prerequisite (course_ID, prerequisite_Course_ID, min_Grade_Req)
SELECT 'CS470', 'CS225', 'C' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Prerequisite WHERE course_ID = 'CS470' AND prerequisite_Course_ID = 'CS225');
INSERT INTO Course_Prerequisite (course_ID, prerequisite_Course_ID, min_Grade_Req)
SELECT 'CS480', 'CS335', 'C' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Prerequisite WHERE course_ID = 'CS480' AND prerequisite_Course_ID = 'CS335');
INSERT INTO Course_Prerequisite (course_ID, prerequisite_Course_ID, min_Grade_Req)
SELECT 'MATH360', 'CS220', 'C' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Prerequisite WHERE course_ID = 'MATH360' AND prerequisite_Course_ID = 'CS220');

-- One Fall 2026 section each (open seats are set by the database trigger)
INSERT INTO Course_Section (CRN, course_ID, section_No, faculty_ID, time_Slot_ID, room_ID, semester_ID, max_Seats)
SELECT 10901, 'CS460', '001', 200001, 3, 'ACB-101', 'FA26', 10 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Section WHERE CRN = 10901);
INSERT INTO Course_Section (CRN, course_ID, section_No, faculty_ID, time_Slot_ID, room_ID, semester_ID, max_Seats)
SELECT 10902, 'CS480', '001', 200003, 4, 'ACB-102', 'FA26', 10 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Section WHERE CRN = 10902);
INSERT INTO Course_Section (CRN, course_ID, section_No, faculty_ID, time_Slot_ID, room_ID, semester_ID, max_Seats)
SELECT 10903, 'CS470', '001', 200003, 1, 'ACB-101', 'FA26', 10 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Section WHERE CRN = 10903);
INSERT INTO Course_Section (CRN, course_ID, section_No, faculty_ID, time_Slot_ID, room_ID, semester_ID, max_Seats)
SELECT 10904, 'MATH360', '001', 200004, 5, 'ACB-101', 'FA26', 10 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Section WHERE CRN = 10904);

-- Julio leaves BUS340 (10493), BIO220 (10481) and ENG225 (10536)
DELETE FROM Attendance WHERE student_ID = 100001 AND CRN IN (10493, 10481, 10536);
DELETE FROM Enrollment WHERE student_ID = 100001 AND CRN IN (10493, 10481, 10536);

-- Students in the new sections
INSERT INTO Enrollment (student_ID, CRN, grade)
SELECT v.student_ID, v.CRN, NULL FROM (SELECT 100001 AS student_ID, 10901 AS CRN UNION ALL SELECT 100145 AS student_ID, 10901 AS CRN UNION ALL SELECT 100163 AS student_ID, 10901 AS CRN UNION ALL SELECT 100175 AS student_ID, 10901 AS CRN UNION ALL SELECT 100174 AS student_ID, 10901 AS CRN UNION ALL SELECT 100001 AS student_ID, 10902 AS CRN UNION ALL SELECT 100031 AS student_ID, 10902 AS CRN UNION ALL SELECT 100093 AS student_ID, 10902 AS CRN UNION ALL SELECT 100132 AS student_ID, 10902 AS CRN UNION ALL SELECT 100040 AS student_ID, 10902 AS CRN UNION ALL SELECT 100001 AS student_ID, 10903 AS CRN UNION ALL SELECT 100030 AS student_ID, 10903 AS CRN UNION ALL SELECT 100032 AS student_ID, 10903 AS CRN UNION ALL SELECT 100065 AS student_ID, 10903 AS CRN UNION ALL SELECT 100043 AS student_ID, 10903 AS CRN UNION ALL SELECT 100001 AS student_ID, 10904 AS CRN UNION ALL SELECT 100013 AS student_ID, 10904 AS CRN UNION ALL SELECT 100167 AS student_ID, 10904 AS CRN UNION ALL SELECT 100115 AS student_ID, 10904 AS CRN UNION ALL SELECT 100133 AS student_ID, 10904 AS CRN) v
WHERE NOT EXISTS (SELECT 1 FROM Enrollment e WHERE e.student_ID = v.student_ID AND e.CRN = v.CRN);

-- Attendance so far this semester: every Monday/Wednesday class date already used by Fall 2026 sections
INSERT INTO Attendance (student_ID, CRN, attendance_Date, attendance_Status)
SELECT e.student_ID, e.CRN, d.attendance_Date, 'Present' FROM Enrollment e
JOIN (SELECT DISTINCT attendance_Date FROM Attendance WHERE CRN = 10493) d
WHERE e.CRN IN (10901,10902,10903,10904)
  AND NOT EXISTS (SELECT 1 FROM Attendance a WHERE a.student_ID = e.student_ID AND a.CRN = e.CRN AND a.attendance_Date = d.attendance_Date);

-- Expected: CS455, CS460, CS470, CS480 and MATH360 (15 credits)
SELECT cs.CRN, cs.course_ID, c.course_Name, c.course_Credits FROM Enrollment e
JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Course c ON c.course_ID = cs.course_ID
WHERE e.student_ID = 100001 AND cs.semester_ID = 'FA26' ORDER BY cs.course_ID;

COMMIT;
