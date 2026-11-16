-- Campus Connect: Harvest Hill rules for the Degree Audit's Diversity, Proficiency, Major Electives
-- and Free Electives sections of the B.S. in Computer Science (program BS-CS), and four new CS elective
-- courses so Major Electives has courses that are not already required by the major.
--
-- New courses (each with one Spring 2027 section; registration is open, so no students are enrolled yet):
--   CS350 Web Development           prerequisite CS115 (C)   Pavel Clarke   Mon/Wed 9:30-10:50   ACB-102
--   CS360 Mobile App Development    prerequisite CS115 (C)   Pavel Clarke   Tue/Thu 9:30-10:50   ACB-101
--   CS370 Computer Graphics         prerequisite MATH220 (C) Mei Baker      Mon/Wed 11:00-12:20  ACB-103
--   CS465 Cloud Computing           prerequisite CS450 (C)   Mei Baker      Tue/Thu 11:00-12:20  ACB-104
--
-- Rules (marked draft, like General Education and Liberal Arts, until the registrar approves them):
--   Diversity         one course, minimum C: HIST330, HIST335, ENG445, ECON335, ECON450 or PSY225.
--                     May also count toward General Education or Liberal Arts.
--   Math proficiency  MATH110 or MATH115 with a C or better, or a score of 70+ on the HHU-MATH placement test.
--   Writing           ENG110 or ENG450 with a C or better, or a score of 70+ on the HHU-WRITING placement test.
--   Major Electives   2 courses, minimum C: CS350, CS360, CS370 or CS465. Not shared with other sections.
--   Free Electives    9 credits from any undergraduate course that is not a CS major requirement
--                     (minimum D, the passing grade). Not shared with other sections.
--
-- Run after the four degree-audit migrations (2026-11-11-add-general-education.sql and the three
-- 2026-11-12 files). Safe to run more than once: courses and sections are added only if missing, and the
-- rules are added only if BS-CS has no Diversity/Proficiency/elective rules yet (existing rules are kept).

USE `campus_connect`;
START TRANSACTION;

-- New CS elective courses and their prerequisites
INSERT INTO Course (course_ID, course_Name, dept_ID, course_Credits, course_Desc, course_Type)
SELECT 'CS350', 'Web Development', 'CS', 3, 'HTML, CSS and JavaScript, server-side programming, databases on the web and deploying a web application.', 'Undergraduate' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course WHERE course_ID = 'CS350');
INSERT INTO Course (course_ID, course_Name, dept_ID, course_Credits, course_Desc, course_Type)
SELECT 'CS360', 'Mobile App Development', 'CS', 3, 'Designing and building apps for phones and tablets: interfaces, local storage, networking and publishing.', 'Undergraduate' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course WHERE course_ID = 'CS360');
INSERT INTO Course (course_ID, course_Name, dept_ID, course_Credits, course_Desc, course_Type)
SELECT 'CS370', 'Computer Graphics', 'CS', 3, 'Transformations, the rendering pipeline, lighting and shading, textures and an introduction to animation.', 'Undergraduate' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course WHERE course_ID = 'CS370');
INSERT INTO Course (course_ID, course_Name, dept_ID, course_Credits, course_Desc, course_Type)
SELECT 'CS465', 'Cloud Computing', 'CS', 3, 'Virtualization, containers, cloud storage and services, scaling and the cost and security of cloud systems.', 'Undergraduate' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course WHERE course_ID = 'CS465');
INSERT INTO Course_Prerequisite (course_ID, prerequisite_Course_ID, min_Grade_Req)
SELECT 'CS350', 'CS115', 'C' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Prerequisite WHERE course_ID = 'CS350' AND prerequisite_Course_ID = 'CS115');
INSERT INTO Course_Prerequisite (course_ID, prerequisite_Course_ID, min_Grade_Req)
SELECT 'CS360', 'CS115', 'C' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Prerequisite WHERE course_ID = 'CS360' AND prerequisite_Course_ID = 'CS115');
INSERT INTO Course_Prerequisite (course_ID, prerequisite_Course_ID, min_Grade_Req)
SELECT 'CS370', 'MATH220', 'C' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Prerequisite WHERE course_ID = 'CS370' AND prerequisite_Course_ID = 'MATH220');
INSERT INTO Course_Prerequisite (course_ID, prerequisite_Course_ID, min_Grade_Req)
SELECT 'CS465', 'CS450', 'C' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Prerequisite WHERE course_ID = 'CS465' AND prerequisite_Course_ID = 'CS450');

-- One Spring 2027 section each (open seats are set by the database trigger)
INSERT INTO Course_Section (CRN, course_ID, section_No, faculty_ID, time_Slot_ID, room_ID, semester_ID, max_Seats)
SELECT 10941, 'CS350', '001', 200001, 2, 'ACB-102', 'SP27', 10 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Section WHERE CRN = 10941);
INSERT INTO Course_Section (CRN, course_ID, section_No, faculty_ID, time_Slot_ID, room_ID, semester_ID, max_Seats)
SELECT 10942, 'CS360', '001', 200001, 9, 'ACB-101', 'SP27', 10 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Section WHERE CRN = 10942);
INSERT INTO Course_Section (CRN, course_ID, section_No, faculty_ID, time_Slot_ID, room_ID, semester_ID, max_Seats)
SELECT 10943, 'CS370', '001', 200005, 3, 'ACB-103', 'SP27', 10 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Section WHERE CRN = 10943);
INSERT INTO Course_Section (CRN, course_ID, section_No, faculty_ID, time_Slot_ID, room_ID, semester_ID, max_Seats)
SELECT 10944, 'CS465', '001', 200005, 10, 'ACB-104', 'SP27', 10 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM Course_Section WHERE CRN = 10944);

