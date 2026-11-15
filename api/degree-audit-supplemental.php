<?php
declare(strict_types=1);

const SUPPLEMENTAL_AUDIT_NAMES = [
    'DIVERSITY' => 'Diversity',
    'MATH' => 'Mathematics Proficiency',
    'WRITING' => 'Writing Proficiency',
];

function loadAuditSupplementalPolicy(string $programId, int $studentId, string $asOf): array
{
    try {
        $rules = rows('SELECT requirement_ID AS id, allow_Course_Sharing AS share, is_Draft AS draft
            FROM Degree_Audit_Supplemental_Rule WHERE program_ID = ?', [$programId]);
        if (!$rules) return [];
        $courses = rows('SELECT m.requirement_ID AS id, m.course_ID AS code, c.course_Name AS title, m.min_Grade_Req AS minimum_grade
            FROM Degree_Audit_Supplemental_Course m JOIN Course c ON c.course_ID = m.course_ID
            WHERE m.program_ID = ? ORDER BY m.requirement_ID, m.course_ID', [$programId]);
        // Use the most recent recorded result as of the audit date for each configured assessment.
        $placements = rows('SELECT p.requirement_ID AS id, r.score >= p.minimum_Score AS met
            FROM Degree_Audit_Placement_Criterion p LEFT JOIN Student_Placement_Result r
              ON r.student_ID = ? AND r.assessment_Code = p.assessment_Code
              AND r.assessed_On = (
                SELECT MAX(last_result.assessed_On) FROM Student_Placement_Result last_result
                WHERE last_result.student_ID = r.student_ID AND last_result.assessment_Code = r.assessment_Code
                  AND last_result.assessed_On <= ?
              )
            WHERE p.program_ID = ?', [$studentId, $asOf, $programId]);
    } catch (PDOException $error) {
        if ($error->getCode() !== '42S02') throw $error;
        return [];
    }
    $policy = array_column($rules, null, 'id');
    foreach ($courses as $course) $policy[$course['id']]['options'][] = $course;
    foreach ($placements as $placement) {
        $id = $placement['id'];
        $policy[$id]['placement_configured'] = true;
        $policy[$id]['placement_met'] = !empty($policy[$id]['placement_met']) || (bool) $placement['met'];
    }
    return $policy;
}

function auditPositiveMatch(string $status): bool
{
    return $status === 'Completed' || $status === 'In progress';
}

// Three requirements can compete for the same courses. Prefer more completions,
// then more in-progress matches, without taking a course away from an existing block.
function allocateAuditSupplementalCourses(array $choices, array $claims): array
{
    $best = [];
    $bestScore = -1;
    $ids = array_keys($choices);
    $search = static function (int $index, array $used, array $picked, int $score) use (&$search, &$best, &$bestScore, $choices, $ids): void {
        if ($index === count($ids)) {
            if ($score > $bestScore) {
                $best = $picked;
                $bestScore = $score;
            }
            return;
        }
        $id = $ids[$index];
        foreach ($choices[$id] as $option) {
            $code = $option['code'];
            if (isset($used[$code]) && !($used[$code] && $option['share'])) continue;
            $search($index + 1, $used + [$code => $option['share']], $picked + [$id => $option],
                $score + ($option['status'] === 'Completed' ? 4 : 1));
        }
        $search($index + 1, $used, $picked, $score);
    };
    $search(0, $claims, [], 0);
    return $best;
}

function auditCourseClaims(array $matches): array
{
    $claims = [];
    foreach ($matches as $match) {
        if (auditPositiveMatch($match['status']) && isset($match['course']['code'])) $claims[$match['course']['code']] = true;
    }
    return $claims;
}

function auditSupplementalRequirements(array $policy, bool $undergraduate, callable $evaluate, array $claims): array
{
    $results = $choices = [];
    $showProficiency = $undergraduate || isset($policy['MATH']) || isset($policy['WRITING']);
    foreach (SUPPLEMENTAL_AUDIT_NAMES as $id => $name) {
        if ($id === 'DIVERSITY' ? !$undergraduate && !isset($policy[$id]) : !$showProficiency) continue;
        $rule = $policy[$id] ?? [];
        $draft = !empty($rule['draft']);
        $options = [];
        if (empty($rule['placement_met'])) {
            foreach ($rule['options'] ?? [] as $option) {
                $options[] = $option + $evaluate($option) + ['share' => !empty($rule['share'])];
            }
        }
        $results[$id] = ['id' => $id, 'name' => $name, 'draft' => $draft, 'options' => $options,
            'configured' => !empty($rule['options']) || !empty($rule['placement_configured'])];
        // With three requirements, at most two peers can block distinct courses.
        // Keep the first three viable options of each positive status to bound the search.
        foreach (['Completed', 'In progress'] as $status) {
            $eligible = array_filter($options, static fn ($option) =>
                $option['status'] === $status && (!isset($claims[$option['code']]) || $option['share']));
            $choices[$id] = [...($choices[$id] ?? []), ...array_slice($eligible, 0, 3)];
        }
    }

    $allocated = allocateAuditSupplementalCourses($choices, $claims);
    foreach ($allocated as $option) $claims[$option['code']] = $option['share'];
    foreach ($results as $id => &$result) {
        $rule = $policy[$id] ?? [];
        $selected = $allocated[$id] ?? null;
        foreach ($result['options'] as &$option) {
            if (auditPositiveMatch($option['status']) && $option['code'] !== ($selected['code'] ?? null)
                && isset($claims[$option['code']]) && !($claims[$option['code']] && $option['share'])) {
                $option['status'] = 'Not completed';
                $option['detail'] = 'Course sharing is restricted by the configured rules.';
                $option['sharing_restricted'] = true;
                $option['course'] = null;
            }
        }
        unset($option);
        $match = $selected ?? auditBestCourseOption($result['options']);
        if (!empty($rule['placement_met'])) {
            $result += ['status' => 'Completed', 'detail' => 'Met by placement', 'course' => null];
        } elseif ($match) {
            $result += array_intersect_key($match, ['status' => null, 'detail' => null, 'course' => null, 'minimum_grade' => null, 'sharing_restricted' => null]);
            if ($id !== 'DIVERSITY' && $result['status'] === 'Completed') $result['detail'] = 'Met by coursework';
        } elseif (!empty($rule['placement_configured'])) {
            $result += ['status' => 'Not completed', 'detail' => 'Still needed · No qualifying placement result on file.', 'course' => null];
        } else {
            $detail = $id === 'DIVERSITY' ? 'Diversity course mappings are unavailable.'
                : 'Placement criteria and coursework mappings are unavailable.';
            $result += ['status' => 'Rules unavailable', 'detail' => $detail, 'course' => null];
        }
    }
    unset($result);

    $diversity = $results['DIVERSITY'] ?? null;
    unset($results['DIVERSITY']);
    return [
        'diversity' => $diversity,
        'proficiency' => $results ? ['status' => auditRequirementBlockStatus($results),
            'draft' => in_array(true, array_column($results, 'draft'), true),
            'configured' => in_array(true, array_column($results, 'configured'), true), 'requirements' => array_values($results)] : null,
    ];
}
