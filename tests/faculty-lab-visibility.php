<?php
// Existing-data integration checks. Only SELECT is permitted; no fixtures, writes,
// migrations, transactions, or locking reads. Browser output excludes student rosters.
declare(strict_types=1);
function run(string $sql, array $params = []): PDOStatement
{
    if (!preg_match('/^\s*SELECT\b/i',$sql) || preg_match('/\bFOR\s+UPDATE\b|\bINTO\s+(OUTFILE|DUMPFILE)\b/i',$sql)) throw new RuntimeException('SELECT-only test guard');
    static $pdo;
    $pdo ??= new PDO('mysql:unix_socket='.(getenv('DB_SOCKET') ?: '/tmp/mysql.sock').';dbname='.(getenv('DB_NAME') ?: 'campus_connect').';charset=utf8mb4',
        getenv('DB_USER') ?: 'campus', getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : 'campus',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $stmt=$pdo->prepare($sql); $stmt->execute($params); return $stmt;
}
function rows(string $sql,array $params=[]): array { return run($sql,$params)->fetchAll(); }
function one(string $sql,array $params=[]): ?array { return run($sql,$params)->fetch() ?: null; }
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
require __DIR__.'/../api/actions.php';
require __DIR__.'/../api/views.php';
set_error_handler(static function ($severity,$message,$file,$line): never { throw new ErrorException($message,0,$severity,$file,$line); });
function fingerprints(): array {
    $queries=[
        'SELECT * FROM Course_Section ORDER BY CRN',
        'SELECT * FROM Course_Section_Lab ORDER BY CRN',
        'SELECT * FROM Time_Slot_Day ORDER BY time_Slot_ID,day_ID',
        'SELECT * FROM Time_Slot_Period ORDER BY time_Slot_ID,period_ID',
        'SELECT * FROM Period ORDER BY period_ID',
        'SELECT * FROM Enrollment ORDER BY student_ID,CRN',
    ];
    return array_map(fn($sql)=>hash('sha256',json_encode(rows($sql),JSON_THROW_ON_ERROR)),$queries);
}
$before=fingerprints();
$faculty=rows('SELECT faculty_ID FROM Faculty ORDER BY faculty_ID');
$semesters=rows('SELECT semester_ID,semester_Name FROM Semester ORDER BY start_Date');
// Original lecture projection, plus independently joined lab records, are reference data.
$lectures=rows('SELECT cs.faculty_ID AS owner, cs.semester_ID AS semester, \'Lecture\' AS Meeting, '.SECTION_COLUMNS.', '.INSTRUCTOR.', '.TIME_ROOM.', '.SEATS.' FROM Course_Section cs '.SECTION_JOINS);
$labs=rows("SELECT cs.faculty_ID AS owner, cs.semester_ID AS semester, 'Lab' AS Meeting,
    cs.CRN,cs.course_ID AS Course,c.course_Name AS Title,cs.section_No AS Section,c.course_Credits AS Credits,".COURSE_LEVEL." AS Level,
    (SELECT GROUP_CONCAT(d.day_ID ORDER BY FIELD(d.day_ID,'M','T','W','R','F') SEPARATOR '') FROM Time_Slot_Day d WHERE d.time_Slot_ID=lab.time_Slot_ID) AS Days,
    CONCAT(TIME_FORMAT(p.start_Time,'%l:%i %p'),' – ',TIME_FORMAT(p.end_Time,'%l:%i %p')) AS Time,
    CONCAT(b.building_Name,' ',r.room_No) AS Room,CONCAT(u.first_Name,' ',u.last_Name) AS Instructor,cs.available_Seats AS `Open seats`
  FROM Course_Section_Lab lab JOIN Course_Section cs ON cs.CRN=lab.CRN
    JOIN Course c ON c.course_ID=cs.course_ID JOIN Time_Slot_Period tp ON tp.time_Slot_ID=lab.time_Slot_ID
    JOIN Period p ON p.period_ID=tp.period_ID JOIN Room r ON r.room_ID=lab.lab_ID JOIN Building b ON b.building_ID=r.building_ID
    LEFT JOIN User u ON u.user_ID=cs.faculty_ID ORDER BY cs.CRN");
$fa26Labs=array_values(array_filter($labs,fn($row)=>$row['semester']==='FA26'));
check(count($fa26Labs)===6 && count(array_unique(array_column($fa26Labs,'owner')))===5,'The six FA26 labs/five faculty must be present');
check(array_column($fa26Labs,'CRN')===[10469,10470,10471,10479,10480,10481],'FA26 lab CRNs differ from inspected records');
$expected=[];
foreach ([...$lectures,...$labs] as $row) $expected[$row['owner']][$row['semester']][]=$row;
$rosterStudents=[];
foreach (rows('SELECT CRN, student_ID FROM Enrollment ORDER BY CRN, student_ID') as $enrollment) {
    $rosterStudents[$enrollment['CRN']][]=(int)$enrollment['student_ID'];
}
$browserIds=array_values(array_unique([...array_column($fa26Labs,'owner'),$faculty[0]['faculty_ID']]));
$browser=[];$checks=0;$seenFa26=[];
foreach ($faculty as $person) foreach ($semesters as $semester) {
    $id=(int)$person['faculty_ID'];$sem=$semester['semester_ID'];
    $page=buildView(['id'=>$id,'name'=>"Faculty $id",'role'=>'Faculty'],'teaching',
        ['semester'=>$sem,'faculty_ID'=>999999,'user_ID'=>999999]);
    $table=$page['blocks'][2];
    check($page['blocks'][0]['filters'][0]['value']===$sem,'Semester filter mismatch');
    check($table['title']==="{$semester['semester_Name']} teaching schedule",'Semester title mismatch');
    $actual=[];$keys=[];
    foreach ($table['rows'] as $row) {
        $named=array_combine($table['columns'],$row);
        $key=json_encode([$named['Meeting'],$named['CRN'],$named['Days'],$named['Time'],$named['Room']]);
        check(!isset($keys[$key]),'Duplicate meeting row');$keys[$key]=true;
        $actual[]=json_encode(array_map(fn($v)=>$v===null ? null : (string)$v,$row));
        if ($sem==='FA26' && $named['Meeting']==='Lab') $seenFa26[]=$named['CRN'];
    }
    $reference=[];$parentSections=[];
    foreach ($expected[$id][$sem] ?? [] as $row) {
        $reference[]=json_encode(array_map(fn($key)=>$row[$key]===null ? null : (string)$row[$key],$table['columns']));
        $parentSections[$row['CRN']]=$row['Course'];
    }
    sort($actual);sort($reference);
    check($actual===$reference,"Incorrect meeting data or faculty/semester leakage: $id/$sem");
    check($page['blocks'][1]['items'][4]['value']===count($parentSections),'Labs inflated section count');

    // Each parent section has one roster and its own attendance and grading panels.
    $expectedPanels=[];
    foreach ($parentSections as $crn=>$course) {
        $sectionLabel="$course (CRN $crn)";
        $expectedPanels["Roster: $sectionLabel"]=[$crn,null];
        $expectedPanels["Take attendance: $sectionLabel"]=[$crn,'record-attendance'];
        $expectedPanels["Enter grades: $sectionLabel"]=[$crn,'record-grade'];
    }
    foreach (array_slice($page['blocks'],3) as $panel) {
        $title=$panel['title'];
        check(isset($expectedPanels[$title]),"Unexpected or duplicate panel for $id/$sem: $title");
        [$crn,$endpoint]=$expectedPanels[$title];
        unset($expectedPanels[$title]);

        $studentColumn=array_search('Student ID',$panel['columns'],true);
        check($studentColumn!==false,"Missing Student ID column: $title");
        $studentIds=array_map('intval',array_column($panel['rows'],$studentColumn));
        sort($studentIds,SORT_NUMERIC);
        check($studentIds===($rosterStudents[$crn] ?? []),"Incorrect enrollment or duplicate student: $title");
        $action=$panel['action'] ?? null;
        if ($endpoint===null) {
            check($action===null,"Roster must be read-only: $title");
        } else {
            check(($action['endpoint'] ?? null)===$endpoint && ($action['column'] ?? null)==='Student ID'
                && ($action['extra']['CRN'] ?? null)===$crn,"Incorrect panel action or CRN: $title");
        }
    }
    check($expectedPanels===[],"Missing roster, attendance, or grading panel for $id/$sem");
    if (in_array($id,$browserIds,true)) $browser[$id]['pages'][$sem]=[
        'title'=>"Faculty $id teaching schedule", 'blocks'=>[$page['blocks'][0],$table],
    ];
    $checks++;
}
sort($seenFa26);check($seenFa26===[10469,10470,10471,10479,10480,10481],'Labs missing or duplicated across faculty');
$current=currentSemester();
foreach ([[],['semester'=>'invalid'],['semester'=>['FA26']],['semester'=>"FA26' OR 1=1"]] as $q) {
    $page=buildView(['id'=>200027,'name'=>'Faculty 200027','role'=>'Faculty'],'teaching',$q);
    check($page['blocks'][0]['filters'][0]['value']===$current['id'],'Invalid semester did not fall back');
}
foreach (['Student','Admin','StatDept'] as $role) check(buildView(['id'=>200027,'role'=>$role],'teaching',[])===null,'Role restriction bypassed');
// Existing shared lab projections and course browsers must retain their data.
$enrolledLab=one('SELECT e.student_ID,cs.semester_ID FROM Enrollment e JOIN Course_Section cs ON cs.CRN=e.CRN JOIN Course_Section_Lab lab ON lab.CRN=cs.CRN ORDER BY e.student_ID LIMIT 1');
check($enrolledLab!==null,'No existing enrolled lab student available');
$studentSchedule=studentSchedule('Existing student schedule',(int)$enrolledLab['student_ID'],$enrolledLab['semester_ID']);
foreach (['Lab days','Lab time','Lab room'] as $column) check(in_array($column,$studentSchedule['columns'],true),'Student lab column lost');
foreach ($studentSchedule['rows'] as $row) {
    $named=array_combine($studentSchedule['columns'],$row);
    foreach ($labs as $lab) if ($named['CRN']===$lab['CRN']) {
        check($named['Lab days']===$lab['Days'] && $named['Lab time']===$lab['Time'] && $named['Lab room']===$lab['Room'],'Student lab data changed');
    }
}
$major=one("SELECT major_ID FROM Major_Course_Requirement WHERE course_ID='BIO110' ORDER BY major_ID LIMIT 1");
check($major!==null,'No existing Biology requirement available');
foreach (['FA26','SP27'] as $sem) {
    $master=masterSchedule(['semester'=>$sem,'dept'=>'BIO']);
    check($master[0]['filters'][0]['value']===$sem && $master[0]['filters'][1]['value']==='BIO','Master Schedule filter regression');
    $public=publicMajorBrowser(['semester'=>$sem,'major'=>$major['major_ID']]);
    foreach ($public[1]['courses'] as $course) foreach ($course['sections'] as $section) {
        $reference=array_values(array_filter($lectures,fn($lecture)=>$lecture['CRN']===$section['crn']));
        check(count($reference)===1 && $reference[0]['semester']===$sem,'Major browser semester/CRN regression');
        check($course['level']===$reference[0]['Level'] && $course['credits']===$reference[0]['Credits'],'Major browser level/credits regression');
    }
}
$catalog=buildView(['id'=>200027,'role'=>'Faculty'],'catalog',['dept'=>'BIO']);
check($catalog['title']==='Course catalog','Catalog regression');
check($before===fingerprints(),'Existing schedules, assignments, or enrollments changed during SELECT-only checks');
if (($argv[1] ?? '')==='--browser-fixture') {
    echo json_encode(['faculty'=>$browser,'defaultSemester'=>$current['id']],JSON_THROW_ON_ERROR);exit;
}
echo "PASS: $checks faculty/semester combinations; all lecture/lab fields match independent SELECTs; six FA26 labs across five faculty; no duplicates, no account/semester leakage, correct section counts, roster membership, attendance/grading panel scope, malformed-filter fallback and role guards.\n";
foreach ($fa26Labs as $row) echo json_encode(array_intersect_key($row,array_flip(['owner','CRN','Course','Days','Time','Room','Instructor'])),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
echo "PASS: scheduling/assignment/enrollment fingerprints unchanged. SELECT-only: no schema/data changes or transactions. Inherited lab instructor is the recorded parent-section faculty; separate lab assignments are not stored.\n";
