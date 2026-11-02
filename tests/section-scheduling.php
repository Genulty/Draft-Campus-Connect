<?php
// Actual creation handler with in-memory SQL adapters; no database connection/writes.
// Optional --existing-data verifies only the helper's SELECT against existing slots.
declare(strict_types=1);
if (($argv[1] ?? '') === '--existing-data') {
    class RuleError extends Exception {}
    function fail(string $message): never { throw new RuleError($message); }
    function rows(string $sql, array $params = []): array {
        if (!preg_match('/^\s*SELECT\b/i', $sql) || str_contains($sql,'FOR UPDATE')) throw new RuntimeException('SELECT-only guard');
        static $pdo;
        $pdo ??= new PDO('mysql:unix_socket='.(getenv('DB_SOCKET') ?: '/tmp/mysql.sock').';dbname='.(getenv('DB_NAME') ?: 'campus_connect'),
            getenv('DB_USER') ?: 'campus',getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : 'campus',
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll();
    }
    require __DIR__.'/../api/actions.php';
    $before=rows('SELECT cs.*,lab.lab_ID AS lab_room,lab.time_Slot_ID AS lab_slot FROM Course_Section cs LEFT JOIN Course_Section_Lab lab ON lab.CRN=cs.CRN WHERE cs.CRN=10569');
    $slots=rows('SELECT ts.time_Slot_ID,COUNT(DISTINCT td.day_ID) AS days,COUNT(DISTINCT p.period_ID) AS periods,
        MIN(TIME_TO_SEC(p.end_Time)-TIME_TO_SEC(p.start_Time)) AS shortest,MAX(TIME_TO_SEC(p.end_Time)-TIME_TO_SEC(p.start_Time)) AS longest
      FROM Time_Slot ts LEFT JOIN Time_Slot_Day td ON td.time_Slot_ID=ts.time_Slot_ID
      LEFT JOIN Time_Slot_Period tp ON tp.time_Slot_ID=ts.time_Slot_ID LEFT JOIN Period p ON p.period_ID=tp.period_ID
      GROUP BY ts.time_Slot_ID ORDER BY ts.time_Slot_ID');
    $courses=rows('SELECT course_Type,course_Credits,MIN(course_ID) AS course_ID FROM Course GROUP BY course_Type,course_Credits');
    $checks=0;$accepted=[];
    foreach($courses as $course) foreach($slots as $slot) {
        $expected=$slot['days']>0 && $slot['periods']>0 && $slot['shortest']>0;
        if($course['course_Type']==='Undergraduate') {
            $expected=$expected && $slot['days']>=2;
            if((int)$course['course_Credits']===3) $expected=$expected && $slot['days']==2 && $slot['periods']==1 && $slot['shortest']==4800 && $slot['longest']==4800;
        }
        try {validateSectionSchedule($course,(int)$slot['time_Slot_ID']);$actual=true;}catch(RuleError $e){$actual=false;}
        if($actual!==(bool)$expected) throw new RuntimeException('Stored slot validation mismatch');
        $key=$course['course_Type'].'/'.$course['course_Credits'];$accepted[$key]=($accepted[$key]??0)+(int)$actual;$checks++;
    }
    $after=rows('SELECT cs.*,lab.lab_ID AS lab_room,lab.time_Slot_ID AS lab_slot FROM Course_Section cs LEFT JOIN Course_Section_Lab lab ON lab.CRN=cs.CRN WHERE cs.CRN=10569');
    if($before!==$after) throw new RuntimeException('BIO110 changed during read-only checks');
    echo "PASS: $checks SELECT-only course-type/credit/time-slot combinations: ".json_encode($accepted)."; BIO110 10569 unchanged. No creation handler or writes executed.\n";exit;
}
ob_start();require __DIR__.'/section-capacity.php';$capacityReport=ob_get_clean();
set_error_handler(static function($severity,$message,$file,$line): never {throw new ErrorException($message,0,$severity,$file,$line);});
function meetings(array $days,array $durations=[4800]): array {
    $rows=[];
    foreach($days as $day) foreach($durations as $i=>$duration) $rows[]=['day_ID'=>$day,'period_ID'=>$i+1,'start_seconds'=>28800+$i*7200,'end_seconds'=>28800+$i*7200+$duration];
    return $rows;
}
function probe(array $course,array $meetings,bool $accepted,string $reason='',mixed $slot='1'): array {
    $GLOBALS['courseOverride']=$course;$GLOBALS['meetingOverride']=$meetings;$GLOBALS['inserts']=[];
    try {
        $result=createSection(1,['max_Seats'=>'5','time_Slot_ID'=>$slot]);
        check($accepted,'Noncompliant scheduling input accepted');check(count($GLOBALS['inserts'])===1,'Valid schedule did not reach simulated insert');
        return ['status'=>200,'body'=>$result];
    } catch(RuleError $e) {
        check(!$accepted,'Valid schedule rejected: '.$e->getMessage());check($GLOBALS['inserts']===[],'Invalid schedule reached an insert');
        if($reason!=='') check(str_contains($e->getMessage(),$reason),'Unexpected rejection: '.$e->getMessage());
        return ['status'=>422,'body'=>['error'=>$e->getMessage()]];
    }
}
$ug=['course_Type'=>'Undergraduate','course_Credits'=>3];$grad=['course_Type'=>'Graduate','course_Credits'=>3];
$scenario='';$checks=0;
foreach([
    [$ug,meetings(['M','W']),true,'','900'], // No time-slot ID convention.
    [$ug,meetings(['F']),false,'at least two'],
    [$ug,meetings(['F'],[10800]),false,'at least two'],
    [$ug,meetings(['M','W'],[4740]),false,'80-minute'],
    [$ug,meetings(['M','W'],[4860]),false,'80-minute'],
    [$ug,meetings(['M','W'],[4801]),false,'80-minute'],
    [$ug,meetings(['M','W'],[6000]),false,'80-minute'],
    [$ug,meetings(['M','W','F']),false,'exactly two'],
    [$ug,meetings(['M','M']),false,'at least two'],
    [$ug,meetings(['M','W','M','W']),true], // Duplicate query rows don't invent extra days.
    [$ug,meetings(['M','W'],[2400,2400]),false,'80-minute'],
    [$ug,meetings(['M','W'],[4800,4800]),false,'80-minute'],
    [$grad,meetings(['F'],[10800]),true],
    [$grad,meetings(['M','W']),true],
    [$grad,meetings(['M','W'],[6000]),true],
    [$grad,meetings(['F']),true], // No unsupported universal graduate duration rule.
    [['course_Type'=>'Graduate','course_Credits'=>4],meetings(['F'],[6000]),true],
] as $case){probe(...$case);$checks++;}
foreach([1,2,4,5,6] as $credits) {
    foreach([2400,4800,6000,9000,10800] as $duration) {
        probe(['course_Type'=>'Undergraduate','course_Credits'=>$credits],meetings(['M','W'],[$duration]),true);$checks++;
    }
    probe(['course_Type'=>'Undergraduate','course_Credits'=>$credits],meetings(['F']),false,'at least two');$checks++;
}
probe(['course_Type'=>'Undergraduate','course_Credits'=>4],meetings(['M','W','F'],[6000]),true);$checks++;
$badRelations=[[],meetings([null]),[['day_ID'=>'M','period_ID'=>null,'start_seconds'=>28800,'end_seconds'=>33600]],
    [['day_ID'=>'M','period_ID'=>1,'start_seconds'=>null,'end_seconds'=>33600]],
    [['day_ID'=>'M','period_ID'=>1,'start_seconds'=>28800,'end_seconds'=>null]],meetings(['M','W'],[0]),meetings(['M','W'],[-60])];
foreach([$ug,$grad] as $course) foreach($badRelations as $bad){probe($course,$bad,false,'recorded meeting days');$checks++;}
foreach([null,'',0,-1,true,[],1.5,'1junk','01','999999999999999999999999'] as $bad){probe($ug,meetings(['M','W']),false,'valid time slot',$bad);$checks++;}
$scenario='missing-slot';probe($ug,meetings(['M','W']),false,'Choose a time slot');$checks++;$scenario='';
// Probe the unchanged department/load/faculty/room guards after valid scheduling.
foreach(['readonly','small-room','department','load','faculty-conflict','room-conflict'] as $scenario) {
    probe($ug,meetings(['M','W']),false);$checks++;
}
$scenario='';
$courseOptions=[
    ['course_ID'=>'TEST101','course_Name'=>'Fixture undergraduate','course_Type'=>'Undergraduate','course_Credits'=>3],
    ['course_ID'=>'TEST104','course_Name'=>'Fixture four-credit','course_Type'=>'Undergraduate','course_Credits'=>4],
    ['course_ID'=>'TEST600','course_Name'=>'Fixture graduate','course_Type'=>'Graduate','course_Credits'=>3],
];
$slotOptions=[['id'=>'1','label'=>'MW 8:00 AM – 9:20 AM'],['id'=>'2','label'=>'F 8:00 AM – 9:20 AM'],['id'=>'3','label'=>'MW 8:00 AM – 9:40 AM'],['id'=>'4','label'=>'F 9:00 AM – 12:00 PM']];
$form=viewCreateSection(['id'=>1,'role'=>'Admin'],['course_ID'=>'TEST101'])['blocks'][0];
check(str_contains($form['note'],'two 80-minute') && str_contains($form['note'],'not restricted to once-weekly'),'Guidance differs from validation');
$cases=[];
foreach($courseOptions as $course) foreach(['1'=>meetings(['M','W']),'2'=>meetings(['F']),'3'=>meetings(['M','W'],[6000]),'4'=>meetings(['F'],[10800])] as $slot=>$m) {
    $ok=$course['course_Type']==='Graduate' || ($slot=='1' || ($course['course_Credits']===4 && $slot=='3'));
    $cases[$course['course_ID'].'/'.$slot]=probe($course,$m,$ok,'',(string)$slot);
}
if(($argv[1]??'')==='--browser-fixture') {echo json_encode(['form'=>$form,'cases'=>$cases],JSON_THROW_ON_ERROR);exit;}
echo $capacityReport;
echo "PASS: $checks scheduling/relationship/input/guard scenarios plus 12 browser response fixtures. Real createSection handler, in-memory adapters only; no database connection or executed inserts.\n";
