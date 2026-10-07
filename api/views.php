<?php
// Read-only dashboard pages for each role. Every page returns
// ['title', 'subtitle', 'blocks'], where a block is a row of stat cards, a set of
// filters, a form or a table. The browser draws them (public/login.js).
declare(strict_types=1);

// A table block: column names come from the query's column aliases.
function table(string $title, string $sql, array $params = [], ?string $note = null): array
{
    $stmt = run($sql, $params);
    $columns = [];
    for ($i = 0; $i < $stmt->columnCount(); $i++) $columns[] = $stmt->getColumnMeta($i)['name'];
    return ['type' => 'table', 'title' => $title, 'note' => $note, 'columns' => $columns, 'rows' => $stmt->fetchAll(PDO::FETCH_NUM)];
}

function stats(array $items): array
{
    return ['type' => 'stats', 'items' => array_map(fn ($i) => ['label' => $i[0], 'value' => $i[1] ?? '—'], $items)];
}

// The current semester is the latest one that has started; the next is the first one that hasn't.
function currentSemester(): array
{
    return one('SELECT semester_ID AS id, semester_Name AS name FROM Semester WHERE start_Date <= CURDATE() ORDER BY start_Date DESC LIMIT 1') ?? [];
}
function nextSemester(): array
{
    return one('SELECT semester_ID AS id, semester_Name AS name FROM Semester WHERE start_Date > CURDATE() ORDER BY start_Date LIMIT 1') ?? [];
}

// Columns shared by every list of course sections.
const SECTION_COLUMNS = "cs.CRN, cs.course_ID AS Course, c.course_Name AS Title, cs.section_No AS Section, c.course_Credits AS Credits,
  (SELECT GROUP_CONCAT(d.day_ID ORDER BY FIELD(d.day_ID,'M','T','W','R','F') SEPARATOR '')
     FROM Time_Slot_Day d WHERE d.time_Slot_ID = cs.time_Slot_ID) AS Days,
  CONCAT(TIME_FORMAT(p.start_Time, '%l:%i %p'), ' – ', TIME_FORMAT(p.end_Time, '%l:%i %p')) AS Time,
  CONCAT(b.building_Name, ' ', r.room_No) AS Room";
const SECTION_JOINS = 'JOIN Course c ON c.course_ID = cs.course_ID
  JOIN Time_Slot_Period tp ON tp.time_Slot_ID = cs.time_Slot_ID
  JOIN Period p ON p.period_ID = tp.period_ID
  JOIN Room r ON r.room_ID = cs.room_ID
  JOIN Building b ON b.building_ID = r.building_ID
  LEFT JOIN User fu ON fu.user_ID = cs.faculty_ID';
const INSTRUCTOR = "CONCAT(fu.first_Name, ' ', fu.last_Name) AS Instructor";
const SEATS = 'cs.max_Seats - (SELECT COUNT(*) FROM Enrollment e2 WHERE e2.CRN = cs.CRN) AS `Open seats`';
const ORDER_BY_TIME = "ORDER BY FIELD(LEFT(Days, 1),'M','T','W','R','F'), p.start_Time";
const GPA_POINTS = "CASE h.grade WHEN 'A' THEN 4 WHEN 'A-' THEN 3.7 WHEN 'B+' THEN 3.3 WHEN 'B' THEN 3 WHEN 'B-' THEN 2.7
  WHEN 'C+' THEN 2.3 WHEN 'C' THEN 2 WHEN 'C-' THEN 1.7 WHEN 'D+' THEN 1.3 WHEN 'D' THEN 1 ELSE 0 END";

