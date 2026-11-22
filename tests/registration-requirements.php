<?php
declare(strict_types=1);
require __DIR__.'/../api/registration-requirements.php';
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$course=['code'=>'HIST110','title'=>'World History I','minimum_grade'=>'D'];
$major=['code'=>'CS110','title'=>'Introduction to Programming','minimum_grade'=>'D','met'=>false];
$audit=['in_progress'=>[],'future_registered'=>[],'review'=>[],'pending_records'=>[],
 'programs'=>[['kind'=>'Major','name'=>'B.S. in Computer Science','requirements'=>[$major]]],
 'gen_ed'=>[['name'=>'World History and Cultures','status'=>'Not completed','options'=>[$course]],
 ['name'=>'Mathematics','status'=>'Completed','options'=>[['code'=>'MATH110','title'=>'Calculus I','minimum_grade'=>'D']]],
 ['name'=>'Missing rules','status'=>'Rules unavailable','options'=>[]]]];
$labels=registrationRequirementMatches($audit,'SP27');
check($labels['HIST110']==='Draft Gen Ed match · World History and Cultures','Missing draft label');
check(str_starts_with($labels['CS110'],'Major requirement'),'Missing declared-major label');
check(!isset($labels['MATH110']),'Completed category was recommended');
$withAlternative=$audit;
$withAlternative['gen_ed'][0]['options'][]=['code'=>'HIST115','title'=>'World History II','minimum_grade'=>'D'];
$withAlternative['future_registered'][]=['code'=>'HIST110','semester_id'=>'SP27'];
$labels=registrationRequirementMatches($withAlternative,'SP27');
check(!isset($labels['HIST110'])&&!isset($labels['HIST115']),'Enrolled category alternatives recommended');
check(isset(registrationRequirementMatches($withAlternative,'FA27')['HIST115']),'A different term was suppressed');
$audit['programs'][0]['requirements'][0]['met']=true;
check(!isset(registrationRequirementMatches($audit,'SP27')['CS110']),'Completed major course was recommended');
$audit['programs'][0]['requirements'][0]['met']=false;
$audit['review'][]=['code'=>'CS110'];
check(!isset(registrationRequirementMatches($audit,'SP27')['CS110']),'Conflicting evidence was recommended');
$audit['pending_records'][]=['code'=>'HIST110'];
check(!isset(registrationRequirementMatches($audit,'SP27')['HIST110']),'Pending reconciliation was recommended');
$audit['programs']=[];$audit['gen_ed']=[];
check(registrationRequirementMatches($audit,'SP27')===[],'Missing mappings created a match');
echo "PASS: personalized major/draft Gen Ed labels, completed and enrolled alternative suppression, term scope, review evidence and unavailable rules.\n";
