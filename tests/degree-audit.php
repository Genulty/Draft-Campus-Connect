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
        (SELECT DISTINCT h.course_ID FROM Student_History h JOIN Course_Section cs ON cs.CRN=h.CRN
          JOIN Semester sem ON sem.semester_ID=h.semester_ID
          LEFT JOIN Enrollment e ON e.student_ID=h.student_ID AND e.CRN=h.CRN
          WHERE h.student_ID = ? AND h.grade IN ('D','D+','C-','C','C+','B-','B','B+','A-','A')
          AND cs.course_ID=h.course_ID AND cs.semester_ID=h.semester_ID AND sem.start_Date<=CURDATE()
          AND (e.CRN IS NULL OR (e.course_ID=h.course_ID AND (e.grade IS NULL OR e.grade=h.grade)))) h
        JOIN Course c ON c.course_ID = h.course_ID", [$id]);
    check($audit['credits']['earned_provisional'] === (int) $reference['credits'], 'Transcript credit mismatch.');
    check($audit['credits']['passing_courses'] === (int) $reference['courses'], 'Passing course count mismatch.');
    check($audit['degree_requirements']['credits_applied'] === (int) $reference['credits'], 'Degree credits must match validated distinct passing courses.');
    check($audit['credits']['degree_target'] === null && $audit['credits']['degree_percent'] === null, 'Invented degree target/percentage.');
    $historyCount = (int) one('SELECT COUNT(*) AS n FROM Student_History WHERE student_ID=?', [$id])['n'];
    check(count($audit['history']) === $historyCount, 'Transcript attempts were lost.');
    foreach ($audit['programs'] as $program) {
        $table = $program['kind'] === 'Major' ? 'Major_Course_Requirement' : 'Minor_Course_Requirement';
        $key = $program['kind'] === 'Major' ? 'major_ID' : 'minor_ID';
        $ref = rows("SELECT r.course_ID,r.min_Grade_Req,EXISTS(SELECT 1 FROM Student_History h JOIN Course_Section cs ON cs.CRN=h.CRN
            JOIN Semester sem ON sem.semester_ID=h.semester_ID
            LEFT JOIN Enrollment e ON e.student_ID=h.student_ID AND e.CRN=h.CRN
            WHERE h.student_ID=? AND h.course_ID=r.course_ID
            AND cs.course_ID=h.course_ID AND cs.semester_ID=h.semester_ID AND sem.start_Date<=CURDATE()
            AND (e.CRN IS NULL OR (e.course_ID=h.course_ID AND (e.grade IS NULL OR e.grade=h.grade)))
            AND FIELD(r.min_Grade_Req,'F','D','D+','C-','C','C+','B-','B','B+','A-','A')>0
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
            WHERE e.student_ID=? AND e.course_ID=cs.course_ID AND e.grade IS NULL AND $condition AND NOT EXISTS(SELECT 1 FROM Student_History h WHERE h.student_ID=e.student_ID AND h.CRN=e.CRN) ORDER BY cs.CRN", [$id]);
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
$attempt = ['crn'=>1,'code'=>'C1','title'=>'Course','credits'=>3,'grade'=>'D','semester_id'=>'OLD','semester'=>'Past','term_start'=>'2025-01-01','section_course'=>'C1','section_semester'=>'OLD','enrollment_grade'=>'D'];
$a = calculateDegreeAudit($profile,$programs,$requirements,[$attempt],[]);
check($a['programs'][0]['met']===0 && $a['programs'][1]['met']===1 && $a['programs'][1]['requirements'][0]['status']==='Completed' && $a['credits']['earned_provisional']===3, 'Program-specific minimum grades/credit sharing calculations.');
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
check(count($a['in_progress'])===1 && $a['credits']['earned_provisional']===0 && $a['programs'][0]['met']===0 && $a['programs'][0]['requirements'][0]['status']==='In progress','In-progress course falsely completed.');
$e['start_Date']='2027-01-01';$e['end_Date']='2027-05-01';
$a=calculateDegreeAudit($profile,$programs,$requirements,[],[$e]);check(count($a['future_registered'])===1 && !$a['in_progress'],'Future enrollment treated as in progress.');
$e['start_Date']='2025-01-01';$e['end_Date']='2025-05-01';
$a=calculateDegreeAudit($profile,$programs,$requirements,[],[$e]);check(count($a['pending_records'])===1 && !$a['in_progress'],'Old ungraded enrollment treated as in progress.');
$genEd = [
    ['id'=>'WRITING','name'=>'Writing','code'=>'C1','title'=>'Course','minimum_grade'=>'B'],
    ['id'=>'WRITING','name'=>'Writing','code'=>'C2','title'=>'Alternative','minimum_grade'=>'D'],
];
$active = array_replace($e, ['code'=>'C2','enrollment_course'=>'C2','start_Date'=>'2026-08-01','end_Date'=>'2026-12-31']);
$a = calculateDegreeAudit($profile, [], [], [$attempt], [], $genEd);
check($a['gen_ed'][0]['status']==='Not completed' && count($a['gen_ed'][0]['options'])===2, 'Gen Ed options or minimum grade mismatch.');
$a = calculateDegreeAudit($profile, [], [], [$attempt], [$active], $genEd);
check($a['gen_ed'][0]['status']==='In progress' && $a['gen_ed'][0]['course']['code']==='C2', 'Current Gen Ed option was not matched.');
$a = calculateDegreeAudit($profile, [], [], [$retry], [$active], $genEd);
check($a['gen_ed'][0]['status']==='Completed' && $a['gen_ed'][0]['course']['semester']==='Past', 'Completed Gen Ed option must take precedence.');
$a = calculateDegreeAudit(array_replace($profile, ['type'=>'Graduate']), [], [], [$retry], [$active], $genEd);
check($a['gen_ed']===[], 'Undergraduate Gen Ed applied to a graduate student.');
$a = calculateDegreeAudit($profile, $programs, $requirements, [$retry], [$active], $genEd);
check($a['gen_ed'][0]['status']==='Completed' && $a['programs'][0]['met']===1 && $a['programs'][1]['met']===1
    && $a['credits']['earned_provisional']===3 && $a['credits']['degree_target']===null,
    'Shared course evidence inflated earned credits or affected eligibility.');
check($a['gen_ed'][0]['course']['crn']===2 && $a['gen_ed'][0]['minimum_grade']==='B'
    && $a['programs'][0]['requirements'][0]['course']['crn']===2, 'Winning transcript evidence or rule was lost.');

$failing = array_replace($attempt, ['grade'=>'F','enrollment_grade'=>'F']);
$a = calculateDegreeAudit($profile, $programs, $requirements, [$failing], [], $genEd);
check($a['credits']['earned_provisional']===0 && $a['gen_ed'][0]['status']==='Not completed'
    && $a['gen_ed'][0]['course']['grade']==='F', 'Failing evidence was credited or lost.');
$a = calculateDegreeAudit($profile, $programs, $requirements, [$attempt], [], $genEd);
check($a['programs'][0]['requirements'][0]['detail']==='Transcript grade D is below the B minimum.'
    && $a['gen_ed'][0]['course']['grade']==='D', 'Below-minimum evidence was lost.');

$courseConflict = array_replace($attempt, ['enrollment_course'=>'C2']);
$a = calculateDegreeAudit($profile, $programs, $requirements, [$courseConflict], [$active], $genEd);
check($a['credits']['earned_provisional']===0 && $a['gen_ed'][0]['status']==='Review needed'
    && $a['programs'][1]['requirements'][0]['status']==='Review needed'
    && $a['gen_ed'][0]['options'][1]['status']==='Review needed', 'Conflicting enrollment identifier was not reconciled.');
$futureGrade = array_replace($retry, ['term_start'=>'2027-01-01']);
$a = calculateDegreeAudit($profile, [], [], [$futureGrade], [], $genEd);
check($a['credits']['earned_provisional']===0 && $a['gen_ed'][0]['status']==='Review needed', 'Future transcript grade was credited.');
$future = array_replace($active, ['start_Date'=>'2027-01-01','end_Date'=>'2027-05-01']);
$a = calculateDegreeAudit($profile, [], [], [], [$future], $genEd);
check($a['gen_ed'][0]['status']==='Not completed' && $a['gen_ed'][0]['detail']==='Registered for a future term.'
    && $a['gen_ed'][0]['course']['crn']===$future['crn'], 'Future registration was treated as progress or lost as evidence.');

$unmapped = [['id'=>'EMPTY','name'=>'Empty','code'=>null,'title'=>null,'minimum_grade'=>null]];
$badMinimum = [array_replace($genEd[1], ['minimum_grade'=>'P'])];
foreach ([$unmapped, $badMinimum] as $rules) {
    $a = calculateDegreeAudit($profile, [], [], [], [$active], $rules);
    check($a['gen_ed'][0]['status']==='Rules unavailable', 'An unconfigured rule was evaluated.');
}
$a = calculateDegreeAudit($profile, $programs, $requirements, [], [$e], $genEd);
check($a['programs'][0]['requirements'][0]['status']==='Review needed' && $a['gen_ed'][0]['status']==='Review needed',
    'Missing transcript for a past enrollment was treated as an ordinary unmet requirement.');
$orphan = array_replace($retry, ['term_start'=>null, 'section_course'=>null]);
$a = calculateDegreeAudit($profile, $programs, $requirements, [$orphan], [], $genEd);
check(count($a['history'])===1 && $a['history'][0]['result']==='Review needed' && $a['credits']['earned_provisional']===0,
    'An orphaned transcript attempt was hidden or credited.');
$creditPolicy = ['name'=>'B.S. in Computer Science','total'=>120,'liberal_arts'=>60,'draft'=>true,'eligible_courses'=>['C1','C2']];
$a = calculateDegreeAudit($profile, $programs, $requirements, [$attempt,$retry], [$active], $genEd, $creditPolicy);
$degree = $a['degree_requirements'];
$liberal = $a['liberal_arts'];
check($degree['credits_required']===120 && $degree['credits_applied']===3 && $degree['status']==='In progress'
    && $liberal['credits_required']===60 && $liberal['credits_applied']===3 && $liberal['credits_still_needed']===57,
    'Below-target draft credit totals are incorrect.');
check(array_column($liberal['courses'],'status')===['Completed','In progress']
    && array_column($liberal['courses'],'code')===['C1','C2']
    && $liberal['courses'][0]['grade']==='A' && $a['programs'][0]['met']===1 && $a['gen_ed'][0]['status']==='Completed',
    'Eligible repeat, active course, or cross-block overlap was evaluated incorrectly.');

$above = [];
for ($i=1; $i<=43; $i++) {
    $code = "C$i";
    $above[] = array_replace($retry, ['crn'=>100+$i,'code'=>$code,'section_course'=>$code,'credits'=>$i===1 ? 5 : 3]);
}
$unfinished = array_replace($active, ['crn'=>999,'code'=>'C44','enrollment_course'=>'C44']);
$unfinishedRule = array_replace($requirements[0], ['code'=>'C44']);
$creditPolicy['eligible_courses'] = [...array_map(static fn ($i) => "C$i", range(1,21)), 'C44'];
$a = calculateDegreeAudit($profile, $programs, [...$requirements,$unfinishedRule], $above, [$unfinished,$future], $genEd, $creditPolicy);
$degree = $a['degree_requirements'];
check($degree['credits_applied']===131 && $degree['status']==='Credit target met'
    && $degree['checklist'][0]['status']==='In progress' && $a['credits']['degree_target']===null,
    'Total must exceed the target without completing an unfinished major or certifying graduation.');
check($a['liberal_arts']['credits_applied']===60 && $a['liberal_arts']['credits_still_needed']===0
    && $a['liberal_arts']['status']==='Completed' && end($a['liberal_arts']['courses'])['status']==='In progress',
    'Liberal Arts credits were not capped or the current eligible course was lost.');

$a = calculateDegreeAudit($profile, $programs, $requirements, [$retry], []);
check($a['degree_requirements']['status']==='Rules unavailable' && $a['degree_requirements']['credits_required']===null
    && $a['liberal_arts']['status']==='Rules unavailable' && $a['liberal_arts']['credits_applied']===null,
    'Missing program policy inherited another program target.');
$master = ['name'=>'Configured master’s program','total'=>36,'liberal_arts'=>null,'draft'=>true,'eligible_courses'=>[]];
$a = calculateDegreeAudit(array_replace($profile,['type'=>'Graduate']), $programs, $requirements, [$retry], [], [], $master);
check($a['degree_requirements']['credits_required']===36 && $a['degree_requirements']['credits_applied']===3
    && !in_array('General Education',array_column($a['degree_requirements']['checklist'],'name'),true),
    'Configured master’s target or applicable blocks are incorrect.');
$a = calculateDegreeAudit($profile, $programs, $requirements, [$retry], [], $genEd,
    array_replace($creditPolicy,['eligible_courses'=>[]]));
check($a['liberal_arts']['status']==='Rules unavailable' && $a['liberal_arts']['credits_required']===60,
    'Missing Liberal Arts mappings must not be treated as zero earned credits.');
$c1 = ['code'=>'C1','title'=>'Course','minimum_grade'=>'B'];
$c2 = ['code'=>'C2','title'=>'Alternative','minimum_grade'=>'D'];
$rules = [
    'DIVERSITY'=>['draft'=>true,'share'=>true,'options'=>[$c1,$c2]],
    'MATH'=>['draft'=>true,'placement_configured'=>true,'placement_met'=>true],
    'WRITING'=>['draft'=>true,'share'=>true,'options'=>[$c1]],
];
$a = calculateDegreeAudit($profile, $programs, $requirements, [$attempt,$retry], [], $genEd, null, $rules);
check($a['diversity']['status']==='Completed' && $a['proficiency']['status']==='Completed'
    && array_column($a['proficiency']['requirements'],'detail')===['Met by placement','Met by coursework']
    && $a['proficiency']['requirements'][0]['course']===null && $a['credits']['earned_provisional']===3,
    'Placement or explicit course sharing changed earned credits or did not satisfy the rules.');
$checklist = array_column($a['degree_requirements']['checklist'],'status','name');
check($checklist['Diversity']==='Completed' && $checklist['Proficiency']==='Completed',
    'Supplemental results are missing from the checklist.');
$rules['DIVERSITY']['share'] = false;
$a = calculateDegreeAudit($profile, $programs, $requirements, [$retry], [$active], $genEd, null, $rules);
check($a['diversity']['status']==='In progress' && $a['diversity']['course']['code']==='C2'
    && $a['diversity']['options'][0]['status']==='Not completed',
    'Exclusive Diversity policy reused a course already applied to another block.');
$a = calculateDegreeAudit($profile, [], [], [], [$future], [], null, [
    'DIVERSITY'=>['options'=>[$c2]], 'MATH'=>['placement_configured'=>true,'placement_met'=>false],
]);
check($a['diversity']['status']==='Not completed' && $a['proficiency']['requirements'][0]['status']==='Not completed'
    && $a['proficiency']['requirements'][1]['status']==='Rules unavailable',
    'Future registration or missing placement/mappings falsely satisfied a requirement.');
$alt = array_replace($retry,['code'=>'C2','section_course'=>'C2','crn'=>7]);
$a = calculateDegreeAudit($profile, [], [], [$retry,$alt], [], [], null, [
    'DIVERSITY'=>['options'=>[$c1,$c2]], 'MATH'=>['options'=>[$c1]],
    'WRITING'=>['placement_configured'=>true,'placement_met'=>true],
]);
check($a['diversity']['course']['code']==='C2' && $a['proficiency']['status']==='Completed',
    'A shared alternative prevented a valid separate-course allocation.');
$a = calculateDegreeAudit($profile, [], [], [], [], []);
check($a['diversity']['status']==='Rules unavailable' && $a['proficiency']['status']==='Rules unavailable'
    && count($a['proficiency']['requirements'])===2, 'Unconfigured undergraduate requirements disappeared or inferred rules.');
$elective = static fn (string $code) => ['code'=>$code,'title'=>"Course $code",'minimum_grade'=>'D'];
$c4 = array_replace($retry,['code'=>'C4','section_course'=>'C4','crn'=>9,'credits'=>4]);
$c3Active = array_replace($active,['code'=>'C3','enrollment_course'=>'C3','crn'=>8]);
$electivePolicy = [
    'MAJOR'=>['unit'=>'COURSES','target'=>2,'draft'=>true,'options'=>[$elective('C2'),$elective('C3')]],
    'FREE'=>['unit'=>'CREDITS','target'=>3,'draft'=>true,'options'=>[$elective('C1'),$elective('C2'),$elective('C4')]],
];
$a = calculateDegreeAudit($profile,$programs,$requirements,[$retry,$alt,$c4],[$c3Active],$genEd,null,[],$electivePolicy);
check($a['major_electives']['status']==='In progress' && $a['major_electives']['applied']===1
    && $a['major_electives']['in_progress_credits']===3, 'Current elective credit inflated the earned count.');
check($a['free_electives']['status']==='Completed' && $a['free_electives']['applied']===3
    && array_column($a['free_electives']['courses'],'code')===['C4']
    && $a['credits']['earned_provisional']===10, 'Free electives reused claimed courses or changed the overall credits.');
$electivePolicy['FREE']['target']=6;
$electivePolicy['FREE']['share']=true;
$a = calculateDegreeAudit($profile,$programs,$requirements,[$retry,$alt],[],$genEd,null,[],$electivePolicy);
check($a['major_electives']['status']==='Not completed' && $a['free_electives']['applied']===3
    && array_column($a['free_electives']['courses'],'code')===['C1'], 'Free sharing overrode an exclusive earlier elective allocation.');
$electivePolicy['MAJOR']['share']=true;
$a = calculateDegreeAudit($profile,$programs,$requirements,[$retry,$alt],[],$genEd,null,[],$electivePolicy);
check($a['free_electives']['status']==='Completed' && $a['credits']['earned_provisional']===6,
    'Explicit sharing did not preserve overall credit deduplication.');
$electivePolicy['MAJOR']['unit']='CREDITS';
$electivePolicy['MAJOR']['target']=3;
$electivePolicy['FREE']['share']=false;
$electivePolicy['FREE']['target']=3;
$electivePolicy['FREE']['options']=[$elective('C4')];
$laPolicy = ['name'=>'Program','total'=>120,'liberal_arts'=>3,'eligible_courses'=>['C1','C4']];
$a = calculateDegreeAudit($profile,$programs,$requirements,[$retry,$alt,$c4],[],$genEd,$laPolicy,[],$electivePolicy);
check($a['major_electives']['status']==='Completed' && $a['free_electives']['status']==='Completed'
    && array_column($a['free_electives']['courses'],'code')===['C4'], 'Surplus Liberal Arts eligibility was treated as an allocated credit.');
$a = calculateDegreeAudit($profile,[],[],[],[],[]);
check(!$a['diversity']['configured'] && !$a['proficiency']['configured']
    && $a['major_electives']['status']==='Rules unavailable' && $a['free_electives']['status']==='Rules unavailable',
    'An unconfigured block was treated as an unmet rule.');
echo 'PASS: SELECT-only comparisons for '.count($students).' existing students and pure calculation edge cases (no DB fixtures).'.PHP_EOL;
echo json_encode($coverage,JSON_PRETTY_PRINT).PHP_EOL;
