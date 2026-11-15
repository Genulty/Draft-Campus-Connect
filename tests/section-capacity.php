<?php
// Database-free probes of the actual action and form metadata. No db.php or PDO connection.
declare(strict_types=1);
class RuleError extends Exception {}
function fail(string $message): never { throw new RuleError($message); }
function transaction(callable $callback): mixed { return $callback(); }
$queries = []; $inserts = []; $scenario = '';
function one(string $sql, array $params = []): ?array
{
    global $queries, $scenario;
    $queries[] = $sql;
    if (str_contains($sql, 'FROM Admin')) return ['security_Level' => $scenario === 'readonly' ? 'Read-Only' : 'Read-Write'];
    if (str_contains($sql, 'FROM Semester')) return ['semester_ID' => 'TEST', 'semester_Name' => 'Fixture', 'started' => 0];
    if (str_contains($sql, 'FROM Department WHERE')) return $params[0] === 'TEST' ? ['dept_ID' => 'TEST'] : null;
    if (str_contains($sql, 'FROM Course WHERE')) return array_merge(['course_ID' => 'TEST101', 'course_Name' => 'Fixture', 'dept_ID' => 'TEST', 'course_Type' => 'Undergraduate', 'course_Credits' => 3], $GLOBALS['courseOverride'] ?? []);
    if (str_contains($sql, 'FROM Faculty f')) return ['faculty_ID' => 1, 'faculty_Type' => 'Full-time', 'name' => 'Fixture Faculty'];
    if (str_contains($sql, 'FROM Time_Slot WHERE')) return $scenario === 'missing-slot' ? null : ['time_Slot_ID' => $params[0]];
    if (str_contains($sql, 'FROM Room r')) return ['room_ID' => 'TEST', 'capacity' => $scenario === 'small-room' ? 4 : 10];
    if (str_contains($sql, 'FROM Faculty_Department')) return in_array($scenario, ['department', 'all-conflicts'], true) ? null : ['ok' => 1];
    if (str_contains($sql, 'MAX(CRN)')) return ['crn' => 10001];
    if (str_contains($sql, 'COUNT(*) AS n')) return ['n' => 0];
    throw new RuntimeException('Unexpected query: ' . $sql);
}
function rows(string $sql, array $params = []): array
{
    global $scenario;
    if (str_contains($sql, 'AS start_seconds')) return $GLOBALS['meetingOverride'] ?? [
        ['day_ID' => 'M', 'period_ID' => 1, 'start_seconds' => 28800, 'end_seconds' => 33600],
        ['day_ID' => 'W', 'period_ID' => 1, 'start_seconds' => 28800, 'end_seconds' => 33600],
    ];
    if (str_contains($sql, 'FROM Time_Slot ts')) return $GLOBALS['slotOptions'] ?? [];
    if (str_contains($sql, 'FROM Course ORDER')) return $GLOBALS['courseOptions'] ?? [];
    if (str_contains($sql, 'FROM Semester')) return [['semester_ID' => 'TEST', 'semester_Name' => 'Fixture']];
    if (str_contains($sql, 'FROM Room r')) return [['room_ID' => 'TEST', 'building_Name' => 'Fixture', 'room_No' => '1', 'room_Type' => 'Lecture', 'capacity' => 10]];
    if (str_contains($sql, 'SELECT CRN FROM Course_Section') && str_contains($sql, 'faculty_ID') && in_array($scenario, ['load', 'all-conflicts'], true)) return [['CRN' => 1], ['CRN' => 2]];
    if (str_contains($sql, ' m WHERE')
        && ($scenario === 'all-conflicts'
            || ($scenario === 'faculty-conflict' && str_contains($sql, 'WHERE m.faculty_ID'))
            || ($scenario === 'room-conflict' && str_contains($sql, 'WHERE m.room_ID')))) {
        return [['CRN' => 1, 'course_ID' => 'TEST101']];
    }
    if (str_contains($sql, 'JOIN Faculty_Department') && ($params[1] ?? null) === 'TEST') {
        return [['faculty_ID' => 1, 'name' => 'Fixture Faculty', 'faculty_Type' => 'Full-time', 'teaching' => 0]];
    }
    return [];
}
function run(string $sql, array $params = []): object
{
    global $inserts;
    if (str_starts_with($sql, 'INSERT INTO Course_Section ') || str_starts_with($sql, 'INSERT INTO Course ')) $inserts[] = $params;
    elseif (!str_starts_with($sql, 'SELECT ')) throw new RuntimeException('Unexpected statement');
    return new class {
        public function columnCount(): int { return 0; }
        public function fetchAll(int $mode): array { return []; }
    };
}
require __DIR__ . '/../api/actions.php';
require __DIR__ . '/../api/views.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
if (($argv[1] ?? '') === '--form') {
    $courseOptions = [['course_ID' => 'TEST101', 'course_Name' => 'Fixture', 'course_Type' => 'Undergraduate',
        'course_Credits' => 3, 'dept_ID' => 'TEST']];
    echo json_encode(viewCreateSection(['id' => 1, 'role' => 'Admin'], ['course_ID' => 'TEST101', 'faculty_ID' => '1'])['blocks'][0], JSON_THROW_ON_ERROR);
    exit;
}
$invalid = [0, 1, 4, 11, -1, -10, 5.5, 5.0, 10.0, '0', '1', '4', '11', '-1', '5.5', '5.0', 'text', '', ' ', ' 5', '5 ', "5\n", '05', '+5', '5e0', '0x5', '5junk', null, true, false, [], ['5'], new stdClass()];
$cases = array_map(fn ($value) => ['max_Seats' => $value], $invalid);
$cases[] = []; // Missing field.
foreach ($cases as $form) {
    $queries = []; $inserts = [];
    try { createSection(1, $form); throw new RuntimeException('Invalid capacity accepted'); }
    catch (RuleError $e) { check($e->getMessage() === 'Section capacity must be a whole number between 5 and 10 students.', 'Incorrect validation message'); }
    check(count($queries) === 1 && $inserts === [], 'Invalid input reached semester queries or a write');
}
foreach ([5, 6, 9, 10, '5', '6', '9', '10'] as $value) {
    $inserts = [];
    $result = createSection(1, ['max_Seats' => $value, 'time_Slot_ID' => '1']);
    check(count($inserts) === 1 && $inserts[0][7] === (int) $value, 'Valid capacity was not passed as an integer');
    check(str_contains($result['message'], 'Created TEST101 section 001'), 'Existing creation response changed');
}
foreach (['readonly', 'small-room', 'department', 'load', 'faculty-conflict', 'room-conflict'] as $scenario) {
    $inserts = [];
    try { createSection(1, ['max_Seats' => '5', 'time_Slot_ID' => '1']); throw new RuntimeException("Existing guard failed: $scenario"); }
    catch (RuleError $e) { check($inserts === [], 'Existing guard allowed insert'); }
}
$scenario = 'all-conflicts';
try {
    createSection(1, ['max_Seats' => '5', 'time_Slot_ID' => '1']);
    throw new RuntimeException('Conflicting section was accepted.');
} catch (RuleError $e) {
    check(count(explode("\n", $e->getMessage())) === 4 && $inserts === [], 'All four conflicts must be returned together.');
}
$course = ['course_ID' => 'test101', 'course_Name' => 'Fixture', 'dept_ID' => 'TEST',
    'course_Credits' => '3', 'course_Type' => 'Undergraduate'];
