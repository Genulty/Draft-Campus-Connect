<?php
// Sign-in, student sign-up and password reset.
declare(strict_types=1);

const MAX_ATTEMPTS = 5;   // the account locks after 5 failed sign-in attempts
const RESET_AFTER = 3;    // a password reset is offered after 3 failed sign-in attempts
const MAX_ADVISEES = 15;  // F-F9
const NAME_PATTERN = "/^[A-Za-z][A-Za-z' -]{0,49}$/";

function userInfo(int $id): array
{
    $u = one('SELECT u.user_ID, u.first_Name, u.last_Name, u.user_Type, l.user_Email
      FROM User u JOIN Login l ON l.user_ID = u.user_ID WHERE u.user_ID = ?', [$id]);
    return ['id' => $u['user_ID'], 'name' => "{$u['first_Name']} {$u['last_Name']}", 'role' => $u['user_Type'], 'email' => $u['user_Email']];
}

// Finds a Login row by user ID (all digits) or by campus email.
function findLogin(string $name, string $extraColumns = ''): ?array
{
    $column = ctype_digit($name) ? 'l.user_ID' : 'l.user_Email';
    return one("SELECT l.*$extraColumns FROM Login l JOIN User u ON u.user_ID = l.user_ID WHERE $column = ?", [$name]);
}

// ---------- Sign in (UC-1 successful login, UC-2 unsuccessful login) ----------
// Returns [HTTP status, response body]. On success the user is stored in the session.
function signIn(array $body): array
{
    $name = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    if ($name === '' || $password === '') return [400, ['error' => 'Enter your email (or ID) and password.']];

    $login = findLogin($name);
    if (!$login) return [401, ['error' => 'Invalid email or password.']];
    if ($login['lock_Var']) {
        return [423, ['error' => 'This account is locked after too many failed attempts. Reset your password to unlock it.', 'canReset' => true]];
    }

    if (!password_verify($password, $login['user_Password'])) {
        $tries = $login['no_Of_Tries'] + 1;
        $locked = $tries >= MAX_ATTEMPTS ? 1 : 0;
        run('UPDATE Login SET no_Of_Tries = ?, lock_Var = ? WHERE user_ID = ?', [$tries, $locked, $login['user_ID']]);
        return [401, [
            'error' => $locked
                ? 'Invalid email or password. The account is now locked.'
                : 'Invalid email or password. ' . (MAX_ATTEMPTS - $tries) . ' attempt(s) left before the account is locked.',
            'canReset' => $tries >= RESET_AFTER,
        ]];
    }

    run('UPDATE Login SET no_Of_Tries = 0 WHERE user_ID = ?', [$login['user_ID']]);
    session_regenerate_id(true); // a fresh session ID on every sign-in
    $_SESSION['user'] = userInfo($login['user_ID']);
    return [200, ['user' => $_SESSION['user']]];
}

function checkPassword(mixed $password, mixed $confirm): void
{
    if (!is_string($password) || strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        fail('Password must be at least 8 characters and include a letter and a number.');
    }
    if ($password !== $confirm) fail('The two passwords do not match.');
}

// ---------- Sign up as a new undergraduate student ----------
function signUp(array $form): array
{
    $clean = fn ($key) => is_string($form[$key] ?? null) ? trim($form[$key]) : '';
    $first = $clean('first_Name');
    $middle = $clean('middle_Name');
    $last = $clean('last_Name');
    if (!preg_match(NAME_PATTERN, $first)) fail('Enter your first name (letters only).');
    if ($middle !== '' && !preg_match(NAME_PATTERN, $middle)) fail('Middle name can only contain letters.');
    if (!preg_match(NAME_PATTERN, $last)) fail('Enter your last name (letters only).');
    $gender = $clean('gender');
    if (!in_array($gender, ['Male', 'Female', 'Other'], true)) fail('Choose a gender.');
    $dob = $clean('DOB');
    $born = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
    if (!$born || $born->format('Y-m-d') !== $dob) fail('Enter your date of birth.');
    $age = $born->diff(new DateTimeImmutable('today'))->y;
    if ($born > new DateTimeImmutable('today') || $age < 14 || $age > 100) fail('Date of birth must make you between 14 and 100 years old.');
    $street = $clean('street') ?: null;
    $city = $clean('city') ?: null;
    $state = strtoupper($clean('state')) ?: null;
    $zip = $clean('zip_Code') ?: null;
    if ($state !== null && !preg_match('/^[A-Z]{2}$/', $state)) fail('State must be a 2-letter code, like NY.');
    if ($zip !== null && !preg_match('/^\d{5}$/', $zip)) fail('ZIP code must be 5 digits.');
    $type = $clean('type');
    if (!in_array($type, ['Full-time', 'Part-time'], true)) fail('Choose full-time or part-time.');
    checkPassword($form['password'] ?? null, $form['confirm'] ?? null);

    return transaction(function () use ($form, $first, $middle, $last, $gender, $dob, $street, $city, $state, $zip, $type) {
        $insert = function (string $table, array $values): void {
            $cols = implode(', ', array_keys($values));
            $marks = implode(', ', array_fill(0, count($values), '?'));
            run("INSERT INTO $table ($cols) VALUES ($marks)", array_values($values));
        };

        $major = one("SELECT m.major_ID, m.major_Name, m.dept_ID FROM Major m
          WHERE m.major_ID = ? AND m.major_ID IN (SELECT major_ID FROM Major_Course_Requirement r JOIN Course c ON c.course_ID = r.course_ID
            WHERE c.course_Type = 'Undergraduate')", [(string) ($form['major_ID'] ?? '')]);
        if (!$major) fail('Choose an undergraduate major.');

        // A random, unused 6-digit student ID in the student range (100000–199999).
        $id = null;
        for ($i = 0; $i < 50 && $id === null; $i++) {
            $candidate = random_int(100000, 199999);
            if (!one('SELECT user_ID FROM User WHERE user_ID = ? FOR UPDATE', [$candidate])) $id = $candidate;
        }
        if ($id === null) fail('Could not pick a student ID. Please try again.');

        // Campus email from the name: first.last@campus.edu, with a number added if it is taken.
        $base = preg_replace('/[^a-z.]/', '', strtolower("$first.$last"));
        $email = "$base@campus.edu";
        for ($n = 2; one('SELECT 1 FROM Login WHERE user_Email = ? FOR UPDATE', [$email]); $n++) $email = "$base$n@campus.edu";

        $today = date('Y-m-d');
        $fullTime = $type === 'Full-time';
        $insert('User', ['user_ID' => $id, 'first_Name' => $first, 'middle_Name' => $middle ?: null, 'last_Name' => $last,
            'gender' => $gender, 'DOB' => $dob, 'street' => $street, 'city' => $city, 'state' => $state, 'zip_Code' => $zip,
            'user_Type' => 'Student']);
        $insert('Login', ['user_ID' => $id, 'user_Email' => $email, 'user_Password' => password_hash((string) $form['password'], PASSWORD_DEFAULT),
            'no_Of_Tries' => 0, 'lock_Var' => 0]);
        $insert('Student', ['student_ID' => $id, 'student_Year' => 'Freshman', 'student_Type' => 'Undergraduate']);
        $insert('Undergraduate_Student', ['student_ID' => $id, 'dept_ID' => $major['dept_ID'], 'undergraduate_Student_Type' => $type]);
        $insert($fullTime ? 'Full_Time_Undergraduate' : 'Part_Time_Undergraduate', ['student_ID' => $id, 'status' => 'Good Standing',
            'min_Credits' => $fullTime ? 12 : 1, 'max_Credits' => $fullTime ? 16 : 8, 'credits_Earned' => 0]);
        $insert('Student_Major', ['student_ID' => $id, 'major_ID' => $major['major_ID'], 'date_Of_Choice' => $today]);

        // Advisor: the full-time faculty member in the major's department with the fewest advisees (F-F9, F-F10).
        $advisor = one("SELECT f.faculty_ID, CONCAT(u.first_Name, ' ', u.last_Name) AS name,
            (SELECT COUNT(*) FROM Advisor a WHERE a.faculty_ID = f.faculty_ID) AS n
          FROM Faculty f JOIN Faculty_Department fd ON fd.faculty_ID = f.faculty_ID JOIN User u ON u.user_ID = f.faculty_ID
          WHERE fd.dept_ID = ? AND f.faculty_Type = 'Full-time'
          HAVING n < ? ORDER BY n, f.faculty_ID LIMIT 1 FOR UPDATE", [$major['dept_ID'], MAX_ADVISEES]);
        if ($advisor) $insert('Advisor', ['faculty_ID' => $advisor['faculty_ID'], 'student_ID' => $id, 'date_Of_Appointment' => $today]);

        return ['id' => $id, 'email' => $email, 'name' => "$first $last", 'major' => $major['major_Name'],
            'advisor' => $advisor['name'] ?? null];
    });
}

// ---------- Reset a password after 3 failed sign-in attempts (S-R2, UC-3) ----------
// Verification: the account's user ID and date of birth must match (S-F2).
// Failed verifications are counted in a small file so they survive between requests:
// after 5 wrong answers, resets for that account are blocked for 15 minutes.
function verifyFailures(callable $update): array
{
    $file = sys_get_temp_dir() . '/campus-connect-reset-failures.json';
    $handle = fopen($file, 'c+');
    flock($handle, LOCK_EX);
    $data = json_decode(stream_get_contents($handle) ?: '{}', true) ?: [];
    $data = array_filter($data, fn ($entry) => $entry['until'] > time()); // forget expired entries
    $result = $update($data);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($data));
    flock($handle, LOCK_UN);
    fclose($handle);
    return $result ?? [];
}

function resetPassword(array $form): array
{
    $name = trim((string) ($form['login'] ?? ''));
    $login = $name === '' ? null : findLogin($name, ', u.DOB');
    if (!$login) fail('No account matches that email or ID.');
    if ($login['no_Of_Tries'] < RESET_AFTER && !$login['lock_Var']) fail('Password reset is available after ' . RESET_AFTER . ' failed sign-in attempts.');

    $key = (string) $login['user_ID'];
    $blocked = verifyFailures(fn (array $data) => ['blocked' => ($data[$key]['count'] ?? 0) >= 5])['blocked'];
    if ($blocked) fail('Too many failed verification attempts. Try again in 15 minutes.');
    if (trim((string) ($form['user_ID'] ?? '')) !== $key || trim((string) ($form['DOB'] ?? '')) !== $login['DOB']) {
        verifyFailures(function (array &$data) use ($key) {
            $data[$key] = ['count' => ($data[$key]['count'] ?? 0) + 1, 'until' => time() + 15 * 60];
        });
        fail('The ID and date of birth do not match this account.');
    }
    checkPassword($form['password'] ?? null, $form['confirm'] ?? null);

    run('UPDATE Login SET user_Password = ?, no_Of_Tries = 0, lock_Var = 0 WHERE user_ID = ?',
        [password_hash((string) $form['password'], PASSWORD_DEFAULT), $login['user_ID']]);
    verifyFailures(function (array &$data) use ($key) { unset($data[$key]); });
    return ['message' => 'Your password was reset and the account is unlocked. You can sign in now.'];
}

// Undergraduate majors for the sign-up form.
function undergraduateMajors(): array
{
    return rows("SELECT m.major_ID, m.major_Name FROM Major m
      WHERE EXISTS (SELECT 1 FROM Major_Course_Requirement r JOIN Course c ON c.course_ID = r.course_ID
        WHERE r.major_ID = m.major_ID AND c.course_Type = 'Undergraduate')
      ORDER BY m.major_Name");
}
