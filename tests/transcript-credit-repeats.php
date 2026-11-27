<?php
// SQLite :memory: only: exercise the real transcript view with repeated,
// future, inconsistent, unsupported, failing and unposted evidence. No MySQL.
declare(strict_types=1);
$pdoClass=class_exists('Pdo\\Sqlite') ? 'Pdo\\Sqlite' : PDO::class;
$memory=new $pdoClass('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$register=method_exists($memory,'createFunction')?'createFunction':'sqliteCreateFunction';
$memory->$register('IF',fn($condition,$yes,$no)=>$condition?$yes:$no,3);
$memory->$register('CURDATE',fn()=>'2026-12-17',0);
$memory->exec('CREATE TABLE Course(course_ID TEXT PRIMARY KEY,course_Name TEXT,course_Credits INTEGER);CREATE TABLE Semester(semester_ID TEXT PRIMARY KEY,semester_Name TEXT,start_Date TEXT);CREATE TABLE Course_Section(CRN INTEGER PRIMARY KEY,course_ID TEXT,semester_ID TEXT);CREATE TABLE Enrollment(student_ID INTEGER,CRN INTEGER,course_ID TEXT,grade TEXT);CREATE TABLE Student_History(student_ID INTEGER,CRN INTEGER,course_ID TEXT,semester_ID TEXT,grade TEXT);');
$memory->exec("INSERT INTO Course VALUES('CS110','Introduction to Programming',3),('BIO110','General Biology I',4),('PSY110','Introduction to Psychology',3),('ENG110','College Writing',3),('HIST110','World History I',3),('CS115','Data Structures',3),('PHYS110','Physics I: Mechanics',3); INSERT INTO Semester VALUES('SP25','Spring 2025','2025-01-27'),('FA25','Fall 2025','2025-08-27'),('FA26','Fall 2026','2026-08-26'),('SP27','Spring 2027','2027-01-25'); INSERT INTO Course_Section VALUES(1,'CS110','SP25'),(2,'CS110','FA25'),(3,'BIO110','FA25'),(4,'PSY110','FA25'),(5,'ENG110','FA26'),(6,'HIST110','SP27'),(7,'CS115','FA25'),(8,'PHYS110','FA25'); INSERT INTO Enrollment VALUES(1,1,'CS110','B'),(1,2,'CS110','A'),(1,3,'BIO110','C'),(1,4,'PSY110','F'),(1,5,'ENG110',NULL),(1,6,'HIST110','B'),(1,7,'CS115','D'),(1,8,'PHYS110','P'); INSERT INTO Student_History VALUES(1,1,'CS110','SP25','B'),(1,2,'CS110','FA25','A'),(1,3,'BIO110','FA25','C'),(1,4,'PSY110','FA25','F'),(1,6,'HIST110','SP27','B'),(1,7,'CS115','FA25','A'),(1,8,'PHYS110','FA25','P');");
function run(string $sql,array $params=[]):PDOStatement{if(!preg_match('/^SELECT\b/i',$sql))throw new RuntimeException('SELECT-only view check');$s=$GLOBALS['memory']->prepare($sql);$s->execute($params);return$s;}
function one(string $sql,array $params=[]):?array{return run($sql,$params)->fetch()?:null;}
function rows(string $sql,array $params=[]):array{return run($sql,$params)->fetchAll();}
require __DIR__.'/../api/views.php';
$page=viewTranscript(['id'=>1],[]);
$stats=array_column($page['blocks'][0]['items'],'value','label');
if((int)$stats['Credits earned']!==7)throw new RuntimeException('Transcript credit display is '.$stats['Credits earned'].'; expected 7 validated distinct posted credits (CS110 3 + BIO110 4).');
if(studentEarnedCredits(1)!==7)throw new RuntimeException('Shared standing credits differ.');
if(count($page['blocks'][1]['rows'])!==7)throw new RuntimeException('Transcript attempts must remain visible.');
$template=array_values(array_filter(studentTranscriptAttempts(1), fn($attempt) => $attempt['code']==='CS110'))[0];
foreach (['F','W',null,'IP','P'] as $grade) {
    [, $excluded]=evaluateTranscriptCredits([array_replace($template,['grade'=>$grade,'enrollment_grade'=>$grade])], '2026-12-17');
    if ($excluded!==[]) throw new RuntimeException('Failed, withdrawn, unposted/in-progress or unsupported evidence earned credit.');
}
echo "PASS actual transcript view: 7 distinct validated earned credits, repeated/future/inconsistent/unsupported/failed records excluded, current enrollment excluded, all seven posted attempts retained; SQLite memory only.\n";
