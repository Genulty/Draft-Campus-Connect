<?php
// Actual shared Add validator with in-memory evidence; stops immediately after
// its standing gate. No SQL is executed and no student/section data is written.
declare(strict_types=1);
set_error_handler(static function ($severity, $message, $file, $line): never { throw new ErrorException($message,0,$severity,$file,$line); });
class RuleError extends Exception {}
class StandingGatePassed extends Exception {}
function fail(string $message): never { throw new RuleError($message); }
function db(): object { return new class { public function inTransaction(): bool { return true; } }; }
$GLOBALS['earned'] = 0;
$GLOBALS['level'] = 2;
$GLOBALS['graduate'] = false;
$GLOBALS['transcriptReads'] = 0;
function one(string $sql, array $params = []): ?array
{
    if ($sql === 'SELECT student_ID FROM Student WHERE student_ID = ? FOR UPDATE') return ['student_ID'=>1];
    if ($sql === 'SELECT CURDATE() AS as_of') return ['as_of'=>'2026-12-17'];
    if (str_starts_with($sql, 'SELECT cs.*')) return [
        'CRN'=>1, 'course_ID'=>'TEST'.$GLOBALS['level'].'00', 'course_Name'=>'Standing fixture',
        'course_Type'=>$GLOBALS['graduate'] ? 'Graduate' : 'Undergraduate',
        'semester_ID'=>'TEST', 'semester_Name'=>'Fixture', 'add_open'=>true,
    ];
    if (str_starts_with($sql, 'SELECT s.student_Type')) return [
        'student_Type'=>$GLOBALS['graduate'] ? 'Graduate' : 'Undergraduate',
        'student_Year'=>'Senior', // Deliberately stale; never an authorization input.
        'load_type'=>'Full-time',
    ];
    if (str_starts_with($sql, 'SELECT cs.CRN FROM Enrollment')) throw new StandingGatePassed();
    throw new RuntimeException('Unexpected standing probe query: '.$sql);
}
function rows(string $sql, array $params = []): array
{
    if (str_contains($sql, 'FROM Student_History h LEFT JOIN Course c')) {
        $GLOBALS['transcriptReads']++;
        return [[
            'code'=>'EARNED', 'grade'=>'B', 'credits'=>$GLOBALS['earned'], 'term_start'=>'2025-01-01',
            'section_course'=>'EARNED', 'semester_id'=>'SP25', 'section_semester'=>'SP25',
            'enrollment_course'=>'EARNED', 'enrollment_grade'=>'B',
        ]];
    }
    // No holds or program restrictions in this isolated standing-only probe.
    return [];
}
require __DIR__.'/../api/actions.php';
foreach ([[31,2,false],[32,2,true],[56,3,false],[57,3,true],[87,4,false],[88,4,true]] as [$earned,$level,$allowed]) {
    $GLOBALS['earned']=$earned;
    $GLOBALS['level']=$level;
    foreach ([false,true] as $locked) {
        try {
            checkAdd(1,1,$locked);
            throw new RuntimeException('Probe did not reach the expected gate.');
        } catch (StandingGatePassed) {
            if (!$allowed) throw new RuntimeException("Standing wrongly allowed $earned credits at level $level.");
        } catch (RuleError $error) {
            if ($allowed || !str_contains($error->getMessage(), undergraduateClassYear($earned))) throw $error;
        }
    }
}
// A display cache is never authoritative on a locked submission.
$GLOBALS['earned']=59; $GLOBALS['level']=4;
try { checkAdd(1,1,true,92); throw new RuntimeException('Locked Add trusted preview credits.'); }
catch (RuleError $error) { if (!str_contains($error->getMessage(), "you're a Junior")) throw $error; }
$GLOBALS['graduate']=true;
$before=$GLOBALS['transcriptReads'];
try { checkAdd(1,1,true); }
catch (StandingGatePassed) {}
if ($GLOBALS['transcriptReads']!==$before) throw new RuntimeException('Undergraduate standing gate applied to a graduate.');
echo "PASS: actual Add standing gate at 31/32, 56/57, 87/88 in preview and locked submission; stale stored Senior ignored; locked Add recomputes; graduate gate unchanged.\n";
