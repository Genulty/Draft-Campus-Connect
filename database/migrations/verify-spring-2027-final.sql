
-- Campus Connect: Spring 2027 Scheduling Verification
-- Read-only: checks Faculty, Room, and Student conflicts.
-- Does not modify the database.

-- 1. Faculty scheduling conflicts
SELECT
    'Faculty' AS conflict_type,
    COUNT(DISTINCT a.CRN, b.CRN) AS conflicts
FROM Course_Section a
JOIN Course_Section b
    ON a.CRN < b.CRN
    AND a.semester_ID = b.semester_ID
    AND a.faculty_ID = b.faculty_ID
JOIN Time_Slot_Day ad
    ON ad.time_Slot_ID = a.time_Slot_ID
JOIN Time_Slot_Day bd
    ON bd.time_Slot_ID = b.time_Slot_ID
    AND ad.day_ID = bd.day_ID
JOIN Time_Slot_Period ap
    ON ap.time_Slot_ID = a.time_Slot_ID
JOIN Period p1
    ON p1.period_ID = ap.period_ID
JOIN Time_Slot_Period bp
    ON bp.time_Slot_ID = b.time_Slot_ID
JOIN Period p2
    ON p2.period_ID = bp.period_ID
WHERE a.semester_ID = 'SP27'
    AND a.faculty_ID IS NOT NULL
    AND p1.start_Time < p2.end_Time
    AND p2.start_Time < p1.end_Time;

-- 2. Classroom scheduling conflicts
SELECT
    'Room' AS conflict_type,
    COUNT(DISTINCT a.CRN, b.CRN) AS conflicts
FROM Course_Section a
JOIN Course_Section b
    ON a.CRN < b.CRN
    AND a.semester_ID = b.semester_ID
    AND a.room_ID = b.room_ID
JOIN Time_Slot_Day ad
    ON ad.time_Slot_ID = a.time_Slot_ID
JOIN Time_Slot_Day bd
    ON bd.time_Slot_ID = b.time_Slot_ID
    AND ad.day_ID = bd.day_ID
JOIN Time_Slot_Period ap
    ON ap.time_Slot_ID = a.time_Slot_ID
JOIN Period p1
    ON p1.period_ID = ap.period_ID
JOIN Time_Slot_Period bp
    ON bp.time_Slot_ID = b.time_Slot_ID
JOIN Period p2
    ON p2.period_ID = bp.period_ID
WHERE a.semester_ID = 'SP27'
    AND p1.start_Time < p2.end_Time
    AND p2.start_Time < p1.end_Time;

-- 3. Student scheduling conflicts
SELECT
    'Student' AS conflict_type,
    COUNT(DISTINCT e1.student_ID, e1.CRN, e2.CRN) AS conflicts
FROM Enrollment e1
JOIN Enrollment e2
    ON e1.student_ID = e2.student_ID
    AND e1.CRN < e2.CRN
JOIN Course_Section a
    ON a.CRN = e1.CRN
JOIN Course_Section b
    ON b.CRN = e2.CRN
    AND a.semester_ID = b.semester_ID
JOIN Time_Slot_Day ad
    ON ad.time_Slot_ID = a.time_Slot_ID
JOIN Time_Slot_Day bd
    ON bd.time_Slot_ID = b.time_Slot_ID
    AND ad.day_ID = bd.day_ID
JOIN Time_Slot_Period ap
    ON ap.time_Slot_ID = a.time_Slot_ID
JOIN Period p1
    ON p1.period_ID = ap.period_ID
JOIN Time_Slot_Period bp
    ON bp.time_Slot_ID = b.time_Slot_ID
JOIN Period p2
    ON p2.period_ID = bp.period_ID
WHERE a.semester_ID = 'SP27'
    AND p1.start_Time < p2.end_Time
    AND p2.start_Time < p1.end_Time;
