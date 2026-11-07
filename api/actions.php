<?php
// Actions that change the database: a student adds or drops a course section,
// and an admin creates a course section. Every SRS rule is checked before anything is
// written; a broken rule throws a RuleError explaining why.
declare(strict_types=1);

const GRADE_ORDER = "'F','D','D+','C-','C','C+','B-','B','B+','A-','A'";
const MAX_CREDITS = ['Full-time' => 16, 'Part-time' => 8];  // S-F7, S-F8
const TEACH_LOAD = ['Full-time' => 2, 'Part-time' => 1];    // A-F7, A-F8

// Every weekly meeting of every section: its lecture (Course_Section) plus its separate lab meeting
// (Course_Section_Lab) when it has one, with the room and teacher of each.
const MEETINGS = "(SELECT cs.CRN, cs.course_ID, cs.semester_ID, cs.faculty_ID, cs.room_ID, cs.time_Slot_ID FROM Course_Section cs
  UNION ALL
  SELECT cs.CRN, cs.course_ID, cs.semester_ID, cs.faculty_ID, l.lab_ID, l.time_Slot_ID FROM Course_Section cs
    JOIN Course_Section_Lab l ON l.CRN = cs.CRN)";

// Two meetings clash when they share a day and their times overlap. Times are compared, not period
// IDs, because a 150-minute lab period overlaps several 80-minute lecture periods.
// $column is a trusted SQL fragment (a column name), never user input; the time slots are bound as ?.
function overlapsAny(string $column, int $count): string
{
    $marks = implode(', ', array_fill(0, $count, '?'));
    return "EXISTS (SELECT 1 FROM Time_Slot_Day d1 JOIN Time_Slot_Day d2 ON d2.day_ID = d1.day_ID
        JOIN Time_Slot_Period tp1 ON tp1.time_Slot_ID = d1.time_Slot_ID JOIN Period p1 ON p1.period_ID = tp1.period_ID
        JOIN Time_Slot_Period tp2 ON tp2.time_Slot_ID = d2.time_Slot_ID JOIN Period p2 ON p2.period_ID = tp2.period_ID
      WHERE d1.time_Slot_ID = $column AND d2.time_Slot_ID IN ($marks)
        AND p1.start_Time < p2.end_Time AND p2.start_Time < p1.end_Time)";
}

// Every enrollment writer must lock this existing parent row before its first
// ordinary SELECT. Locking reads do not establish a REPEATABLE READ snapshot, so
// a waiter validates against a fresh snapshot after the prior writer finishes.
// Order: Student first, then the existing section/enrollment locks. Keep this
// discipline for future enrollment writers; previews must remain read-only.
function lockStudentRegistration(int $studentId): void
{
    if (!db()->inTransaction()) {
        throw new LogicException('Student registration locks require an active transaction.');
    }
    if (!one('SELECT student_ID FROM Student WHERE student_ID = ? FOR UPDATE', [$studentId])) {
        fail('That student account does not exist.');
    }
}

// Validate API section identifiers without accepting PHP's lossy integer casts.
function registrationCrn(mixed $value): int
{
    if ((!is_int($value) && !is_string($value))
        || !preg_match('/\A[1-9][0-9]*\z/', (string) $value)
        || filter_var($value, FILTER_VALIDATE_INT) === false) {
        fail('Choose a valid course section (CRN).');
    }
    return (int) $value;
}