foreach (['readonly', 'invalid-department'] as $scenario) {
    $inserts = [];
    $input = $scenario === 'invalid-department' ? ['dept_ID' => 'MISSING'] + $course : $course;
    try {
        createCourse(1, $input);
        throw new RuntimeException("Course creation guard failed: $scenario");
    } catch (RuleError $e) {
        check($inserts === [], 'Course guard allowed an insert.');
    }
}
$scenario = '';
createCourse(1, $course);
check($inserts === [['TEST101', 'Fixture', 'TEST', 3, null, 'Undergraduate']], 'Catalog course was saved incorrectly.');
$field = array_values(array_filter(viewCreateSection(['id' => 1], [])['blocks'][0]['fields'], fn ($f) => $f['name'] === 'max_Seats'))[0];
check($field['min'] === 5 && $field['max'] === 10 && $field['step'] === 1 && $field['required'] === true, 'Browser constraint metadata mismatch');
$courseOptions = [
    ['course_ID' => 'TEST101', 'course_Name' => 'Fixture', 'course_Type' => 'Undergraduate',
        'course_Credits' => 3, 'dept_ID' => 'TEST'],
    ['course_ID' => 'OTHER101', 'course_Name' => 'Other course', 'course_Type' => 'Undergraduate',
        'course_Credits' => 3, 'dept_ID' => 'OTHER'],
];
foreach (['' => [''], 'TEST101' => ['', '1'], 'OTHER101' => ['']] as $courseId => $expected) {
    $form = viewCreateSection(['id' => 1], ['course_ID' => $courseId, 'faculty_ID' => '1'])['blocks'][0];
    $fields = array_column($form['fields'], null, 'name');
    check(array_column($fields['faculty_ID']['options'], 0) === $expected, 'Faculty choices must match the course department.');
    check($fields['faculty_ID']['value'] === ($courseId === 'TEST101' ? '1' : ''), 'Faculty selection must belong to the course department.');
}
$scenario = 'readonly';
check(viewCreateSection(['id' => 1], [])['blocks'][0]['type'] === 'table', 'Read-only admin can view sections.');
check(viewCreateCourse(['id' => 1], [])['blocks'] === [], 'Read-only admin received the course creation form.');
$scenario = '';
echo 'PASS: ' . count($cases) . " invalid payloads, 8 valid payloads, 6 existing creation guards, and browser metadata.\n";
echo "Database-free only: SQL calls were in-memory stubs; no MySQL connection or actual section creation.\n";
