<?php
// Read-only Degree Audit data and calculations. No catalog policies are inferred.
declare(strict_types=1);

require_once __DIR__ . '/degree-audit-supplemental.php';
require_once __DIR__ . '/degree-audit-electives.php';

// The letter-grade order already used by registration and stored requirement checks.
// Other grading schemes need an explicit policy and remain unclassified here.
const AUDIT_GRADES = ['F', 'D', 'D+', 'C-', 'C', 'C+', 'B-', 'B', 'B+', 'A-', 'A'];

function auditGradeRank(?string $grade): ?int
{
    $rank = array_search($grade, AUDIT_GRADES, true);
    return $rank === false ? null : $rank;
}

function loadDegreeAudit(int $studentId, bool $includeCreditPolicy = true, ?string $previewMajorId = null): ?array
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
    $choices = $selected = null;
    if ($previewMajorId !== null) {
        $choices = degreeAuditMajorOptions($profile['type'], $programs);
        $selected = $choices[$previewMajorId] ?? null;
        if ($selected === null) fail('Choose an undeclared major offered at your academic level.');
    }
    $history = rows("SELECT h.CRN AS crn, h.course_ID AS code, c.course_Name AS title, c.course_Credits AS credits,
        h.grade, h.semester_ID AS semester_id, COALESCE(sem.semester_Name, h.semester_ID) AS semester, sem.start_Date AS term_start,
        cs.course_ID AS section_course, cs.semester_ID AS section_semester,
        e.course_ID AS enrollment_course, e.grade AS enrollment_grade
      FROM Student_History h LEFT JOIN Course c ON c.course_ID = h.course_ID
      LEFT JOIN Semester sem ON sem.semester_ID = h.semester_ID LEFT JOIN Course_Section cs ON cs.CRN = h.CRN
      LEFT JOIN Enrollment e ON e.student_ID = h.student_ID AND e.CRN = h.CRN
      WHERE h.student_ID = ? ORDER BY sem.start_Date DESC, h.course_ID, h.CRN", [$studentId]);
    $enrollments = rows("SELECT e.CRN AS crn, COALESCE(cs.course_ID, e.course_ID) AS code, c.course_Name AS title, c.course_Credits AS credits,
        e.grade, e.course_ID AS enrollment_course, cs.semester_ID AS semester_id,
        COALESCE(sem.semester_Name, cs.semester_ID) AS semester, sem.start_Date, sem.end_Date
      FROM Enrollment e LEFT JOIN Course_Section cs ON cs.CRN = e.CRN LEFT JOIN Course c ON c.course_ID = cs.course_ID
      LEFT JOIN Semester sem ON sem.semester_ID = cs.semester_ID WHERE e.student_ID = ?
      ORDER BY sem.start_Date, cs.course_ID, cs.CRN", [$studentId]);
    $genEd = [];
    if ($profile['type'] === 'Undergraduate') {
        try {
            $genEd = rows('SELECT r.requirement_ID AS id, r.requirement_Name AS name,
                o.course_ID AS code, c.course_Name AS title, o.min_Grade_Req AS minimum_grade
              FROM Gen_Ed_Requirement r LEFT JOIN Gen_Ed_Requirement_Course o ON o.requirement_ID = r.requirement_ID
              LEFT JOIN Course c ON c.course_ID = o.course_ID
              ORDER BY r.requirement_Name, r.requirement_ID, c.course_ID');
        } catch (PDOException $error) {
            // Older databases can still serve the audit before the Gen Ed migration runs.
            if ($error->getCode() !== '42S02') throw $error;
        }
    }
    // Both audits use the same transcript, enrollment, and shared Gen Ed inputs.
    $evaluate = static function (array $programs) use ($profile, $history, $enrollments, $genEd, $includeCreditPolicy, $studentId): array {
        $creditPolicy = $includeCreditPolicy && !empty($programs[0]['is_primary'])
            ? loadDegreeCreditPolicy($programs[0]['id'])
            : null;
        $supplemental = $creditPolicy ? loadAuditSupplementalPolicy($creditPolicy['id'], $studentId, $profile['as_of']) : [];
        $electives = $creditPolicy ? loadAuditElectivePolicy($creditPolicy['id']) : [];
        return calculateDegreeAudit($profile, $programs, degreeAuditRequirements($programs), $history, $enrollments,
            $genEd, $creditPolicy, $supplemental, $electives);
    };
    $current = $evaluate($programs);
    if ($selected === null) return $current;

    $simulated = [['kind' => 'Major', 'id' => $previewMajorId, 'name' => $selected, 'is_primary' => 1, 'declared' => 1]];
    foreach ($programs as $program) {
        if (!$program['is_primary']) $simulated[] = $program;
    }
    $preview = $evaluate($simulated);
    return $preview + ['preview' => [
        'major_id' => $previewMajorId, 'major_name' => $selected, 'options' => $choices,
    ] + compareDegreeAuditPreview($current, $preview)];
}

// The catalog has no active flag or independent level. Use required courses
// or recorded primary majors as evidence of a program's academic level.
function degreeAuditMajorOptions(string $level, array $programs): array
{
    $declared = array_column(array_filter($programs, static fn ($p) => $p['kind'] === 'Major'), 'id');
    $options = [];
    foreach (rows('SELECT m.major_ID AS id, m.major_Name AS name FROM Major m
        WHERE NOT EXISTS (SELECT 1 FROM Major_Course_Requirement r JOIN Course c USING (course_ID)
            WHERE r.major_ID = m.major_ID AND c.course_Type <> ?)
          AND (EXISTS (SELECT 1 FROM Major_Course_Requirement r WHERE r.major_ID = m.major_ID)
            OR EXISTS (SELECT 1 FROM Student s WHERE s.major_ID = m.major_ID AND s.student_Type = ?))
        ORDER BY m.major_Name', [$level, $level]) as $major) {
        if (!in_array($major['id'], $declared, true)) $options[$major['id']] = $major['name'];
    }
    return $options;
}

function degreeAuditRequirements(array $programs): array
{
    // Evaluate the declarations read above even if a major change commits during this request.
    $programIds = ['Major' => [], 'Minor' => []];
    foreach ($programs as $program) $programIds[$program['kind']][] = $program['id'];
    $marks = static fn (array $ids) => $ids ? implode(', ', array_fill(0, count($ids), '?')) : 'NULL';
    return rows("SELECT 'Major' AS kind, r.major_ID AS program_id, r.course_ID AS code,
        c.course_Name AS title, c.course_Credits AS credits, r.min_Grade_Req AS minimum_grade
      FROM Major_Course_Requirement r LEFT JOIN Course c ON c.course_ID = r.course_ID
      WHERE r.major_ID IN ({$marks($programIds['Major'])})
      UNION ALL
      SELECT 'Minor', r.minor_ID, r.course_ID, c.course_Name, c.course_Credits, r.min_Grade_Req
      FROM Minor_Course_Requirement r LEFT JOIN Course c ON c.course_ID = r.course_ID
      WHERE r.minor_ID IN ({$marks($programIds['Minor'])})
      ORDER BY kind, program_id, code", [...$programIds['Major'], ...$programIds['Minor']]);
}

// A match needs completed coursework selected by the evaluator. Alternate options,
// in-progress courses, and placement without coursework are not course allocations.
function degreeAuditAppliedCourses(array $audit, bool $includeLiberalArts): array
{
    $applied = [];
    $add = static function (array $match, string $requirement, bool $draft) use (&$applied): void {
        if ($match['status'] !== 'Completed' || empty($match['course'])) return;
        $course = $match['course'];
        $applied[$course['code']] ??= ['code' => $course['code'], 'title' => $course['title'], 'requirements' => []];
        $applied[$course['code']]['requirements'][] = $requirement . ($draft ? ' (draft)' : '');
    };
    foreach ($audit['programs'] as $program) {
        foreach ($program['requirements'] as $rule) $add($rule, $program['name'], false);
    }
    foreach ($audit['gen_ed'] as $rule) $add($rule, $rule['name'], true);
    if ($audit['diversity'] !== null) $add($audit['diversity'], 'Diversity', $audit['diversity']['draft']);
    foreach ($audit['proficiency']['requirements'] ?? [] as $rule) $add($rule, $rule['name'], $rule['draft']);
    foreach (['major_electives' => 'Major Electives', 'free_electives' => 'Free Electives', 'liberal_arts' => 'Liberal Arts'] as $key => $label) {
        $block = $audit[$key] ?? null;
        $courses = array_filter($block['courses'] ?? [], static fn ($c) => $c['status'] === 'Completed');
        if ($key === 'liberal_arts' && !$includeLiberalArts) continue;
        foreach ($courses as $course) $add(['status' => 'Completed', 'course' => $course], $label, $block['draft']);
    }
    return $applied;
}

function compareDegreeAuditPreview(array $current, array $preview): array
{
    // A capped total does not identify which Liberal Arts courses were applied.
    $hasUnallocatedCredits = static function (array $audit): bool {
        $block = $audit['liberal_arts'];
        $earned = array_filter($block['courses'], static fn ($course) => $course['status'] === 'Completed');
        return array_sum(array_column($earned, 'credits')) > ($block['credits_applied'] ?? 0);
    };
    $compareLiberalArts = !$hasUnallocatedCredits($current) && !$hasUnallocatedCredits($preview);
    $before = degreeAuditAppliedCourses($current, $compareLiberalArts);
    $after = degreeAuditAppliedCourses($preview, $compareLiberalArts);
    $stillCounts = array_intersect_key($after, $before);
    // Surface courses that lost a mapped use even if another block still uses them.
    $lostUses = [];
    foreach ($before as $code => $course) {
        $lost = array_values(array_diff($course['requirements'], $after[$code]['requirements'] ?? []));
        if ($lost) $lostUses[] = array_replace($course, ['requirements' => $lost]);
    }
    $primary = $preview['programs'][0];
    $needed = [];
    foreach ($primary['requirements'] as $rule) {
        if (!$rule['met']) $needed[] = ['name' => $rule['title'] ?? $rule['code'], 'status' => $rule['status']];
    }
    // Program-specific detail and options remain in the full audit below.
    $additional = array_slice($preview['degree_requirements']['checklist'], count($preview['programs']));
    $draftBlocks = [
        'General Education' => (bool) $preview['gen_ed'],
        'Liberal Arts' => $preview['liberal_arts']['draft'],
        'Diversity' => $preview['diversity']['draft'] ?? false,
        'Proficiency' => $preview['proficiency']['draft'] ?? false,
        'Major Electives' => $preview['major_electives']['draft'] ?? false,
        'Free Electives' => $preview['free_electives']['draft'] ?? false,
    ];
    foreach ($additional as &$rule) {
        if (!empty($draftBlocks[$rule['name']])) $rule['name'] .= ' (draft)';
    }
    unset($rule);
    if ($preview['degree_requirements']['status'] === 'Rules unavailable') {
        array_unshift($additional, ['name' => 'Degree credit requirements', 'status' => 'Rules unavailable']);
    }
    return [
        'still_counts' => array_values($stillCounts),
        'newly_counts' => array_values(array_diff_key($after, $before)),
        'lost_uses' => $lostUses,
        'compare_liberal_arts' => $compareLiberalArts,
        'needed' => $needed,
        'primary_status' => auditRequirementBlockStatus($primary['requirements']),
        'additional' => array_values(array_filter($additional, static fn ($r) => $r['status'] !== 'Completed')),
    ];
}

function loadDegreeCreditPolicy(string $primaryMajorId): ?array
{
    try {
        $policy = rows('SELECT p.program_ID AS id, p.program_Name AS name, p.total_Credits AS total,
            p.liberal_Arts_Credits AS liberal_arts, p.is_Draft AS draft, la.course_ID AS eligible_course
          FROM Degree_Audit_Program_Major m JOIN Degree_Audit_Program p ON p.program_ID = m.program_ID
          LEFT JOIN Degree_Audit_Liberal_Arts_Course la ON la.program_ID = p.program_ID
          WHERE m.major_ID = ? ORDER BY la.course_ID', [$primaryMajorId]);
    } catch (PDOException $error) {
        if ($error->getCode() !== '42S02') throw $error;
        return null; // Older installations can render the audit before the migration.
    }
    if (!$policy) return null;
    return $policy[0] + ['eligible_courses' => array_values(array_filter(array_column($policy, 'eligible_course')))];
}

// Evaluate a catalog course against recognized, reconciled transcript and enrollment evidence.
function evaluateAuditCourse(array $rule, array $best, array $active, array $future, array $needsReview): array
{
    $code = $rule['code'];
    $minimum = auditGradeRank($rule['minimum_grade']);
    $passing = $best[$code] ?? null;
    $review = $needsReview[$code] ?? null;
    $course = $passing['course'] ?? null;
    $match = static fn (string $status, string $detail, ?array $evidence = null) => [
        'status' => $status, 'detail' => $detail, 'course' => $evidence, 'minimum_grade' => $rule['minimum_grade'],
    ];
    if ($rule['title'] === null || $minimum === null) {
        return $match('Rules unavailable', 'The catalog course or minimum grade is missing or unsupported.', $course);
    }
    if ($passing && $passing['rank'] > 0 && $passing['rank'] >= $minimum) {
        return $match('Completed', "Transcript grade {$course['grade']} meets the {$rule['minimum_grade']} minimum.", $course);
    }
    if ($review) return $match('Review needed', $review['reason'], $review['course']);
    if (isset($active[$code])) return $match('In progress', 'Current enrollment. A passing transcript grade is still required.', $active[$code]);
    if (isset($future[$code])) return $match('Not completed', 'Registered for a future term.', $future[$code]);
    if ($course) {
        $detail = $passing['rank'] === 0 ? 'The recorded grade is failing.'
            : "Transcript grade {$course['grade']} is below the {$rule['minimum_grade']} minimum.";
        return $match('Not completed', $detail, $course);
    }
    return $match('Not completed', 'No qualifying course record is on file.');
}

// Pick the strongest outcome among alternative catalog courses.
function auditBestCourseOption(array $options): ?array
{
    $priority = ['Not completed' => 1, 'In progress' => 2, 'Rules unavailable' => 3, 'Review needed' => 4, 'Completed' => 5];
    $best = null;
    foreach ($options as $option) {
        if ($best === null || $priority[$option['status']] > $priority[$best['status']]
            || ($option['status'] === $best['status'] && $best['course'] === null && $option['course'] !== null)) {
            $best = $option;
        }
    }
    return $best;
}

function auditRequirementBlockStatus(array $requirements): string
{
    if (!$requirements) return 'Rules unavailable';

    $statuses = array_column($requirements, 'status');
    foreach (['Rules unavailable', 'Review needed', 'In progress', 'Not completed'] as $status) {
        if (in_array($status, $statuses, true)) return $status;
    }
    return 'Completed';
}

function auditCreditRequirements(
    ?array $policy, array $programs, array $genEd, bool $undergraduate, array $earned, array $best, array $active
): array {
    $totalTarget = isset($policy['total']) ? (int) $policy['total'] : null;
    $liberalTarget = isset($policy['liberal_arts']) ? (int) $policy['liberal_arts'] : null;
    $eligible = array_fill_keys($policy['eligible_courses'] ?? [], true);
    $applied = $liberalTarget !== null && $eligible
        ? min($liberalTarget, array_sum(array_intersect_key($earned, $eligible)))
        : null;
    $courses = [];
    if ($applied !== null) {
        foreach (array_intersect_key($earned, $eligible) as $code => $credits) {
            $courses[] = ['status' => 'Completed'] + $best[$code]['course'];
        }
        foreach (array_intersect_key($active, $eligible) as $course) {
            $courses[] = ['status' => 'In progress'] + $course;
        }
    }
    $liberal = [
        'credits_required' => $liberalTarget, 'credits_applied' => $applied,
        'credits_still_needed' => $applied === null ? null : $liberalTarget - $applied,
        'status' => match (true) {
            $applied === null => 'Rules unavailable',
            $applied >= $liberalTarget => 'Completed',
            (bool) $courses => 'In progress',
            default => 'Not completed',
        },
        'draft' => (bool) ($policy['draft'] ?? false),
        'courses' => $courses,
    ];

    $checklist = [];
    $primaryName = null;
    foreach ($programs as $program) {
        if ($program['kind'] === 'Major' && $program['is_primary']) $primaryName = $program['name'];
        $checklist[] = ['name' => $program['kind'] . ' · ' . $program['name'],
            'status' => auditRequirementBlockStatus($program['requirements'])];
    }
    if ($undergraduate) $checklist[] = ['name' => 'General Education', 'status' => auditRequirementBlockStatus($genEd)];
    if ($undergraduate || $liberalTarget !== null) $checklist[] = ['name' => 'Liberal Arts', 'status' => $liberal['status']];

    $earnedTotal = array_sum($earned);
    return [
        'degree_requirements' => [
            'program' => $policy['name'] ?? $primaryName,
            'credits_required' => $totalTarget,
            'credits_applied' => $earnedTotal,
            'draft' => (bool) ($policy['draft'] ?? false),
            'status' => match (true) {
                $totalTarget === null => 'Rules unavailable',
                $earnedTotal >= $totalTarget => 'Credit target met',
                default => 'In progress',
            },
            'checklist' => $checklist,
        ],
        'liberal_arts' => $liberal,
    ];
}

function calculateDegreeAudit(array $profile, array $programs, array $requirements, array $history, array $enrollments, array $genEd = [], ?array $creditPolicy = null, array $supplemental = [], array $electivePolicy = []): array
{
    $profile['name'] = trim(implode(' ', array_filter([$profile['first_Name'], $profile['middle_Name'], $profile['last_Name']], static fn ($v) => $v !== null && $v !== '')));
    unset($profile['first_Name'], $profile['middle_Name'], $profile['last_Name']);
    $profile['enrollment_status'] = in_array($profile['enrollment_status'], ['Full-time', 'Part-time'], true) ? $profile['enrollment_status'] : 'Unknown / not on file';
    $best = $attempts = $earned = $historyByCrn = $reviewCourses = [];
    $review = [];
    foreach ($history as &$attempt) {
        $code = $attempt['code'];
        $historyByCrn[$attempt['crn']] = $attempt;
        $rank = auditGradeRank($attempt['grade']);
        $consistent = $code === $attempt['section_course'] && $attempt['semester_id'] === $attempt['section_semester']
            && (($attempt['enrollment_course'] ?? null) === null || $attempt['enrollment_course'] === $code)
            && ($attempt['enrollment_grade'] === null || $attempt['enrollment_grade'] === $attempt['grade']);
        $reason = match (true) {
            $attempt['credits'] === null || $attempt['term_start'] === null || $attempt['section_course'] === null
                => 'The transcript references a missing course, section, or term.',
            !$consistent => 'Transcript and section/enrollment records disagree.',
            $attempt['term_start'] > $profile['as_of'] => 'A transcript grade is recorded for a future term.',
            $rank === null => 'The transcript grade is unsupported.',
            default => null,
        };
        $attempt['result'] = $reason !== null ? 'Review needed' : ($rank > 0 ? 'Passing grade recorded' : 'Not passing');
        $attempts[$code][] = $attempt;
        if ($reason !== null) {
            foreach (array_filter([$code, $attempt['section_course'], $attempt['enrollment_course'] ?? null]) as $affectedCode) {
                $reviewCourses[$affectedCode] ??= ['reason' => $reason, 'course' => $attempt];
            }
            $review[] = ['code' => $code, 'crn' => $attempt['crn'], 'semester' => $attempt['semester'],
                'reason' => $reason . ' Excluded from calculated credits.'];
            continue;
        }
        if (!isset($best[$code]) || $rank > $best[$code]['rank']) $best[$code] = ['rank' => $rank, 'course' => $attempt];
        if ($rank > 0) $earned[$code] = (int) $attempt['credits'];
    }
    unset($attempt);

    $inProgress = $future = $pending = $activeByCode = $futureByCode = [];
    foreach ($enrollments as $enrollment) {
        if (isset($historyByCrn[$enrollment['crn']])) continue; // Transcript is authoritative for this attempt.
        $code = $enrollment['code'];
        $reason = match (true) {
            $enrollment['credits'] === null || $enrollment['start_Date'] === null || $enrollment['end_Date'] === null
                => 'The enrollment references a missing course, section, or term.',
            $code !== $enrollment['enrollment_course'] => 'Section and enrollment course identifiers disagree.',
            $enrollment['grade'] !== null => 'Grade posted without transcript history.',
            $enrollment['end_Date'] < $profile['as_of'] => 'Past enrollment is missing a final transcript grade.',
            default => null,
        };
        if ($reason !== null) {
            $enrollment['status'] = $reason;
            $pending[] = $enrollment;
            foreach ([$code, $enrollment['enrollment_course']] as $affectedCode) {
                $reviewCourses[$affectedCode] ??= ['reason' => $reason, 'course' => $enrollment];
            }
        } elseif ($enrollment['start_Date'] > $profile['as_of']) {
            $future[] = $enrollment;
            $futureByCode[$code] ??= $enrollment;
        } else {
            $inProgress[] = $enrollment;
            $activeByCode[$code] ??= $enrollment;
        }
    }
    $evaluate = static fn (array $rule) => evaluateAuditCourse($rule, $best, $activeByCode, $futureByCode, $reviewCourses);
    $allRequired = $outstanding = [];
    foreach ($programs as &$program) {
        $program['requirements'] = [];
        $program['met'] = $program['listed_credits'] = $program['met_credits'] = 0;
        if (!(bool) $program['declared']) $review[] = ['code' => $program['id'], 'crn' => null, 'semester' => null, 'reason' => 'Primary major is missing its Student_Major declaration link.'];
        foreach ($requirements as $requirement) {
            if ($requirement['kind'] !== $program['kind'] || $requirement['program_id'] !== $program['id']) continue;
            $code = $requirement['code'];
            $allRequired[$code] = true;
            $requirement = $evaluate($requirement) + $requirement;
            $met = $requirement['status'] === 'Completed';
            $requirement['best_grade'] = $best[$code]['course']['grade'] ?? null;
            $requirement['attempts'] = array_map(static fn ($a) => ['grade' => $a['grade'], 'semester' => $a['semester'], 'crn' => $a['crn']], $attempts[$code] ?? []);
            $requirement['met'] = $met;
            // A specific required course shows one of exactly three statuses to the student:
            // Completed, In progress, or Not completed. The richer internal states (Rules
            // unavailable, Review needed) stay in place for the newer checklist-level statuses
            // (General Education, Liberal Arts, etc.) elsewhere in this file -- this collapse
            // applies only to this per-program, per-required-course array.
            $requirement['status'] = $met ? 'Completed' : ($requirement['status'] === 'In progress' ? 'In progress' : 'Not completed');
            $program['requirements'][] = $requirement;
            $program['listed_credits'] += (int) $requirement['credits'];
            if ($met) {
                $program['met']++;
                $program['met_credits'] += (int) $requirement['credits'];
            } else $outstanding[] = ['program' => $program['name'], 'kind' => $program['kind'], 'code' => $code,
                'minimum_grade' => $requirement['minimum_grade'], 'status' => $requirement['status'], 'detail' => $requirement['detail']];
        }
        $program['total'] = count($program['requirements']);
        $program['percent'] = $program['total'] ? round(100 * $program['met'] / $program['total'], 1) : null;
    }
    unset($program);
    $genEdRequirements = [];
    if ($profile['type'] === 'Undergraduate') {
        foreach ($genEd as $option) {
            $id = $option['id'];
            $genEdRequirements[$id] ??= [
                'id' => $id, 'name' => $option['name'], 'status' => 'Rules unavailable', 'course' => null,
                'detail' => 'No course mappings are stored for this requirement.', 'minimum_grade' => null, 'options' => [],
            ];
            if ($option['code'] !== null) $genEdRequirements[$id]['options'][] = $option + $evaluate($option);
        }
        foreach ($genEdRequirements as &$requirement) {
            $bestOption = auditBestCourseOption($requirement['options']);
            if ($bestOption) {
                foreach (['status', 'detail', 'course', 'minimum_grade'] as $field) $requirement[$field] = $bestOption[$field];
            }
        }
        unset($requirement);
    }
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
    $creditRequirements = auditCreditRequirements(
        $creditPolicy, $programs, $genEdRequirements, $profile['type'] === 'Undergraduate', $earned, $best, $activeByCode
    );
    $requiredMatches = array_merge($genEdRequirements, ...array_column($programs, 'requirements'));
    $claims = auditLiberalArtsClaims(auditCourseClaims($requiredMatches), $creditRequirements['liberal_arts']);
    $extra = auditSupplementalRequirements($supplemental, $profile['type'] === 'Undergraduate', $evaluate, $claims);
    $electives = auditElectiveRequirements($electivePolicy, $evaluate, $claims, $extra, $supplemental);
    foreach (['diversity' => 'Diversity', 'proficiency' => 'Proficiency',
        'major_electives' => 'Major Electives', 'free_electives' => 'Free Electives'] as $key => $name) {
        $result = $extra[$key] ?? $electives[$key] ?? null;
        if ($result !== null) $creditRequirements['degree_requirements']['checklist'][] = [
            'name' => $name, 'status' => $result['status'],
        ];
    }
    return $creditRequirements + $extra + $electives + [
        'profile' => $profile, 'programs' => $programs, 'gen_ed' => array_values($genEdRequirements),
        'credits' => ['earned_provisional' => $earnedTotal, 'passing_courses' => count($earned), 'transcript_attempts' => count($history),
            'repeated_courses' => $repeats, 'in_progress' => $distinctCredits($inProgress), 'future_registered' => $distinctCredits($future),
            'degree_target' => null, 'degree_percent' => null],
        'in_progress' => $inProgress, 'future_registered' => $future, 'pending_records' => $pending,
        'history' => $history, 'other_coursework' => array_values(array_filter($history, static fn ($a) => !isset($allRequired[$a['code']]))),
        'outstanding' => $outstanding, 'review' => $review,
    ];
}