-- Degree Audit rules for BS-CS, only if none are configured yet
SET @hhu_seed_rules = EXISTS (SELECT 1 FROM Degree_Audit_Program WHERE program_ID = 'BS-CS')
    AND NOT EXISTS (SELECT 1 FROM Degree_Audit_Supplemental_Rule WHERE program_ID = 'BS-CS')
    AND NOT EXISTS (SELECT 1 FROM Degree_Audit_Elective_Rule WHERE program_ID = 'BS-CS');

INSERT INTO Degree_Audit_Supplemental_Rule (program_ID, requirement_ID, allow_Course_Sharing, is_Draft)
SELECT 'BS-CS', 'DIVERSITY', TRUE, TRUE FROM DUAL WHERE @hhu_seed_rules
UNION ALL SELECT 'BS-CS', 'MATH', TRUE, TRUE FROM DUAL WHERE @hhu_seed_rules
UNION ALL SELECT 'BS-CS', 'WRITING', TRUE, TRUE FROM DUAL WHERE @hhu_seed_rules;

INSERT INTO Degree_Audit_Supplemental_Course (program_ID, requirement_ID, course_ID, min_Grade_Req)
SELECT 'BS-CS', 'DIVERSITY', 'HIST330', 'C' FROM DUAL WHERE @hhu_seed_rules
UNION ALL SELECT 'BS-CS', 'DIVERSITY', 'HIST335', 'C' FROM DUAL WHERE @hhu_seed_rules
UNION ALL SELECT 'BS-CS', 'DIVERSITY', 'ENG445', 'C' FROM DUAL WHERE @hhu_seed_rules
UNION ALL SELECT 'BS-CS', 'DIVERSITY', 'ECON335', 'C' FROM DUAL WHERE @hhu_seed_rules
UNION ALL SELECT 'BS-CS', 'DIVERSITY', 'ECON450', 'C' FROM DUAL WHERE @hhu_seed_rules
UNION ALL SELECT 'BS-CS', 'DIVERSITY', 'PSY225', 'C' FROM DUAL WHERE @hhu_seed_rules
UNION ALL SELECT 'BS-CS', 'MATH', 'MATH110', 'C' FROM DUAL WHERE @hhu_seed_rules
UNION ALL SELECT 'BS-CS', 'MATH', 'MATH115', 'C' FROM DUAL WHERE @hhu_seed_rules
UNION ALL SELECT 'BS-CS', 'WRITING', 'ENG110', 'C' FROM DUAL WHERE @hhu_seed_rules
UNION ALL SELECT 'BS-CS', 'WRITING', 'ENG450', 'C' FROM DUAL WHERE @hhu_seed_rules;

INSERT INTO Degree_Audit_Placement_Criterion (program_ID, requirement_ID, assessment_Code, minimum_Score)
SELECT 'BS-CS', 'MATH', 'HHU-MATH', 70 FROM DUAL WHERE @hhu_seed_rules
UNION ALL SELECT 'BS-CS', 'WRITING', 'HHU-WRITING', 70 FROM DUAL WHERE @hhu_seed_rules;

INSERT INTO Degree_Audit_Elective_Rule (program_ID, elective_Type, target_Unit, target_Amount, allow_Course_Sharing, is_Draft)
SELECT 'BS-CS', 'MAJOR', 'COURSES', 2, FALSE, TRUE FROM DUAL WHERE @hhu_seed_rules
UNION ALL SELECT 'BS-CS', 'FREE', 'CREDITS', 9, FALSE, TRUE FROM DUAL WHERE @hhu_seed_rules;

INSERT INTO Degree_Audit_Elective_Course (program_ID, elective_Type, course_ID, min_Grade_Req)
SELECT 'BS-CS', 'MAJOR', c.course_ID, 'C' FROM Course c
WHERE @hhu_seed_rules AND c.course_ID IN ('CS350', 'CS360', 'CS370', 'CS465');

-- Free Electives: every undergraduate course that is not a CS major requirement (the CS electives included).
INSERT INTO Degree_Audit_Elective_Course (program_ID, elective_Type, course_ID, min_Grade_Req)
SELECT 'BS-CS', 'FREE', c.course_ID, 'D' FROM Course c
WHERE @hhu_seed_rules AND c.course_Type = 'Undergraduate'
  AND c.course_ID NOT IN (SELECT r.course_ID FROM Major_Course_Requirement r WHERE r.major_ID = 'CS-BS');

COMMIT;

-- Expected: DIVERSITY 6, MATH 2, WRITING 2 courses; MAJOR 4 courses; FREE about 90 courses.
SELECT requirement_ID AS Requirement, COUNT(*) AS Courses FROM Degree_Audit_Supplemental_Course WHERE program_ID = 'BS-CS' GROUP BY requirement_ID
UNION ALL
SELECT elective_Type, COUNT(*) FROM Degree_Audit_Elective_Course WHERE program_ID = 'BS-CS' GROUP BY elective_Type;
