-- OPTIONAL LOCAL DEMO ONLY. These mappings and thresholds are illustrative drafts.
-- Apply the Degree Audit credit, supplemental, and elective schema migrations first.
-- Preserve existing configuration: seed BS-CS only if neither rule set exists.
START TRANSACTION;
SET @campus_seed_audit_rules = NOT EXISTS (
    SELECT 1 FROM Degree_Audit_Supplemental_Rule WHERE program_ID = 'BS-CS'
) AND NOT EXISTS (
    SELECT 1 FROM Degree_Audit_Elective_Rule WHERE program_ID = 'BS-CS'
);

INSERT INTO Degree_Audit_Supplemental_Rule (program_ID, requirement_ID, allow_Course_Sharing, is_Draft)
SELECT 'BS-CS', 'DIVERSITY', TRUE, TRUE WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'MATH', TRUE, TRUE WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'WRITING', TRUE, TRUE WHERE @campus_seed_audit_rules;

INSERT INTO Degree_Audit_Supplemental_Course (program_ID, requirement_ID, course_ID, min_Grade_Req)
SELECT 'BS-CS', 'DIVERSITY', 'BIO110', 'B' WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'DIVERSITY', 'ENG115', 'C' WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'MATH', 'MATH110', 'C' WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'WRITING', 'ENG110', 'C' WHERE @campus_seed_audit_rules;

INSERT INTO Degree_Audit_Placement_Criterion (program_ID, requirement_ID, assessment_Code, minimum_Score)
SELECT 'BS-CS', 'MATH', 'DRAFT-MATH', 75 WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'WRITING', 'DRAFT-WRITING', 70 WHERE @campus_seed_audit_rules;

INSERT INTO Degree_Audit_Elective_Rule (program_ID, elective_Type, target_Unit, target_Amount, allow_Course_Sharing, is_Draft)
SELECT 'BS-CS', 'MAJOR', 'COURSES', 3, TRUE, TRUE WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'FREE', 'CREDITS', 9, FALSE, TRUE WHERE @campus_seed_audit_rules;

INSERT INTO Degree_Audit_Elective_Course (program_ID, elective_Type, course_ID, min_Grade_Req)
SELECT 'BS-CS', 'MAJOR', 'CS445', 'C' WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'MAJOR', 'CS450', 'C' WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'MAJOR', 'CS455', 'C' WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'FREE', 'BUS110', 'D' WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'FREE', 'BUS220', 'D' WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'FREE', 'BUS225', 'D' WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'FREE', 'PSY115', 'D' WHERE @campus_seed_audit_rules
UNION ALL SELECT 'BS-CS', 'FREE', 'BIO110', 'D' WHERE @campus_seed_audit_rules;
COMMIT;
