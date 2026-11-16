<?php
declare(strict_types=1);

function loadAuditElectivePolicy(string $programId): array
{
    try {
        $rules = rows('SELECT r.elective_Type AS type, r.target_Unit AS unit, r.target_Amount AS target,
            r.allow_Course_Sharing AS share, r.is_Draft AS draft,
            m.course_ID AS code, c.course_Name AS title, c.course_Credits AS credits, m.min_Grade_Req AS minimum_grade
          FROM Degree_Audit_Elective_Rule r
          LEFT JOIN Degree_Audit_Elective_Course m USING (program_ID, elective_Type)
          LEFT JOIN Course c ON c.course_ID = m.course_ID
          WHERE r.program_ID = ? ORDER BY r.elective_Type, m.course_ID', [$programId]);
    } catch (PDOException $error) {
        if ($error->getCode() !== '42S02') throw $error;
        return [];
    }

    $policy = [];
    foreach ($rules as $row) {
        $type = $row['type'];
        $policy[$type] ??= array_intersect_key($row, array_flip(['unit', 'target', 'share', 'draft'])) + ['options' => []];
        if ($row['code'] !== null) $policy[$type]['options'][] = array_intersect_key($row, array_flip(['code', 'title', 'credits', 'minimum_grade']));
    }
    return $policy;
}

function auditLiberalArtsClaims(array $claims, array $liberal): array
{
    // Liberal Arts can reuse a required course. Prefer those overlaps, then claim only
    // enough whole courses to reach the target, including planned courses if needed.
    $remaining = $liberal['credits_required'];
    $appliedCourses = [];
    foreach (['Completed', 'In progress'] as $status) {
        $courses = array_filter($liberal['courses'], static fn ($course) => $course['status'] === $status);
        usort($courses, static fn ($a, $b) => isset($claims[$b['code']]) <=> isset($claims[$a['code']])
            ?: strcmp($a['code'], $b['code']));
        foreach ($courses as $course) {
            if ($remaining <= 0) break;
            if (isset($appliedCourses[$course['code']])) continue;
            $appliedCourses[$course['code']] = true;
            $claims[$course['code']] = true;
            $remaining -= (int) $course['credits'];
        }
    }

    return $claims;
}

function auditElectiveRequirements(array $policy, callable $evaluate, array $claims, array $supplemental, array $supplementalPolicy, array $requiredMajorCodes): array
{
    $extraMatches = [$supplemental['diversity'], ...($supplemental['proficiency']['requirements'] ?? [])];
    foreach ($extraMatches as $match) {
        if ($match && auditPositiveMatch($match['status']) && isset($match['course']['code'])) {
            $code = $match['course']['code'];
            $claims[$code] = ($claims[$code] ?? true) && !empty($supplementalPolicy[$match['id']]['share']);
        }
    }

    $freeOptions = array_fill_keys(array_column($policy['FREE']['options'] ?? [], 'code'), true);
    $majorRule = $policy['MAJOR'] ?? [];
    $majorRule['options'] = array_values(array_filter($majorRule['options'] ?? [],
        static fn ($option) => !in_array($option['code'], $requiredMajorCodes, true)));
    $major = auditElectiveBlock($majorRule, $evaluate, $claims, $freeOptions);
    if (!empty($majorRule['target']) && !$majorRule['options']) {
        $major['detail'] = 'No eligible Major Elective options are configured outside the primary major requirements.';
    }
    return [
        'major_electives' => $major,
        'free_electives' => auditElectiveBlock($policy['FREE'] ?? [], $evaluate, $claims),
    ];
}

function auditElectiveBlock(array $rule, callable $evaluate, array &$claims, array $reserveForFree = []): array
{
    $result = ['status' => 'Rules unavailable', 'configured' => false, 'draft' => !empty($rule['draft']),
        'target' => null, 'unit' => null, 'applied' => 0, 'in_progress' => 0, 'options' => [], 'courses' => [],
        'eligible_not_applied' => [], 'excluded' => [], 'choose_one' => false, 'detail' => null];
    if (empty($rule['target']) || empty($rule['options'])) return $result;

    $target = (int) $rule['target'];
    $share = !empty($rule['share']);
    $result['target'] = $target;
    $result['unit'] = $rule['unit'];
    $result['configured'] = true;
    foreach ($rule['options'] as $option) {
        $match = $evaluate($option);
        if (auditPositiveMatch($match['status']) && isset($claims[$option['code']]) && !($claims[$option['code']] && $share)) {
            $match['status'] = 'Not completed';
            $match['detail'] = 'Already applied to or reserved by another requirement.';
            $match['sharing_restricted'] = true;
        }
        $result['options'][] = $option + $match;
    }

    $candidates = $result['options'];
    usort($candidates, static fn ($a, $b) => isset($reserveForFree[$a['code']]) <=> isset($reserveForFree[$b['code']])
        ?: strcmp($a['code'], $b['code']));
    $remaining = $target;
    $allocated = [];
    foreach (['Completed', 'In progress'] as $status) {
        foreach ($candidates as $option) {
            if ($remaining <= 0) break;
            if ($option['status'] !== $status) continue;
            $course = $option['course'];
            $credits = (int) $course['credits'];
            $units = $rule['unit'] === 'COURSES' ? 1 : $credits;
            $applied = min($remaining, $units);
            $remaining -= $applied;
            $claims[$option['code']] = $share;
            $allocated[$option['code']] = true;
            $result['courses'][] = ['status' => $status] + $course;
            if ($status === 'Completed') $result['applied'] += $applied;
            else $result['in_progress'] += $units;
        }
    }
    $statuses = array_column($result['options'], 'status');
    $result['status'] = match (true) {
        $result['applied'] >= $target => 'Completed',
        $result['in_progress'] > 0 => 'In progress',
        in_array('Review needed', $statuses, true) => 'Review needed',
        array_unique($statuses) === ['Rules unavailable'] => 'Rules unavailable',
        array_unique($statuses) === ['Not started'] => 'Not started',
        default => 'Not completed',
    };
    foreach ($result['options'] as $option) {
        if (isset($allocated[$option['code']])) continue;
        if (auditPositiveMatch($option['status'])) {
            $result['eligible_not_applied'][] = ['status' => $option['status']] + $option['course'];
        } elseif ($option['course'] !== null && ($option['status'] !== 'Not completed' || !empty($option['sharing_restricted'])
            || $option['course']['grade'] !== null)) {
            $result['excluded'][] = $option;
        }
    }
    $result['options'] = array_values(array_filter($result['options'], static fn ($option) =>
        !isset($allocated[$option['code']]) && empty($option['sharing_restricted'])
        && !in_array($option['status'], ['Completed', 'Rules unavailable'], true)));
    $shortfall = $target - $result['applied'];
    $result['choose_one'] = $shortfall > 0 && $result['options'] && !array_filter($result['options'],
        static fn ($option) => ($rule['unit'] === 'COURSES' ? 1 : (int) ($option['credits'] ?? 0)) < $shortfall);
    return $result;
}
