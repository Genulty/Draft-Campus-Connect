<?php
declare(strict_types=1);

require_once __DIR__ . '/degree-audit.php';

// These labels describe a possible use. The Add validator still decides eligibility.
function registrationRequirementMatches(array $audit, string $semesterId): array
{
    $enrolled = [];
    foreach (array_merge($audit['in_progress'], $audit['future_registered']) as $course) {
        if ($course['semester_id'] === $semesterId) $enrolled[$course['code']] = true;
    }
    $review = array_fill_keys(array_filter(array_column($audit['review'], 'code')), true);
    foreach ($audit['pending_records'] as $course) $review[$course['code']] = true;
    $matches = [];
    foreach ($audit['programs'] as $program) {
        foreach ($program['requirements'] as $requirement) {
            $code = $requirement['code'];
            if ($requirement['met'] || isset($enrolled[$code]) || isset($review[$code]) ||
                empty($requirement['title']) || (auditGradeRank($requirement['minimum_grade']) ?? 0) <= 0) continue;
            $matches[$code][] = "{$program['kind']} requirement · {$program['name']} · {$requirement['title']}";
        }
    }
    foreach ($audit['gen_ed'] as $requirement) {
        if (in_array($requirement['status'], ['Completed', 'Rules unavailable', 'Review needed'], true)) continue;
        if (array_intersect_key($enrolled, array_fill_keys(array_column($requirement['options'], 'code'), true))) continue;
        foreach ($requirement['options'] as $option) {
            $code = $option['code'];
            if (isset($review[$code]) || empty($option['title']) ||
                (auditGradeRank($option['minimum_grade']) ?? 0) <= 0) continue;
            // The shared Gen Ed catalog currently contains demonstration draft rules.
            $matches[$code][] = "Draft Gen Ed match · {$requirement['name']}";
        }
    }
    return array_map(static fn (array $labels) => implode('; ', array_unique($labels)), $matches);
}
