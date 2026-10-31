<?php
// Application control-flow probes only. No PDO, SQL execution, files, or database fixtures.
declare(strict_types=1);
class RuleError extends Exception {}
class StopRegistrationProbe extends Exception {}
function fail(string $message): never { throw new RuleError($message); }
final class RegistrationProbeConnection
{
    public bool $active = false;
    public bool $studentExists = true;
    public array $queries = [];
    public array $bound = [];
    public array $boundaries = [];
    public function inTransaction(): bool { return $this->active; }
}
$probe = new RegistrationProbeConnection();
function db(): RegistrationProbeConnection { global $probe; return $probe; }
function one(string $sql, array $params = []): ?array
{
    $probe = db();
    $probe->queries[] = preg_replace('/\s+/', ' ', trim($sql));
    $probe->bound[] = $params;
    if ($sql === 'SELECT student_ID FROM Student WHERE student_ID = ? FOR UPDATE') {
        if (!$probe->active) throw new RuntimeException('Lock attempted outside a transaction');
        return $probe->studentExists ? ['student_ID' => $params[0]] : null;
    }
    // Stop before any business reads/writes; this is not an InnoDB simulation.
    throw new StopRegistrationProbe('Reached existing section/enrollment lookup');
}
function transaction(callable $work): mixed
{
    $probe = db(); $probe->active = true; $probe->boundaries[] = 'BEGIN';
    try { $result = $work(); $probe->boundaries[] = 'COMMIT'; return $result; }
    catch (Throwable $e) { $probe->boundaries[] = 'ROLLBACK'; throw $e; }
    finally { $probe->active = false; }
}
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
require __DIR__ . '/../api/actions.php';

foreach (['addSection', 'dropSection'] as $action) {
    $probe = new RegistrationProbeConnection();
    try { $action(100001, 10548); throw new RuntimeException('Probe did not stop'); }
    catch (StopRegistrationProbe $e) {}
    check($probe->queries[0] === 'SELECT student_ID FROM Student WHERE student_ID = ? FOR UPDATE', "$action must lock Student before all other reads");
    check($probe->bound[0] === [100001], "$action must bind the authenticated student ID");
    check(count($probe->queries) === 2 && str_contains($probe->queries[1], 'FOR UPDATE'), "$action must retain the existing locking lookup");
    check($probe->boundaries === ['BEGIN', 'ROLLBACK'] && !$probe->active, "$action must use a transaction");
}
$probe = new RegistrationProbeConnection();
try { lockStudentRegistration(100001); throw new RuntimeException('Expected transaction guard'); }
catch (LogicException $e) {}
check($probe->queries === [], 'Outside-transaction guard must fail before SQL');

foreach (['addSection', 'dropSection'] as $action) {
    $probe = new RegistrationProbeConnection(); $probe->studentExists = false;
    try { $action(999999, 10548); throw new RuntimeException('Expected missing-student rejection'); }
    catch (RuleError $e) { check(str_contains($e->getMessage(), 'student account'), 'Unexpected rejection'); }
    check(count($probe->queries) === 1 && $probe->boundaries === ['BEGIN', 'ROLLBACK'], 'Missing student must stop before section access');
}
$probe = new RegistrationProbeConnection();
try { whyCannotAdd(100001, 10548); } catch (StopRegistrationProbe $e) {}
check(count($probe->queries) === 1 && !str_contains($probe->queries[0], 'FOR UPDATE'), 'Registration preview must not acquire row locks');
check($probe->boundaries === [], 'Preview must not open a transaction');
echo "PASS: actual add/drop entry points lock Student first inside a transaction, retain locking lookups, bind the student ID, reject missing students, and leave previews unlocked.\n";
echo "LIMITATION: control-flow probes only; no database or concurrent InnoDB execution.\n";
