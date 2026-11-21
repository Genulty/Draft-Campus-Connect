<?php
declare(strict_types=1);

// Applied only to the private, dedicated senior fixture. Never use a shared database.
require __DIR__ . '/../../api/db.php';
require __DIR__ . '/../../api/actions.php';

$socket = getenv('DB_SOCKET') ?: '';
if (($argv[1] ?? '') !== '--load-senior') throw new RuntimeException('Opt in with --load-senior on the dedicated disposable standing database.');
$settings = config();
if ($settings['host'] !== 'localhost' || $settings['socket'] !== $socket ||
    !preg_match('~/campus-standing-review-db\.[A-Za-z0-9]+/mysql\.sock$~', $socket) ||
    !one('SELECT 1 AS ok FROM DUAL WHERE @@socket = ? AND @@skip_networking = 1 AND @@server_id = 732157', [$socket])) {
    throw new RuntimeException('Use the disposable senior database created by tests/academic-standing-live.sh.');
}
$password = getenv('DEMO_PASSWORD');
if (!$password) throw new RuntimeException('Set DEMO_PASSWORD for the local synthetic login.');

const SENIOR_DEMO_ID = 199088;
const CURRENT_CRNS = [10442, 10901, 10903, 10902];

transaction(function () use ($password) {
    if (one('SELECT 1 FROM User WHERE user_ID=?', [SENIOR_DEMO_ID])) fail('The synthetic senior already exists. Reset the demo to reapply.');
    run("INSERT INTO User (user_ID,first_Name,last_Name,DOB,user_Type) VALUES (?, 'Riley', 'Senior Demo', '2005-06-01', 'Student')", [SENIOR_DEMO_ID]);
    run("INSERT INTO Login (user_ID,user_Email,user_Password,user_Type) VALUES (?, 'riley.seniordemo@campus.edu', ?, 'Student')",
        [SENIOR_DEMO_ID, password_hash($password, PASSWORD_DEFAULT)]);
    // The legacy year intentionally stays at the intake default. Display and registration derive standing.
    run("INSERT INTO Student VALUES (?, 'CS-BS', 'Freshman', 'Undergraduate')", [SENIOR_DEMO_ID]);
    run("INSERT INTO Undergraduate_Student VALUES (?, 'CS', 'Full-time')", [SENIOR_DEMO_ID]);
    run("INSERT INTO Full_Time_Undergraduate (student_ID) VALUES (?)", [SENIOR_DEMO_ID]);
    run("INSERT INTO Student_Major VALUES (?, 'CS-BS', '2023-08-28')", [SENIOR_DEMO_ID]);
    run("INSERT INTO Semester VALUES
        ('FA23','Fall 2023',2023,'2023-08-30','2023-12-20','2023-06-30','2023-09-06','2023-06-30','2023-09-20','2023-12-13','2023-12-27'),
        ('SP24','Spring 2024',2024,'2024-01-24','2024-05-17','2023-11-24','2024-01-31','2023-11-24','2024-02-14','2024-05-10','2024-05-24')");
    foreach (file(__DIR__ . '/senior-standing-history.sql', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $statement) {
        run($statement);
    }
    // Replay normal standing, seat, prerequisite, and schedule checks during the actual Add window.
    run("SET timestamp = UNIX_TIMESTAMP('2026-08-27 12:00:00')");
    $earned = studentEarnedCredits(SENIOR_DEMO_ID);
    if ($earned !== 92 || undergraduateClassYear($earned) !== 'Senior') fail('Unexpected earned-credit total for the senior fixture.');
    run('UPDATE Full_Time_Undergraduate SET credits_Earned=? WHERE student_ID=?', [$earned, SENIOR_DEMO_ID]);
    foreach (CURRENT_CRNS as $crn) {
        checkAdd(SENIOR_DEMO_ID, $crn, true);
        run('INSERT INTO Enrollment (student_ID,CRN,grade) VALUES (?,?,NULL)', [SENIOR_DEMO_ID, $crn]);
    }
});
echo "Created synthetic senior 199088 with 92 earned and 12 in-progress credits.\n";
