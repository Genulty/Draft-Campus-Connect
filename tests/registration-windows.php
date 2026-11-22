<?php
// Isolated only: copied semester dates, synthetic student/course rows, SQLite :memory:.
// Never loads db.php or opens a MySQL connection. Enrollment writes are intercepted.
declare(strict_types=1);
class RuleError extends Exception {}
class ReachedRegistrationWrite extends Exception {}
function fail(string $message): never { throw new RuleError($message); }
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$clock = '2027-01-15'; $active = false; $queries = []; $writes = 0;
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/registration-semesters.json'), true, 512, JSON_THROW_ON_ERROR);
$memory = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$memory->exec('CREATE TABLE Semester (semester_ID TEXT, semester_Name TEXT, start_Date TEXT, add_Start TEXT, add_End TEXT, drop_Start TEXT, drop_End TEXT)');
$memory->exec('CREATE TABLE Course (course_ID TEXT, course_Name TEXT, course_Credits INT, course_Type TEXT)');
$memory->exec("INSERT INTO Course VALUES ('TEST100','Isolated test course',3,'Undergraduate')");
$memory->exec('CREATE TABLE Course_Section (CRN INT, course_ID TEXT, semester_ID TEXT, time_Slot_ID INT, available_Seats INT, max_Seats INT)');
$memory->exec('CREATE TABLE Enrollment (student_ID INT, CRN INT, grade TEXT)');
foreach ($fixture as $i => $sem) {
    $memory->prepare('INSERT INTO Semester VALUES (?,?,?,?,?,?,?)')->execute(array_values($sem));
    $memory->prepare('INSERT INTO Course_Section VALUES (?,?,?,?,?,?)')->execute([90001+$i,'TEST100',$sem['semester_ID'],1,10,10]);
    $memory->prepare('INSERT INTO Enrollment VALUES (?,?,NULL)')->execute([1,90001+$i]);
}
function db(): object { return new class { public function inTransaction(): bool { return $GLOBALS['active']; } }; }
function transaction(callable $fn): mixed
{
    $GLOBALS['active'] = true;
    try { return $fn(); } finally { $GLOBALS['active'] = false; }
}
function selectFixture(string $sql, array $params = []): array
{
    check(str_starts_with($sql, 'SELECT '), 'Only fixture SELECTs may reach SQLite adapter');
    check((bool) preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $GLOBALS['clock']), 'Invalid test clock');
    $sql = str_replace('CURDATE()', "'" . $GLOBALS['clock'] . "'", $sql);
    $sql = preg_replace('/ FOR UPDATE$/', '', $sql);
    $stmt = $GLOBALS['memory']->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
}
function one(string $sql, array $params = []): ?array
{
    $GLOBALS['queries'][] = [$sql,$params];
    if ($sql === 'SELECT CURDATE() AS as_of') return ['as_of' => $GLOBALS['clock']];
    if (str_starts_with($sql, 'SELECT s.student_ID AS id')) return null; // This fixture has no audit profile.
    if (str_starts_with($sql, 'SELECT student_ID FROM Student')) return in_array($params[0], [1,2], true) ? ['student_ID'=>$params[0]] : null;
    if (str_starts_with($sql, 'SELECT COUNT(*) AS n FROM (')) return ['n'=>0];
    if (str_starts_with($sql, 'SELECT cs.*') || str_starts_with($sql, 'SELECT e.grade')) return selectFixture($sql, $params)[0] ?? null;
    if (str_contains($sql, 'COALESCE(ug.undergraduate_Student_Type')) return ['dept'=>'TEST','load_type'=>'Full-time','student_Type'=>'Undergraduate'];
    if (str_contains($sql, 'SELECT s.student_Type')) return ['student_Type'=>'Undergraduate','student_Year'=>'Senior','load_type'=>'Full-time'];
    if (str_contains($sql, 'GROUP_CONCAT(h.hold_Type')) return ['list'=>null];
    if (str_contains($sql, 'SUM(c.course_Credits)')) return selectFixture($sql, $params)[0];
    if (str_contains($sql, 'FROM Enrollment e') || str_contains($sql, 'FROM Student_History h') || str_contains($sql, 'FROM Course_Section_Lab')) return null;
    throw new RuntimeException('Unexpected one(): ' . $sql);
}
function rows(string $sql, array $params = []): array
{
    if (str_contains($sql, 'FROM Student_History h LEFT JOIN Course c')) return [];
    if (str_contains($sql, 'FROM Gen_Ed_Requirement r')) return [];
    if (str_contains($sql, 'FROM Semester')) return selectFixture($sql, $params);
    if (str_contains($sql, 'FROM Department')) return [['dept_ID'=>'TEST','dept_Name'=>'Fixture department'], ['dept_ID'=>'OTHER','dept_Name'=>'Other fixture']];
    foreach (['Student_Hold', 'Course_Prerequisite', 'Course_Program_Restriction',
        'Gen_Ed_Requirement_Course', 'Major_Course_Requirement', 'Degree_Audit_Elective_Course'] as $table) {
        if (str_contains($sql, 'FROM ' . $table)) return [];
    }
    throw new RuntimeException('Unexpected rows(): ' . $sql);
}
function run(string $sql, array $params = []): object
{
    if (preg_match('/^(INSERT INTO|DELETE FROM) Enrollment /', $sql)) {
        check($GLOBALS['active'], 'Action must retain its transaction');
        $GLOBALS['writes']++;
        throw new ReachedRegistrationWrite('Stopped before enrollment write');
    }
    check(str_starts_with($sql, 'SELECT '), 'Unexpected statement');
    $columns = ['CRN','Course','Title','Section'];
    if (str_contains($sql, 'FROM Enrollment e')) {
        $list = array_map('array_values', selectFixture("SELECT cs.CRN, c.course_ID, c.course_Name, '001'
          FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Course c ON c.course_ID = cs.course_ID
          WHERE e.student_ID = ? AND cs.semester_ID = ? ORDER BY cs.CRN", $params));
    } else {
        $GLOBALS['queries'][] = [$sql, $params];
        $crn = (int) selectFixture('SELECT CRN FROM Course_Section WHERE semester_ID = ? ORDER BY CRN LIMIT 1', [$params[0]])[0]['CRN'];
        $emptyDepartment = str_contains($sql, 'AND c.dept_ID = ?') && $params[2] === 'OTHER';
        $list = $emptyDepartment ? [] : [[$crn,'TEST100','Isolated test course','001']];
    }
    return new class($columns, $list) {
        public function __construct(private array $columns, private array $list) {}
        public function columnCount(): int { return count($this->columns); }
        public function getColumnMeta(int $i): array { return ['name'=>$this->columns[$i]]; }
        public function fetchAll(int $mode): array { return $this->list; }
    };
}
require __DIR__ . '/../api/actions.php';
require __DIR__ . '/../api/views.php';
set_error_handler(static function ($severity,$message,$file,$line): never { throw new ErrorException($message,0,$severity,$file,$line); });
$user = ['id'=>1,'role'=>'Student'];
function expectAction(string $fn, int $student, int $crn, bool $open, ?string $rejection = null): void
{
    $before = $GLOBALS['writes']; $GLOBALS['queries'] = [];
    try { $fn($student,$crn); throw new RuntimeException('Expected boundary or rejection'); }
    catch (ReachedRegistrationWrite $e) { check($open, "$fn bypassed a closed window"); }
    catch (RuleError $e) {
        check(!$open, "$fn unexpectedly rejected an open window: {$e->getMessage()}");
        $expected = $rejection ?? ($fn === 'addSection' ? 'The add period' : 'The drop period');
        check(str_contains($e->getMessage(), $expected), "Unexpected rejection: {$e->getMessage()}");
    }
    check($GLOBALS['writes'] === $before + ($open ? 1 : 0), 'Wrong write boundary count');
    check(str_contains($GLOBALS['queries'][0][0], 'FROM Student WHERE student_ID = ? FOR UPDATE'), 'Student-first locking changed');
}
$cases = 0;
foreach ($fixture as $i => $sem) {
    $dates = [];
    foreach (['add_Start','add_End','drop_Start','drop_End'] as $key) {
        foreach ([-1,0,1] as $delta) $dates[] = (new DateTimeImmutable($sem[$key]))->modify("$delta day")->format('Y-m-d');
    }
    foreach (array_unique($dates) as $clock) {
        $add = $clock >= $sem['add_Start'] && $clock <= $sem['add_End'];
        $drop = $clock >= $sem['drop_Start'] && $clock <= $sem['drop_End'];
        foreach ([1,2] as $student) {
            $page = viewRegister(['id'=>$student,'role'=>'Student'], ['semester'=>$sem['semester_ID']]);
            $options = $page['blocks'][0]['filters'][0]['options'] ?? [];
            check(in_array($sem['semester_ID'], array_column($options,0),true) === ($add || $drop), 'Semester visibility mismatch');
            if ($add || $drop) {
                check($page['blocks'][0]['filters'][0]['value'] === $sem['semester_ID'], 'Requested semester not selected');
                foreach ($page['blocks'] as $block) if (isset($block['action'])) {
                    $allowed = $block['action']['endpoint'] === 'drop-section' ? $drop : $add;
                    check($block['action']['disabled'] === !$allowed, 'Incorrect action availability');
                }
                check(count($page['blocks'][2]['rows']) === ($student === 1 ? 1 : 0), 'Registration isolation mismatch');
            }
        }
        expectAction('addSection',2,90001+$i,$add);
        expectAction('dropSection',1,90001+$i,$drop);
        $cases++;
    }
}
// Stored dates have no add-only interval. Change ONLY the in-memory fixture to test that state.
$memory->exec("UPDATE Semester SET drop_Start='2027-01-01' WHERE semester_ID='SP27'");
$clock='2026-12-01';
$addOnly=viewRegister($user,['semester'=>'SP27']);
check($addOnly['blocks'][0]['filters'][1]['value']===''
    && $addOnly['blocks'][0]['filters'][1]['options'][0]===['', 'All Departments'], 'Registration must default to all departments');
check($addOnly['blocks'][2]['action']['disabled'] === true && $addOnly['blocks'][3]['action']['disabled'] === false, 'Add-only controls incorrect');
expectAction('addSection',2,90006,true); expectAction('dropSection',1,90006,false);
$memory->exec("UPDATE Semester SET drop_Start='2026-10-01' WHERE semester_ID='SP27'");
$pages=['add-only'=>$addOnly];
foreach (['both-open'=>'2027-02-01','drop-only'=>'2027-02-02','last-drop-day'=>'2027-02-15','both-closed'=>'2027-02-16'] as $name=>$clock) {
    $pages[$name]=viewRegister($user,['semester'=>'SP27']);
}
$clock='2027-02-02';
$pages['no-enrollments']=viewRegister(['id'=>2,'role'=>'Student'],['semester'=>'SP27']);
$clock = '2027-02-01';
$pages['empty-department'] = viewRegister($user, ['semester'=>'SP27','dept'=>'OTHER']);
$all = $pages['both-open']['blocks'];
$other = $pages['empty-department']['blocks'];
check($all[0]['filters'][1]['value'] === '', 'All Departments must be the default');
check($all[0]['filters'][1]['options'][0] === ['', 'All Departments'], 'Missing All Departments option');
check($all[1] === $other[1] && $all[2] === $other[2], 'Department filter changed semester credits or schedule');
check($other[3]['empty']['text'] === 'No eligible sections in Other fixture', 'Wrong department empty message');
check($other[3]['empty']['params'] === ['semester'=>'SP27','dept'=>''], 'Recovery must preserve the semester');
foreach (['unknown', ['TEST']] as $invalidDept) {
    $page = viewRegister($user, ['semester'=>'SP27', 'dept'=>$invalidDept]);
    check($page['blocks'][0]['filters'][1]['value'] === '', 'Invalid department must fall back to All Departments');
}
$clock = '2027-02-02';
foreach (['bogus','FA26', ['SP27']] as $requested) {
    $page=viewRegister($user,['semester'=>$requested,'dept'=>'OTHER']);
    check($page['blocks'][0]['filters'][0]['value']==='SP27', 'Invalid/closed selection must safely fall back');
    check($page['blocks'][0]['filters'][1]['value']==='OTHER', 'Department filter lost');
}
foreach (['Admin','Faculty','StatDept'] as $role) check(buildView(['id'=>1,'role'=>$role],'register',[])===null,'Unauthorized view access');
foreach ([null,'',0,-1,[],true,90006.0,'90006junk','9e4','90006.0',' 90006','9999999999999999999999999'] as $bad) {
    $before=count($queries);
    try { registrationCrn($bad); throw new RuntimeException('Malformed CRN accepted'); } catch (RuleError $e) {}
    check(count($queries)===$before,'CRN validation touched the database');
}
check(registrationCrn('90006')===90006 && registrationCrn(90006)===90006,'Valid CRN rejected');
$clock='2027-02-02'; expectAction('dropSection',2,90006,false,'You are not registered'); // No registration for another student.
expectAction('addSection',2,99999,false,'That course section does not exist'); expectAction('dropSection',1,99999,false,'You are not registered');
// Exercise load guidance and bindings using only generic SQLite fixture sections.
$clock = '2027-01-15';
$progress = [];
$expectedByTotal = [8 => '4 credits to go', 11 => '1 credit to go', 12 => 'Goal reached', 13 => 'Goal reached'];
$total = 0;
foreach ([4, 4, 3, 1, 1] as $i => $credits) {
    $crn = 91001 + $i;
    $code = 'LOAD' . ($i + 100);
    $memory->prepare("INSERT INTO Course VALUES (?, ?, ?, 'Undergraduate')")->execute([$code, 'Load test course', $credits]);
    $memory->prepare("INSERT INTO Course_Section VALUES (?, ?, 'SP27', 1, 10, 10)")->execute([$crn, $code]);
    $memory->prepare('INSERT INTO Enrollment VALUES (2, ?, NULL)')->execute([$crn]);
    $total += $credits;
    if (!isset($expectedByTotal[$total])) continue;
    $base = viewRegister(['id'=>2,'role'=>'Student'], ['semester'=>'SP27']);
    check($base['blocks'][1]['items'][0]['value'] === "$total of 16", 'Wrong semester credits');
    check($base['blocks'][1]['items'][1] === ['label'=>'Credit Goal: 12','value'=>$expectedByTotal[$total]],
        "Wrong credit goal guidance at $total credits");
    check($base['blocks'][1]['items'][2]['value'] === 'Full-time', 'Recorded status changed');
    check(count($base['blocks'][2]['rows']) === $i + 1, 'Unexpected registered sections');
    foreach (['', 'TEST', 'OTHER'] as $dept) {
        $queries = [];
        $page = viewRegister(['id'=>2,'role'=>'Student'], ['semester'=>'SP27','dept'=>$dept]);
        $offering = array_values(array_filter($queries, fn ($query) => str_contains($query[0], 'AS Prerequisites')));
        check(count($offering) === 1, 'Expected one department-scoped offering query');
        [$offeringQuery, $bindings] = $offering[0];
        check(str_contains($offeringQuery, 'AND c.dept_ID = ?') === ($dept !== ''), 'Department filter applies to the wrong query');
        check($bindings === ($dept === '' ? ['SP27','Undergraduate',2] : ['SP27','Undergraduate',$dept,2]), 'Incorrect offering bindings');
        check($page['blocks'][1] === $base['blocks'][1] && $page['blocks'][2] === $base['blocks'][2], 'Filter changed credits or registration');
        $progress[$total][$dept] = $page;
    }
}
if (($argv[1] ?? '')==='--pages') { echo json_encode($pages + ['progress'=>$progress],JSON_THROW_ON_ERROR); exit; }
echo "PASS: All Departments default, filter-only offering bindings, unchanged registrations, and credit goal guidance at 8, 11, 12, and 13 credits.
";
echo "PASS: $cases stored-date boundary scenarios across six semesters, students with/without registrations, isolated add-only state, independent action guards, malformed CRNs, role restrictions, and safe filter selection.\n";
echo "No MySQL connection: all fixtures are SQLite :memory:, enrollment writes stopped before execution. Existing prerequisite/seat/conflict rules remain unchanged; their live behavior is not simulated by these stubs.\n";
