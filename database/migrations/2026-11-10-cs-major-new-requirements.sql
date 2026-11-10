-- Campus Connect: add the new senior courses to the B.S. in Computer Science requirements.
-- CS460 Artificial Intelligence, CS470 Cybersecurity Fundamentals, CS480 Senior Capstone and MATH360 Cryptography
-- become required (minimum grade D, like the other CS major requirements), so the major lists 14 courses.
-- Run after 2026-11-10-senior-cs-and-math-courses.sql (which adds the courses). Safe to run more than once.
USE `campus_connect`;
START TRANSACTION;

INSERT INTO Major_Course_Requirement (major_ID, course_ID, min_Grade_Req)
SELECT 'CS-BS', v.course_ID, 'D'
FROM (SELECT 'CS460' AS course_ID UNION ALL SELECT 'CS470' UNION ALL SELECT 'CS480' UNION ALL SELECT 'MATH360') v
WHERE EXISTS (SELECT 1 FROM Course c WHERE c.course_ID = v.course_ID)
  AND NOT EXISTS (SELECT 1 FROM Major_Course_Requirement r WHERE r.major_ID = 'CS-BS' AND r.course_ID = v.course_ID);

-- Expected: 14 required courses for the B.S. in Computer Science.
SELECT COUNT(*) AS cs_major_requirements FROM Major_Course_Requirement WHERE major_ID = 'CS-BS';

COMMIT;
