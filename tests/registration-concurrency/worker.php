<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$action = $argv[1] ?? '';
$student = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT);
$crn = filter_var($argv[3] ?? '', FILTER_VALIDATE_INT);
$isolation = $argv[4] ?? '';
$barrier = $argv[5] ?? 'none';
$failInsert = ($argv[6] ?? '') === 'fail-insert';
$waitSeconds = ($argv[7] ?? '') === 'short-timeout' ? 1 : 5;
if (!in_array($action, ['addSection', 'dropSection'], true) || !$student || !$crn
    || !in_array($isolation, ['REPEATABLE READ', 'READ COMMITTED'], true)
    || !in_array($barrier, ['none', 'student-locked', 'section-locked'], true)) throw new RuntimeException('Invalid worker arguments');
// These session settings apply only after the strict isolated-server guard.
db()->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
db()->exec('SET SESSION innodb_lock_wait_timeout = ' . $waitSeconds);
$studentLocked = false;
function emitEvent(string $event, array $data = []): void { echo json_encode(['event' => $event] + $data) . "\n"; fflush(STDOUT); }
final class RegistrationObservedStatement extends PDOStatement
{
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        global $barrier, $failInsert, $studentLocked;
        $sql = preg_replace('/\s+/', ' ', trim($this->queryString));
        $kind = $sql === 'SELECT student_ID FROM Student WHERE student_ID = ? FOR UPDATE' ? 'student'
            : ((str_contains($sql, 'FROM Course_Section cs ') && str_ends_with($sql, 'FOR UPDATE')) ? 'section' : null);
        if (db()->inTransaction() && !$studentLocked && $kind !== 'student') throw new RuntimeException('A read preceded the student mutex');
        if ($kind) emitEvent($kind . '-waiting');
        $result = parent::execute($params);
        if ($kind === 'student') $studentLocked = true;
        if ($kind) {
            emitEvent($kind . '-locked');
            if ($barrier === $kind . '-locked') {
                if (trim((string) fgets(STDIN)) !== 'release') throw new RuntimeException('Coordinator cancelled the transaction');
            }
        }
        if ($failInsert && str_starts_with($sql, 'INSERT INTO Enrollment ')) throw new RuntimeException('Forced isolated rollback after insert');
        return $result;
    }
}
db()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RegistrationObservedStatement::class]);
try {
    $result = $action((int) $student, (int) $crn);
    emitEvent('done', ['outcome' => 'ok', 'message' => $result['message']]);
} catch (Throwable $e) {
    emitEvent('done', ['outcome' => 'error', 'class' => get_class($e), 'message' => $e->getMessage(),
        'mysql_code' => $e instanceof PDOException ? ($e->errorInfo[1] ?? null) : null,
        'transaction_open' => db()->inTransaction()]);
}
