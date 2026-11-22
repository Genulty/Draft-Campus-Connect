<?php
// Read-only, per-student Spring coverage against the configured local database.
declare(strict_types=1);

require __DIR__ . '/../api/db.php';
require __DIR__ . '/../api/actions.php';

if (config()['host'] !== 'localhost' || !config()['socket'] ||
    !one('SELECT 1 FROM DUAL WHERE @@socket = ? AND @@skip_networking = 1', [config()['socket']])) {
    throw new RuntimeException('Use a private, socket-only local database.');
}

function registrationCoverageClash(array $left, array $right): bool
{
    foreach ($left as $a) {
        foreach ($right as $b) {
            if ($a['day_ID'] === $b['day_ID'] && $a['start_Time'] < $b['end_Time'] &&
                $b['start_Time'] < $a['end_Time']) return true;
        }
    }
    return false;
}

function registrationCoverageBest(array $choices, int $credits, int $cap, array $conflicts): array
{
    $best = ['credits' => $credits, 'courses' => []];
    $visit = static function (array $remaining, int $total, array $schedule) use (&$visit, &$best, $cap, $conflicts): void {
        if ($total > $best['credits']) $best = ['credits' => $total, 'courses' => $schedule];
        if ($best['credits'] === $cap) return;
        while ($remaining && $total + array_sum(array_column($remaining, 'credits')) > $best['credits']) {
            $course = array_shift($remaining);
            $next = $total + $course['credits'];
            if ($next > $cap) continue;
            $compatible = array_filter($remaining, static fn ($other) =>
                $course['code'] !== $other['code'] && empty($conflicts[$course['crn']][$other['crn']]));
            $visit($compatible, $next, [...$schedule, $course]);
            if ($best['credits'] === $cap) return;
        }
    };
    $visit($choices, $credits, []);
    return $best;
}

