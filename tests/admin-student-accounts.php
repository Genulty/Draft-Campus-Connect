<?php
// Read-only integration checks against existing local data. No fixtures are inserted.
// DB_USER=root DB_PASSWORD='' php tests/admin-student-accounts.php
// The query guard below refuses every statement except SELECT.
declare(strict_types=1);

function run(string $sql, array $params = []): PDOStatement
{
    if (!preg_match('/^\s*SELECT\b/i', $sql)) throw new RuntimeException('This test permits SELECT queries only.');
    static $pdo;
    $pdo ??= new PDO(
        'mysql:unix_socket=' . (getenv('DB_SOCKET') ?: '/tmp/mysql.sock') . ';dbname=' . (getenv('DB_NAME') ?: 'campus_connect') . ';charset=utf8mb4',
        getenv('DB_USER') ?: 'campus', getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : 'campus',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
function rows(string $sql, array $params = []): array { return run($sql, $params)->fetchAll(); }
function one(string $sql, array $params = []): ?array { return run($sql, $params)->fetch() ?: null; }
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

require __DIR__ . '/../api/actions.php';
require __DIR__ . '/../api/views.php';
set_error_handler(static function ($severity, $message, $file, $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });

$admin = one("SELECT u.user_ID AS id, u.user_Type AS role FROM User u JOIN Admin a ON a.admin_ID = u.user_ID WHERE u.user_Type = 'Admin' LIMIT 1");
check($admin !== null, 'An existing admin account is required.');
$students = rows('SELECT s.student_ID, s.student_Type, ug.undergraduate_Student_Type AS ug_status, g.graduate_Student_Type AS g_status, l.user_Email
    FROM Student s LEFT JOIN Undergraduate_Student ug USING(student_ID) LEFT JOIN Graduate_Student g USING(student_ID)
    LEFT JOIN Login l ON l.user_ID = s.student_ID ORDER BY s.student_ID');
check(count($students) > 0, 'Existing student data is required.');
$holds = [];
foreach (rows('SELECT sh.student_ID, h.hold_Type FROM Student_Hold sh JOIN Hold h USING(hold_ID)') as $hold) $holds[$hold['student_ID']][$hold['hold_Type']] = true;
$definitions = array_column(rows('SELECT hold_Type FROM Hold'), 'hold_Type');
$coverage = ['undergraduate_full_time' => 0, 'undergraduate_part_time' => 0, 'graduate' => 0,
    'academic' => 0, 'disciplinary' => 0, 'both' => 0, 'neither' => 0, 'other_holds_only' => 0, 'missing_or_unexpected_classification' => 0];
foreach ($students as $student) {
    $blocks = adminStudentAccountBlocks($admin, ['student' => (string) $student['student_ID']]);
    check(count($blocks) === 2 && $blocks[1]['title'] === 'Student Account Information', 'Student lookup did not return exactly one details block.');
    $info = array_column($blocks[1]['rows'], 1, 0);
    check((int) $info['Student ID'] === (int) $student['student_ID'], 'Wrong student ID.');
    check($info['Campus email'] === ($student['user_Email'] ?? 'Not on file'), 'Wrong email association.');
    $status = $student['student_Type'] === 'Undergraduate' ? $student['ug_status'] : $student['g_status'];
    $expected = in_array($status, ['Full-time', 'Part-time'], true) ? $status : 'Unknown / not on file';
    check($info['Enrollment status'] === $expected && $info['Student type'] === $student['student_Type'], 'Wrong classification or student type.');
    if ($student['student_Type'] === 'Graduate') $coverage['graduate']++;
    elseif ($status === 'Full-time') $coverage['undergraduate_full_time']++;
    elseif ($status === 'Part-time') $coverage['undergraduate_part_time']++;
    if (!in_array($status, ['Full-time', 'Part-time'], true)) $coverage['missing_or_unexpected_classification']++;
    $academic = isset($holds[$student['student_ID']]['Academic']);
    $disciplinary = isset($holds[$student['student_ID']]['Disciplinary']);
    foreach (['Academic' => $academic, 'Disciplinary' => $disciplinary] as $kind => $present) {
        $expected = !in_array($kind, $definitions, true) ? 'Unknown (hold type unavailable)' : ($present ? 'Yes (record on file)' : 'No record on file');
        check($info[$kind . ' Hold'] === $expected, 'Hold indicators do not match the student/type associations.');
    }
    if ($academic) $coverage['academic']++;
    if ($disciplinary) $coverage['disciplinary']++;
    if ($academic && $disciplinary) $coverage['both']++;
    if (!$academic && !$disciplinary) {
        $coverage['neither']++;
        if (!empty($holds[$student['student_ID']])) $coverage['other_holds_only']++;
    }
    check(str_contains($blocks[1]['note'], 'active/resolved status cannot be established'), 'Missing hold-state limitation.');
}
$sample = $students[0];
$byEmail = adminStudentAccountBlocks($admin, ['student' => $sample['user_Email']]);
check((int) array_column($byEmail[1]['rows'], 1, 0)['Student ID'] === (int) $sample['student_ID'], 'Email lookup failed.');
foreach (['Student', 'Faculty', 'StatDept'] as $role) {
    check(adminStudentAccountBlocks(['role' => $role], ['student' => (string) $sample['student_ID']]) === [], 'Non-admin lookup was not refused.');
}
check(count(adminStudentAccountBlocks($admin, [])) === 1, 'Empty lookup should show only the form.');
foreach ([['bad'], str_repeat('x', 255), '0', '   ', "' OR 1=1 --", 'not-a-student@invalid.example'] as $input) {
    $blocks = adminStudentAccountBlocks($admin, ['student' => $input]);
    check(count($blocks) === 2 && $blocks[1]['type'] === 'message' && $blocks[1]['ok'] === false, 'Invalid/unmatched lookup exposed details.');
}
$own = viewAccount($admin, []);
$selected = viewAccount($admin, ['student' => (string) $sample['student_ID']]);
check(array_slice($own['blocks'], 0, -1) === array_slice($selected['blocks'], 0, -2), 'Existing own-account information or settings changed.');
check(count(viewDegreeAudit(['id' => (int) $sample['student_ID']], [])['blocks']) > 0, 'Degree Audit regression.');
check(count(viewCatalog([], ['dept' => 'CS'])['blocks']) > 0, 'Catalog regression.');
foreach (['FA26', 'SP27'] as $semester) {
    check(count(masterSchedule(['semester' => $semester])[1]['rows']) > 0, 'Master Schedule regression.');
    check(publicMajorBrowser(['semester' => $semester, 'major' => 'CS-BS'])[1]['type'] === 'course-cards', 'Major browser regression.');
}
echo 'PASS: ' . count($students) . " existing student accounts, independent hold associations, input safety, own-account preservation and read-only regressions.\n";
echo json_encode($coverage, JSON_PRETTY_PRINT) . "\n";
foreach (['both', 'missing_or_unexpected_classification'] as $scenario) if ($coverage[$scenario] === 0) echo "UNTESTED WITH LIVE DATA: $scenario (no matching existing record).\n";
