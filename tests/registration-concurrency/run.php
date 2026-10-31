<?php
// WRITE-CAPABLE: refuses the host/live database; run only after explicit approval.
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
function must(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }

// Copy only table/trigger definitions into the EMPTY, hard-wired disposable schema.
// Never execute the source script's DROP DATABASE, CREATE DATABASE, USE, or seed data.
must((int) one('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')['n'] === 0, 'Test database must be empty; refusing to overwrite it');
$schema = file_get_contents(__DIR__ . '/../../db/Database_schema.sql');
preg_match_all('/^CREATE TABLE [A-Za-z_]+ \([\s\S]*?^\);/m', $schema, $tables);
preg_match_all('/^CREATE TRIGGER [\s\S]*?^END\$\$/m', $schema, $triggers);
must(count($tables[0]) === 47 && count($triggers[0]) === 7, 'Schema parser/source changed; review test setup before execution');
foreach ($tables[0] as $sql) {
    must(!preg_match('/\b(?:campus_connect|DROP\s+DATABASE|CREATE\s+DATABASE|USE\s+)\b/i', $sql), 'Unsafe qualified schema statement');
    db()->exec($sql);
}
foreach ($triggers[0] as $sql) db()->exec(substr($sql, 0, -2));
foreach (rows("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('Student','Enrollment','Course_Section')") as $r) must($r['ENGINE'] === 'InnoDB', 'InnoDB is required');

