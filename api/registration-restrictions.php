<?php
declare(strict_types=1);

// Display-only diagnostics. whyCannotAdd/checkAdd remain the sole eligibility decision.
// Build one context per view, not a static cache: filter reloads and post-Add views must be fresh.
function registrationRestrictionContext(int $studentId, string $semesterId, ?int $earned): array
{
    return [
        'student' => $studentId, 'semester' => $semesterId, 'earned' => $earned,
        'profile' => one("SELECT s.student_Type, COALESCE(u.undergraduate_Student_Type, g.graduate_Student_Type) AS load_type
          FROM Student s LEFT JOIN Undergraduate_Student u ON u.student_ID=s.student_ID
          LEFT JOIN Graduate_Student g ON g.student_ID=s.student_ID WHERE s.student_ID=?", [$studentId]),
        'holds' => rows('SELECT h.hold_Type FROM Student_Hold sh JOIN Hold h ON h.hold_ID=sh.hold_ID WHERE sh.student_ID=?', [$studentId]),
        'credits' => (int) one('SELECT COALESCE(SUM(c.course_Credits), 0) AS n FROM Enrollment e
          JOIN Course_Section cs ON cs.CRN=e.CRN JOIN Course c ON c.course_ID=cs.course_ID
          WHERE e.student_ID=? AND cs.semester_ID=?', [$studentId, $semesterId])['n'],
        'courses' => [],
    ];
}

function registrationRestrictionDetails(array &$context, int $crn, string $primaryReason): array
{
    $sec = one("SELECT cs.*, c.course_Name, c.course_Credits, c.course_Type, s.semester_Name,
        CURDATE() BETWEEN s.add_Start AND s.add_End AS add_open, s.add_Start, s.add_End
      FROM Course_Section cs JOIN Course c ON c.course_ID=cs.course_ID JOIN Semester s ON s.semester_ID=cs.semester_ID
      WHERE cs.CRN=?", [$crn]);
    if (!$sec) return [['code'=>'unavailable', 'summary'=>'Section unavailable', 'explanation'=>$primaryReason]];
    $id=$context['student']; $code=$sec['course_ID']; $profile=$context['profile']; $items=[];
    $add = static function (string $code, string $summary, string $explanation, array $evidence=[]) use (&$items): void {
        $items[]=['code'=>$code, 'summary'=>$summary, 'explanation'=>$explanation, ...$evidence];
    };
    if (!$sec['add_open']) $add('window','Registration closed',"The add period for {$sec['semester_Name']} is {$sec['add_Start']} to {$sec['add_End']}.");
    if ($context['holds']) $add('hold','Active hold', 'You have an active '.implode(' and ',array_map(fn($h)=>strtolower($h['hold_Type']),$context['holds'])).' hold.');
    if ($sec['course_Type']!==$profile['student_Type']) $add('level','Academic level restriction', $profile['student_Type']==='Undergraduate'?'Undergraduates cannot register for graduate courses.':'Graduate students cannot register for undergraduate courses.');

    // Reuse the program validator and missing-prerequisite predicate, not a prefix/department guess.
    if (!isset($context['courses'][$code])) {
        $program=null;
        try { checkCourseProgram($id,$code,$sec['course_Type']); } catch (RuleError $e) { $program=$e->getMessage(); }
        $context['courses'][$code]=[
            'program'=>$program,
            'passed'=>one("SELECT h.grade FROM Student_History h JOIN Course_Section hc ON hc.CRN=h.CRN
              WHERE h.student_ID=? AND hc.course_ID=? AND h.grade<>'F'",[$id,$code]),
            'registered'=>one('SELECT cs.CRN FROM Enrollment e JOIN Course_Section cs ON cs.CRN=e.CRN
              WHERE e.student_ID=? AND cs.course_ID=? AND cs.semester_ID=?',[$id,$code,$sec['semester_ID']]),
            'current'=>one('SELECT s.semester_Name FROM Enrollment e JOIN Course_Section cs ON cs.CRN=e.CRN
              JOIN Semester s ON s.semester_ID=cs.semester_ID WHERE e.student_ID=? AND cs.course_ID=? AND e.grade IS NULL',[$id,$code]),
            'missing'=>missingRegistrationPrerequisites($id,$code),
        ];
    }
    $course=$context['courses'][$code];
    if ($course['program']) $add('program',str_contains($course['program'],'(minor)')?'Program restriction':'Not open to your major',$course['program']);
    if ($sec['course_Type']==='Undergraduate') {
        $level=(int)substr(preg_replace('/\D/','',$code),0,1);
        $minimum=[2=>'Sophomore',3=>'Junior',4=>'Senior'][$level]??null;
        $order=['Freshman'=>1,'Sophomore'=>2,'Junior'=>3,'Senior'=>4];
        $standing=undergraduateClassYear($context['earned']??studentEarnedCredits($id));
        if ($minimum && $order[$standing]<$order[$minimum]) $add('standing',"$minimum standing required", "This is a {$level}00-level course. You need at least $minimum standing; your posted earned credits give you $standing standing.");
    }
    if ($course['registered']) $add('registered','Already registered',"You are already registered for $code this semester (CRN {$course['registered']['CRN']}).");
    if ($course['passed']) $add('completed','Already completed — '.$course['passed']['grade'],"You already passed $code (grade {$course['passed']['grade']}).");
    if ($course['current'] && !$course['registered']) $add('current','Already taking this course',"You are already taking $code in {$course['current']['semester_Name']}.");
    if ($course['missing']) {
        $summary = count($course['missing']) === 1
            ? 'Missing prerequisite — '.$course['missing'][0]['id'] : 'Missing prerequisites';
        $add('prerequisites',$summary,'Complete the following prerequisites at the configured minimum grades.',['prerequisites'=>$course['missing']]);
    }
    if ((int)$sec['available_Seats']<=0) $add('full','Section full',"$code (CRN $crn) is full ({$sec['max_Seats']} seats).");
    $max=MAX_CREDITS[$profile['load_type']]; $after=$context['credits']+(int)$sec['course_Credits'];
    if ($after>$max) $add('credits','Credit limit exceeded',"This would bring you to $after credits; the limit for ".strtolower($profile['load_type'])." students is $max.");
    $slots=[(int)$sec['time_Slot_ID']];
    $lab=one('SELECT time_Slot_ID FROM Course_Section_Lab WHERE CRN=?',[$crn]);
    if ($lab) $slots[]=(int)$lab['time_Slot_ID'];
    // Same strict time overlap and lecture/lab union as checkAdd; include every conflicting section.
    $conflicts=rows('SELECT DISTINCT m.course_ID, m.CRN FROM Enrollment e JOIN '.MEETINGS.' m ON m.CRN=e.CRN
      WHERE e.student_ID=? AND m.semester_ID=? AND '.overlapsAny('m.time_Slot_ID',count($slots)).' ORDER BY m.course_ID,m.CRN',[$id,$sec['semester_ID'],...$slots]);
    if ($conflicts) $add('conflict','Schedule conflict',"This section's lecture or linked lab overlaps ".implode(', ',array_map(fn($c)=>$c['course_ID'].' (CRN '.$c['CRN'].')',$conflicts)).'.',['conflicts'=>$conflicts]);
    // Never discard the authoritative refusal when an unknown/new rule has no display adapter.
    if (!$items) $add('other','Registration restricted',$primaryReason);
    $priority=['completed'=>0,'registered'=>1,'current'=>2,'hold'=>3,'window'=>4,'program'=>5,'level'=>6,'standing'=>7,'prerequisites'=>8,'full'=>9,'credits'=>10,'conflict'=>11,'other'=>12];
    usort($items,static fn($a,$b)=>($priority[$a['code']]??99)<=>($priority[$b['code']]??99));
    return $items;
}
