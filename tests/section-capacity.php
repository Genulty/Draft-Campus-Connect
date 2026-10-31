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
    if (str_contains($sql, 'FROM Course WHERE')) return ['course_ID' => 'TEST101', 'course_Name' => 'Fixture', 'dept_ID' => 'TEST'];
    if (str_contains($sql, 'FROM Faculty f')) return ['faculty_ID' => 1, 'faculty_Type' => 'Full-time', 'name' => 'Fixture Faculty'];
    if (str_contains($sql, 'FROM Time_Slot WHERE')) return ['time_Slot_ID' => 1];
    if (str_contains($sql, 'FROM Room r')) return ['room_ID' => 'TEST', 'capacity' => $scenario === 'small-room' ? 4 : 10];
    if (str_contains($sql, 'FROM Faculty_Department')) return $scenario === 'department' ? null : ['ok' => 1];
    if (str_contains($sql, ' m WHERE')) {
        if (($scenario === 'faculty-conflict' && str_contains($sql, 'WHERE m.faculty_ID'))
            || ($scenario === 'room-conflict' && str_contains($sql, 'WHERE m.room_ID'))) return ['CRN' => 1, 'course_ID' => 'TEST101'];
        return null;
    }
    if (str_contains($sql, 'MAX(CRN)')) return ['crn' => 10001];
    if (str_contains($sql, 'COUNT(*) AS n')) return ['n' => 0];
    throw new RuntimeException('Unexpected query: ' . $sql);
}
function rows(string $sql, array $params = []): array
{
    global $scenario;
    if (str_contains($sql, 'FROM Semester')) return [['semester_ID' => 'TEST', 'semester_Name' => 'Fixture']];
    if (str_contains($sql, 'FROM Room r')) return [['room_ID' => 'TEST', 'building_Name' => 'Fixture', 'room_No' => '1', 'room_Type' => 'Lecture', 'capacity' => 10]];
    if (str_contains($sql, 'SELECT CRN FROM Course_Section') && str_contains($sql, 'faculty_ID') && $scenario === 'load') return [['CRN' => 1], ['CRN' => 2]];
    return [];
}
function run(string $sql, array $params = []): object
{
    global $inserts;
    if (str_starts_with($sql, 'INSERT INTO Course_Section ')) $inserts[] = $params;
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
    echo json_encode(viewCreateSection(['id' => 1, 'role' => 'Admin'], [])['blocks'][0], JSON_THROW_ON_ERROR);
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
    $result = createSection(1, ['max_Seats' => $value]);
    check(count($inserts) === 1 && $inserts[0][7] === (int) $value, 'Valid capacity was not passed as an integer');
    check(str_contains($result['message'], 'Created TEST101 section 001'), 'Existing creation response changed');
}
foreach (['readonly', 'small-room', 'department', 'load', 'faculty-conflict', 'room-conflict'] as $scenario) {
    $inserts = [];
    try { createSection(1, ['max_Seats' => '5']); throw new RuntimeException("Existing guard failed: $scenario"); }
    catch (RuleError $e) { check($inserts === [], 'Existing guard allowed insert'); }
}
$scenario = '';
$field = array_values(array_filter(viewCreateSection([], [])['blocks'][0]['fields'], fn ($f) => $f['name'] === 'max_Seats'))[0];
check($field['min'] === 5 && $field['max'] === 10 && $field['step'] === 1 && $field['required'] === true, 'Browser constraint metadata mismatch');
echo 'PASS: ' . count($cases) . " invalid payloads, 8 valid payloads, 6 existing creation guards, and browser metadata.\n";
echo "Database-free only: SQL calls were in-memory stubs; no MySQL connection or actual section creation.\n";
