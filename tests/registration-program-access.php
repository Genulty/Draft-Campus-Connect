<?php
// Database-free regression: requirement membership does not imply exclusive access.
declare(strict_types=1);
class RuleError extends Exception {}
function fail(string $message): never { throw new RuleError($message); }
$fixture=[];
function rows(string $sql,array $params=[]):array {global $fixture;if(!str_contains($sql,'FROM Course_Program_Restriction'))throw new RuntimeException('Unexpected curriculum-based restriction query');return $fixture;}
require __DIR__.'/../api/actions.php';
function verify(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
checkCourseProgram(1,'BIO115','Undergraduate'); // no explicit restriction
$fixture=[['major_Name'=>'Computer Science','minor_Name'=>null,'allowed'=>0]];
try{checkCourseProgram(1,'CS110','Undergraduate');throw new RuntimeException('Explicit rule ignored');}catch(RuleError$e){verify(str_contains($e->getMessage(),'Computer Science'),'Missing plain permission reason');}
$fixture[0]['allowed']=1;checkCourseProgram(1,'CS110','Undergraduate');
$fixture=[['major_Name'=>'Computer Science','minor_Name'=>null,'allowed'=>0],['major_Name'=>'Mathematics','minor_Name'=>null,'allowed'=>1]];checkCourseProgram(1,'CS110','Undergraduate');
$fixture=[['major_Name'=>null,'minor_Name'=>'Computer Science','allowed'=>1]];checkCourseProgram(1,'CS110','Undergraduate');
$fixture=[];checkCourseProgram(1,'CHEM110','Undergraduate');
echo "PASS explicit major/multiple-program/minor access, denied reason, and unrestricted cross-major courses; no inferred curriculum restriction.\n";
