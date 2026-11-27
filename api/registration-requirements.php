<?php
declare(strict_types=1);
require_once __DIR__.'/degree-audit.php';

function registrationMatchLabel(string $kind,string $name,bool $draft): string
{
    // Display names mirror Degree Audit headings/categories, not internal rule keys.
    // Approval remains in each match's draft flag, independently of its label.
    $title = ['Major Requirement'=>'Major Requirements', 'Minor Requirement'=>'Minor Requirements',
        'Gen Ed'=>'General Education', 'Major Elective'=>'Major Electives',
        'Liberal Arts'=>'Liberal Arts Requirement', 'Free Elective'=>'Free Electives'][$kind] ?? null;
    return $title === null ? 'Requirement match unavailable'
        : 'Applies to: '.$title.($name!==''?' — '.$name:'');
}

// Candidate labels are planning evidence, never enrollment permission or awarded credit.
function registrationRequirementMatchDetails(array $audit,string $semesterId,?array $planning=null): array
{
    $enrolled=[];
    foreach(array_merge($audit['in_progress'],$audit['future_registered'])as$c)$enrolled[$c['code']]=true;
    $review=array_fill_keys(array_filter(array_column($audit['review'],'code')),true);
    foreach($audit['pending_records']as$c)$review[$c['code']]=true;
    $earned=[];
    foreach($audit['history']??[]as$c)if(($c['result']??'')==='Passing grade recorded')$earned[$c['code']]=true;
    $matches=[];
    $add=static function(string $code,string $kind,string $name,bool $draft,int $priority)use(&$matches):void{
        $label=registrationMatchLabel($kind,$name,$draft);
        $matches[$code][$label]=['label'=>$label,'kind'=>$kind,'name'=>$name,'draft'=>$draft,'priority'=>$priority];
    };
    foreach($audit['programs']as$p)foreach($p['requirements']as$r){
        $code=$r['code'];
        if($r['met']||isset($enrolled[$code])||isset($review[$code])||isset($earned[$code])||empty($r['title'])||(auditGradeRank($r['minimum_grade'])??0)<=0)continue;
        $add($code,$p['kind'].' Requirement',$r['title'],false,$p['kind']==='Major'?0:1);
    }
    foreach($audit['gen_ed']as$r){
        if(in_array($r['status'],['Completed','Rules unavailable','Review needed'],true))continue;
        if(array_intersect_key($enrolled,array_fill_keys(array_column($r['options'],'code'),true)))continue;
        foreach($r['options']as$o){$code=$o['code'];
            if(isset($review[$code])||isset($earned[$code])||empty($o['title'])||(auditGradeRank($o['minimum_grade'])??0)<=0)continue;
            // Existing shared Gen Ed rules are draft; there is no approval/version flag in their schema.
            $add($code,'Gen Ed',$r['name'],true,2);
        }
    }
    if($planning!==null){
        $base=registrationOptionalPlan($audit,$planning,null);
        foreach($planning['catalog']as$candidate){
            $code=$candidate['code'];
            if(isset($enrolled[$code])||isset($earned[$code])||isset($review[$code]))continue;
            $projected=registrationOptionalPlan($audit,$planning,$candidate);
            foreach(['major_electives'=>['Major Elective',3],'liberal_arts'=>['Liberal Arts',4],'free_electives'=>['Free Elective',5]]as$key=>[$kind,$priority]){
                if(!empty($base[$key]['unmet'])&&!empty($projected[$key]['uses'][$code]))$add($code,$kind,'',$projected[$key]['draft'],$priority);
            }
        }
    }
    foreach($matches as &$list){$list=array_values($list);usort($list,static fn($a,$b)=>$a['priority']<=>$b['priority']?:strcmp($a['label'],$b['label']));}unset($list);
    return $matches;
}

// The same pure audit allocators reserve existing planned coursework and enforce
// sharing/credit targets. A candidate is hypothetical ungraded planning evidence;
// no student record, credit, grade or enrollment is written or awarded.
function registrationOptionalPlan(array $audit,array $planning,?array $candidate): array
{
    $best=$review=$active=$earned=[];
    foreach(evaluateTranscriptCredits($audit['history'],$audit['profile']['as_of'])[0]as[$course,$rank,$issue]){
        if($issue!==null){$review[$course['code']]=['reason'=>$issue,'course'=>$course];continue;}
        if(!isset($best[$course['code']])||$rank>$best[$course['code']]['rank'])$best[$course['code']]=['rank'=>$rank,'course'=>$course];
        if($rank>0)$earned[$course['code']]=(int)$course['credits'];
    }
    foreach(array_merge($audit['in_progress'],$audit['future_registered'])as$c)$active[$c['code']]??=$c;
    foreach($audit['pending_records']as$c)$review[$c['code']]=['reason'=>'Record needs review','course'=>$c];
    if($candidate)$active[$candidate['code']]=['code'=>$candidate['code'],'title'=>$candidate['title'],'credits'=>$candidate['credits'],'grade'=>null,'crn'=>null,'semester_id'=>$planning['semester'],'semester'=>$planning['semester']];
    $evaluate=static fn($r)=>evaluateAuditCourse($r,$best,$active,[],$review,true);
    $required=[];$primaryCodes=[];
    foreach($audit['programs']as$p)foreach($p['requirements']as$r){$required[]=$evaluate($r)+$r;if($p['kind']==='Major'&&!empty($p['is_primary']))$primaryCodes[]=$r['code'];}
    foreach($audit['gen_ed']as$r){$options=[];foreach($r['options']as$o)$options[]=$evaluate($o)+$o;$choice=auditBestCourseOption($options);if($choice)$required[]=$choice;}
    $credit=auditCreditRequirements($planning['credit_policy'],$audit['programs'],$audit['gen_ed'],$audit['profile']['type']==='Undergraduate',$earned,$best,$active);
    $liberal=$credit['liberal_arts'];$claims=auditLiberalArtsClaims(auditCourseClaims($required),$liberal);
    $supplemental=auditSupplementalRequirements($planning['supplemental'],$audit['profile']['type']==='Undergraduate',$evaluate,$claims);
    $electives=auditElectiveRequirements($planning['electives'],$evaluate,$claims,$supplemental,$planning['supplemental'],$primaryCodes);
    $out=[];
    foreach(['major_electives','free_electives']as$key){$b=$electives[$key];$out[$key]=['draft'=>$b['draft'],'unmet'=>$b['configured']&&!in_array($b['status'],['Rules unavailable','Review needed'],true)&&$b['applied']+$b['in_progress']<$b['target'],'uses'=>array_fill_keys(array_column($b['courses'],'code'),true)];}
    $laCodes=array_fill_keys($planning['credit_policy']['eligible_courses']??[],true);$laPlanned=0;foreach($active as$code=>$c)if(isset($laCodes[$code])&&!isset($earned[$code]))$laPlanned+=(int)$c['credits'];
    $out['liberal_arts']=['draft'=>$liberal['draft'],'unmet'=>$liberal['credits_required']!==null&&$liberal['credits_applied']!==null&&$liberal['status']!=='Rules unavailable'&&$liberal['credits_applied']+$laPlanned<$liberal['credits_required'],'uses'=>array_fill_keys(array_column($liberal['courses'],'code'),true)];
    return $out;
}

function registrationRequirementMatches(array $audit,string $semesterId,?array $planning=null): array
{
    return array_map(static fn($list)=>$list[0]['label'],registrationRequirementMatchDetails($audit,$semesterId,$planning));
}
