<?php
declare(strict_types=1);

require __DIR__ . '/../api/academic-standing.php';

$boundaries = [31 => 'Freshman', 32 => 'Sophomore', 50 => 'Sophomore',
    56 => 'Sophomore', 57 => 'Junior', 59 => 'Junior', 87 => 'Junior', 88 => 'Senior'];
foreach ($boundaries as $credits => $year) {
    if (undergraduateClassYear($credits) !== $year) throw new RuntimeException("Wrong year at $credits credits.");
}

$pass = ['code' => 'CS110', 'grade' => 'B', 'credits' => 3, 'term_start' => '2025-08-27',
    'section_course' => 'CS110', 'semester_id' => 'FA25', 'section_semester' => 'FA25',
    'enrollment_course' => 'CS110', 'enrollment_grade' => 'B'];
[$attempts, $earned] = evaluateTranscriptCredits([
    $pass, array_replace($pass, ['grade' => 'A', 'enrollment_grade' => 'A']),
    array_replace($pass, ['code' => 'MATH110', 'section_course' => 'MATH110', 'enrollment_course' => 'MATH110', 'grade' => 'F', 'enrollment_grade' => 'F']),
    array_replace($pass, ['code' => 'CS220', 'section_course' => 'CS220', 'enrollment_course' => 'CS220', 'term_start' => '2027-01-25']),
    array_replace($pass, ['code' => 'CS115', 'section_course' => 'CS115']),
    array_replace($pass, ['grade' => null, 'enrollment_grade' => null]),
    array_replace($pass, ['grade' => 'IP', 'enrollment_grade' => 'IP']),
    array_replace($pass, ['grade' => 'F', 'enrollment_grade' => 'F']),
], '2026-12-17');
if (count($attempts) !== 8 || $earned !== ['CS110' => 3]) {
    throw new RuntimeException('Earned-credit counting did not reconcile repeats, failure, future, and conflicting attempts.');
}
echo "PASS: standing boundaries 31/32, 56/57, 87/88; 50/59 controls; repeat, later failure, unposted/in-progress, unsupported, future and conflicting transcript records.\n";
