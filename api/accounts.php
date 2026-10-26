<?php
// Sign-in and creating user accounts (admins only). Password resets by emailed code are in mail.php.
declare(strict_types=1);

const MAX_ATTEMPTS = 5;   // the account locks after 5 failed sign-in attempts
const RESET_AFTER = 3;    // a password reset (emailed code) is offered after 3 failed sign-in attempts
const MAX_ADVISEES = 15;  // F-F9, used when assigning advisors
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

    // Each type of user signs in on its own page: the account must be the type chosen there.
    $labels = ['Student' => 'Student', 'Faculty' => 'Faculty', 'Admin' => 'Admin', 'StatDept' => 'Stat-Dept member'];
    $role = (string) ($body['role'] ?? '');
    if (isset($labels[$role]) && $role !== $login['user_Type']) {
        $article = $role === 'Admin' ? 'an' : 'a';
        return [403, ['error' => "This is not $article {$labels[$role]} account. Choose {$labels[$login['user_Type']]} in \"Sign in as\" and try again."]];
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

// ---------- Create a user (A-R5): only a Read-Write admin can add accounts ----------
const ID_BASE = ['Student' => 100000, 'Faculty' => 200000, 'Admin' => 300000, 'StatDept' => 400000];
const FACULTY_RANKS = ['Professor', 'Associate Professor', 'Assistant Professor', 'Lecturer', 'Adjunct Professor'];

function createUser(int $adminId, array $form): array
{
    $admin = one('SELECT security_Level FROM Admin WHERE admin_ID = ?', [$adminId]);
    if (!$admin || $admin['security_Level'] !== 'Read-Write') fail('Read-Only admins cannot create users.');  // A-F2

    $clean = fn ($key) => is_string($form[$key] ?? null) ? trim($form[$key]) : '';
    $role = $clean('role');
    if (!isset(ID_BASE[$role])) fail('Choose the type of user to create.');
    $first = $clean('first_Name');
    $middle = $clean('middle_Name');
    $last = $clean('last_Name');
    if (!preg_match(NAME_PATTERN, $first)) fail('Enter a first name (letters only).');
    if ($middle !== '' && !preg_match(NAME_PATTERN, $middle)) fail('Middle name can only contain letters.');
    if (!preg_match(NAME_PATTERN, $last)) fail('Enter a last name (letters only).');
    $gender = $clean('gender');
    if (!in_array($gender, ['Male', 'Female', 'Other'], true)) fail('Choose a gender.');
    $dob = $clean('DOB');
    $born = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
    if (!$born || $born->format('Y-m-d') !== $dob) fail('Enter a date of birth.');
    $age = $born->diff(new DateTimeImmutable('today'))->y;
    if ($born > new DateTimeImmutable('today') || $age < 14 || $age > 100) fail('Date of birth must make the user between 14 and 100 years old.');
    [$street, $city, $state, $zip] = cleanAddress($form);
    checkPassword($form['password'] ?? null, $form['confirm'] ?? null);

    return transaction(function () use ($adminId, $form, $clean, $role, $first, $middle, $last, $gender, $dob, $street, $city, $state, $zip) {
        $insert = function (string $table, array $values): void {
            $cols = implode(', ', array_map(fn ($c) => "`$c`", array_keys($values)));  // quoted: `rank` is a reserved word
            $marks = implode(', ', array_fill(0, count($values), '?'));
            run("INSERT INTO $table ($cols) VALUES ($marks)", array_values($values));
        };

        // Check the role's own fields before anything is written.
        if ($role === 'Student') {
            $level = $clean('level');
            if (!in_array($level, ['Undergraduate', 'Graduate'], true)) fail('Choose undergraduate or graduate.');
            $load = $clean('load');
            if (!in_array($load, ['Full-time', 'Part-time'], true)) fail('Choose full-time or part-time.');
            $major = findMajor($clean('major_ID'));
            if (!$major) fail('Choose a major.');
            if ($major['level'] !== $level) fail("{$major['major_Name']} is a " . strtolower($major['level']) . ' program, so ' . ($level === 'Graduate' ? 'a graduate' : 'an undergraduate') . ' student cannot declare it.');
        } elseif ($role === 'Faculty') {
            $dept = one('SELECT dept_ID FROM Department WHERE dept_ID = ?', [$clean('dept_ID')]);
            if (!$dept) fail('Choose a department.');
            $load = $clean('load');
            if (!in_array($load, ['Full-time', 'Part-time'], true)) fail('Choose full-time or part-time.');
            $rank = $clean('rank');
            if (!in_array($rank, FACULTY_RANKS, true)) fail('Choose a rank.');
            $specialty = $clean('specialty');
            if (strlen($specialty) > 100) fail('Specialty can be at most 100 characters.');
        } elseif ($role === 'Admin') {
            $level = $clean('security_Level');
            if (!in_array($level, ['Read-Only', 'Read-Write'], true)) fail('Choose a security level.');
        }

        // A random, unused 6-digit ID in the role's range (students 1xxxxx, faculty 2xxxxx, admins 3xxxxx, Stat-Dept 4xxxxx).
        $id = null;
        for ($i = 0; $i < 50 && $id === null; $i++) {
            $candidate = random_int(ID_BASE[$role] + 1, ID_BASE[$role] + 99999);
            if (!one('SELECT user_ID FROM User WHERE user_ID = ? FOR UPDATE', [$candidate])) $id = $candidate;
        }
        if ($id === null) fail('Could not pick a user ID. Please try again.');

        // Campus email from the name: first.last@campus.edu, with a number added if it is taken.
        $base = preg_replace('/[^a-z.]/', '', strtolower("$first.$last"));
        $email = "$base@campus.edu";
        for ($n = 2; one('SELECT 1 FROM Login WHERE user_Email = ? FOR UPDATE', [$email]); $n++) $email = "$base$n@campus.edu";

        $today = date('Y-m-d');
        $insert('User', ['user_ID' => $id, 'first_Name' => $first, 'middle_Name' => $middle ?: null, 'last_Name' => $last,
            'gender' => $gender, 'DOB' => $dob, 'street' => $street, 'city' => $city, 'state' => $state, 'zip_Code' => $zip,
            'user_Type' => $role]);
        $insert('Login', ['user_ID' => $id, 'user_Email' => $email, 'user_Password' => password_hash((string) $form['password'], PASSWORD_DEFAULT),
            'user_Type' => $role, 'no_Of_Tries' => 0, 'lock_Var' => 0]);
        $extra = '';
        if ($role === 'Student') {
            $fullTime = $load === 'Full-time';
            $insert('Student', ['student_ID' => $id, 'major_ID' => $major['major_ID'],
                'student_Year' => $level === 'Undergraduate' ? 'Freshman' : 'First Year', 'student_Type' => $level]);
            if ($level === 'Undergraduate') {
                $insert('Undergraduate_Student', ['student_ID' => $id, 'dept_ID' => $major['dept_ID'], 'undergraduate_Student_Type' => $load]);
                $insert($fullTime ? 'Full_Time_Undergraduate' : 'Part_Time_Undergraduate', ['student_ID' => $id, 'status' => 'Good Standing',
                    'min_Credits' => $fullTime ? 12 : 1, 'max_Credits' => $fullTime ? 16 : 8, 'credits_Earned' => 0]);
            } else {
                $insert('Graduate_Student', ['student_ID' => $id, 'dept_ID' => $major['dept_ID'], 'program' => 'MA/MS', 'graduate_Student_Type' => $load]);
                $insert($fullTime ? 'Full_Time_Graduate_Student' : 'Part_Time_Graduate_Student', ['student_ID' => $id, 'year' => 1,
                    'credits_Earned' => 0, 'thesis_Type' => 'Non-Thesis']);
            }
            $insert('Student_Major', ['student_ID' => $id, 'major_ID' => $major['major_ID'], 'date_Of_Choice' => $today]);
            $extra = syncAdvisors($id);
        } elseif ($role === 'Faculty') {
            $insert('Faculty', ['faculty_ID' => $id, 'specialty' => $specialty ?: null, 'rank' => $rank, 'faculty_Type' => $load]);
            if ($load === 'Full-time') $insert('Full_Time_Faculty', ['faculty_ID' => $id, 'no_Of_Classes' => 0]);
            else $insert('Part_Time_Faculty', ['faculty_ID' => $id, 'no_Of_Classes' => 0, 'date_Of_Appointment' => $today]);
            $insert('Faculty_Department', ['faculty_ID' => $id, 'dept_ID' => $dept['dept_ID'], 'percent_Time' => 100, 'date_Of_Appointment' => $today]);
        } elseif ($role === 'Admin') {
            $insert('Admin', ['admin_ID' => $id, 'security_Level' => $level]);
        } else {
            $insert('Stat_Dept_Member', ['stat_dept_ID' => $id, 'access_Level' => 'Aggregate']);
        }

        // A-R43, A-R44: every admin change to user information is logged.
        run("INSERT INTO Audit_Log (admin_ID, user_ID, action_Type, field_Name, old_Value, new_Value, date_Time)
          VALUES (?, ?, 'Create', 'user_ID', NULL, ?, NOW())", [$adminId, $id, $id]);

        $label = $role === 'StatDept' ? 'Stat-Dept member' : strtolower($role);
        return ['message' => "Created $label $first $last: ID $id, email $email. They can sign in with the password you set." . str_replace('your advisor', 'their advisor', $extra)];
    });
}

// Wrong answers on the demo mail screen (ID + date of birth) are counted in a small file so they
// survive between requests: after 5, that account's mail is blocked for 15 minutes. The emailed
// password-reset codes themselves are in mail.php.
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

