-- Apply after the degree-credit migration. Academic rules are configured separately.
CREATE TABLE IF NOT EXISTS Degree_Audit_Elective_Rule (
    program_ID VARCHAR(20) NOT NULL,
    elective_Type ENUM('MAJOR', 'FREE') NOT NULL,
    target_Unit ENUM('COURSES', 'CREDITS') NOT NULL,
    target_Amount SMALLINT UNSIGNED NOT NULL CHECK (target_Amount > 0),
    allow_Course_Sharing BOOLEAN NOT NULL DEFAULT FALSE,
    is_Draft BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (program_ID, elective_Type),
    FOREIGN KEY (program_ID) REFERENCES Degree_Audit_Program(program_ID),
    CHECK (elective_Type = 'MAJOR' OR target_Unit = 'CREDITS')
);

CREATE TABLE IF NOT EXISTS Degree_Audit_Elective_Course (
    program_ID VARCHAR(20) NOT NULL,
    elective_Type ENUM('MAJOR', 'FREE') NOT NULL,
    course_ID VARCHAR(10) NOT NULL,
    min_Grade_Req VARCHAR(2) NOT NULL,
    PRIMARY KEY (program_ID, elective_Type, course_ID),
    FOREIGN KEY (program_ID, elective_Type)
        REFERENCES Degree_Audit_Elective_Rule(program_ID, elective_Type),
    FOREIGN KEY (course_ID) REFERENCES Course(course_ID)
);
