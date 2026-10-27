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
const SEATS = 'cs.available_Seats AS `Open seats`';
const ORDER_BY_TIME = "ORDER BY FIELD(LEFT(Days, 1),'M','T','W','R','F'), p.start_Time";

// A section's separate lab meeting (Course_Section_Lab), alongside its lecture columns above.
// Blank/— for sections without one (every section except the four-credit Biology ones) --
// LAB_JOINS is all LEFT JOINs, so CONCAT()/the subquery naturally come back NULL, not an error.
const LAB_COLUMNS = "(SELECT GROUP_CONCAT(ld.day_ID ORDER BY FIELD(ld.day_ID,'M','T','W','R','F') SEPARATOR '')
     FROM Time_Slot_Day ld WHERE ld.time_Slot_ID = csl.time_Slot_ID) AS `Lab days`,
  CONCAT(TIME_FORMAT(lp.start_Time, '%l:%i %p'), ' – ', TIME_FORMAT(lp.end_Time, '%l:%i %p')) AS `Lab time`,
  CONCAT(lb.building_Name, ' ', lr.room_No) AS `Lab room`";
const LAB_JOINS = 'LEFT JOIN Course_Section_Lab csl ON csl.CRN = cs.CRN
  LEFT JOIN Time_Slot_Period ltp ON ltp.time_Slot_ID = csl.time_Slot_ID
  LEFT JOIN Period lp ON lp.period_ID = ltp.period_ID
  LEFT JOIN Room lr ON lr.room_ID = csl.lab_ID
  LEFT JOIN Building lb ON lb.building_ID = lr.building_ID';
const GPA_POINTS = "CASE h.grade WHEN 'A' THEN 4 WHEN 'A-' THEN 3.7 WHEN 'B+' THEN 3.3 WHEN 'B' THEN 3 WHEN 'B-' THEN 2.7
  WHEN 'C+' THEN 2.3 WHEN 'C' THEN 2 WHEN 'C-' THEN 1.7 WHEN 'D+' THEN 1.3 WHEN 'D' THEN 1 ELSE 0 END";

