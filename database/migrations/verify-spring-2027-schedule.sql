
-- Spring 2027: Verify proposed course assignments
-- Read-only; no database changes.

WITH proposed AS (
    SELECT 10548 AS CRN, 1 AS slot
    UNION ALL SELECT 10557, 3
    UNION ALL SELECT 10563, 2
    UNION ALL SELECT 10578, 4
    UNION ALL SELECT 10583, 5
    UNION ALL SELECT 10586, 6
    UNION ALL SELECT 10588, 8
    UNION ALL SELECT 10598, 11
    UNION ALL SELECT 10599, 7
    UNION ALL SELECT 10601, 9
    UNION ALL SELECT 10614, 10
    UNION ALL SELECT 10617, 12
    UNION ALL SELECT 10620, 13
)
SELECT
    a.CRN AS section_1,
    b.CRN AS section_2,
    CASE
        WHEN a.room_ID = b.room_ID THEN 'Room'
        ELSE 'Faculty'
    END AS conflict_type
FROM proposed pa
JOIN proposed pb ON pa.CRN < pb.CRN
JOIN Course_Section a ON a.CRN = pa.CRN
JOIN Course_Section b ON b.CRN = pb.CRN
JOIN Time_Slot_Day da ON da.time_Slot_ID = pa.slot
JOIN Time_Slot_Day db
    ON db.time_Slot_ID = pb.slot
    AND db.day_ID = da.day_ID
JOIN Time_Slot_Period ta ON ta.time_Slot_ID = pa.slot
JOIN Period p1 ON p1.period_ID = ta.period_ID
JOIN Time_Slot_Period tb ON tb.time_Slot_ID = pb.slot
JOIN Period p2 ON p2.period_ID = tb.period_ID
WHERE (
    a.room_ID = b.room_ID
    OR (
        a.faculty_ID = b.faculty_ID
        AND a.faculty_ID IS NOT NULL
    )
)
AND p1.start_Time < p2.end_Time
AND p2.start_Time < p1.end_Time;
