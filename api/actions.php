<?php
// Actions that change the database: a student adds or drops a course section,
// and an admin creates a course section. Every SRS rule is checked before anything is
// written; a broken rule throws a RuleError explaining why.
declare(strict_types=1);

const GRADE_ORDER = "'F','D','D+','C-','C','C+','B-','B','B+','A-','A'";
const MAX_CREDITS = ['Full-time' => 16, 'Part-time' => 8];  // S-F7, S-F8
const TEACH_LOAD = ['Full-time' => 2, 'Part-time' => 1];    // A-F7, A-F8

// Sections that meet on a shared day in the same period clash.
const CLASH = "EXISTS (SELECT 1 FROM Time_Slot_Day d1 JOIN Time_Slot_Day d2 ON d2.day_ID = d1.day_ID
    JOIN Time_Slot_Period p1 ON p1.time_Slot_ID = d1.time_Slot_ID
    JOIN Time_Slot_Period p2 ON p2.time_Slot_ID = d2.time_Slot_ID AND p2.period_ID = p1.period_ID
  WHERE d1.time_Slot_ID = cs.time_Slot_ID AND d2.time_Slot_ID = ?)";

// ---------- Student: add a course section (S-R22, UC-24/25) ----------
// Throws a RuleError naming the broken rule, or returns the section label if the student may add it.
// $lock locks the section row so two students can't take the last seat at the same time.
function checkAdd(int $studentId, int $crn, bool $lock): string
{
    $sec = one("SELECT cs.*, c.course_Name, c.course_Credits, c.course_Type, s.semester_Name,
        CURDATE() BETWEEN s.add_Start AND s.add_End AS add_open, s.add_Start, s.add_End
      FROM Course_Section cs JOIN Course c ON c.course_ID = cs.course_ID JOIN Semester s ON s.semester_ID = cs.semester_ID
      WHERE cs.CRN = ?" . ($lock ? ' FOR UPDATE' : ''), [$crn]);
    if (!$sec) fail('That course section does not exist.');
    $label = "{$sec['course_ID']} {$sec['course_Name']} (CRN {$sec['CRN']})";

    $stu = one("SELECT s.student_Type, COALESCE(u.undergraduate_Student_Type, g.graduate_Student_Type) AS load_type
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
    $taken = (int) one('SELECT COUNT(*) AS n FROM Enrollment WHERE CRN = ?', [$crn])['n'];
    if ($taken >= $sec['max_Seats']) fail("$label is full ({$sec['max_Seats']} seats).");
    $credits = (int) one('SELECT COALESCE(SUM(c.course_Credits), 0) AS n FROM Enrollment e
      JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Course c ON c.course_ID = cs.course_ID
      WHERE e.student_ID = ? AND cs.semester_ID = ?', [$studentId, $sec['semester_ID']])['n'];
    $max = MAX_CREDITS[$stu['load_type']];
    $after = $credits + (int) $sec['course_Credits'];
    if ($after > $max) {
        fail("This would bring you to $after credits; the limit for " . strtolower($stu['load_type']) . " students is $max.");
    }
    $clash = one('SELECT cs.course_ID, cs.CRN FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN
      WHERE e.student_ID = ? AND cs.semester_ID = ? AND ' . CLASH, [$studentId, $sec['semester_ID'], $sec['time_Slot_ID']]);
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

// ---------- Admin: create a course section (A-R26, UC-13) ----------
function createSection(int $adminId, array $form): array
{
    return transaction(function () use ($adminId, $form) {
        $admin = one('SELECT security_Level FROM Admin WHERE admin_ID = ?', [$adminId]);
        if (!$admin || $admin['security_Level'] !== 'Read-Write') fail('Read-Only admins cannot create course sections.');

        $seats = filter_var($form['max_Seats'] ?? null, FILTER_VALIDATE_INT);
        if ($seats === false || $seats < 1 || $seats > 10) fail('A section holds 1 to 10 students.');
        $sem = one('SELECT semester_ID, semester_Name, start_Date <= CURDATE() AS started FROM Semester WHERE semester_ID = ?',
            [(string) ($form['semester_ID'] ?? '')]);
        if (!$sem) fail('Choose a semester.');
        if ($sem['started']) fail("{$sem['semester_Name']} has already started; sections can only be added to an upcoming semester.");
        $course = one('SELECT course_ID, course_Name, dept_ID FROM Course WHERE course_ID = ?', [(string) ($form['course_ID'] ?? '')]);
        if (!$course) fail('Choose a course.');
        $fac = one("SELECT f.faculty_ID, f.faculty_Type, CONCAT(u.first_Name, ' ', u.last_Name) AS name
          FROM Faculty f JOIN User u ON u.user_ID = f.faculty_ID WHERE f.faculty_ID = ?", [(int) ($form['faculty_ID'] ?? 0)]);
        if (!$fac) fail('Choose a faculty member.');
        $slot = one('SELECT time_Slot_ID FROM Time_Slot WHERE time_Slot_ID = ?', [(int) ($form['time_Slot_ID'] ?? 0)]);
        if (!$slot) fail('Choose a time slot.');
        $room = one('SELECT room_ID, capacity FROM Room WHERE room_ID = ?', [(string) ($form['room_ID'] ?? '')]);
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
        $facClash = one('SELECT cs.CRN, cs.course_ID FROM Course_Section cs WHERE cs.faculty_ID = ? AND cs.semester_ID = ? AND ' . CLASH,
            [$fac['faculty_ID'], $sem['semester_ID'], $slot['time_Slot_ID']]);
        if ($facClash) fail("{$fac['name']} already teaches {$facClash['course_ID']} (CRN {$facClash['CRN']}) at that time.");
        $roomClash = one('SELECT cs.CRN, cs.course_ID FROM Course_Section cs WHERE cs.room_ID = ? AND cs.semester_ID = ? AND ' . CLASH . ' FOR UPDATE',
            [$room['room_ID'], $sem['semester_ID'], $slot['time_Slot_ID']]);
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