// One student's sections in one semester. Includes each section's lab meeting
// (Course_Section_Lab), when it has one, alongside its lecture.
function studentSchedule(string $title, int $studentId, string $semesterId, ?string $note = null): array
{
    return table($title, 'SELECT ' . SECTION_COLUMNS . ', ' . LAB_COLUMNS . ', ' . INSTRUCTOR . ' FROM Enrollment e
      JOIN Course_Section cs ON cs.CRN = e.CRN ' . SECTION_JOINS . ' ' . LAB_JOINS . '
      WHERE e.student_ID = ? AND cs.semester_ID = ? ' . ORDER_BY_TIME, [$studentId, $semesterId], $note);
}

// The current semester and next registration window for the welcome page (no sign-in needed).
function welcomeInfo(): array
{
    $cur = currentSemester();
    $next = nextSemester();
    $window = $next ? one("SELECT DATE_FORMAT(add_Start, '%b %e') AS opens, DATE_FORMAT(add_End, '%b %e, %Y') AS closes,
        CURDATE() BETWEEN add_Start AND add_End AS is_open FROM Semester WHERE semester_ID = ?", [$next['id']]) : null;
    return ['current' => $cur['name'] ?? null, 'next' => $next['name'] ?? null, 'registration' => $window];
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
        'subtitle' => "{$s['student_Type']} · {$s['student_Year']} · {$s['load_type']} · " . ($holds['n'] ? 'Hold' : 'No hold'),
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
            l.user_Email AS Email, CONCAT(ro.building_ID, '-', ro.room_No) AS Office
          FROM Advisor a JOIN Faculty f ON f.faculty_ID = a.faculty_ID JOIN User u ON u.user_ID = f.faculty_ID
          JOIN Login l ON l.user_ID = f.faculty_ID LEFT JOIN Room ro ON ro.room_ID = f.office_ID
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

// Semester grades (S-R7): one semester at a time, with that semester's GPA.
function viewGrades(array $u, array $q): array
{
    $sems = rows('SELECT DISTINCT sem.semester_ID, sem.semester_Name, sem.start_Date FROM Semester sem
      WHERE sem.semester_ID IN (SELECT h.semester_ID FROM Student_History h WHERE h.student_ID = ?)
         OR sem.semester_ID IN (SELECT cs.semester_ID FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN WHERE e.student_ID = ?)
      ORDER BY sem.start_Date', [$u['id'], $u['id']]);
    if (!$sems) return ['title' => 'Semester grades', 'subtitle' => 'You have no courses yet.', 'blocks' => []];
    $graded = array_column(rows('SELECT DISTINCT semester_ID FROM Student_History WHERE student_ID = ?', [$u['id']]), 'semester_ID');
    $default = end($sems)['semester_ID'];
    foreach ($sems as $x) if (in_array($x['semester_ID'], $graded, true)) $default = $x['semester_ID'];
    $sem = in_array($q['semester'] ?? null, array_column($sems, 'semester_ID'), true) ? $q['semester'] : $default;
    $name = array_values(array_filter($sems, fn ($x) => $x['semester_ID'] === $sem))[0]['semester_Name'];
    $sum = one("SELECT ROUND(SUM(" . GPA_POINTS . " * c.course_Credits) / SUM(c.course_Credits), 2) AS gpa,
        SUM(IF(h.grade <> 'F', c.course_Credits, 0)) AS earned, COUNT(*) AS n
      FROM Student_History h JOIN Course c ON c.course_ID = h.course_ID WHERE h.student_ID = ? AND h.semester_ID = ?", [$u['id'], $sem]);
    return ['title' => 'Semester grades', 'blocks' => [
        ['type' => 'filters', 'filters' => [['name' => 'semester', 'label' => 'Semester', 'value' => $sem,
            'options' => array_map(fn ($x) => [$x['semester_ID'], $x['semester_Name']], $sems)]]],
        stats([["$name GPA", $sum['n'] ? $sum['gpa'] : 'No grades yet'], ['Credits earned', $sum['earned'] ?? 0], ['Courses graded', $sum['n']]]),
        table("$name grades", "SELECT c.course_ID AS Course, c.course_Name AS Title, c.course_Credits AS Credits, h.grade AS Grade
          FROM Student_History h JOIN Course c ON c.course_ID = h.course_ID
          WHERE h.student_ID = ? AND h.semester_ID = ?
          UNION ALL
          SELECT c.course_ID, c.course_Name, c.course_Credits, 'In progress'
          FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Course c ON c.course_ID = cs.course_ID
          WHERE e.student_ID = ? AND cs.semester_ID = ?
            AND e.CRN NOT IN (SELECT CRN FROM Student_History WHERE student_ID = ?)
          ORDER BY Course", [$u['id'], $sem, $u['id'], $sem, $u['id']]),
    ]];
}

// Degree audit (S-R9): every required course of each major and minor, and where the student stands on it.
function viewDegreeAudit(array $u, array $q): array
{
    $programs = rows("SELECT 'Major' AS kind, m.major_ID AS id, m.major_Name AS name FROM Student_Major sm
        JOIN Major m ON m.major_ID = sm.major_ID JOIN Student s ON s.student_ID = sm.student_ID
        WHERE sm.student_ID = ? ORDER BY sm.major_ID = s.major_ID DESC", [$u['id']]);
    foreach (rows("SELECT 'Minor' AS kind, n.minor_ID AS id, n.minor_Name AS name FROM Student_Minor sm
        JOIN Minor n ON n.minor_ID = sm.minor_ID WHERE sm.student_ID = ?", [$u['id']]) as $n) $programs[] = $n;
    $cur = currentSemester();
    $next = nextSemester();
    $summary = [];
    $blocks = [];
    foreach ($programs as $p) {
        $table = $p['kind'] === 'Major' ? 'Major_Course_Requirement' : 'Minor_Course_Requirement';
        $key = $p['kind'] === 'Major' ? 'major_ID' : 'minor_ID';
        // Best grade earned in each required course, and whether the student is taking it now or next semester.
        $reqs = rows("SELECT c.course_ID, c.course_Name, c.course_Credits, r.min_Grade_Req,
            (SELECT h.grade FROM Student_History h WHERE h.student_ID = ? AND h.course_ID = c.course_ID
              ORDER BY FIELD(h.grade, " . GRADE_ORDER . ") DESC LIMIT 1) AS best,
            (SELECT GROUP_CONCAT(sem.semester_Name ORDER BY sem.start_Date DESC) FROM Student_History h JOIN Semester sem ON sem.semester_ID = h.semester_ID
              WHERE h.student_ID = ? AND h.course_ID = c.course_ID) AS taken,
            (SELECT cs.semester_ID FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN
              WHERE e.student_ID = ? AND cs.course_ID = c.course_ID AND cs.semester_ID IN (?, ?) LIMIT 1) AS enrolled
          FROM $table r JOIN Course c ON c.course_ID = r.course_ID WHERE r.$key = ? ORDER BY c.course_ID",
            [$u['id'], $u['id'], $u['id'], $cur['id'] ?? '', $next['id'] ?? '', $p['id']]);
        $order = explode(',', str_replace("'", '', GRADE_ORDER));
        $done = 0;
        $rows = [];
        foreach ($reqs as $r) {
            $met = $r['best'] !== null && array_search($r['best'], $order, true) >= array_search($r['min_Grade_Req'], $order, true);
            if ($met) { $status = 'Completed'; $done++; }
            elseif ($r['enrolled'] === ($cur['id'] ?? null)) $status = 'In progress';
            elseif ($r['enrolled'] !== null) $status = 'Registered for ' . $next['name'];
            elseif ($r['best'] !== null) $status = 'Grade below minimum';
            else $status = 'Not started';
            $rows[] = [$r['course_ID'], $r['course_Name'], $r['course_Credits'], $r['min_Grade_Req'], $r['best'] ?? '—', $r['taken'] ?? '—', $status];
        }
        $summary[] = [$p['name'], count($reqs) ? "$done of " . count($reqs) . ' courses' : 'No requirements'];
        $blocks[] = ['type' => 'table', 'title' => "{$p['kind']}: {$p['name']}", 'note' => null,
            'columns' => ['Course', 'Title', 'Credits', 'Minimum grade', 'Your grade', 'Taken', 'Status'], 'rows' => $rows];
    }
    return ['title' => 'Degree audit', 'subtitle' => 'Required courses for each of your programs. A course counts once you earn at least its minimum grade.',
        'blocks' => [stats($summary), ...$blocks]];
}

// A student's majors and minors, with the forms to declare, change and drop them (S-R24 to S-R30).
function viewMyPrograms(array $u, array $q): array
{
    $mine = rows("SELECT m.major_ID AS `Major ID`, m.major_Name AS Major, d.dept_Name AS Department,
        DATE_FORMAT(sm.date_Of_Choice, '%b %e, %Y') AS `Declared on`, IF(s.major_ID = m.major_ID, 'Primary', 'Second') AS `Role`
      FROM Student_Major sm JOIN Major m ON m.major_ID = sm.major_ID JOIN Department d ON d.dept_ID = m.dept_ID
      JOIN Student s ON s.student_ID = sm.student_ID WHERE sm.student_ID = ? ORDER BY s.major_ID = m.major_ID DESC", [$u['id']]);
    $myMinors = rows("SELECT n.minor_ID AS `Minor ID`, n.minor_Name AS Minor, d.dept_Name AS Department,
        DATE_FORMAT(sm.date_Of_Choice, '%b %e, %Y') AS `Declared on`
      FROM Student_Minor sm JOIN Minor n ON n.minor_ID = sm.minor_ID JOIN Department d ON d.dept_ID = n.dept_ID WHERE sm.student_ID = ?", [$u['id']]);
    $majorIds = array_column($mine, 'Major ID');
    $minorIds = array_column($myMinors, 'Minor ID');
    $mark = fn (string $id, string $name, array $have) => [$id, $name . (in_array($id, $have, true) ? ' (yours)' : '')];
    $majors = array_map(fn ($m) => $mark($m['major_ID'], $m['major_Name'], $majorIds), rows('SELECT major_ID, major_Name FROM Major ORDER BY major_Name'));
    $minors = array_map(fn ($n) => $mark($n['minor_ID'], $n['minor_Name'], $minorIds), rows('SELECT minor_ID, minor_Name FROM Minor ORDER BY minor_Name'));
    $firstOther = fn (array $options, array $have) => (array_values(array_filter($options, fn ($o) => !in_array($o[0], $have, true)))[0] ?? $options[0])[0];
    $asTable = fn (string $title, array $list, string $empty) => ['type' => 'table', 'title' => $title, 'note' => $list ? null : $empty,
        'columns' => $list ? array_keys($list[0]) : [], 'rows' => array_map('array_values', $list)];
    $form = fn (string $title, string $endpoint, string $submit, array $fields) =>
        ['type' => 'form', 'title' => $title, 'endpoint' => $endpoint, 'submit' => $submit, 'fields' => $fields];
    $myMajorOptions = array_map(fn ($m) => [$m['Major ID'], $m['Major']], $mine);
    $myMinorOptions = $myMinors ? array_map(fn ($n) => [$n['Minor ID'], $n['Minor']], $myMinors) : [['', 'You have no minor']];

    return ['title' => 'My majors & minors', 'subtitle' => 'You can have up to 2 majors and 1 minor. Your advisors follow your programs.', 'blocks' => [
        $asTable('My majors', $mine, ''),
        $asTable('My minor', $myMinors, 'You have not declared a minor.'),
        $form('Add a major', 'add-major', 'Add major', [
            ['name' => 'major_ID', 'label' => 'Major', 'value' => $firstOther($majors, $majorIds), 'options' => $majors]]),
        $form('Change a major', 'change-major', 'Change major', [
            ['name' => 'from_ID', 'label' => 'Current major', 'value' => $myMajorOptions[0][0], 'options' => $myMajorOptions],
            ['name' => 'major_ID', 'label' => 'New major', 'value' => $firstOther($majors, $majorIds), 'options' => $majors]]),
        $form('Drop a major', 'drop-major', 'Drop major', [
            ['name' => 'major_ID', 'label' => 'Major', 'value' => $majorIds[0] ?? '', 'options' => $majors]]),
        $form('Add a minor', 'add-minor', 'Add minor', [
            ['name' => 'minor_ID', 'label' => 'Minor', 'value' => $firstOther($minors, $minorIds), 'options' => $minors]]),
        $form('Change a minor', 'change-minor', 'Change minor', [
            ['name' => 'from_ID', 'label' => 'Current minor', 'value' => $myMinorOptions[0][0], 'options' => $myMinorOptions],
            ['name' => 'minor_ID', 'label' => 'New minor', 'value' => $firstOther($minors, $minorIds), 'options' => $minors]]),
        $form('Drop a minor', 'drop-minor', 'Drop minor', [
            ['name' => 'minor_ID', 'label' => 'Minor', 'value' => $minorIds[0] ?? $minors[0][0], 'options' => $minors]]),
        table('My advisors', "SELECT CONCAT(u.first_Name, ' ', u.last_Name) AS Advisor,
            (SELECT GROUP_CONCAT(d.dept_Name SEPARATOR ', ') FROM Faculty_Department fd JOIN Department d ON d.dept_ID = fd.dept_ID WHERE fd.faculty_ID = a.faculty_ID) AS Department,
            DATE_FORMAT(a.date_Of_Appointment, '%b %e, %Y') AS Since
          FROM Advisor a JOIN User u ON u.user_ID = a.faculty_ID WHERE a.student_ID = ?", [$u['id']]),
    ]];
}

// ---------------- Faculty ----------------

function viewTeaching(array $u, array $q): array
{
    $f = one("SELECT f.`rank`, f.faculty_Type, f.specialty,
        (SELECT GROUP_CONCAT(CONCAT(d.dept_Name, ' (', fd.percent_Time, '%)') SEPARATOR ', ') FROM Faculty_Department fd
          JOIN Department d ON d.dept_ID = fd.dept_ID WHERE fd.faculty_ID = f.faculty_ID) AS depts,
        CONCAT(ro.building_ID, '-', ro.room_No) AS office
      FROM Faculty f LEFT JOIN Room ro ON ro.room_ID = f.office_ID WHERE f.faculty_ID = ?", [$u['id']]);
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
    // Only Lecture and Lab rooms can hold a class (not an Office); capacity comes from whichever
    // subtype table the room matches (Lecture.seats or Lab.work_Stations).
    $rooms = rows("SELECT r.room_ID, b.building_Name, r.room_No, r.room_Type,
        COALESCE(lab.work_Stations, lec.seats) AS capacity
      FROM Room r
      JOIN Building b ON b.building_ID = r.building_ID
      LEFT JOIN Lab lab ON lab.lab_ID = r.room_ID
      LEFT JOIN Lecture lec ON lec.lecture_ID = r.room_ID
      WHERE r.room_Type IN ('Lecture', 'Lab')
      ORDER BY r.room_ID");
    $v = fn (string $name, string $fallback) => isset($q[$name]) ? (string) $q[$name] : $fallback;
    return ['title' => 'Create a course section', 'blocks' => [
        ['type' => 'form', 'title' => "New section for {$sem['semester_Name']}", 'endpoint' => 'create-section', 'submit' => 'Create section', 'keep' => true, 'fields' => [
            ['name' => 'semester_ID', 'label' => 'Semester', 'value' => $sem['semester_ID'], 'reload' => 'semester',
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
            COUNT(e.student_ID) AS Registered, cs.max_Seats AS `Max seats`, cs.available_Seats AS Available
          FROM Course_Section cs JOIN Course c ON c.course_ID = cs.course_ID LEFT JOIN Enrollment e ON e.CRN = cs.CRN
          WHERE cs.semester_ID = ? AND (? = '' OR c.dept_ID = ?)
          GROUP BY cs.CRN, cs.course_ID, c.course_Name, cs.section_No, cs.max_Seats, cs.available_Seats ORDER BY cs.course_ID, cs.section_No",
            [$semester, $deptId, $deptId]),
    ]];
}

// Pages shared by several roles
function publicMajorBrowser(array $q): array
{
    // Get the available semesters from MySQL.
    $semesters = rows(
       "SELECT semester_ID, semester_Name
        FROM Semester
        WHERE semester_ID IN ('FA26', 'SP27')
        ORDER BY start_Date"
    );


    // Get the actual majors from MySQL.
    $majors = rows(
        'SELECT major_ID, major_Name
         FROM Major
         ORDER BY major_Name'
    );

    // Use the current semester by default.
    $semester = $q['semester'] ?? 'FA26';

    if (!in_array($semester, array_column($semesters, 'semester_ID'), true)) {
    $semester = 'FA26';
}

    // No major selected by default.
    $major = (string) ($q['major'] ?? '');

    $majorIds = array_map(
        'strval',
        array_column($majors, 'major_ID')
    );

    if (!in_array($major, $majorIds, true)) {
        $major = '';
    }

    // Build the semester and major dropdowns.
    $filters = [
        'type' => 'filters',
        'filters' => [
            [
                'name' => 'semester',
                'label' => 'Semester',
                'value' => $semester,
                'options' => array_map(
                    fn ($s) => [
                        $s['semester_ID'],
                        $s['semester_Name']
                    ],
                    $semesters
                )
            ],
            [
                'name' => 'major',
                'label' => 'Choose Major',
                'value' => $major,
                'options' => array_merge(
                    [['', 'Choose a major']],
                    array_map(
                        fn ($m) => [
                            (string) $m['major_ID'],
                            $m['major_Name']
                        ],
                        $majors
                    )
                )
            ]
        ]
    ];

    // Display the welcome message until
    // the visitor selects a major.
    if ($major === '') {
        return [
            $filters,
            [
                'type' => 'empty-major',
                'message' =>
                    'Select a major above to explore available courses.'
            ]
        ];
    }
    // Retrieve real course sections for the selected major.
    $sections = rows(
        'SELECT DISTINCT ' .
        SECTION_COLUMNS . ', ' .
        LAB_COLUMNS . ', ' .
        INSTRUCTOR . ', ' .
        SEATS . '
        FROM Course_Section cs
        ' . SECTION_JOINS . '
        ' . LAB_JOINS . '
        JOIN Major_Course_Requirement mcr
          ON mcr.course_ID = cs.course_ID
        WHERE cs.semester_ID = ?
          AND mcr.major_ID = ?
        ORDER BY cs.course_ID, cs.section_No',
        [$semester, $major]
    );

    // Group multiple sections under each course.
    $grouped = [];

    foreach ($sections as $row) {
        $code = $row['Course'];

        if (!isset($grouped[$code])) {
            $grouped[$code] = [
                'code' => $code,
                'title' => $row['Title'],
                'credits' => $row['Credits'],
                'sections' => []
            ];
        }

        $grouped[$code]['sections'][] = [
            'section' => $row['Section'],
            'crn' => $row['CRN'],
            'days' => $row['Days'] ?? '—',
            'time' => $row['Time'] ?? '—',
            'room' => $row['Room'] ?? '—',
            'instructor' => $row['Instructor'] ?? 'TBA',
            'seats' => $row['Open seats'],
            'labDays' => $row['Lab days'] ?? null,
            'labTime' => $row['Lab time'] ?? null,
            'labRoom' => $row['Lab room'] ?? null
        ];
    }

    return [
        $filters,
        [
            'type' => 'course-cards',
            'title' => 'Available courses',
            'courses' => array_values($grouped)
        ]
    ];
}

// The semester master schedule (filters + every section). Public: the welcome page shows it without signing in.
function masterSchedule(array $q): array
{
    [$semester, $deptId, $filters] = semesterFilter($q);
    return [$filters,
        table('Course sections', 'SELECT ' . SECTION_COLUMNS . ', ' . LAB_COLUMNS . ', ' . INSTRUCTOR . ', ' . SEATS . '
          FROM Course_Section cs ' . SECTION_JOINS . ' ' . LAB_JOINS . "
          WHERE cs.semester_ID = ? AND (? = '' OR c.dept_ID = ?) ORDER BY cs.course_ID, cs.section_No", [$semester, $deptId, $deptId])];
}

function viewMasterSchedule(array $u, array $q): array
{
    return ['title' => 'Semester master schedule', 'blocks' => masterSchedule($q)];
}

function viewCatalog(array $u, array $q): array
{
    $depts = departments();
    $deptId = in_array($q['dept'] ?? null, array_column($depts, 'dept_ID'), true) ? $q['dept'] : $depts[0]['dept_ID'];
    $cur = currentSemester();
    return ['title' => 'Course catalog', 'blocks' => [
        table('University departments', "SELECT d.dept_Name AS Department, CONCAT(u.first_Name, ' ', u.last_Name) AS Chair,
            CONCAT(b.building_Name, ' ', r.room_No) AS Office, d.phone_No AS Phone, d.email AS Email,
            (SELECT COUNT(*) FROM Course c WHERE c.dept_ID = d.dept_ID) AS Courses
          FROM Department d LEFT JOIN User u ON u.user_ID = d.chair_ID LEFT JOIN Room r ON r.room_ID = d.office_ID
          LEFT JOIN Building b ON b.building_ID = r.building_ID ORDER BY d.dept_Name"),
        ['type' => 'filters', 'filters' => [['name' => 'dept', 'label' => 'Department', 'value' => $deptId,
            'options' => array_map(fn ($d) => [$d['dept_ID'], $d['dept_Name']], $depts)]]],
        table('Courses in this department', "SELECT c.course_ID AS Course, c.course_Name AS Title, c.course_Credits AS Credits, c.course_Type AS Level,
            COALESCE((SELECT GROUP_CONCAT(CONCAT(cp.prerequisite_Course_ID, ' (min ', cp.min_Grade_Req, ')') SEPARATOR ', ')
              FROM Course_Prerequisite cp WHERE cp.course_ID = c.course_ID), '—') AS Prerequisites
          FROM Course c WHERE c.dept_ID = ? ORDER BY c.course_ID", [$deptId]),
        table("{$cur['name']} sections offered", 'SELECT ' . SECTION_COLUMNS . ', ' . INSTRUCTOR . ', ' . SEATS . '
          FROM Course_Section cs ' . SECTION_JOINS . "
          WHERE cs.semester_ID = ? AND c.dept_ID = ? " . ORDER_BY_TIME, [$cur['id'], $deptId],
            'Who is teaching each section this semester, and when and where it meets.'),
        table('Majors and minors', "SELECT 'Major' AS Type, m.major_Name AS Program,
            (SELECT GROUP_CONCAT(r.course_ID ORDER BY r.course_ID SEPARATOR ', ') FROM Major_Course_Requirement r WHERE r.major_ID = m.major_ID) AS `Required courses`
          FROM Major m WHERE m.dept_ID = ?
          UNION ALL SELECT 'Minor', n.minor_Name,
            (SELECT GROUP_CONCAT(r.course_ID ORDER BY r.course_ID SEPARATOR ', ') FROM Minor_Course_Requirement r WHERE r.minor_ID = n.minor_ID)
          FROM Minor n WHERE n.dept_ID = ?", [$deptId, $deptId]),
    ]];
}

// Majors and minors offered (S-R14, S-R16) and the course requirements of a selected one (S-R15, S-R17).
function viewPrograms(array $u, array $q): array
{
    $majors = rows("SELECT m.major_ID, m.major_Name, d.dept_Name,
        (SELECT c.course_Type FROM Major_Course_Requirement r JOIN Course c ON c.course_ID = r.course_ID WHERE r.major_ID = m.major_ID LIMIT 1) AS level,
        (SELECT COUNT(*) FROM Major_Course_Requirement r WHERE r.major_ID = m.major_ID) AS n
      FROM Major m JOIN Department d ON d.dept_ID = m.dept_ID ORDER BY d.dept_Name, level DESC");
    $minors = rows('SELECT n.minor_ID, n.minor_Name, d.dept_Name, (SELECT COUNT(*) FROM Minor_Course_Requirement r WHERE r.minor_ID = n.minor_ID) AS n
      FROM Minor n JOIN Department d ON d.dept_ID = n.dept_ID ORDER BY d.dept_Name');
    $options = [...array_map(fn ($m) => ["major:{$m['major_ID']}", "Major: {$m['major_Name']}"], $majors),
        ...array_map(fn ($n) => ["minor:{$n['minor_ID']}", "Minor: {$n['minor_Name']}"], $minors)];
    $pick = in_array($q['program'] ?? null, array_column($options, 0), true) ? $q['program'] : $options[0][0];
    [$kind, $id] = explode(':', $pick);
    $name = substr(array_values(array_filter($options, fn ($o) => $o[0] === $pick))[0][1], 7);
    $table = $kind === 'major' ? 'Major_Course_Requirement' : 'Minor_Course_Requirement';
    return ['title' => 'Majors & minors', 'blocks' => [
        ['type' => 'table', 'title' => 'Majors offered', 'note' => null, 'columns' => ['Major', 'Department', 'Level', 'Required courses'],
            'rows' => array_map(fn ($m) => [$m['major_Name'], $m['dept_Name'], $m['level'], $m['n']], $majors)],
        ['type' => 'table', 'title' => 'Minors offered', 'note' => null, 'columns' => ['Minor', 'Department', 'Required courses'],
            'rows' => array_map(fn ($n) => [$n['minor_Name'], $n['dept_Name'], $n['n']], $minors)],
        ['type' => 'filters', 'filters' => [['name' => 'program', 'label' => 'Show requirements for', 'value' => $pick, 'options' => $options]]],
        table("Course requirements: $name", "SELECT c.course_ID AS Course, c.course_Name AS Title, c.course_Credits AS Credits,
            r.min_Grade_Req AS `Minimum grade`,
            COALESCE((SELECT GROUP_CONCAT(cp.prerequisite_Course_ID SEPARATOR ', ') FROM Course_Prerequisite cp WHERE cp.course_ID = c.course_ID), '—') AS Prerequisites
          FROM $table r JOIN Course c ON c.course_ID = r.course_ID WHERE r.{$kind}_ID = ? ORDER BY c.course_ID", [$id]),
    ]];
}

// Prerequisite search (S-R19): find a course by its ID or part of its title.
function viewPrereqSearch(array $u, array $q): array
{
    $term = trim((string) ($q['course'] ?? ''));
    $blocks = [['type' => 'form', 'title' => 'Search for a course', 'method' => 'view', 'submit' => 'Search', 'fields' => [
        ['name' => 'course', 'label' => 'Course ID or title', 'value' => $term, 'placeholder' => 'For example CS455 or Calculus']]]];
    if ($term === '') return ['title' => 'Prerequisite search', 'subtitle' => 'See which courses you must pass first, and the minimum grade.', 'blocks' => $blocks];

    $id = strtoupper(preg_replace('/\s+/', '', $term));
    $courses = rows('SELECT course_ID, course_Name FROM Course WHERE course_ID = ? OR course_Name LIKE ? ORDER BY course_ID',
        [$id, '%' . addcslashes($term, '%_\\') . '%']);
    if (!$courses) {
        $blocks[] = ['type' => 'message', 'ok' => false, 'heading' => 'No result', 'message' => "No course matches \"$term\". Check the course ID, for example CS455."];
    } else {
        $ids = array_column($courses, 'course_ID');
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $result = table('Prerequisites', "SELECT cp.course_ID AS Course, c.course_Name AS Title, cp.prerequisite_Course_ID AS Prerequisite,
            p.course_Name AS `Prerequisite title`, cp.min_Grade_Req AS `Minimum grade`
          FROM Course_Prerequisite cp JOIN Course c ON c.course_ID = cp.course_ID JOIN Course p ON p.course_ID = cp.prerequisite_Course_ID
          WHERE cp.course_ID IN ($marks) ORDER BY cp.course_ID, cp.prerequisite_Course_ID", $ids);
        if (!$result['rows']) {
            $list = implode(', ', array_map(fn ($c) => "{$c['course_ID']} {$c['course_Name']}", array_slice($courses, 0, 5)));
            $blocks[] = ['type' => 'message', 'ok' => false, 'heading' => 'No result', 'message' => "No prerequisite record was found: $list " . (count($courses) === 1 ? 'has' : 'have') . ' no prerequisites.'];
        } else {
            $without = array_diff($ids, array_column($result['rows'], 0));
            if ($without) $result['note'] = 'Also matched, with no prerequisites: ' . implode(', ', $without) . '.';
            $blocks[] = $result;
        }
    }
    return ['title' => 'Prerequisite search', 'subtitle' => 'See which courses you must pass first, and the minimum grade.', 'blocks' => $blocks];
}

// The signed-in user's own account: address (S-R20) and password (S-R21). The ID and email can't be changed (S-F14, S-F15).
function viewAccount(array $me, array $q): array
{
    $u = one('SELECT u.*, l.user_Email FROM User u JOIN Login l ON l.user_ID = u.user_ID WHERE u.user_ID = ?', [$me['id']]);
    return ['title' => 'My account', 'subtitle' => 'Your ID and campus email cannot be changed.', 'blocks' => [
        stats([['Name', trim("{$u['first_Name']} " . ($u['middle_Name'] ? "{$u['middle_Name']} " : '') . $u['last_Name'])],
            ['ID', $u['user_ID']], ['Campus email', $u['user_Email']],
            ['Address', $u['street'] ? "{$u['street']}, {$u['city']}, {$u['state']} {$u['zip_Code']}" : 'Not on file']]),
        ['type' => 'form', 'title' => 'Update address', 'endpoint' => 'update-address', 'submit' => 'Save address', 'fields' => [
            ['name' => 'street', 'label' => 'Street', 'value' => $u['street'] ?? ''],
            ['name' => 'city', 'label' => 'City', 'value' => $u['city'] ?? ''],
            ['name' => 'state', 'label' => 'State (2 letters)', 'value' => $u['state'] ?? ''],
            ['name' => 'zip_Code', 'label' => 'ZIP code', 'value' => $u['zip_Code'] ?? ''],
        ]],
        ...passwordChangeBlocks($me['id']),
    ]];
}

// Changing your own password takes three steps: current password, then the emailed code, then the new
// password. Which step shows depends on what this browser has done so far (kept in the session).
function passwordChangeBlocks(int $userId): array
{
    $pending = one('SELECT reset_ID FROM Password_Reset WHERE user_ID = ? AND purpose = \'Change\' AND used_At IS NULL
      AND expires_At > NOW() ORDER BY reset_ID DESC LIMIT 1', [$userId]);
    $mine = ($_SESSION['reset_user'] ?? null) === $userId;
    $request = ['type' => 'form', 'title' => $pending && $mine ? 'Send a new code' : 'Change password', 'endpoint' => 'request-password-change',
        'submit' => 'Email me a code', 'fields' => [['name' => 'current', 'label' => 'Current password', 'type' => 'password', 'value' => '']],
        'note' => 'We email a 6-digit code to your campus inbox (see Inbox). It works for 15 minutes.'];
    if ($pending && $mine && ($_SESSION['reset_verified'] ?? null) === (int) $pending['reset_ID']) {
        return [['type' => 'form', 'title' => 'Choose a new password', 'endpoint' => 'complete-password-change', 'submit' => 'Save new password', 'fields' => [
            ['name' => 'password', 'label' => 'New password', 'type' => 'password', 'value' => ''],
            ['name' => 'confirm', 'label' => 'Confirm new password', 'type' => 'password', 'value' => ''],
        ], 'note' => 'At least 8 characters, with a letter and a number.']];
    }
    if ($pending && $mine) {
        return [['type' => 'form', 'title' => 'Enter your code', 'endpoint' => 'verify-password-code', 'submit' => 'Check code', 'fields' => [
            ['name' => 'code', 'label' => '6-digit code from your Inbox', 'value' => '', 'placeholder' => '123456'],
        ], 'note' => 'Open Inbox in the menu to find the email "Your Campus Connect verification code".'], $request];
    }
    return [$request];
}

// Campus mail: every email the system sent this user (newest first). Opening it marks them read.
function viewInbox(array $u, array $q): array
{
    $messages = inbox($u['id']);
    run('UPDATE Email_Message SET read_At = NOW() WHERE user_ID = ? AND read_At IS NULL', [$u['id']]);
    return ['title' => 'Inbox', 'subtitle' => "Campus mail for {$u['email']}. In this project, emails are kept here instead of being delivered.",
        'blocks' => [['type' => 'mail', 'messages' => $messages]]];
}

// Admin: send a user a password-reset email (A-R6). The admin never sees or sets the new password.
function viewResetPassword(array $u, array $q): array
{
    $level = one('SELECT security_Level FROM Admin WHERE admin_ID = ?', [$u['id']])['security_Level'];
    return ['title' => 'Reset a password', 'subtitle' => $level === 'Read-Write'
            ? 'The user gets an email with a code and a link, and chooses the new password themselves.'
            : 'You are a Read-Only admin, so sending a reset will be refused.', 'blocks' => [
        ['type' => 'form', 'title' => 'Send a password reset', 'endpoint' => 'send-password-reset', 'submit' => 'Send reset email', 'fields' => [
            ['name' => 'login', 'label' => 'User email or ID', 'value' => '', 'placeholder' => 'student@campus.edu or 100001'],
        ], 'note' => 'The code and link work for 15 minutes. The reset is recorded in the audit log.'],
        table('Recent password resets', "SELECT CONCAT(u.first_Name, ' ', u.last_Name, ' (', r.user_ID, ')') AS User,
            CASE r.purpose WHEN 'Forgot' THEN 'Forgot password' WHEN 'Change' THEN 'Changed own password' ELSE 'Sent by admin' END AS Reason,
            COALESCE(CONCAT(au.first_Name, ' ', au.last_Name), '—') AS `Sent by`,
            DATE_FORMAT(r.created_At, '%b %e, %Y %l:%i %p') AS Sent,
            CASE WHEN r.used_At IS NOT NULL THEN 'Password changed' WHEN r.expires_At <= NOW() THEN 'Expired' ELSE 'Waiting for the user' END AS Status
          FROM Password_Reset r JOIN User u ON u.user_ID = r.user_ID LEFT JOIN User au ON au.user_ID = r.requested_By
          ORDER BY r.reset_ID DESC LIMIT 15"),
    ]];
}

// Create a user (A-R5). The fields depend on the type of user, so choosing a type reloads the form.
function viewCreateUser(array $u, array $q): array
{
    $roles = [['Student', 'Student'], ['Faculty', 'Faculty'], ['Admin', 'Admin'], ['StatDept', 'Stat-Dept member']];
    $role = in_array($q['role'] ?? null, array_column($roles, 0), true) ? $q['role'] : 'Student';
    $level = one('SELECT security_Level FROM Admin WHERE admin_ID = ?', [$u['id']])['security_Level'];
    $fields = [
        ['name' => 'role', 'label' => 'Type of user', 'value' => $role, 'options' => $roles, 'reload' => 'role'],
        ['name' => 'first_Name', 'label' => 'First name', 'value' => ''],
        ['name' => 'middle_Name', 'label' => 'Middle name (optional)', 'value' => ''],
        ['name' => 'last_Name', 'label' => 'Last name', 'value' => ''],
        ['name' => 'gender', 'label' => 'Gender', 'value' => '', 'options' => [['', 'Choose…'], ['Female', 'Female'], ['Male', 'Male'], ['Other', 'Other']]],
        ['name' => 'DOB', 'label' => 'Date of birth', 'type' => 'date', 'value' => ''],
    ];
    $load = ['name' => 'load', 'label' => 'Status', 'value' => 'Full-time', 'options' => [['Full-time', 'Full-time'], ['Part-time', 'Part-time']]];
    if ($role === 'Student') {
        $majors = rows("SELECT m.major_ID, m.major_Name, (SELECT c.course_Type FROM Major_Course_Requirement r JOIN Course c ON c.course_ID = r.course_ID
            WHERE r.major_ID = m.major_ID LIMIT 1) AS level FROM Major m ORDER BY level DESC, m.major_Name");
        $fields[] = ['name' => 'level', 'label' => 'Level', 'value' => 'Undergraduate', 'options' => [['Undergraduate', 'Undergraduate'], ['Graduate', 'Graduate']]];
        $fields[] = $load;
        $fields[] = ['name' => 'major_ID', 'label' => 'Major', 'value' => '', 'options' => array_merge([['', 'Choose…']],
            array_map(fn ($m) => [$m['major_ID'], "{$m['major_Name']} ({$m['level']})"], $majors))];
    } elseif ($role === 'Faculty') {
        $fields[] = ['name' => 'dept_ID', 'label' => 'Department', 'value' => '', 'options' => array_merge([['', 'Choose…']],
            array_map(fn ($d) => [$d['dept_ID'], $d['dept_Name']], departments()))];
        $fields[] = $load;
        $fields[] = ['name' => 'rank', 'label' => 'Rank', 'value' => 'Assistant Professor', 'options' => array_map(fn ($r) => [$r, $r], FACULTY_RANKS)];
        $fields[] = ['name' => 'specialty', 'label' => 'Specialty (optional)', 'value' => ''];
    } elseif ($role === 'Admin') {
        $fields[] = ['name' => 'security_Level', 'label' => 'Security level', 'value' => 'Read-Only', 'options' => [['Read-Only', 'Read-Only'], ['Read-Write', 'Read-Write']]];
    }
    array_push($fields,
        ['name' => 'street', 'label' => 'Street (optional)', 'value' => ''],
        ['name' => 'city', 'label' => 'City (optional)', 'value' => ''],
        ['name' => 'state', 'label' => 'State (optional)', 'value' => ''],
        ['name' => 'zip_Code', 'label' => 'ZIP code (optional)', 'value' => ''],
        ['name' => 'password', 'label' => 'Starting password', 'type' => 'password', 'value' => ''],
        ['name' => 'confirm', 'label' => 'Confirm password', 'type' => 'password', 'value' => '']);
    return ['title' => 'Create a user', 'subtitle' => $level === 'Read-Write'
            ? 'The new user gets an ID and a campus email to sign in with.'
            : 'You are a Read-Only admin, so creating a user will be refused.', 'blocks' => [
        ['type' => 'form', 'title' => 'New user', 'endpoint' => 'create-user', 'submit' => 'Create user', 'fields' => $fields,
            'note' => 'The password needs at least 8 characters, with a letter and a number.'],
        table('Accounts created by admins', "SELECT l.user_ID AS ID, CONCAT(uu.first_Name, ' ', uu.last_Name) AS Name,
            IF(uu.user_Type = 'StatDept', 'Stat-Dept', uu.user_Type) AS Type,
            lg.user_Email AS Email, CONCAT(au.first_Name, ' ', au.last_Name) AS `Created by`, DATE_FORMAT(l.date_Time, '%b %e, %Y %l:%i %p') AS `Created on`
          FROM Audit_Log l JOIN User uu ON uu.user_ID = l.user_ID JOIN User au ON au.user_ID = l.admin_ID JOIN Login lg ON lg.user_ID = l.user_ID
          WHERE l.action_Type = 'Create' ORDER BY l.date_Time DESC, l.log_ID DESC LIMIT 10"),
    ]];
}

// Every page: id => [sidebar label, roles allowed, function that builds it]. The order is the sidebar order.
const VIEWS = [
    'overview' => ['Overview', ['Student'], 'viewOverview'],
    'schedule' => ['My schedule', ['Student'], 'viewSchedule'],
    'register' => ['Register', ['Student'], 'viewRegister'],
    'grades' => ['Grades', ['Student'], 'viewGrades'],
    'transcript' => ['Transcript', ['Student'], 'viewTranscript'],
    'degree-audit' => ['Degree audit', ['Student'], 'viewDegreeAudit'],
    'holds-advisors' => ['Holds & advisors', ['Student'], 'viewHoldsAdvisors'],
    'my-programs' => ['My majors & minors', ['Student'], 'viewMyPrograms'],
    'teaching' => ['My teaching', ['Faculty'], 'viewTeaching'],
    'advisees' => ['Advisees', ['Faculty'], 'viewAdvisees'],
    'admin-overview' => ['Overview', ['Admin'], 'viewAdminOverview'],
    'create-user' => ['Create user', ['Admin'], 'viewCreateUser'],
    'reset-password' => ['Reset a password', ['Admin'], 'viewResetPassword'],
    'create-section' => ['Create section', ['Admin'], 'viewCreateSection'],
    'holds' => ['Student holds', ['Admin'], 'viewHolds'],
    'audit' => ['Audit log', ['Admin'], 'viewAudit'],
    'stats' => ['Statistics', ['StatDept'], 'viewStats'],
    'enrollment-counts' => ['Enrollment counts', ['StatDept'], 'viewEnrollmentCounts'],
    'master-schedule' => ['Master schedule', ['Faculty', 'Admin', 'Student'], 'viewMasterSchedule'],
    'catalog' => ['Course catalog', ['Student', 'Faculty', 'Admin', 'StatDept'], 'viewCatalog'],
    'programs' => ['Majors & minors', ['Student', 'Faculty', 'Admin', 'StatDept'], 'viewPrograms'],
    'prereq-search' => ['Prerequisite search', ['Student', 'Faculty', 'Admin', 'StatDept'], 'viewPrereqSearch'],
    'inbox' => ['Inbox', ['Student', 'Faculty', 'Admin', 'StatDept'], 'viewInbox'],
    'account' => ['My account', ['Student', 'Faculty', 'Admin', 'StatDept'], 'viewAccount'],
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
