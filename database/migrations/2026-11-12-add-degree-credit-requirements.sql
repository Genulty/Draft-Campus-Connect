-- Harvest Hill draft degree-credit rules. Apply after the current course catalog.
-- The student schema does not assign a catalog year, so these rules are unversioned.
CREATE TABLE IF NOT EXISTS Degree_Audit_Program (
    program_ID VARCHAR(20) PRIMARY KEY,
    program_Name VARCHAR(100) NOT NULL,
    total_Credits SMALLINT UNSIGNED CHECK (total_Credits > 0),
    liberal_Arts_Credits SMALLINT UNSIGNED CHECK (liberal_Arts_Credits > 0),
    is_Draft BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS Degree_Audit_Program_Major (
    major_ID VARCHAR(10) PRIMARY KEY,
    program_ID VARCHAR(20) NOT NULL,
    FOREIGN KEY (major_ID) REFERENCES Major(major_ID),
    FOREIGN KEY (program_ID) REFERENCES Degree_Audit_Program(program_ID)
);

CREATE TABLE IF NOT EXISTS Degree_Audit_Liberal_Arts_Course (
    program_ID VARCHAR(20) NOT NULL,
    course_ID VARCHAR(10) NOT NULL,
    PRIMARY KEY (program_ID, course_ID),
    FOREIGN KEY (program_ID) REFERENCES Degree_Audit_Program(program_ID),
    FOREIGN KEY (course_ID) REFERENCES Course(course_ID)
);

START TRANSACTION;
INSERT INTO Degree_Audit_Program (program_ID, program_Name, total_Credits, liberal_Arts_Credits, is_Draft)
VALUES ('BS-CS', 'B.S. in Computer Science', 120, 60, TRUE)
ON DUPLICATE KEY UPDATE program_ID = Degree_Audit_Program.program_ID;

INSERT INTO Degree_Audit_Program_Major (major_ID, program_ID)
VALUES ('CS-BS', 'BS-CS')
ON DUPLICATE KEY UPDATE major_ID = Degree_Audit_Program_Major.major_ID;

-- Explicit course-by-course draft eligibility. Ambiguous and professional titles
-- stay unclassified. See docs/degree-audit-phase-1.md for the review basis.
INSERT INTO Degree_Audit_Liberal_Arts_Course (program_ID, course_ID)
VALUES
    ('BS-CS', 'ENG110'), ('BS-CS', 'ENG115'), ('BS-CS', 'ENG220'), ('BS-CS', 'ENG225'),
    ('BS-CS', 'ENG335'), ('BS-CS', 'ENG340'), ('BS-CS', 'ENG445'),
    ('BS-CS', 'HIST110'), ('BS-CS', 'HIST115'), ('BS-CS', 'HIST220'), ('BS-CS', 'HIST225'),
    ('BS-CS', 'HIST330'), ('BS-CS', 'HIST335'),
    ('BS-CS', 'MATH110'), ('BS-CS', 'MATH115'), ('BS-CS', 'MATH220'), ('BS-CS', 'MATH225'),
    ('BS-CS', 'MATH330'), ('BS-CS', 'MATH335'), ('BS-CS', 'MATH340'), ('BS-CS', 'MATH445'), ('BS-CS', 'MATH450'),
    ('BS-CS', 'BIO110'), ('BS-CS', 'BIO115'), ('BS-CS', 'BIO220'), ('BS-CS', 'BIO225'),
    ('BS-CS', 'BIO330'), ('BS-CS', 'BIO335'), ('BS-CS', 'BIO445'), ('BS-CS', 'BIO450'), ('BS-CS', 'BIO455'),
    ('BS-CS', 'CHEM110'), ('BS-CS', 'CHEM115'), ('BS-CS', 'CHEM220'), ('BS-CS', 'CHEM225'),
    ('BS-CS', 'CHEM330'), ('BS-CS', 'CHEM335'), ('BS-CS', 'CHEM445'),
    ('BS-CS', 'PHYS110'), ('BS-CS', 'PHYS115'), ('BS-CS', 'PHYS220'), ('BS-CS', 'PHYS225'),
    ('BS-CS', 'PHYS330'), ('BS-CS', 'PHYS335'), ('BS-CS', 'PHYS445'), ('BS-CS', 'PHYS450'),
    ('BS-CS', 'PSY110'), ('BS-CS', 'PSY220'), ('BS-CS', 'PSY225'), ('BS-CS', 'PSY330'),
    ('BS-CS', 'PSY335'), ('BS-CS', 'PSY340'), ('BS-CS', 'PSY445'),
    ('BS-CS', 'ECON110'), ('BS-CS', 'ECON115'), ('BS-CS', 'ECON220'), ('BS-CS', 'ECON225'),
    ('BS-CS', 'ECON330'), ('BS-CS', 'ECON335'), ('BS-CS', 'ECON450')
ON DUPLICATE KEY UPDATE course_ID = Degree_Audit_Liberal_Arts_Course.course_ID;
COMMIT;
