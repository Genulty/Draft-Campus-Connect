-- TEST ONLY: optional existing explicit-restriction schema, private fixture databases only.
-- Multiple rows are alternatives. No policy/access rows are included.
CREATE TABLE IF NOT EXISTS Course_Program_Restriction (
    restriction_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_ID VARCHAR(10) NOT NULL,
    major_ID VARCHAR(10),
    minor_ID VARCHAR(10),
    UNIQUE (course_ID, major_ID),
    UNIQUE (course_ID, minor_ID),
    CHECK ((major_ID IS NOT NULL) <> (minor_ID IS NOT NULL)),
    FOREIGN KEY (course_ID) REFERENCES Course(course_ID) ON DELETE CASCADE,
    FOREIGN KEY (major_ID) REFERENCES Major(major_ID),
    FOREIGN KEY (minor_ID) REFERENCES Minor(minor_ID)
);
