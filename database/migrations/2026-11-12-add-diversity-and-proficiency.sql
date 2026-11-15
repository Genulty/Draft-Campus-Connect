-- Apply after 2026-11-12-add-degree-credit-requirements.sql.
-- Harvest Hill has no approved mappings or placement thresholds on file.
CREATE TABLE IF NOT EXISTS Degree_Audit_Supplemental_Rule (
    program_ID VARCHAR(20) NOT NULL,
    requirement_ID ENUM('DIVERSITY', 'MATH', 'WRITING') NOT NULL,
    allow_Course_Sharing BOOLEAN NOT NULL DEFAULT FALSE,
    is_Draft BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (program_ID, requirement_ID),
    FOREIGN KEY (program_ID) REFERENCES Degree_Audit_Program(program_ID)
);

CREATE TABLE IF NOT EXISTS Degree_Audit_Supplemental_Course (
    program_ID VARCHAR(20) NOT NULL,
    requirement_ID ENUM('DIVERSITY', 'MATH', 'WRITING') NOT NULL,
    course_ID VARCHAR(10) NOT NULL,
    min_Grade_Req VARCHAR(2) NOT NULL,
    PRIMARY KEY (program_ID, requirement_ID, course_ID),
    FOREIGN KEY (program_ID, requirement_ID)
        REFERENCES Degree_Audit_Supplemental_Rule(program_ID, requirement_ID),
    FOREIGN KEY (course_ID) REFERENCES Course(course_ID)
);

CREATE TABLE IF NOT EXISTS Degree_Audit_Placement_Criterion (
    program_ID VARCHAR(20) NOT NULL,
    requirement_ID ENUM('DIVERSITY', 'MATH', 'WRITING') NOT NULL,
    assessment_Code VARCHAR(40) NOT NULL,
    minimum_Score DECIMAL(8,2) UNSIGNED NOT NULL,
    PRIMARY KEY (program_ID, requirement_ID, assessment_Code),
    FOREIGN KEY (program_ID, requirement_ID)
        REFERENCES Degree_Audit_Supplemental_Rule(program_ID, requirement_ID),
    CHECK (requirement_ID IN ('MATH', 'WRITING'))
);

CREATE TABLE IF NOT EXISTS Student_Placement_Result (
    student_ID INT NOT NULL,
    assessment_Code VARCHAR(40) NOT NULL,
    assessed_On DATE NOT NULL,
    score DECIMAL(8,2) UNSIGNED NOT NULL,
    PRIMARY KEY (student_ID, assessment_Code, assessed_On),
    FOREIGN KEY (student_ID) REFERENCES Student(student_ID) ON DELETE CASCADE
);
