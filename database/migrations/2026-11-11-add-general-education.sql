-- Shared General Education requirements for undergraduate students.
-- Harvest Hill draft policy for demonstration only. Run after loading the current
-- catalog, including the November 10 department and senior-course additions.
-- Existing names and minimum grades are preserved on repeat application.
CREATE TABLE IF NOT EXISTS Gen_Ed_Requirement (
    requirement_ID VARCHAR(30) PRIMARY KEY,
    requirement_Name VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS Gen_Ed_Requirement_Course (
    requirement_ID VARCHAR(30) NOT NULL,
    course_ID VARCHAR(10) NOT NULL,
    min_Grade_Req VARCHAR(2) NOT NULL,
    PRIMARY KEY (requirement_ID, course_ID),
    FOREIGN KEY (requirement_ID) REFERENCES Gen_Ed_Requirement(requirement_ID),
    FOREIGN KEY (course_ID) REFERENCES Course(course_ID)
);

START TRANSACTION;
INSERT INTO Gen_Ed_Requirement (requirement_ID, requirement_Name)
VALUES ('WRITING', 'Basic Communication'), ('MATH', 'Mathematics'),
       ('HUMANITIES', 'Humanities'), ('SOCIAL_SCIENCE', 'Social Science'),
       ('AMERICAN_EXPERIENCE', 'The American Experience'), ('WORLD_HISTORY', 'World History and Cultures'),
       ('NATURAL_SCIENCES', 'Natural Sciences')
ON DUPLICATE KEY UPDATE requirement_Name = Gen_Ed_Requirement.requirement_Name;

INSERT INTO Gen_Ed_Requirement_Course (requirement_ID, course_ID, min_Grade_Req)
VALUES ('WRITING', 'ENG110', 'D'), ('MATH', 'MATH110', 'D'), ('MATH', 'MATH225', 'D'),
       ('HUMANITIES', 'ENG115', 'D'), ('HUMANITIES', 'ENG220', 'D'),
       ('SOCIAL_SCIENCE', 'ECON110', 'D'), ('SOCIAL_SCIENCE', 'PSY110', 'D'),
       ('AMERICAN_EXPERIENCE', 'HIST220', 'D'), ('WORLD_HISTORY', 'HIST110', 'D'),
       ('NATURAL_SCIENCES', 'BIO110', 'D'), ('NATURAL_SCIENCES', 'PHYS110', 'D')
ON DUPLICATE KEY UPDATE min_Grade_Req = Gen_Ed_Requirement_Course.min_Grade_Req;
COMMIT;
