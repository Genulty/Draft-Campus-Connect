<?php
// Read-only Degree Audit data and calculations. No catalog policies are inferred.
declare(strict_types=1);

// The letter-grade order already used by registration and stored requirement checks.
// Other grading schemes need an explicit policy and remain unclassified here.
const AUDIT_GRADES = ['F', 'D', 'D+', 'C-', 'C', 'C+', 'B-', 'B', 'B+', 'A-', 'A'];

function auditGradeRank(?string $grade): ?int
{
    $rank = array_search($grade, AUDIT_GRADES, true);
    return $rank === false ? null : $rank;
}

function loadDegreeAudit(int $studentId): ?array
{
    $profile = one("SELECT s.student_ID AS id, s.student_Type AS type, s.student_Year AS year,
        u.first_Name, u.middle_Name, u.last_Name, CURDATE() AS as_of,
        CASE s.student_Type WHEN 'Undergraduate' THEN ug.undergraduate_Student_Type
          WHEN 'Graduate' THEN g.graduate_Student_Type END AS enrollment_status,
        CASE s.student_Type WHEN 'Undergraduate' THEN
          CASE ug.undergraduate_Student_Type WHEN 'Full-time' THEN ft.credits_Earned WHEN 'Part-time' THEN pt.credits_Earned END
          WHEN 'Graduate' THEN CASE g.graduate_Student_Type WHEN 'Full-time' THEN fg.credits_Earned WHEN 'Part-time' THEN pg.credits_Earned END
        END AS stored_credits
      FROM Student s JOIN User u ON u.user_ID = s.student_ID
      LEFT JOIN Undergraduate_Student ug ON ug.student_ID = s.student_ID
      LEFT JOIN Graduate_Student g ON g.student_ID = s.student_ID
      LEFT JOIN Full_Time_Undergraduate ft ON ft.student_ID = s.student_ID
      LEFT JOIN Part_Time_Undergraduate pt ON pt.student_ID = s.student_ID
      LEFT JOIN Full_Time_Graduate_Student fg ON fg.student_ID = s.student_ID
      LEFT JOIN Part_Time_Graduate_Student pg ON pg.student_ID = s.student_ID
      WHERE s.student_ID = ?", [$studentId]);
    if (!$profile) return null;

    // Retain a primary major recorded only in Student, but flag missing declaration links.
    $programs = rows("SELECT 'Major' AS kind, m.major_ID AS id, m.major_Name AS name,
        m.major_ID = s.major_ID AS is_primary, sm.major_ID IS NOT NULL AS declared
      FROM Student s JOIN Major m ON m.major_ID = s.major_ID
        OR EXISTS (SELECT 1 FROM Student_Major x WHERE x.student_ID = s.student_ID AND x.major_ID = m.major_ID)
      LEFT JOIN Student_Major sm ON sm.student_ID = s.student_ID AND sm.major_ID = m.major_ID
      WHERE s.student_ID = ? ORDER BY is_primary DESC, m.major_ID", [$studentId]);
    foreach (rows("SELECT 'Minor' AS kind, m.minor_ID AS id, m.minor_Name AS name, 0 AS is_primary, 1 AS declared
        FROM Student_Minor sm JOIN Minor m ON m.minor_ID = sm.minor_ID WHERE sm.student_ID = ? ORDER BY m.minor_ID", [$studentId]) as $minor) $programs[] = $minor;
    $requirements = rows("SELECT 'Major' AS kind, r.major_ID AS program_id, c.course_ID AS code,
        c.course_Name AS title, c.course_Credits AS credits, r.min_Grade_Req AS minimum_grade
      FROM Major_Course_Requirement r JOIN Course c ON c.course_ID = r.course_ID
      WHERE r.major_ID IN (SELECT major_ID FROM Student_Major WHERE student_ID = ? UNION SELECT major_ID FROM Student WHERE student_ID = ?)
      UNION ALL
      SELECT 'Minor', r.minor_ID, c.course_ID, c.course_Name, c.course_Credits, r.min_Grade_Req
      FROM Minor_Course_Requirement r JOIN Course c ON c.course_ID = r.course_ID
      WHERE r.minor_ID IN (SELECT minor_ID FROM Student_Minor WHERE student_ID = ?)
      ORDER BY kind, program_id, code", [$studentId, $studentId, $studentId]);
    $history = rows("SELECT h.CRN AS crn, h.course_ID AS code, c.course_Name AS title, c.course_Credits AS credits,
        h.grade, h.semester_ID AS semester_id, sem.semester_Name AS semester,
        cs.course_ID AS section_course, cs.semester_ID AS section_semester, e.grade AS enrollment_grade
      FROM Student_History h JOIN Course c ON c.course_ID = h.course_ID
      JOIN Semester sem ON sem.semester_ID = h.semester_ID JOIN Course_Section cs ON cs.CRN = h.CRN
      LEFT JOIN Enrollment e ON e.student_ID = h.student_ID AND e.CRN = h.CRN
      WHERE h.student_ID = ? ORDER BY sem.start_Date DESC, h.course_ID, h.CRN", [$studentId]);
    $enrollments = rows("SELECT cs.CRN AS crn, cs.course_ID AS code, c.course_Name AS title, c.course_Credits AS credits,
        e.grade, e.course_ID AS enrollment_course, cs.semester_ID AS semester_id,
        sem.semester_Name AS semester, sem.start_Date, sem.end_Date
      FROM Enrollment e JOIN Course_Section cs ON cs.CRN = e.CRN JOIN Course c ON c.course_ID = cs.course_ID
      JOIN Semester sem ON sem.semester_ID = cs.semester_ID WHERE e.student_ID = ?
      ORDER BY sem.start_Date, cs.course_ID, cs.CRN", [$studentId]);
    return calculateDegreeAudit($profile, $programs, $requirements, $history, $enrollments);
}

function calculateDegreeAudit(array $profile, array $programs, array $requirements, array $history, array $enrollments): array
{
    $profile['name'] = trim(implode(' ', array_filter([$profile['first_Name'], $profile['middle_Name'], $profile['last_Name']], static fn ($v) => $v !== null && $v !== '')));
    unset($profile['first_Name'], $profile['middle_Name'], $profile['last_Name']);
    $profile['enrollment_status'] = in_array($profile['enrollment_status'], ['Full-time', 'Part-time'], true) ? $profile['enrollment_status'] : 'Unknown / not on file';
    $best = $attempts = $earned = $historyByCrn = $reviewCourses = [];
    $review = [];
    foreach ($history as &$attempt) {
        $code = $attempt['code'];
        $attempts[$code][] = $attempt;
        $historyByCrn[$attempt['crn']] = $attempt;
        $rank = auditGradeRank($attempt['grade']);
        $consistent = $code === $attempt['section_course'] && $attempt['semester_id'] === $attempt['section_semester']
            && ($attempt['enrollment_grade'] === null || $attempt['enrollment_grade'] === $attempt['grade']);
        if (!$consistent || $rank === null) {
            $attempt['result'] = 'Review needed';
            $reviewCourses[$code] = true;
            $review[] = ['code' => $code, 'crn' => $attempt['crn'], 'semester' => $attempt['semester'],
                'reason' => !$consistent ? 'Transcript and section/enrollment records disagree; excluded from calculated credits.' : 'Grade policy unavailable; excluded from calculated credits.'];
            continue;
        }
        $attempt['result'] = $rank > 0 ? 'Passing grade recorded' : 'Not passing';
        if (!isset($best[$code]) || $rank > $best[$code]['rank']) $best[$code] = ['rank' => $rank, 'grade' => $attempt['grade']];
        if ($rank > 0) $earned[$code] = (int) $attempt['credits'];
    }
    unset($attempt);

    $inProgress = $future = $pending = $activeByCode = $futureByCode = [];
    foreach ($enrollments as $enrollment) {
        if (isset($historyByCrn[$enrollment['crn']])) continue; // Transcript is authoritative for this attempt.
        $code = $enrollment['code'];
        if ($code !== $enrollment['enrollment_course']) {
            $enrollment['status'] = 'Course identifiers disagree; review needed';
            $pending[] = $enrollment;
            $reviewCourses[$code] = true;
        } elseif ($enrollment['grade'] !== null) {
            $enrollment['status'] = 'Grade posted without transcript history; review needed';
            $pending[] = $enrollment;
            $reviewCourses[$code] = true;
        } elseif ($enrollment['start_Date'] > $profile['as_of']) {
            $future[] = $enrollment;
            $futureByCode[$code] = true;
        } elseif ($enrollment['end_Date'] >= $profile['as_of']) {
            $inProgress[] = $enrollment;
            $activeByCode[$code] = true;
        } else {
            $enrollment['status'] = 'Past semester; final grade/history missing';
            $pending[] = $enrollment;
        }
    }
    $allRequired = $outstanding = [];
    foreach ($programs as &$program) {
        $program['requirements'] = [];
        $program['met'] = $program['listed_credits'] = $program['met_credits'] = 0;
        if (!(bool) $program['declared']) $review[] = ['code' => $program['id'], 'crn' => null, 'semester' => null, 'reason' => 'Primary major is missing its Student_Major declaration link.'];
        foreach ($requirements as $requirement) {
            if ($requirement['kind'] !== $program['kind'] || $requirement['program_id'] !== $program['id']) continue;
            $code = $requirement['code'];
            $allRequired[$code] = true;
            $minimum = auditGradeRank($requirement['minimum_grade']);
            $passing = isset($best[$code]) && $best[$code]['rank'] > 0;
            $met = $minimum !== null && $passing && $best[$code]['rank'] >= $minimum;
            $requirement['best_grade'] = $best[$code]['grade'] ?? null;
            $requirement['attempts'] = array_map(static fn ($a) => ['grade' => $a['grade'], 'semester' => $a['semester'], 'crn' => $a['crn']], $attempts[$code] ?? []);
            $requirement['met'] = $met;
            $requirement['status'] = match (true) {
                $met => 'Complete',
                $minimum === null || isset($reviewCourses[$code]) => 'Review needed',
                isset($activeByCode[$code]) => 'In progress',
                isset($futureByCode[$code]) => 'Registered for future semester',
                isset($best[$code]) => 'Below minimum grade',
                default => 'No completion recorded',
            };
            $program['requirements'][] = $requirement;
            $program['listed_credits'] += (int) $requirement['credits'];
            if ($met) {
                $program['met']++;
                $program['met_credits'] += (int) $requirement['credits'];
            } else $outstanding[] = ['program' => $program['name'], 'kind' => $program['kind'], 'code' => $code,
                'minimum_grade' => $requirement['minimum_grade'], 'status' => $requirement['status']];
        }
        $program['total'] = count($program['requirements']);
        $program['percent'] = $program['total'] ? round(100 * $program['met'] / $program['total'], 1) : null;
    }
    unset($program);
    $distinctCredits = static function (array $list): int {
        $courses = [];
        foreach ($list as $course) $courses[$course['code']] = (int) $course['credits'];
        return array_sum($courses);
    };
    $repeats = count(array_filter($attempts, static fn ($list) => count($list) > 1));
    $stored = $profile['stored_credits'];
    $earnedTotal = array_sum($earned);
    if ($stored !== null && (int) $stored !== $earnedTotal) $review[] = ['code' => null, 'crn' => null, 'semester' => null,
        'reason' => 'Stored student credit total differs from the transcript-derived provisional total; reconciliation policy is unavailable.'];
    return [
        'profile' => $profile, 'programs' => $programs,
        'credits' => ['earned_provisional' => $earnedTotal, 'passing_courses' => count($earned), 'transcript_attempts' => count($history),
            'repeated_courses' => $repeats, 'in_progress' => $distinctCredits($inProgress), 'future_registered' => $distinctCredits($future),
            'degree_target' => null, 'degree_percent' => null],
        'in_progress' => $inProgress, 'future_registered' => $future, 'pending_records' => $pending,
        'history' => $history, 'other_coursework' => array_values(array_filter($history, static fn ($a) => !isset($allRequired[$a['code']]))),
        'outstanding' => $outstanding, 'review' => $review,
        'unavailable' => [
            ['title' => 'General Education requirements', 'reason' => 'No program-specific categories or approved course mappings are stored. Applicability and completion are unavailable.'],
            ['title' => 'Liberal Arts requirements', 'reason' => 'No eligibility classifications or program-specific credit targets are stored. Department prefixes do not establish eligibility.'],
            ['title' => 'Major Electives', 'reason' => 'No approved elective options, choice groups, or required credit totals are stored.'],
            ['title' => 'Free Electives', 'reason' => 'No allocation rules, program-specific totals, or cross-requirement credit-sharing policy are stored.'],
        ],
    ];
}
