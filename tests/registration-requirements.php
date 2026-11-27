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
check($labels['HIST110']==='Applies to: General Education — World History and Cultures','Missing draft label');
check(str_starts_with($labels['CS110'],'Applies to: Major Requirements'),'Missing declared-major label');
check(!isset($labels['MATH110']),'Completed category was recommended');
$withAlternative=$audit;
$withAlternative['gen_ed'][0]['options'][]=['code'=>'HIST115','title'=>'World History II','minimum_grade'=>'D'];
$withAlternative['future_registered'][]=['code'=>'HIST110','semester_id'=>'SP27'];
$labels=registrationRequirementMatches($withAlternative,'SP27');
check(!isset($labels['HIST110'])&&!isset($labels['HIST115']),'Enrolled category alternatives recommended');
check(!isset(registrationRequirementMatches($withAlternative,'FA27')['HIST115']),'An already planned alternative was recommended again');
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

// Allocation-backed planning: respect caps, current/future reservations and sharing.
$profile=['id'=>1,'first_Name'=>'Unit','middle_Name'=>null,'last_Name'=>'Fixture','type'=>'Undergraduate','year'=>'Freshman','as_of'=>'2026-12-17','enrollment_status'=>'Full-time','stored_credits'=>0];
$programs=[['id'=>'CS-BS','kind'=>'Major','name'=>'Computer Science','is_primary'=>1,'declared'=>1]];
$required=[['kind'=>'Major','program_id'=>'CS-BS','code'=>'CS110','title'=>'Introduction to Programming','credits'=>3,'minimum_grade'=>'D']];
$gen=[['id'=>'HIST','name'=>'World History and Cultures','code'=>'HIST110','title'=>'World History I','minimum_grade'=>'D']];
$credit=['name'=>'Test target','total'=>120,'liberal_arts'=>3,'draft'=>true,'eligible_courses'=>['HIST110','MATH110']];
$rule=static fn($code)=>['code'=>$code,'title'=>$code,'credits'=>3,'minimum_grade'=>'D'];
$electives=['MAJOR'=>['unit'=>'COURSES','target'=>1,'share'=>false,'draft'=>false,'options'=>[$rule('CS350')]],'FREE'=>['unit'=>'CREDITS','target'=>3,'share'=>false,'draft'=>true,'options'=>array_map($rule,['BUS110','BUS115','HIST110','CS350'])]];
$catalog=array_map($rule,['CS110','HIST110','MATH110','CS350','BUS110','BUS115','PSY110']);
$planning=['semester'=>'SP27','credit_policy'=>$credit,'supplemental'=>[],'electives'=>$electives,'catalog'=>$catalog];
$base=calculateDegreeAudit($profile,$programs,$required,[],[],$gen,$credit,[],$electives);
$before=serialize($base);$details=registrationRequirementMatchDetails($base,'SP27',$planning);
check($details['HIST110'][0]['label']==='Applies to: General Education — World History and Cultures','Wrong primary priority');
check(in_array('Applies to: Liberal Arts Requirement',array_column($details['HIST110'],'label'),true),'Missing draft Liberal Arts');
check(!in_array('Free Elective',array_column($details['HIST110'],'kind'),true),'Nonsharing free elective claimed a Gen Ed/Liberal Arts course');
check($details['CS350'][0]['label']==='Applies to: Major Electives','Confirmed elective mislabeled draft');
check(count($details['CS350'])===1,'Major/free sharing restriction lost');
check($details['CS350'][0]['draft']===false&&$details['HIST110'][0]['draft']===true&&$details['BUS110'][0]['draft']===true,'Approval metadata changed with terminology');
check(registrationMatchLabel('Unconfigured','',false)==='Requirement match unavailable','Unknown requirement invented a label');
check($details['BUS110'][0]['label']==='Applies to: Free Electives','Missing explicitly mapped Free Elective');
check(!isset($details['PSY110']),'Unmapped subject guessed a degree use');
check(serialize($base)===$before,'Planning changed the audit');
$enroll=static fn($code,$crn)=>['crn'=>$crn,'code'=>$code,'title'=>$code,'credits'=>3,'grade'=>null,'enrollment_course'=>$code,'semester_id'=>'SP27','semester'=>'Spring 2027','start_Date'=>'2027-01-25','end_Date'=>'2027-05-20'];
$planned=calculateDegreeAudit($profile,$programs,$required,[],[$enroll('HIST110',91),$enroll('BUS110',92)],$gen,$credit,[],$electives);
$reserved=registrationRequirementMatchDetails($planned,'SP27',$planning);
check(!isset($reserved['MATH110']),'Liberal Arts target already covered by planned coursework');
check(!isset($reserved['BUS115']),'Free Electives target already covered by planned coursework');
check(!isset($reserved['HIST110']),'An enrolled course was recommended');
$missing=$planning;$missing['credit_policy']=null;$missing['electives']=[];
check(!isset(registrationRequirementMatchDetails($base,'SP27',$missing)['BUS110']),'Unavailable optional rules invented a match');
echo "PASS allocation-backed matches: confirmed/draft labels, multiple possibilities, sharing exclusions, target reservations, future enrollment, missing rules and no audit mutation.\n";
