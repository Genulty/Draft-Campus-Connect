<?php
declare(strict_types=1);
require __DIR__.'/../api/registration-course-groups.php';
$columns=['CRN','Course','Title','Section','Credits','Prerequisites','Requirement match'];
$available=['columns'=>$columns,'rows'=>[[1,'SHARED110','Shared course','001',3,'—','Draft Gen Ed match'],[3,'OPEN110','Open course','001',4,'—',null],[4,'OPEN110','Open course','002',4,'—',null]]];
$blocked=['columns'=>[...$columns,'Reason'],'rows'=>[[2,'SHARED110','Shared course','002',3,'—','Draft Gen Ed match','Full'],[5,'DONE110','Completed course','001',3,'—',null,'Passed']],'restrictions'=>[2=>[['code'=>'full','summary'=>'Section full']],5=>[['code'=>'completed','summary'=>'Already completed — B']]]];
[$yes,$no]=registrationCourseGroups($available,$blocked);
assert(count($yes)===2&&count($no)===1);
assert($yes[0]['code']==='SHARED110'&&count($yes[0]['rows'])===2);
assert($yes[0]['sectionEligibility'][1]===true&&$yes[0]['sectionEligibility'][2]===false);
assert($yes[0]['available']===1&&$yes[0]['blocked']===1);
assert($yes[1]['available']===2&&$no[0]['available']===0);
assert($no[0]['restrictions'][5][0]['code']==='completed');
$ids=[];foreach([...$yes,...$no]as$g)foreach($g['rows']as$row){assert(count($row)===count($g['columns']));$ids[]=$row[0];}
sort($ids);assert($ids===[1,2,3,4,5]);
assert(count($available['rows'])===3&&count($blocked['rows'])===2);
echo "PASS grouping: course uniqueness across lists, mixed section eligibility, distinct seats/reasons retained, no duplicated or lost CRNs, raw API rows unchanged.\n";
