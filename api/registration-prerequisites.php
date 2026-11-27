<?php
declare(strict_types=1);

// Read every configured prerequisite, including satisfied ones. The met predicate
// is the same as the Add validator; this presentation helper changes no rules.
function registrationPrerequisiteEvidence(int $studentId, array $codes): array
{
    $codes=array_values(array_unique($codes));
    if (!$codes) return [];
    $result=array_fill_keys($codes,[]);
    $marks=implode(',',array_fill(0,count($codes),'?'));
    foreach(rows("SELECT cp.course_ID AS course,cp.prerequisite_Course_ID AS code,c.course_Name AS title,
        cp.min_Grade_Req AS minimum_grade, EXISTS(SELECT 1 FROM Student_History h
          JOIN Course_Section hc ON hc.CRN=h.CRN WHERE h.student_ID=?
          AND hc.course_ID=cp.prerequisite_Course_ID
          AND FIELD(h.grade,".GRADE_ORDER.")>=FIELD(cp.min_Grade_Req,".GRADE_ORDER.")) AS met
      FROM Course_Prerequisite cp LEFT JOIN Course c ON c.course_ID=cp.prerequisite_Course_ID
      WHERE cp.course_ID IN ($marks) ORDER BY cp.course_ID,cp.prerequisite_Course_ID",[$studentId,...$codes])as$row){
        $result[$row['course']][]=['code'=>$row['code'],'title'=>$row['title'],
            'minimum_grade'=>$row['minimum_grade'],'met'=>(bool)$row['met']];
    }
    return $result;
}
