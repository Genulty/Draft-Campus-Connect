<?php
// Shared transcript evidence for academic standing and the Degree Audit.
declare(strict_types=1);

// The letter-grade order already used by registration and stored requirement checks.
// Other grading schemes need an explicit policy and remain unclassified here.
const AUDIT_GRADES = ['F', 'D', 'D+', 'C-', 'C', 'C+', 'B-', 'B', 'B+', 'A-', 'A'];

function auditGradeRank(?string $grade): ?int
{
    $rank = array_search($grade, AUDIT_GRADES, true);
    return $rank === false ? null : $rank;
}

function studentTranscriptAttempts(int $studentId): array
{
    return rows("SELECT h.CRN AS crn, h.course_ID AS code, c.course_Name AS title, c.course_Credits AS credits,
        h.grade, h.semester_ID AS semester_id, COALESCE(sem.semester_Name, h.semester_ID) AS semester, sem.start_Date AS term_start,
        cs.course_ID AS section_course, cs.semester_ID AS section_semester,
        e.course_ID AS enrollment_course, e.grade AS enrollment_grade
      FROM Student_History h LEFT JOIN Course c ON c.course_ID = h.course_ID
      LEFT JOIN Semester sem ON sem.semester_ID = h.semester_ID LEFT JOIN Course_Section cs ON cs.CRN = h.CRN
      LEFT JOIN Enrollment e ON e.student_ID = h.student_ID AND e.CRN = h.CRN
      WHERE h.student_ID = ? ORDER BY sem.start_Date DESC, h.course_ID, h.CRN", [$studentId]);
}

function transcriptCreditIssue(array $attempt, string $asOf, ?int $rank): ?string
{
    $code = $attempt['code'];
    $consistent = $code === $attempt['section_course'] && $attempt['semester_id'] === $attempt['section_semester']
        && (($attempt['enrollment_course'] ?? null) === null || $attempt['enrollment_course'] === $code)
        && ($attempt['enrollment_grade'] === null || $attempt['enrollment_grade'] === $attempt['grade']);
    return match (true) {
        $attempt['credits'] === null || $attempt['term_start'] === null || $attempt['section_course'] === null
            => 'The transcript references a missing course, section, or term.',
        !$consistent => 'Transcript and section/enrollment records disagree.',
        $attempt['term_start'] > $asOf => 'A transcript grade is recorded for a future term.',
        $rank === null => 'The transcript grade is unsupported.',
        default => null,
    };
}

function undergraduateClassYear(int $earnedCredits): string
{
    return match (true) {
        $earnedCredits >= 88 => 'Senior',
        $earnedCredits >= 57 => 'Junior',
        $earnedCredits >= 32 => 'Sophomore',
        default => 'Freshman',
    };
}

// Keep every attempt for audit evidence, with one earned-credit entry per catalog course.
function evaluateTranscriptCredits(array $history, string $asOf): array
{
    $attempts = $earned = [];
    foreach ($history as $attempt) {
        $rank = auditGradeRank($attempt['grade']);
        $issue = transcriptCreditIssue($attempt, $asOf, $rank);
        $attempts[] = [$attempt, $rank, $issue];
        if ($issue === null && $rank > 0) {
            $earned[$attempt['code']] = (int) $attempt['credits'];
        }
    }
    return [$attempts, $earned];
}

function studentEarnedCredits(int $studentId): int
{
    $asOf = one('SELECT CURDATE() AS as_of')['as_of'];
    [, $earned] = evaluateTranscriptCredits(studentTranscriptAttempts($studentId), $asOf);
    return array_sum($earned);
}