run("INSERT INTO Building(building_ID,building_Name) VALUES('TST','Concurrency fixtures')");
for ($n=1; $n<=9; $n++) {
    $room='R'.str_pad((string)$n,2,'0',STR_PAD_LEFT);
    run("INSERT INTO Room(room_ID,building_ID,room_No,room_Type) VALUES(?,'TST',?,'Lecture')",[$room,(string)$n]);
    run('INSERT INTO Lecture(lecture_ID,seats) VALUES(?,10)',[$room]);
}
run("INSERT INTO Department(dept_ID,dept_Name) VALUES('CS','Test Computer Science')");
run("INSERT INTO Major(major_ID,dept_ID,major_Name) VALUES('TEST-BS','CS','Test program')");
for ($id=100001; $id<=100012; $id++) {
    run("INSERT INTO User(user_ID,first_Name,last_Name,user_Type) VALUES(?,'Isolated','Fixture','Student')",[$id]);
    run("INSERT INTO Student(student_ID,major_ID,student_Year,student_Type) VALUES(?,'TEST-BS','Senior','Undergraduate')",[$id]);
    run("INSERT INTO Undergraduate_Student(student_ID,dept_ID,undergraduate_Student_Type) VALUES(?,'CS','Full-time')",[$id]);
    run('INSERT INTO Full_Time_Undergraduate(student_ID) VALUES(?)',[$id]);
    run("INSERT INTO Student_Major(student_ID,major_ID,date_Of_Choice) VALUES(?,'TEST-BS',CURDATE())",[$id]);
}
foreach (['TEST-A'=>30,'TEST-B'=>130] as $id=>$offset) {
    $start=(new DateTimeImmutable('today'))->modify("+$offset days");
    run('INSERT INTO Semester(semester_ID,semester_Name,year,start_Date,end_Date,add_Start,add_End,drop_Start,drop_End,grade_Start,grade_End) VALUES(?,?,?,?,?,CURDATE()-INTERVAL 7 DAY,CURDATE()+INTERVAL 14 DAY,CURDATE()-INTERVAL 7 DAY,CURDATE()+INTERVAL 21 DAY,?,?)',
        [$id,$id,(int)$start->format('Y'),$start->format('Y-m-d'),$start->modify('+90 days')->format('Y-m-d'),$start->modify('+70 days')->format('Y-m-d'),$start->modify('+90 days')->format('Y-m-d')]);
}
run("INSERT INTO Day(day_ID,week_Day) VALUES('M','Monday'),('W','Wednesday')");
foreach (['08:00','09:30','11:00','12:30','14:00','15:30','08:30'] as $i=>$start) {
    $id=$i+1;$end=(new DateTimeImmutable('2000-01-01 '.$start))->modify('+80 minutes')->format('H:i:s');
    run('INSERT INTO Period(period_ID,start_Time,end_Time) VALUES(?,?,?)',[$id,$start,$end]);
    run('INSERT INTO Time_Slot(time_Slot_ID) VALUES(?)',[$id]);
    run("INSERT INTO Time_Slot_Day(time_Slot_ID,day_ID) VALUES(?,'M'),(?,'W')",[$id,$id]);
    run('INSERT INTO Time_Slot_Period(time_Slot_ID,period_ID) VALUES(?,?)',[$id,$id]);
}
for($i=1;$i<=6;$i++)run("INSERT INTO Course(course_ID,course_Name,dept_ID,course_Credits,course_Type) VALUES(?,?,'CS',3,'Undergraduate')",['CS'.(105+$i*5),'Fixture '.$i]);
function sectionFixture(int $crn,int $course,int $slot,int $room,string $sem='TEST-A',string $number='001'):void {
    run('INSERT INTO Course_Section(CRN,course_ID,section_No,time_Slot_ID,room_ID,semester_ID,max_Seats) VALUES(?,?,?,?,?,?,10)',[$crn,'CS'.(105+$course*5),$number,$slot,'R'.str_pad((string)$room,2,'0',STR_PAD_LEFT),$sem]);
}
function enrollFixture(int $id,int $crn):void { run('INSERT INTO Enrollment(student_ID,CRN,grade) VALUES(?,?,NULL)',[$id,$crn]); }
function seedCase(string $name):array {
    // Only the dedicated container's guarded test database can reach these statements.
    run('DELETE FROM Enrollment');run('DELETE FROM Course_Section_Lab');run('DELETE FROM Course_Section');
    $case=['actionA'=>'addSection','studentA'=>100001,'crnA'=>1,'studentB'=>100001,'crnB'=>2,'barrier'=>'student-locked'];
    if(in_array($name,['credit','rollback','drop-add'],true)) {
        for($i=1;$i<=6;$i++)sectionFixture($i,$i,$i,$i);
        for($i=1;$i<=4;$i++)enrollFixture(100001,$i);
        $case['crnA']=5;$case['crnB']=6;
        if($name==='drop-add'){enrollFixture(100001,5);$case['actionA']='dropSection';}
    }elseif($name==='last-seat'){
        sectionFixture(1,1,1,1);for($id=100003;$id<=100011;$id++)enrollFixture($id,1);
        $case['studentB']=100002;$case['crnB']=1;$case['barrier']='section-locked';
    }else{
        sectionFixture(1,1,1,1);
        sectionFixture(2,in_array($name,['duplicate','cross-semester','timeout'],true)?1:2,$name==='overlap'?7:2,2,$name==='cross-semester'?'TEST-B':'TEST-A','002');
        if($name==='independent')$case['studentB']=100002;
    }
    return $case;
}
function startWorker(string $action,int $student,int $crn,string $isolation,string $barrier='none',bool $fail=false,bool $short=false):array {
    $command=[PHP_BINARY,__DIR__.'/worker.php',$action,(string)$student,(string)$crn,$isolation,$barrier,$fail?'fail-insert':'normal',$short?'short-timeout':'normal'];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    must(is_resource($process),'Worker failed to start');stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    return ['process'=>$process,'pipes'=>$pipes,'buffer'=>'','events'=>[]];
}
function pumpWorker(array &$w):void {
    $w['buffer'].=stream_get_contents($w['pipes'][1]);
    while(($at=strpos($w['buffer'],"\n"))!==false){$line=substr($w['buffer'],0,$at);$w['buffer']=substr($w['buffer'],$at+1);if($line!=='')$w['events'][]=json_decode($line,true,512,JSON_THROW_ON_ERROR);}
}
function eventOf(array $w,string $event):?array {foreach($w['events'] as $item)if($item['event']===$event)return $item;return null;}
function awaitEvent(array &$w,string $event,float $timeout=8):array {
    $until=microtime(true)+$timeout;
    do{pumpWorker($w);if($item=eventOf($w,$event))return $item;if(eventOf($w,'done'))throw new RuntimeException('Worker finished before '.$event.': '.json_encode($w['events']));usleep(10000);}while(microtime(true)<$until);
    throw new RuntimeException('Timed out waiting for '.$event.'; '.stream_get_contents($w['pipes'][2]));
}
function assertStillBlocked(array &$w,string $event):void {
    $until=microtime(true)+0.25;
    do{pumpWorker($w);must(eventOf($w,$event)===null&&eventOf($w,'done')===null,'Contender passed the held database lock');usleep(10000);}while(microtime(true)<$until);
}
function releaseWorker(array &$w):void {fwrite($w['pipes'][0],"release\n");fflush($w['pipes'][0]);}
function closeWorker(array &$w):void {foreach($w['pipes'] as $p)if(is_resource($p))fclose($p);proc_close($w['process']);}
function checkInvariants():void {
    must((int)one('SELECT COUNT(*) AS n FROM Course_Section cs WHERE available_Seats<>max_Seats-(SELECT COUNT(*) FROM Enrollment e WHERE e.CRN=cs.CRN) OR available_Seats<0')['n']===0,'Seat/trigger mismatch');
    must((int)one('SELECT COUNT(*) AS n FROM(SELECT e.student_ID,cs.semester_ID FROM Enrollment e JOIN Course_Section cs USING(CRN) JOIN Course c ON c.course_ID=cs.course_ID GROUP BY e.student_ID,cs.semester_ID HAVING SUM(c.course_Credits)>16)x')['n']===0,'Credit limit bypass');
    must((int)one('SELECT COUNT(*) AS n FROM(SELECT e.student_ID,cs.semester_ID,cs.course_ID FROM Enrollment e JOIN Course_Section cs USING(CRN) GROUP BY e.student_ID,cs.semester_ID,cs.course_ID HAVING COUNT(*)>1)x')['n']===0,'Duplicate course bypass');
    $overlap=one('SELECT COUNT(*) AS n FROM Enrollment e1 JOIN Enrollment e2 ON e1.student_ID=e2.student_ID AND e1.CRN<e2.CRN JOIN Course_Section a ON a.CRN=e1.CRN JOIN Course_Section b ON b.CRN=e2.CRN AND a.semester_ID=b.semester_ID JOIN Time_Slot_Day da ON da.time_Slot_ID=a.time_Slot_ID JOIN Time_Slot_Day dd ON dd.time_Slot_ID=b.time_Slot_ID AND dd.day_ID=da.day_ID JOIN Time_Slot_Period ta ON ta.time_Slot_ID=a.time_Slot_ID JOIN Period pa ON pa.period_ID=ta.period_ID JOIN Time_Slot_Period tb ON tb.time_Slot_ID=b.time_Slot_ID JOIN Period pb ON pb.period_ID=tb.period_ID WHERE pa.start_Time<pb.end_Time AND pb.start_Time<pa.end_Time');
    must((int)$overlap['n']===0,'Overlapping registration bypass');
}
foreach(['REPEATABLE READ','READ COMMITTED'] as $isolation)foreach(['credit','duplicate','overlap','cross-semester','rollback','drop-add','last-seat','independent','timeout'] as $name){
    $case=seedCase($name);$a=$b=null;
    try{
        $a=startWorker($case['actionA'],$case['studentA'],$case['crnA'],$isolation,$case['barrier'],$name==='rollback');
        awaitEvent($a,$case['barrier']);
        $b=startWorker('addSection',$case['studentB'],$case['crnB'],$isolation,'none',false,$name==='timeout');
        $wait=$name==='last-seat'?'section':'student';awaitEvent($b,$wait.'-waiting');
        if($name==='independent'){
            // A holds only student 100001; B uses a different parent row and may finish.
            awaitEvent($b,'student-locked');$outB=awaitEvent($b,'done');releaseWorker($a);$outA=awaitEvent($a,'done');
        }elseif($name==='timeout'){
            $outB=awaitEvent($b,'done');must($outB['mysql_code']===1205&&$outB['transaction_open']===false,'Lock timeout must fail closed and roll back');releaseWorker($a);$outA=awaitEvent($a,'done');
        }else{
            assertStillBlocked($b,$wait.'-locked');releaseWorker($a);$outA=awaitEvent($a,'done');$outB=awaitEvent($b,'done');
        }
        if($name==='rollback'){
            must($outA['outcome']==='error'&&str_contains($outA['message'],'Forced isolated rollback')&&$outA['transaction_open']===false,'Forced rollback did not execute');
            must($outB['outcome']==='ok','Waiter did not see rollback/fresh state');must(one('SELECT student_ID FROM Enrollment WHERE student_ID=100001 AND CRN=5')===null,'Rolled-back registration persisted');
        }else must($outA['outcome']==='ok','First request failed: '.json_encode($outA));
        if(in_array($name,['independent','drop-add'],true))must($outB['outcome']==='ok','Independent/drop-followed-by-add request failed');
        elseif($name!=='rollback'&&$name!=='timeout'){
            $why=['credit'=>'limit','duplicate'=>'already registered','overlap'=>'same time','cross-semester'=>'already taking','last-seat'=>'full'][$name];
            must($outB['outcome']==='error'&&$outB['class']==='RuleError'&&str_contains($outB['message'],$why)&&$outB['transaction_open']===false,'Second request did not reject for the expected rule: '.json_encode($outB));
        }
        if($name==='cross-semester')must((int)one('SELECT COUNT(*) AS n FROM Enrollment WHERE student_ID=100001')['n']===1,'Duplicate course across semesters');
        if($name==='last-seat')must((int)one('SELECT available_Seats FROM Course_Section WHERE CRN=1')['available_Seats']===0,'Last seat was not consumed once');
        checkInvariants();echo "PASS [$isolation] $name\n";
    }finally{if($a)closeWorker($a);if($b)closeWorker($b);}
}
echo "PASS: 18 isolated InnoDB scenarios using actual registration and transaction helpers.\n";