// One student's sections in one semester.
function studentSchedule(string $title, int $studentId, string $semesterId, ?string $note = null): array
{
    return table($title, 'SELECT ' . SECTION_COLUMNS . ', ' . INSTRUCTOR . ' FROM Enrollment e
      JOIN Course_Section cs ON cs.CRN = e.CRN ' . SECTION_JOINS . '
      WHERE e.student_ID = ? AND cs.semester_ID = ? ' . ORDER_BY_TIME, [$studentId, $semesterId], $note);
}

function departments(): array
{
    return rows('SELECT dept_ID, dept_Name FROM Department ORDER BY dept_Name');
}

// Semester (and optional department) filters, validated against the database.
function semesterFilter(array $q, bool $withDept = true): array
{
    $sems = rows('SELECT semester_ID, semester_Name FROM Semester ORDER BY start_Date');
    $depts = departments();
    $semester = in_array($q['semester'] ?? null, array_column($sems, 'semester_ID'), true) ? $q['semester'] : currentSemester()['id'];
    $deptId = in_array($q['dept'] ?? null, array_column($depts, 'dept_ID'), true) ? $q['dept'] : '';
    $filters = [['name' => 'semester', 'label' => 'Semester', 'value' => $semester,
        'options' => array_map(fn ($s) => [$s['semester_ID'], $s['semester_Name']], $sems)]];
    if ($withDept) {
        $filters[] = ['name' => 'dept', 'label' => 'Department', 'value' => $deptId,
            'options' => array_merge([['', 'All departments']], array_map(fn ($d) => [$d['dept_ID'], $d['dept_Name']], $depts))];
    }
    return [$semester, $deptId, ['type' => 'filters', 'filters' => $filters]];
}

function shortDate(string $date): string
{
    return date('M j, Y', strtotime($date));
}

// ---------------- Student ----------------

function viewOverview(array $u, array $q): array
{
    $s = one('SELECT s.student_Year, s.student_Type,
        COALESCE(ug.undergraduate_Student_Type, g.graduate_Student_Type) AS load_type,
        COALESCE(ft.credits_Earned, pt.credits_Earned, fg.credits_Earned, pg.credits_Earned) AS credits,
        COALESCE(ft.status, pt.status) AS status,
        (SELECT GROUP_CONCAT(m.major_Name SEPARATOR \', \') FROM Student_Major sm JOIN Major m ON m.major_ID = sm.major_ID WHERE sm.student_ID = s.student_ID) AS majors,
        (SELECT GROUP_CONCAT(m.minor_Name SEPARATOR \', \') FROM Student_Minor sm JOIN Minor m ON m.minor_ID = sm.minor_ID WHERE sm.student_ID = s.student_ID) AS minors
      FROM Student s
      LEFT JOIN Undergraduate_Student ug ON ug.student_ID = s.student_ID
      LEFT JOIN Graduate_Student g ON g.student_ID = s.student_ID
      LEFT JOIN Full_Time_Undergraduate ft ON ft.student_ID = s.student_ID
      LEFT JOIN Part_Time_Undergraduate pt ON pt.student_ID = s.student_ID
      LEFT JOIN Full_Time_Graduate_Student fg ON fg.student_ID = s.student_ID
      LEFT JOIN Part_Time_Graduate_Student pg ON pg.student_ID = s.student_ID
      WHERE s.student_ID = ?', [$u['id']]);
    $gpa = one('SELECT ROUND(SUM(' . GPA_POINTS . ' * c.course_Credits) / SUM(c.course_Credits), 2) AS gpa
      FROM Student_History h JOIN Course_Section cs ON cs.CRN = h.CRN JOIN Course c ON c.course_ID = cs.course_ID
      WHERE h.student_ID = ?', [$u['id']]);
    $cur = currentSemester();
    $now = one('SELECT COUNT(*) AS n, COALESCE(SUM(c.course_Credits), 0) AS credits FROM Enrollment e
      JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Course c ON c.course_ID = cs.course_ID
      WHERE e.student_ID = ? AND cs.semester_ID = ?', [$u['id'], $cur['id']]);
    $holds = one('SELECT COUNT(*) AS n FROM Student_Hold WHERE student_ID = ?', [$u['id']]);
    return [
        'title' => "Welcome, {$u['name']}",
        'subtitle' => "{$s['student_Type']} · {$s['student_Year']} · {$s['load_type']}" . ($s['status'] ? " · {$s['status']}" : ''),
        'blocks' => [
            stats([['Major', $s['majors']], ['Minor', $s['minors'] ?: 'None'], ['GPA', $gpa['gpa']], ['Credits earned', $s['credits']],
                ["{$cur['name']} classes", "{$now['n']} ({$now['credits']} credits)"], ['Active holds', $holds['n']]]),
            studentSchedule("{$cur['name']} schedule", $u['id'], $cur['id']),
        ],
    ];
}

function viewSchedule(array $u, array $q): array
{
    $cur = currentSemester();
    $next = nextSemester();
    $blocks = [studentSchedule("{$cur['name']} (current semester)", $u['id'], $cur['id'])];
    if ($next) $blocks[] = studentSchedule("{$next['name']} (next semester)", $u['id'], $next['id'], 'Add or drop sections on the Register page.');
    return ['title' => 'My schedule', 'blocks' => $blocks];
}

function viewTranscript(array $u, array $q): array
{
    $gpa = one("SELECT ROUND(SUM(" . GPA_POINTS . " * c.course_Credits) / SUM(c.course_Credits), 2) AS gpa,
        SUM(IF(h.grade <> 'F', c.course_Credits, 0)) AS earned, COUNT(*) AS courses
      FROM Student_History h JOIN Course_Section cs ON cs.CRN = h.CRN JOIN Course c ON c.course_ID = cs.course_ID
      WHERE h.student_ID = ?", [$u['id']]);
    return ['title' => 'Unofficial transcript', 'blocks' => [
        stats([['Cumulative GPA', $gpa['gpa']], ['Credits earned', $gpa['earned']], ['Courses completed', $gpa['courses']]]),
        table('Completed courses', 'SELECT sem.semester_Name AS Semester, cs.course_ID AS Course, c.course_Name AS Title,
            c.course_Credits AS Credits, h.grade AS Grade
          FROM Student_History h JOIN Course_Section cs ON cs.CRN = h.CRN JOIN Course c ON c.course_ID = cs.course_ID
          JOIN Semester sem ON sem.semester_ID = h.semester_ID
          WHERE h.student_ID = ? ORDER BY sem.start_Date, cs.course_ID', [$u['id']]),
    ]];
}

function viewHoldsAdvisors(array $u, array $q): array
{
    return ['title' => 'Holds & advisors', 'blocks' => [
        table('Active holds', "SELECT h.hold_Type AS Hold, DATE_FORMAT(sh.hold_Date, '%b %e, %Y') AS `Placed on`
          FROM Student_Hold sh JOIN Hold h ON h.hold_ID = sh.hold_ID WHERE sh.student_ID = ?", [$u['id']],
            'A student with an active hold cannot register.'),
        table('My advisors', "SELECT CONCAT(u.first_Name, ' ', u.last_Name) AS Advisor, f.`rank` AS `Rank`,
            (SELECT GROUP_CONCAT(d.dept_Name SEPARATOR ', ') FROM Faculty_Department fd JOIN Department d ON d.dept_ID = fd.dept_ID WHERE fd.faculty_ID = f.faculty_ID) AS Department,
            l.user_Email AS Email, CONCAT(o.building_ID, '-', o.room_No) AS Office
          FROM Advisor a JOIN Faculty f ON f.faculty_ID = a.faculty_ID JOIN User u ON u.user_ID = f.faculty_ID
          JOIN Login l ON l.user_ID = f.faculty_ID LEFT JOIN Office o ON o.office_ID = f.office_ID
          WHERE a.student_ID = ?", [$u['id']]),
    ]];
}

// Registration (S-R22, S-R23): add and drop sections for a semester whose add period is open.
function viewRegister(array $u, array $q): array
{
    $open = rows('SELECT semester_ID, semester_Name, add_Start, add_End, drop_Start, drop_End FROM Semester
      WHERE CURDATE() BETWEEN add_Start AND add_End ORDER BY start_Date');
    if (!$open) return ['title' => 'Register', 'subtitle' => 'No semester is open for registration right now.', 'blocks' => []];
    $sem = end($open);
    foreach ($open as $o) if ($o['semester_ID'] === ($q['semester'] ?? null)) $sem = $o;
    $depts = departments();
    $me = one('SELECT COALESCE(ug.dept_ID, g.dept_ID) AS dept, COALESCE(ug.undergraduate_Student_Type, g.graduate_Student_Type) AS load_type,
        s.student_Type FROM Student s LEFT JOIN Undergraduate_Student ug ON ug.student_ID = s.student_ID
        LEFT JOIN Graduate_Student g ON g.student_ID = s.student_ID WHERE s.student_ID = ?', [$u['id']]);
    $deptId = in_array($q['dept'] ?? null, array_column($depts, 'dept_ID'), true) ? $q['dept'] : $me['dept'];
    $credits = one('SELECT COALESCE(SUM(c.course_Credits), 0) AS n FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN
      JOIN Course c ON c.course_ID = cs.course_ID WHERE e.student_ID = ? AND cs.semester_ID = ?', [$u['id'], $sem['semester_ID']]);
    $holds = one("SELECT GROUP_CONCAT(h.hold_Type SEPARATOR ', ') AS list FROM Student_Hold sh JOIN Hold h ON h.hold_ID = sh.hold_ID
      WHERE sh.student_ID = ?", [$u['id']]);

    $mine = studentSchedule("My {$sem['semester_Name']} registration", $u['id'], $sem['semester_ID']);
    $mine['action'] = ['label' => 'Drop', 'endpoint' => 'drop-section', 'column' => 'CRN', 'style' => 'danger'];
    $offered = table('Sections you can add', 'SELECT ' . SECTION_COLUMNS . ', ' . INSTRUCTOR . ', ' . SEATS . ",
        COALESCE((SELECT GROUP_CONCAT(cp.prerequisite_Course_ID SEPARATOR ', ') FROM Course_Prerequisite cp WHERE cp.course_ID = c.course_ID), '—') AS Prerequisites
      FROM Course_Section cs " . SECTION_JOINS . '
      WHERE cs.semester_ID = ? AND c.dept_ID = ? AND c.course_Type = ?
        AND cs.CRN NOT IN (SELECT CRN FROM Enrollment WHERE student_ID = ?)
      ORDER BY cs.course_ID, cs.section_No', [$sem['semester_ID'], $deptId, $me['student_Type'], $u['id']],
        'These sections pass every registration rule for you: holds, prerequisites, seats, credit limit, time conflicts and courses already passed.');

    // Split the department's sections with the same rule check the Add button runs.
    $crnAt = array_search('CRN', $offered['columns'], true);
    $keep = array_map(fn ($c) => array_search($c, $offered['columns'], true), ['CRN', 'Course', 'Title', 'Section']);
    $canAdd = [];
    $cannot = [];
    foreach ($offered['rows'] as $row) {
        $reason = whyCannotAdd($u['id'], (int) $row[$crnAt]);
        if ($reason === null) $canAdd[] = $row;
        else $cannot[] = [...array_map(fn ($k) => $row[$k], $keep), $reason];
    }
    $available = array_merge($offered, ['rows' => $canAdd, 'action' => ['label' => 'Add', 'endpoint' => 'add-section', 'column' => 'CRN']]);
    $blocked = ['type' => 'table', 'title' => "Sections you can't add",
        'note' => 'Each one breaks a registration rule. Try sends the request anyway, so you can see it refused.',
        'columns' => [...array_map(fn ($k) => $offered['columns'][$k], $keep), 'Reason'], 'rows' => $cannot,
        'action' => ['label' => 'Try', 'endpoint' => 'add-section', 'column' => 'CRN', 'style' => 'secondary']];

    return ['title' => "Register for {$sem['semester_Name']}", 'subtitle' => 'Add and drop course sections.', 'blocks' => [
        ['type' => 'filters', 'filters' => [
            ['name' => 'semester', 'label' => 'Semester', 'value' => $sem['semester_ID'],
                'options' => array_map(fn ($x) => [$x['semester_ID'], $x['semester_Name']], $open)],
            ['name' => 'dept', 'label' => 'Department', 'value' => $deptId,
                'options' => array_map(fn ($d) => [$d['dept_ID'], $d['dept_Name']], $depts)],
        ]],
        stats([['Registered credits', "{$credits['n']} of " . ($me['load_type'] === 'Full-time' ? 16 : 8)], ['Active holds', $holds['list'] ?: 'None'],
            ['Add period', shortDate($sem['add_Start']) . ' – ' . shortDate($sem['add_End'])],
            ['Drop period', shortDate($sem['drop_Start']) . ' – ' . shortDate($sem['drop_End'])]]),
        $mine,
        $available,
        $blocked,
    ]];
}

// ---------------- Faculty ----------------

function viewTeaching(array $u, array $q): array
{
    $f = one("SELECT f.`rank`, f.faculty_Type, f.specialty,
        (SELECT GROUP_CONCAT(CONCAT(d.dept_Name, ' (', fd.percent_Time, '%)') SEPARATOR ', ') FROM Faculty_Department fd
          JOIN Department d ON d.dept_ID = fd.dept_ID WHERE fd.faculty_ID = f.faculty_ID) AS depts,
        CONCAT(o.building_ID, '-', o.room_No) AS office
      FROM Faculty f LEFT JOIN Office o ON o.office_ID = f.office_ID WHERE f.faculty_ID = ?", [$u['id']]);
    $cur = currentSemester();
    $next = nextSemester();
    $advisees = one('SELECT COUNT(*) AS n FROM Advisor WHERE faculty_ID = ?', [$u['id']]);
    $mine = rows('SELECT cs.CRN, cs.course_ID FROM Course_Section cs WHERE cs.faculty_ID = ? AND cs.semester_ID = ? ORDER BY cs.CRN',
        [$u['id'], $cur['id']]);
    $blocks = [
        stats([['Rank', $f['rank']], ['Type', $f['faculty_Type']], ['Department(s)', $f['depts']], ['Office', $f['office']],
            ["{$cur['name']} sections", count($mine)], ['Advisees', $advisees['n']]]),
        table("{$cur['name']} teaching schedule", 'SELECT ' . SECTION_COLUMNS . ', ' . SEATS . ' FROM Course_Section cs ' . SECTION_JOINS . '
          WHERE cs.faculty_ID = ? AND cs.semester_ID = ? ' . ORDER_BY_TIME, [$u['id'], $cur['id']]),
    ];
    foreach ($mine as $s) {
        $blocks[] = table("Roster: {$s['course_ID']} (CRN {$s['CRN']})", "SELECT u.user_ID AS `Student ID`,
            CONCAT(u.first_Name, ' ', u.last_Name) AS Student, st.student_Year AS Year, l.user_Email AS Email,
            CONCAT(ROUND(100 * AVG(a.attendance_Status = 'Present')), '%') AS Attendance
          FROM Enrollment e JOIN User u ON u.user_ID = e.student_ID JOIN Student st ON st.student_ID = e.student_ID
          JOIN Login l ON l.user_ID = e.student_ID
          LEFT JOIN Attendance a ON a.CRN = e.CRN AND a.student_ID = e.student_ID
          WHERE e.CRN = ? GROUP BY u.user_ID, st.student_Year, l.user_Email ORDER BY u.last_Name", [$s['CRN']]);
    }
    if ($next) {
        $blocks[] = table("{$next['name']} teaching schedule", 'SELECT ' . SECTION_COLUMNS . ' FROM Course_Section cs ' . SECTION_JOINS . '
          WHERE cs.faculty_ID = ? AND cs.semester_ID = ? ' . ORDER_BY_TIME, [$u['id'], $next['id']]);
    }
    return ['title' => "Welcome, {$u['name']}", 'subtitle' => $f['specialty'] ? "Specialty: {$f['specialty']}" : '', 'blocks' => $blocks];
}

function viewAdvisees(array $u, array $q): array
{
    return ['title' => 'My advisees', 'subtitle' => 'Full-time faculty advise at most 15 students.', 'blocks' => [
        table('Advisees', "SELECT st.student_ID AS `Student ID`, CONCAT(u.first_Name, ' ', u.last_Name) AS Student,
            st.student_Type AS Level, st.student_Year AS Year,
            (SELECT GROUP_CONCAT(m.major_Name SEPARATOR ', ') FROM Student_Major sm JOIN Major m ON m.major_ID = sm.major_ID WHERE sm.student_ID = st.student_ID) AS Major,
            (SELECT GROUP_CONCAT(h.hold_Type SEPARATOR ', ') FROM Student_Hold sh JOIN Hold h ON h.hold_ID = sh.hold_ID WHERE sh.student_ID = st.student_ID) AS Holds,
            DATE_FORMAT(a.date_Of_Appointment, '%b %e, %Y') AS `Advising since`
          FROM Advisor a JOIN Student st ON st.student_ID = a.student_ID JOIN User u ON u.user_ID = st.student_ID
          WHERE a.faculty_ID = ? ORDER BY u.last_Name", [$u['id']]),
    ]];
}

// ---------------- Admin ----------------

function viewAdminOverview(array $u, array $q): array
{
    $a = one('SELECT security_Level FROM Admin WHERE admin_ID = ?', [$u['id']]);
    $cur = currentSemester();
    $c = one('SELECT
        (SELECT COUNT(*) FROM Student) AS students, (SELECT COUNT(*) FROM Faculty) AS faculty,
        (SELECT COUNT(*) FROM Department) AS depts, (SELECT COUNT(*) FROM Course) AS courses,
        (SELECT COUNT(*) FROM Course_Section WHERE semester_ID = ?) AS sections,
        (SELECT COUNT(*) FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN WHERE cs.semester_ID = ?) AS enrollments,
        (SELECT COUNT(DISTINCT student_ID) FROM Student_Hold) AS holds,
        (SELECT COUNT(*) FROM Login WHERE lock_Var = 1) AS locked', [$cur['id'], $cur['id']]);
    return ['title' => "Welcome, {$u['name']}", 'subtitle' => "{$a['security_Level']} administrator", 'blocks' => [
        stats([['Students', $c['students']], ['Faculty', $c['faculty']], ['Departments', $c['depts']], ['Courses', $c['courses']],
            ["{$cur['name']} sections", $c['sections']], ["{$cur['name']} enrollments", $c['enrollments']],
            ['Students with holds', $c['holds']], ['Locked accounts', $c['locked']]]),
        table('Departments', "SELECT d.dept_ID AS ID, d.dept_Name AS Department, CONCAT(u.first_Name, ' ', u.last_Name) AS Chair,
            d.dept_Manager AS Manager, d.phone_No AS Phone, d.email AS Email,
            (SELECT COUNT(*) FROM Faculty_Department fd WHERE fd.dept_ID = d.dept_ID) AS Faculty,
            (SELECT COUNT(*) FROM Course c WHERE c.dept_ID = d.dept_ID) AS Courses
          FROM Department d LEFT JOIN User u ON u.user_ID = d.chair_ID ORDER BY d.dept_Name"),
    ]];
}

function viewHolds(array $u, array $q): array
{
    return ['title' => 'Student holds', 'blocks' => [
        table('Active holds', "SELECT sh.student_ID AS `Student ID`, CONCAT(u.first_Name, ' ', u.last_Name) AS Student,
            h.hold_Type AS Hold, DATE_FORMAT(sh.hold_Date, '%b %e, %Y') AS `Placed on`
          FROM Student_Hold sh JOIN Hold h ON h.hold_ID = sh.hold_ID JOIN User u ON u.user_ID = sh.student_ID
          ORDER BY sh.hold_Date DESC"),
    ]];
}

function viewAudit(array $u, array $q): array
{
    return ['title' => 'Audit log', 'subtitle' => 'Every admin update to user information.', 'blocks' => [
        table('Entries', "SELECT l.log_ID AS `#`, DATE_FORMAT(l.date_Time, '%b %e, %Y %l:%i %p') AS `Date & time`,
            CONCAT(au.first_Name, ' ', au.last_Name) AS Admin, CONCAT(uu.first_Name, ' ', uu.last_Name, ' (', l.user_ID, ')') AS `Affected user`,
            l.action_Type AS Action, l.field_Name AS Field, l.old_Value AS `Old value`, l.new_Value AS `New value`
          FROM Audit_Log l JOIN User au ON au.user_ID = l.admin_ID JOIN User uu ON uu.user_ID = l.user_ID
          ORDER BY l.date_Time DESC"),
    ]];
}

// Create a course section (A-R26) for an upcoming semester.
function viewCreateSection(array $u, array $q): array
{
    $sems = rows('SELECT semester_ID, semester_Name FROM Semester WHERE start_Date > CURDATE() ORDER BY start_Date');
    if (!$sems) return ['title' => 'Create a course section', 'subtitle' => 'There is no upcoming semester.', 'blocks' => []];
    $sem = $sems[0];
    foreach ($sems as $s) if ($s['semester_ID'] === ($q['semester'] ?? null)) $sem = $s;
    $courses = rows('SELECT course_ID, course_Name FROM Course ORDER BY course_ID');
    $fac = rows("SELECT f.faculty_ID, CONCAT(u.first_Name, ' ', u.last_Name) AS name, f.faculty_Type,
        (SELECT GROUP_CONCAT(fd.dept_ID SEPARATOR '/') FROM Faculty_Department fd WHERE fd.faculty_ID = f.faculty_ID) AS depts,
        (SELECT COUNT(*) FROM Course_Section cs WHERE cs.faculty_ID = f.faculty_ID AND cs.semester_ID = ?) AS teaching
      FROM Faculty f JOIN User u ON u.user_ID = f.faculty_ID ORDER BY depts, u.last_Name", [$sem['semester_ID']]);
    $slots = rows("SELECT ts.time_Slot_ID AS id,
        CONCAT((SELECT GROUP_CONCAT(d.day_ID ORDER BY FIELD(d.day_ID,'M','T','W','R','F') SEPARATOR '') FROM Time_Slot_Day d WHERE d.time_Slot_ID = ts.time_Slot_ID),
          ' ', TIME_FORMAT(p.start_Time, '%l:%i %p'), ' – ', TIME_FORMAT(p.end_Time, '%l:%i %p')) AS label
      FROM Time_Slot ts JOIN Time_Slot_Period tp ON tp.time_Slot_ID = ts.time_Slot_ID JOIN Period p ON p.period_ID = tp.period_ID
      ORDER BY ts.time_Slot_ID");
    $rooms = rows('SELECT r.room_ID, b.building_Name, r.room_No, r.room_Type, r.capacity FROM Room r
      JOIN Building b ON b.building_ID = r.building_ID ORDER BY r.room_ID');
    $v = fn (string $name, string $fallback) => isset($q[$name]) ? (string) $q[$name] : $fallback;
    return ['title' => 'Create a course section', 'blocks' => [
        ['type' => 'form', 'title' => "New section for {$sem['semester_Name']}", 'endpoint' => 'create-section', 'submit' => 'Create section', 'fields' => [
            ['name' => 'semester_ID', 'label' => 'Semester', 'value' => $sem['semester_ID'], 'reload' => true,
                'options' => array_map(fn ($x) => [$x['semester_ID'], $x['semester_Name']], $sems)],
            ['name' => 'course_ID', 'label' => 'Course', 'value' => $v('course_ID', 'CS455'),
                'options' => array_map(fn ($c) => [$c['course_ID'], "{$c['course_ID']} {$c['course_Name']}"], $courses)],
            ['name' => 'faculty_ID', 'label' => 'Faculty', 'value' => $v('faculty_ID', ''),
                'options' => array_merge([['', 'Choose…']], array_map(fn ($f) => [(string) $f['faculty_ID'],
                    "{$f['name']} ({$f['depts']}, {$f['faculty_Type']}, teaching {$f['teaching']} of " . ($f['faculty_Type'] === 'Full-time' ? 2 : 1) . ')'], $fac))],
            ['name' => 'time_Slot_ID', 'label' => 'Time slot', 'value' => $v('time_Slot_ID', '1'),
                'options' => array_map(fn ($t) => [(string) $t['id'], $t['label']], $slots)],
            ['name' => 'room_ID', 'label' => 'Room', 'value' => $v('room_ID', $rooms[0]['room_ID']),
                'options' => array_map(fn ($r) => [$r['room_ID'], "{$r['building_Name']} {$r['room_No']} ({$r['room_Type']}, {$r['capacity']} seats)"], $rooms)],
            ['name' => 'max_Seats', 'label' => 'Max seats', 'type' => 'number', 'min' => 1, 'max' => 10, 'value' => $v('max_Seats', '10')],
        ]],
        table("Newest {$sem['semester_Name']} sections", 'SELECT ' . SECTION_COLUMNS . ', ' . INSTRUCTOR . ' FROM Course_Section cs ' . SECTION_JOINS . '
          WHERE cs.semester_ID = ? ORDER BY cs.CRN DESC LIMIT 5', [$sem['semester_ID']]),
    ]];
}

// ---------------- Statistics department ----------------

function viewStats(array $u, array $q): array
{
    $cur = currentSemester();
    $c = one("SELECT
        (SELECT COUNT(*) FROM Student WHERE student_Type = 'Undergraduate') AS ug,
        (SELECT COUNT(*) FROM Student WHERE student_Type = 'Graduate') AS gr,
        (SELECT COUNT(*) FROM Faculty WHERE faculty_Type = 'Full-time') AS ft,
        (SELECT COUNT(*) FROM Faculty WHERE faculty_Type = 'Part-time') AS pt,
        (SELECT COUNT(*) FROM Course_Section WHERE semester_ID = ?) AS sections,
        (SELECT SUM(max_Seats) FROM Course_Section WHERE semester_ID = ?) AS seats,
        (SELECT COUNT(*) FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN WHERE cs.semester_ID = ?) AS filled",
        [$cur['id'], $cur['id'], $cur['id']]);
    return ['title' => "Welcome, {$u['name']}", 'subtitle' => 'Aggregate data only: no student is identified.', 'blocks' => [
        stats([['Undergraduate students', $c['ug']], ['Graduate students', $c['gr']], ['Full-time faculty', $c['ft']], ['Part-time faculty', $c['pt']],
            ["{$cur['name']} sections", $c['sections']], ["{$cur['name']} seats filled", "{$c['filled']} of {$c['seats']}"]]),
        table('Students per major', 'SELECT m.major_Name AS Major, COUNT(sm.student_ID) AS Students
          FROM Major m LEFT JOIN Student_Major sm ON sm.major_ID = m.major_ID GROUP BY m.major_ID, m.major_Name ORDER BY Students DESC'),
        table('Grades by department', 'SELECT d.dept_Name AS Department, COUNT(*) AS Grades,
            ROUND(AVG(' . GPA_POINTS . "), 2) AS `Avg grade points`,
            SUM(h.grade LIKE 'A%') AS A, SUM(h.grade LIKE 'B%') AS B, SUM(h.grade LIKE 'C%') AS C,
            SUM(h.grade LIKE 'D%') AS D, SUM(h.grade = 'F') AS F
          FROM Student_History h JOIN Course_Section cs ON cs.CRN = h.CRN JOIN Course c ON c.course_ID = cs.course_ID
          JOIN Department d ON d.dept_ID = c.dept_ID GROUP BY d.dept_ID, d.dept_Name ORDER BY d.dept_Name"),
    ]];
}

function viewEnrollmentCounts(array $u, array $q): array
{
    [$semester, $deptId, $filters] = semesterFilter($q);
    return ['title' => 'Enrollment counts', 'subtitle' => 'Number of students per section, without names.', 'blocks' => [
        $filters,
        table('Sections', "SELECT cs.CRN, cs.course_ID AS Course, c.course_Name AS Title, cs.section_No AS Section,
            COUNT(e.student_ID) AS Registered, cs.max_Seats AS `Max seats`, cs.max_Seats - COUNT(e.student_ID) AS Available
          FROM Course_Section cs JOIN Course c ON c.course_ID = cs.course_ID LEFT JOIN Enrollment e ON e.CRN = cs.CRN
          WHERE cs.semester_ID = ? AND (? = '' OR c.dept_ID = ?)
          GROUP BY cs.CRN, cs.course_ID, c.course_Name, cs.section_No, cs.max_Seats ORDER BY cs.course_ID, cs.section_No",
            [$semester, $deptId, $deptId]),
    ]];
}

// ---------------- Pages shared by several roles ----------------

function viewMasterSchedule(array $u, array $q): array
{
    [$semester, $deptId, $filters] = semesterFilter($q);
    return ['title' => 'Semester master schedule', 'blocks' => [
        $filters,
        table('Course sections', 'SELECT ' . SECTION_COLUMNS . ', ' . INSTRUCTOR . ', ' . SEATS . '
          FROM Course_Section cs ' . SECTION_JOINS . "
          WHERE cs.semester_ID = ? AND (? = '' OR c.dept_ID = ?) ORDER BY cs.course_ID, cs.section_No", [$semester, $deptId, $deptId]),
    ]];
}

function viewCatalog(array $u, array $q): array
{
    $depts = departments();
    $deptId = in_array($q['dept'] ?? null, array_column($depts, 'dept_ID'), true) ? $q['dept'] : $depts[0]['dept_ID'];
    return ['title' => 'Course catalog', 'blocks' => [
        ['type' => 'filters', 'filters' => [['name' => 'dept', 'label' => 'Department', 'value' => $deptId,
            'options' => array_map(fn ($d) => [$d['dept_ID'], $d['dept_Name']], $depts)]]],
        table('Courses', "SELECT c.course_ID AS Course, c.course_Name AS Title, c.course_Credits AS Credits, c.course_Type AS Level,
            COALESCE((SELECT GROUP_CONCAT(CONCAT(cp.prerequisite_Course_ID, ' (min ', cp.min_Grade_Req, ')') SEPARATOR ', ')
              FROM Course_Prerequisite cp WHERE cp.course_ID = c.course_ID), '—') AS Prerequisites
          FROM Course c WHERE c.dept_ID = ? ORDER BY c.course_ID", [$deptId]),
        table('Majors and minors', "SELECT 'Major' AS Type, m.major_Name AS Program,
            (SELECT GROUP_CONCAT(r.course_ID ORDER BY r.course_ID SEPARATOR ', ') FROM Major_Course_Requirement r WHERE r.major_ID = m.major_ID) AS `Required courses`
          FROM Major m WHERE m.dept_ID = ?
          UNION ALL SELECT 'Minor', n.minor_Name,
            (SELECT GROUP_CONCAT(r.course_ID ORDER BY r.course_ID SEPARATOR ', ') FROM Minor_Course_Requirement r WHERE r.minor_ID = n.minor_ID)
          FROM Minor n WHERE n.dept_ID = ?", [$deptId, $deptId]),
    ]];
}

// Every page: id => [sidebar label, roles allowed, function that builds it]. The order is the sidebar order.
const VIEWS = [
    'overview' => ['Overview', ['Student'], 'viewOverview'],
    'schedule' => ['My schedule', ['Student'], 'viewSchedule'],
    'transcript' => ['Transcript', ['Student'], 'viewTranscript'],
    'holds-advisors' => ['Holds & advisors', ['Student'], 'viewHoldsAdvisors'],
    'teaching' => ['My teaching', ['Faculty'], 'viewTeaching'],
    'advisees' => ['Advisees', ['Faculty'], 'viewAdvisees'],
    'admin-overview' => ['Overview', ['Admin'], 'viewAdminOverview'],
    'holds' => ['Student holds', ['Admin'], 'viewHolds'],
    'audit' => ['Audit log', ['Admin'], 'viewAudit'],
    'stats' => ['Statistics', ['StatDept'], 'viewStats'],
    'enrollment-counts' => ['Enrollment counts', ['StatDept'], 'viewEnrollmentCounts'],
    'register' => ['Register', ['Student'], 'viewRegister'],
    'create-section' => ['Create section', ['Admin'], 'viewCreateSection'],
    'master-schedule' => ['Master schedule', ['Faculty', 'Admin', 'Student'], 'viewMasterSchedule'],
    'catalog' => ['Course catalog', ['Student', 'Faculty', 'Admin', 'StatDept'], 'viewCatalog'],
];

function viewsFor(string $role): array
{
    $list = [];
    foreach (VIEWS as $id => [$label, $roles]) if (in_array($role, $roles, true)) $list[] = ['id' => $id, 'label' => $label];
    return $list;
}

function buildView(array $user, string $id, array $query): ?array
{
    $view = VIEWS[$id] ?? null;
    if (!$view || !in_array($user['role'], $view[1], true)) return null;
    return $view[2]($user, $query);
}