// ---------- Student: add a course section (S-R22, UC-24/25) ----------
// Throws a RuleError naming the broken rule, or returns the section label if the student may add it.
// $lock serializes this student first, then retains section locks for last-seat safety.
function checkAdd(int $studentId, int $crn, bool $lock): string
{
    if ($lock) lockStudentRegistration($studentId);

    $sec = one("SELECT cs.*, c.course_Name, c.course_Credits, c.course_Type, s.semester_Name,
        CURDATE() BETWEEN s.add_Start AND s.add_End AS add_open, s.add_Start, s.add_End
      FROM Course_Section cs JOIN Course c ON c.course_ID = cs.course_ID JOIN Semester s ON s.semester_ID = cs.semester_ID
      WHERE cs.CRN = ?" . ($lock ? ' FOR UPDATE' : ''), [$crn]);
    if (!$sec) fail('That course section does not exist.');
    $label = "{$sec['course_ID']} {$sec['course_Name']} (CRN {$sec['CRN']})";

    $stu = one("SELECT s.student_Type, s.student_Year, COALESCE(u.undergraduate_Student_Type, g.graduate_Student_Type) AS load_type
      FROM Student s LEFT JOIN Undergraduate_Student u ON u.student_ID = s.student_ID
      LEFT JOIN Graduate_Student g ON g.student_ID = s.student_ID WHERE s.student_ID = ?", [$studentId]);

    if (!$sec['add_open']) fail("The add period for {$sec['semester_Name']} is {$sec['add_Start']} to {$sec['add_End']}.");
    $holds = rows('SELECT h.hold_Type FROM Student_Hold sh JOIN Hold h ON h.hold_ID = sh.hold_ID WHERE sh.student_ID = ?', [$studentId]);
    if ($holds) {
        fail('You have an active ' . implode(' and ', array_map(fn ($h) => strtolower($h['hold_Type']), $holds)) . ' hold.');
    }
    if ($sec['course_Type'] !== $stu['student_Type']) {
        fail($stu['student_Type'] === 'Undergraduate'
            ? 'Undergraduates cannot register for graduate courses.'
            : 'Graduate students cannot register for undergraduate courses.');
    }
    // A course's level (the thousands digit of its number) sets the minimum standing to take it:
    // 1000-level is open to anyone, 2000 needs Sophomore+, 3000 needs Junior+, 4000 needs Senior+.
    if ($sec['course_Type'] === 'Undergraduate') {
        $order = ['Freshman' => 1, 'Sophomore' => 2, 'Junior' => 3, 'Senior' => 4];
        $level = (int) substr(preg_replace('/\D/', '', $sec['course_ID']), 0, 1);
        $minYear = [2 => 'Sophomore', 3 => 'Junior', 4 => 'Senior'][$level] ?? null;
        if ($minYear !== null && ($order[$stu['student_Year']] ?? 0) < $order[$minYear]) {
            fail("$label is a {$level}000-level course; you need to be at least a $minYear (you're a {$stu['student_Year']}).");
        }
    }
    $already = one('SELECT cs.CRN FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN
      WHERE e.student_ID = ? AND cs.course_ID = ? AND cs.semester_ID = ?', [$studentId, $sec['course_ID'], $sec['semester_ID']]);
    if ($already) fail("You are already registered for {$sec['course_ID']} this semester (CRN {$already['CRN']}).");
    $passed = one("SELECT h.grade FROM Student_History h JOIN Course_Section hc ON hc.CRN = h.CRN
      WHERE h.student_ID = ? AND hc.course_ID = ? AND h.grade <> 'F'", [$studentId, $sec['course_ID']]);
    if ($passed) fail("You already passed {$sec['course_ID']} (grade {$passed['grade']}).");
    $inProgress = one('SELECT s.semester_Name FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN
      JOIN Semester s ON s.semester_ID = cs.semester_ID WHERE e.student_ID = ? AND cs.course_ID = ? AND e.grade IS NULL',
        [$studentId, $sec['course_ID']]);
    if ($inProgress) fail("You are already taking {$sec['course_ID']} in {$inProgress['semester_Name']}.");
    $missing = rows('SELECT cp.prerequisite_Course_ID AS id, cp.min_Grade_Req AS min FROM Course_Prerequisite cp
      WHERE cp.course_ID = ? AND NOT EXISTS (
        SELECT 1 FROM Student_History h JOIN Course_Section hc ON hc.CRN = h.CRN
        WHERE h.student_ID = ? AND hc.course_ID = cp.prerequisite_Course_ID
          AND FIELD(h.grade, ' . GRADE_ORDER . ') >= FIELD(cp.min_Grade_Req, ' . GRADE_ORDER . '))', [$sec['course_ID'], $studentId]);
    if ($missing) {
        fail('Missing prerequisite ' . implode(', ', array_map(fn ($m) => "{$m['id']} (grade {$m['min']} or better)", $missing)) . '.');
    }
    if ((int) $sec['available_Seats'] <= 0) fail("$label is full ({$sec['max_Seats']} seats).");
    $credits = (int) one('SELECT COALESCE(SUM(c.course_Credits), 0) AS n FROM Enrollment e
      JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Course c ON c.course_ID = cs.course_ID
      WHERE e.student_ID = ? AND cs.semester_ID = ?', [$studentId, $sec['semester_ID']])['n'];
    $max = MAX_CREDITS[$stu['load_type']];
    $after = $credits + (int) $sec['course_Credits'];
    if ($after > $max) {
        fail("This would bring you to $after credits; the limit for " . strtolower($stu['load_type']) . " students is $max.");
    }
    // Every time slot this section would occupy: its lecture, plus its lab's time slot
    // when it has a separate Course_Section_Lab meeting (the four-credit Biology sections).
    $newSlots = [(int) $sec['time_Slot_ID']];
    $newLab = one('SELECT time_Slot_ID FROM Course_Section_Lab WHERE CRN = ?', [$crn]);
    if ($newLab) $newSlots[] = (int) $newLab['time_Slot_ID'];

    // Clashes against every meeting (lecture or lab) already on the student's schedule (S-F11).
    $clash = one('SELECT m.course_ID, m.CRN FROM Enrollment e JOIN ' . MEETINGS . ' m ON m.CRN = e.CRN
      WHERE e.student_ID = ? AND m.semester_ID = ? AND ' . overlapsAny('m.time_Slot_ID', count($newSlots)),
        [$studentId, $sec['semester_ID'], ...$newSlots]);
    if ($clash) fail("$label meets at the same time as {$clash['course_ID']} (CRN {$clash['CRN']}).");
    return $label;
}

// The reason a student can't add a section, or null if they can. Used to sort the Register page.
function whyCannotAdd(int $studentId, int $crn): ?string
{
    try {
        checkAdd($studentId, $crn, false);
        return null;
    } catch (RuleError $e) {
        return $e->getMessage();
    }
}

function addSection(int $studentId, int $crn): array
{
    return transaction(function () use ($studentId, $crn) {
        $label = checkAdd($studentId, $crn, true);
        run('INSERT INTO Enrollment (student_ID, CRN, grade) VALUES (?, ?, NULL)', [$studentId, $crn]);
        return ['message' => "Added $label."];
    });
}

// ---------- Student: drop a course section (S-R23, UC-26/27) ----------
function dropSection(int $studentId, int $crn): array
{
    return transaction(function () use ($studentId, $crn) {
        lockStudentRegistration($studentId);
        $row = one('SELECT e.grade, cs.course_ID, s.semester_Name, s.drop_Start, s.drop_End,
            CURDATE() BETWEEN s.drop_Start AND s.drop_End AS drop_open
          FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Semester s ON s.semester_ID = cs.semester_ID
          WHERE e.student_ID = ? AND e.CRN = ? FOR UPDATE', [$studentId, $crn]);
        if (!$row) fail('You are not registered for that section.');
        if (!$row['drop_open']) fail("The drop period for {$row['semester_Name']} is {$row['drop_Start']} to {$row['drop_End']}.");
        if ($row['grade'] !== null) fail('A graded section cannot be dropped.');
        run('DELETE FROM Enrollment WHERE student_ID = ? AND CRN = ?', [$studentId, $crn]);
        return ['message' => "Dropped {$row['course_ID']} (CRN $crn)."];
    });
}

// Time_Slot_Day and Time_Slot_Period apply each linked period to every linked day.
// Validate the actual relationships, never a time-slot-number convention.
function validateSectionSchedule(array $course, int $slotId): void
{
    $meetings = rows('SELECT DISTINCT d.day_ID, p.period_ID,
        TIME_TO_SEC(p.start_Time) AS start_seconds, TIME_TO_SEC(p.end_Time) AS end_seconds
      FROM Time_Slot ts
      LEFT JOIN Time_Slot_Day td ON td.time_Slot_ID = ts.time_Slot_ID
      LEFT JOIN Day d ON d.day_ID = td.day_ID
      LEFT JOIN Time_Slot_Period tp ON tp.time_Slot_ID = ts.time_Slot_ID
      LEFT JOIN Period p ON p.period_ID = tp.period_ID
      WHERE ts.time_Slot_ID = ?', [$slotId]);
    $days = [];
    $periods = [];
    if (!$meetings) fail('Choose a time slot with recorded meeting days and valid start/end times.');
    foreach ($meetings as $meeting) {
        if ($meeting['day_ID'] === null || $meeting['period_ID'] === null
            || $meeting['start_seconds'] === null || $meeting['end_seconds'] === null
            || (int) $meeting['end_seconds'] <= (int) $meeting['start_seconds']) {
            fail('Choose a time slot with recorded meeting days and valid start/end times.');
        }
        $days[$meeting['day_ID']] = true;
        $periods[$meeting['period_ID']] = (int) $meeting['end_seconds'] - (int) $meeting['start_seconds'];
    }
    if ($course['course_Type'] !== 'Undergraduate') return;
    if (count($days) < 2) {
        fail("{$course['course_ID']} is undergraduate and must meet on at least two distinct days each week. Choose a different time slot.");
    }
    if ((int) $course['course_Credits'] === 3
        && (count($days) !== 2 || count($periods) !== 1 || reset($periods) !== 80 * 60)) {
        fail("{$course['course_ID']} is a three-credit undergraduate course: choose exactly two meeting days with one 80-minute meeting each day (160 minutes weekly).");
    }
}

// ---------- Admin: create a course section (A-R26, UC-13) ----------
function createSection(int $adminId, array $form): array
{
    return transaction(function () use ($adminId, $form) {
        $admin = one('SELECT security_Level FROM Admin WHERE admin_ID = ?', [$adminId]);
        if (!$admin || $admin['security_Level'] !== 'Read-Write') fail('Read-Only admins cannot create course sections.');

        $capacity = $form['max_Seats'] ?? null;
        // Accept form strings and JSON integers, never coerced floats, booleans, or arrays.
        if ((!is_int($capacity) && !is_string($capacity))
            || !preg_match('/\A(?:[5-9]|10)\z/', (string) $capacity)) {
            fail('Section capacity must be a whole number between 5 and 10 students.');
        }
        $seats = (int) $capacity;
        $sem = one('SELECT semester_ID, semester_Name, start_Date <= CURDATE() AS started FROM Semester WHERE semester_ID = ?',
            [(string) ($form['semester_ID'] ?? '')]);
        if (!$sem) fail('Choose a semester.');
        if ($sem['started']) fail("{$sem['semester_Name']} has already started; sections can only be added to an upcoming semester.");
        $course = one('SELECT course_ID, course_Name, dept_ID, course_Type, course_Credits FROM Course WHERE course_ID = ?', [(string) ($form['course_ID'] ?? '')]);
        if (!$course) fail('Choose a course.');
        $fac = one("SELECT f.faculty_ID, f.faculty_Type, CONCAT(u.first_Name, ' ', u.last_Name) AS name
          FROM Faculty f JOIN User u ON u.user_ID = f.faculty_ID WHERE f.faculty_ID = ?", [(int) ($form['faculty_ID'] ?? 0)]);
        if (!$fac) fail('Choose a faculty member.');
        $slotId = $form['time_Slot_ID'] ?? null;
        if ((!is_int($slotId) && !is_string($slotId)) || !preg_match('/\A[1-9][0-9]*\z/', (string) $slotId)
            || filter_var($slotId, FILTER_VALIDATE_INT) === false) fail('Choose a valid time slot.');
        $slot = one('SELECT time_Slot_ID FROM Time_Slot WHERE time_Slot_ID = ?', [(int) $slotId]);
        if (!$slot) fail('Choose a time slot.');
        validateSectionSchedule($course, (int) $slot['time_Slot_ID']);
        // Capacity comes from whichever subtype table the room matches (Lecture.seats or Lab.work_Stations);
        // an Office cannot hold a class, so only Lecture/Lab rooms are eligible.
        $room = one("SELECT r.room_ID, COALESCE(lab.work_Stations, lec.seats) AS capacity
          FROM Room r LEFT JOIN Lab lab ON lab.lab_ID = r.room_ID LEFT JOIN Lecture lec ON lec.lecture_ID = r.room_ID
          WHERE r.room_ID = ? AND r.room_Type IN ('Lecture', 'Lab')", [(string) ($form['room_ID'] ?? '')]);
        if (!$room) fail('Choose a room.');
        if ($room['capacity'] < $seats) fail("Room {$room['room_ID']} only holds {$room['capacity']}.");

        $member = one('SELECT 1 AS ok FROM Faculty_Department WHERE faculty_ID = ? AND dept_ID = ?', [$fac['faculty_ID'], $course['dept_ID']]);
        if (!$member) fail("{$fac['name']} is not a member of the {$course['dept_ID']} department.");
        // Lock this faculty member's sections for the semester while checking the teaching load.
        $teaching = rows('SELECT CRN FROM Course_Section WHERE faculty_ID = ? AND semester_ID = ? FOR UPDATE',
            [$fac['faculty_ID'], $sem['semester_ID']]);
        if (count($teaching) >= TEACH_LOAD[$fac['faculty_Type']]) {
            fail("{$fac['name']} (" . strtolower($fac['faculty_Type']) . ') already teaches ' . count($teaching)
                . " section(s) in {$sem['semester_Name']}.");
        }
        // The teacher and the room must be free at that time, counting lab meetings too (A-F5, A-F6).
        $facClash = one('SELECT m.CRN, m.course_ID FROM ' . MEETINGS . ' m WHERE m.faculty_ID = ? AND m.semester_ID = ? AND '
            . overlapsAny('m.time_Slot_ID', 1), [$fac['faculty_ID'], $sem['semester_ID'], $slot['time_Slot_ID']]);
        if ($facClash) fail("{$fac['name']} already teaches {$facClash['course_ID']} (CRN {$facClash['CRN']}) at that time.");
        // Lock the room's sections for the semester first, so two admins can't book it at the same time.
        rows('SELECT CRN FROM Course_Section WHERE room_ID = ? AND semester_ID = ? FOR UPDATE', [$room['room_ID'], $sem['semester_ID']]);
        $roomClash = one('SELECT m.CRN, m.course_ID FROM ' . MEETINGS . ' m WHERE m.room_ID = ? AND m.semester_ID = ? AND '
            . overlapsAny('m.time_Slot_ID', 1), [$room['room_ID'], $sem['semester_ID'], $slot['time_Slot_ID']]);
        if ($roomClash) fail("Room {$room['room_ID']} is already used by {$roomClash['course_ID']} (CRN {$roomClash['CRN']}) at that time.");

        $crn = (int) one('SELECT COALESCE(MAX(CRN), 10000) + 1 AS crn FROM Course_Section FOR UPDATE')['crn'];
        $n = (int) one('SELECT COUNT(*) AS n FROM Course_Section WHERE course_ID = ? AND semester_ID = ?',
            [$course['course_ID'], $sem['semester_ID']])['n'];
        $sectionNo = str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT);
        run('INSERT INTO Course_Section (CRN, course_ID, section_No, faculty_ID, time_Slot_ID, room_ID, semester_ID, max_Seats)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$crn, $course['course_ID'], $sectionNo, $fac['faculty_ID'], $slot['time_Slot_ID'], $room['room_ID'], $sem['semester_ID'], $seats]);
        return ['message' => "Created {$course['course_ID']} section $sectionNo (CRN $crn) for {$sem['semester_Name']}, taught by {$fac['name']}."];
    });
}

// A letter grade in Enrollment.grade/Student_History.grade, newest earned first (matches GRADE_ORDER).
const VALID_GRADES = ['A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'F'];

// A weekday letter for today, or null on a weekend (no section ever meets then).
function todayLetter(): ?string
{
    return [1 => 'M', 2 => 'T', 3 => 'W', 4 => 'R', 5 => 'F'][(int) date('N')] ?? null;
}

// ---------- Faculty: take attendance for one student, in one of their own sections, today ----------
function recordAttendance(int $facultyId, array $form): array
{
    $crn = registrationCrn($form['CRN'] ?? null);
    $studentId = filter_var($form['student_ID'] ?? null, FILTER_VALIDATE_INT);
    if ($studentId === false) fail('Choose a valid student.');
    $status = (string) ($form['status'] ?? '');
    if (!in_array($status, ['Present', 'Absent'], true)) fail('Choose Present or Absent.');

    return transaction(function () use ($facultyId, $crn, $studentId, $status) {
        $sec = one('SELECT s.start_Date, s.end_Date FROM Course_Section cs JOIN Semester s ON s.semester_ID = cs.semester_ID
          WHERE cs.CRN = ? AND cs.faculty_ID = ?', [$crn, $facultyId]);
        if (!$sec) fail('That is not one of your sections.');
        if (!one('SELECT 1 AS ok FROM Enrollment WHERE CRN = ? AND student_ID = ?', [$crn, $studentId])) {
            fail('That student is not enrolled in this section.');
        }
        $today = date('Y-m-d');
        if ($today < $sec['start_Date'] || $today > $sec['end_Date']) {
            fail("Today is outside {$sec['start_Date']} to {$sec['end_Date']}, this section's semester; attendance can only be taken while it is in session.");
        }
        $letter = todayLetter();
        // Checked against every meeting this CRN has today (lecture or, for a four-credit
        // Biology section, its separate lab), the same way a time clash is checked elsewhere.
        $meetsToday = $letter !== null && one('SELECT 1 AS ok FROM ' . MEETINGS . ' m JOIN Time_Slot_Day d ON d.time_Slot_ID = m.time_Slot_ID
          WHERE m.CRN = ? AND d.day_ID = ?', [$crn, $letter]);
        if (!$meetsToday) fail('This section does not meet today; attendance can only be taken on a day it is scheduled to meet.');
        run('INSERT INTO Attendance (student_ID, CRN, attendance_Date, attendance_Status) VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE attendance_Status = VALUES(attendance_Status)', [$studentId, $crn, $today, $status]);
        return ['message' => "Attendance saved: student $studentId marked $status for today."];
    });
}

// ---------- Faculty: record/assign a grade for one student in one of their own sections ----------
function recordGrade(int $facultyId, array $form): array
{
    $crn = registrationCrn($form['CRN'] ?? null);
    $studentId = filter_var($form['student_ID'] ?? null, FILTER_VALIDATE_INT);
    if ($studentId === false) fail('Choose a valid student.');
    $grade = (string) ($form['grade'] ?? '');
    if (!in_array($grade, VALID_GRADES, true)) fail('Choose a valid letter grade.');

    return transaction(function () use ($facultyId, $crn, $studentId, $grade) {
        $sec = one('SELECT s.semester_ID, s.semester_Name, s.grade_Start, s.grade_End,
            CURDATE() BETWEEN s.grade_Start AND s.grade_End AS grading_open
          FROM Course_Section cs JOIN Semester s ON s.semester_ID = cs.semester_ID
          WHERE cs.CRN = ? AND cs.faculty_ID = ? FOR UPDATE', [$crn, $facultyId]);
        if (!$sec) fail('That is not one of your sections.');
        if (!one('SELECT 1 AS ok FROM Enrollment WHERE CRN = ? AND student_ID = ?', [$crn, $studentId])) {
            fail('That student is not enrolled in this section.');
        }
        if (!$sec['grading_open']) {
            fail("The grading window for {$sec['semester_Name']} is {$sec['grade_Start']} to {$sec['grade_End']}; no grades can be saved outside it.");
        }
        run('UPDATE Enrollment SET grade = ? WHERE CRN = ? AND student_ID = ?', [$grade, $crn, $studentId]);
        // Student_History is the permanent transcript/prerequisite record; Enrollment.grade is the
        // live value for the section the student is currently in. Keep both in sync.
        run('INSERT INTO Student_History (student_ID, CRN, semester_ID, grade) VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE grade = VALUES(grade)', [$studentId, $crn, $sec['semester_ID'], $grade]);
        return ['message' => "Grade saved: student $studentId received $grade."];
    });
}