$term = 'SP27';
db()->exec('START TRANSACTION READ ONLY');
try {
    $sections = [];
    foreach (rows("SELECT cs.CRN AS crn, c.course_ID AS code, c.course_Credits AS credits,
            d.day_ID, p.start_Time, p.end_Time
          FROM Course_Section cs JOIN Course c USING (course_ID)
          LEFT JOIN " . MEETINGS . " m ON m.CRN = cs.CRN
          LEFT JOIN Time_Slot_Day d ON d.time_Slot_ID = m.time_Slot_ID
          LEFT JOIN Time_Slot_Period tp ON tp.time_Slot_ID = m.time_Slot_ID
          LEFT JOIN Period p ON p.period_ID = tp.period_ID
          WHERE cs.semester_ID = ? AND c.course_Type = 'Undergraduate'
          ORDER BY c.course_Credits DESC, cs.CRN, d.day_ID, p.start_Time", [$term]) as $row) {
        $sections[$row['crn']] ??= ['crn' => $row['crn'], 'code' => $row['code'], 'credits' => (int) $row['credits'],
            'meetings' => [], 'missing_meeting' => false];
        if ($row['day_ID'] === null || $row['start_Time'] === null) {
            $sections[$row['crn']]['missing_meeting'] = true;
        } else {
            $sections[$row['crn']]['meetings'][] = $row;
        }
    }
    $conflicts = [];
    foreach ($sections as $a) {
        foreach ($sections as $b) {
            if ($a['crn'] < $b['crn'] && registrationCoverageClash($a['meetings'], $b['meetings'])) {
                $conflicts[$a['crn']][$b['crn']] = $conflicts[$b['crn']][$a['crn']] = true;
            }
        }
    }
    $label = static fn ($course) => "{$course['code']}/{$course['crn']}";
    $roster = rows("SELECT s.student_ID, s.major_ID, u.undergraduate_Student_Type AS load_type
        FROM Student s JOIN Undergraduate_Student u USING (student_ID)
        WHERE s.student_Type = 'Undergraduate' ORDER BY s.student_ID");
    $registered = [];
    foreach (rows("SELECT e.student_ID, e.CRN FROM Enrollment e JOIN Course_Section cs USING (CRN)
        WHERE cs.semester_ID = ? ORDER BY e.CRN", [$term]) as $row) {
        $registered[$row['student_ID']][] = $row['CRN'];
    }

    echo "Student\tMajor\tCap\tRegistered\tHighest valid credits\tCurrent CRNs\tAdditional courses (code/CRN)\tAddable section count\tBlockers\n";
    foreach ($roster as $student) {
        $id = $student['student_ID'];
        $cap = MAX_CREDITS[$student['load_type']];
        $current = $registered[$id] ?? [];
        $credits = (int) one('SELECT COALESCE(SUM(c.course_Credits), 0) AS n FROM Enrollment e
          JOIN Course_Section cs USING (CRN) JOIN Course c ON c.course_ID = cs.course_ID
          WHERE e.student_ID = ? AND cs.semester_ID = ?', [$id, $term])['n'];
        $invalid = [];
        $candidates = [];
        $reasonCounts = [];
        foreach ($sections as $section) {
            $crn = $section['crn'];
            $internalClash = false;
            foreach ($section['meetings'] as $index => $meeting) {
                $internalClash = $internalClash || registrationCoverageClash([$meeting], array_slice($section['meetings'], $index + 1));
            }
            if (in_array($crn, $current, true)) {
                if ($internalClash || $section['missing_meeting']) $invalid[] = "Invalid meeting data at $crn";
                foreach ($current as $other) {
                    if ($crn < $other && isset($conflicts[$crn][$other])) $invalid[] = "Current meeting conflict $crn/$other";
                }
                continue;
            }
            $reason = whyCannotAdd((int) $id, (int) $crn);
            if ($reason === null && !$internalClash && !$section['missing_meeting']) {
                $candidates[] = $section;
            } else {
                $category = match (true) {
                    $reason === null => 'invalid meeting data',
                    str_contains($reason, ' hold.') => 'holds',
                    str_contains($reason, 'open to') => 'major eligibility',
                    str_contains($reason, 'you need to be at least') => 'standing',
                    str_starts_with($reason, 'Missing prerequisite') => 'prerequisites',
                    str_contains($reason, ' is full ') => 'full sections',
                    str_contains($reason, 'already passed') => 'already passed',
                    str_contains($reason, 'already registered'), str_contains($reason, 'already taking') => 'already registered or taking',
                    str_contains($reason, 'meets at the same time') => 'conflicts with current schedule',
                    str_contains($reason, 'the limit') => 'credit cap',
                    default => $reason,
                };
                $reasonCounts[$category] = ($reasonCounts[$category] ?? 0) + 1;
            }
        }
        if ($credits > $cap) $invalid[] = 'Existing credits exceed cap';
        if (array_diff($current, array_keys($sections))) $invalid[] = 'Existing section has no undergraduate offering data';
        $best = registrationCoverageBest($candidates, $credits, $cap, $conflicts);
        $blockers = $invalid;
        if (!$invalid && $best['credits'] < 12) {
            if ($cap < 12) $blockers[] = "Part-time cap $cap";
            if ($candidates) {
                $unique = array_column($candidates, 'credits', 'code');
                if ($credits + array_sum($unique) >= 12 && $cap >= 12) $blockers[] = 'No compatible combination fits the cap';
                elseif ($cap >= 12) $blockers[] = 'Too few remaining eligible courses';
            }
            arsort($reasonCounts);
            foreach ($reasonCounts as $reason => $count) $blockers[] = "$reason ($count)";
        }
        echo implode("\t", [$id, $student['major_ID'], $cap, $credits, $invalid ? 'N/A' : $best['credits'],
            implode(',', $current), $invalid ? '' : implode(',', array_map($label, $best['courses'])),
            count($candidates), implode('; ', $blockers)]), PHP_EOL;
    }
} finally {
    db()->exec('ROLLBACK');
}
