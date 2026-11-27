<?php
declare(strict_types=1);

// Preserve raw per-section API rows. UI groups are a partition: a mixed course lives
// in the available list once, carrying both its eligible and blocked sections.
function registrationCourseGroups(array $available, array $blocked): array
{
    $columns=array_values(array_unique([...$available['columns'],...$blocked['columns']]));
    $groups=[];
    foreach ([[$available,true],[$blocked,false]] as [$block,$eligible]) {
        foreach ($block['rows'] as $row) {
            $values=array_combine($block['columns'],$row);
            $code=$values['Course']; $crn=(string)$values['CRN'];
            if (!isset($groups[$code])) $groups[$code]=[
                'code'=>$code, 'title'=>$values['Title'], 'credits'=>$values['Credits']??null,
                'level'=>$values['Level']??null, 'prerequisites'=>$values['Prerequisites']??'—',
                'prerequisitesKnown'=>array_key_exists($code,$block['prerequisitesByCourse']??[]),
                'prerequisiteDetails'=>$block['prerequisitesByCourse'][$code]??[],
                'matches'=>$block['requirementMatchesByCourse'][$code]??[],
                'match'=>$values['Requirement match']??null, 'planning'=>$values['Planning suggestion']??null,
                'columns'=>$columns, 'rows'=>[], 'sectionEligibility'=>[], 'restrictions'=>[],
                'available'=>0, 'blocked'=>0,
            ];
            $groups[$code]['rows'][]=array_map(static fn($name)=>$values[$name]??null,$columns);
            $groups[$code]['sectionEligibility'][$crn]=$eligible;
            $groups[$code][$eligible?'available':'blocked']++;
            if (!$eligible) $groups[$code]['restrictions'][$crn]=$blocked['restrictions'][$crn]??[];
        }
    }
    $can=[];$cannot=[];
    foreach ($groups as $group) {
        $sectionAt=array_search('Section',$columns,true);$crnAt=array_search('CRN',$columns,true);
        usort($group['rows'],static fn($a,$b)=>strnatcmp((string)$a[$sectionAt],(string)$b[$sectionAt])?:($a[$crnAt]<=>$b[$crnAt]));
        if ($group['available']) $can[]=$group; else $cannot[]=$group;
    }
    return [$can,$cannot];
}
