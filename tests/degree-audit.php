<?php
// Existing-data integration checks plus isolated in-memory calculation edge cases.
// No database fixtures, migrations, transaction control, or writes are permitted.
declare(strict_types=1);
function run(string $sql, array $params = []): PDOStatement
{
    if (!preg_match('/^\s*SELECT\b/i', $sql)) throw new RuntimeException('Only SELECT is permitted by this test.');
    static $pdo;
    $pdo ??= new PDO('mysql:unix_socket=' . (getenv('DB_SOCKET') ?: '/tmp/mysql.sock') . ';dbname=' . (getenv('DB_NAME') ?: 'campus_connect') . ';charset=utf8mb4',
        getenv('DB_USER') ?: 'campus', getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : 'campus',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $s = $pdo->prepare($sql); $s->execute($params); return $s;
}
function rows(string $sql, array $params = []): array { return run($sql, $params)->fetchAll(); }
function one(string $sql, array $params = []): ?array { return run($sql, $params)->fetch() ?: null; }
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
require __DIR__ . '/../api/views.php';
set_error_handler(static function ($severity, $message, $file, $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });

$students = rows('SELECT student_ID,student_Type FROM Student ORDER BY student_ID');
$coverage = ['students' => 0, 'graduate' => 0, 'no_history' => 0, 'repeated_courses' => 0, 'multiple_majors' => 0, 'with_minor' => 0, 'future_enrollment_students' => 0];
foreach ($students as $student) {
    $id = (int) $student['student_ID'];
    $audit = loadDegreeAudit($id);
    check($audit !== null && (int) $audit['profile']['id'] === $id, 'Student isolation/profile mismatch.');
    $reference = one("SELECT COUNT(DISTINCT h.course_ID) AS courses, COALESCE(SUM(c.course_Credits),0) AS credits FROM
        (SELECT DISTINCT course_ID FROM Student_History WHERE student_ID = ? AND grade IN ('D','D+','C-','C','C+','B-','B','B+','A-','A')) h
        JOIN Course c ON c.course_ID = h.course_ID", [$id]);
    check($audit['credits']['earned_provisional'] === (int) $reference['credits'], 'Transcript credit mismatch.');
    check($audit['credits']['passing_courses'] === (int) $reference['courses'], 'Passing course count mismatch.');
    check($audit['credits']['degree_target'] === null && $audit['credits']['degree_percent'] === null, 'Invented degree target/percentage.');
    $historyCount = (int) one('SELECT COUNT(*) AS n FROM Student_History WHERE student_ID=?', [$id])['n'];
    check(count($audit['history']) === $historyCount, 'Transcript attempts were lost.');
    foreach ($audit['programs'] as $program) {
        $table = $program['kind'] === 'Major' ? 'Major_Course_Requirement' : 'Minor_Course_Requirement';
        $key = $program['kind'] === 'Major' ? 'major_ID' : 'minor_ID';
        $ref = rows("SELECT r.course_ID,r.min_Grade_Req,EXISTS(SELECT 1 FROM Student_History h WHERE h.student_ID=? AND h.course_ID=r.course_ID
            AND FIELD(h.grade,'F','D','D+','C-','C','C+','B-','B','B+','A-','A')>1
            AND FIELD(h.grade,'F','D','D+','C-','C','C+','B-','B','B+','A-','A')>=FIELD(r.min_Grade_Req,'F','D','D+','C-','C','C+','B-','B','B+','A-','A')) AS met
            FROM $table r WHERE r.$key=? ORDER BY r.course_ID", [$id, $program['id']]);
        check(count($program['requirements']) === count($ref), 'Lost program requirements.');
        $met = 0;
        foreach ($ref as $i => $r) { check($program['requirements'][$i]['code'] === $r['course_ID'] && $program['requirements'][$i]['met'] === (bool) $r['met'], 'Requirement/minimum-grade mismatch.'); $met += (int) $r['met']; }
        check($program['met'] === $met && $program['percent'] === (count($ref) ? round(100*$met/count($ref),1) : null), 'Incorrect program progress.');
    }
    foreach (['in_progress' => 'sem.start_Date<=CURDATE() AND sem.end_Date>=CURDATE()', 'future_registered' => 'sem.start_Date>CURDATE()'] as $bucket => $condition) {
        $ref = rows("SELECT cs.CRN FROM Enrollment e JOIN Course_Section cs ON cs.CRN=e.CRN JOIN Semester sem ON sem.semester_ID=cs.semester_ID
            WHERE e.student_ID=? AND e.grade IS NULL AND $condition AND NOT EXISTS(SELECT 1 FROM Student_History h WHERE h.student_ID=e.student_ID AND h.CRN=e.CRN) ORDER BY cs.CRN", [$id]);
        $actual = array_map('intval', array_column($audit[$bucket], 'crn')); sort($actual);
        check($actual === array_map('intval', array_column($ref, 'CRN')), 'Current/future enrollment separation mismatch.');
    }
    $ignoredQuery = viewDegreeAudit(['id' => $id], ['student' => 999999999, 'id' => 999999999]);
    check($ignoredQuery['blocks'][0]['audit'] === $audit, 'Query parameter changed the audited student.');
    foreach (['Faculty','Admin','StatDept'] as $role) check(buildView(['id'=>$id,'role'=>$role], 'degree-audit', []) === null, 'Non-student audit access.');
    $coverage['students']++;
    if ($student['student_Type'] === 'Graduate') $coverage['graduate']++;
    if (!$historyCount) $coverage['no_history']++;
    if ($audit['credits']['repeated_courses']) $coverage['repeated_courses']++;
    if (count(array_filter($audit['programs'],static fn($p)=>$p['kind']==='Major'))>1) $coverage['multiple_majors']++;
    if (count(array_filter($audit['programs'],static fn($p)=>$p['kind']==='Minor'))) $coverage['with_minor']++;
    if ($audit['future_registered']) $coverage['future_enrollment_students']++;
}
check(loadDegreeAudit(2147483647) === null, 'Missing profile should remain unavailable.');

// Pure calculation tests do not create or modify database records.
$profile = ['id'=>1,'type'=>'Undergraduate','year'=>'Junior','first_Name'=>'Test','middle_Name'=>null,'last_Name'=>'Calculation','as_of'=>'2026-10-29','enrollment_status'=>null,'stored_credits'=>null];
$programs = [['kind'=>'Major','id'=>'P','name'=>'Program','is_primary'=>1,'declared'=>1],['kind'=>'Minor','id'=>'M','name'=>'Minor','is_primary'=>0,'declared'=>1]];
$requirements = [['kind'=>'Major','program_id'=>'P','code'=>'C1','title'=>'Course','credits'=>3,'minimum_grade'=>'B'],['kind'=>'Minor','program_id'=>'M','code'=>'C1','title'=>'Course','credits'=>3,'minimum_grade'=>'D']];
$attempt = ['crn'=>1,'code'=>'C1','title'=>'Course','credits'=>3,'grade'=>'D','semester_id'=>'OLD','semester'=>'Past','section_course'=>'C1','section_semester'=>'OLD','enrollment_grade'=>'D'];
$a = calculateDegreeAudit($profile,$programs,$requirements,[$attempt],[]);
check($a['programs'][0]['met']===0 && $a['programs'][1]['met']===1 && $a['credits']['earned_provisional']===3, 'Program-specific minimum grades/credit sharing calculations.');
$retry=$attempt;$retry['crn']=2;$retry['grade']=$retry['enrollment_grade']='A';
$a=calculateDegreeAudit($profile,$programs,$requirements,[$attempt,$retry],[]);
check($a['credits']['earned_provisional']===3 && $a['programs'][0]['met']===1 && count($a['history'])===2,'Repeat credits or best grade are incorrect.');
$unknown=$attempt;$unknown['grade']=$unknown['enrollment_grade']='P';
$a=calculateDegreeAudit($profile,$programs,$requirements,[$unknown],[]);
check($a['credits']['earned_provisional']===0 && $a['programs'][0]['requirements'][0]['status']==='Review needed','Unknown grade falsely earned credits or met a requirement.');
$a=calculateDegreeAudit($profile,$programs,[],[],[]);
check($a['programs'][0]['percent']===null && $a['credits']['earned_provisional']===0,'Empty course list falsely marked complete.');
$conflict=$attempt;$conflict['enrollment_grade']='A';
$a=calculateDegreeAudit($profile,$programs,$requirements,[$conflict],[]);
check($a['credits']['earned_provisional']===0 && count($a['review'])===1,'Conflicting record falsely credited.');
$e=['crn'=>3,'code'=>'C1','title'=>'Course','credits'=>3,'grade'=>null,'enrollment_course'=>'C1','semester_id'=>'TERM','semester'=>'Term','start_Date'=>'2026-08-01','end_Date'=>'2026-12-31'];
$a=calculateDegreeAudit($profile,$programs,$requirements,[],[$e]);
check(count($a['in_progress'])===1 && $a['credits']['earned_provisional']===0 && $a['programs'][0]['met']===0,'In-progress course falsely completed.');
$e['start_Date']='2027-01-01';$e['end_Date']='2027-05-01';
$a=calculateDegreeAudit($profile,$programs,$requirements,[],[$e]);check(count($a['future_registered'])===1 && !$a['in_progress'],'Future enrollment treated as in progress.');
$e['start_Date']='2025-01-01';$e['end_Date']='2025-05-01';
$a=calculateDegreeAudit($profile,$programs,$requirements,[],[$e]);check(count($a['pending_records'])===1 && !$a['in_progress'],'Old ungraded enrollment treated as in progress.');
echo 'PASS: SELECT-only comparisons for '.count($students).' existing students and pure calculation edge cases (no DB fixtures).'.PHP_EOL;
echo json_encode($coverage,JSON_PRETTY_PRINT).PHP_EOL;
