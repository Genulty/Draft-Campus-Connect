<?php
// A user's own address, and a student's academic programs (majors and minors).
declare(strict_types=1);

const MAX_MAJORS = 2;        // S-F20
const MAX_MINORS = 1;
const MAX_ADVISORS = 2;      // S-R11, A-F12

// ---------- Account: address (S-R20). The password (S-R21) is changed with an emailed code (mail.php). ----------

// Checks an optional address. Returns [street, city, state, zip], each null when left empty.
function cleanAddress(array $form): array
{
    $text = fn ($key) => is_string($form[$key] ?? null) ? trim($form[$key]) : '';
    $street = $text('street') ?: null;
    $city = $text('city') ?: null;
    $state = strtoupper($text('state')) ?: null;
    $zip = $text('zip_Code') ?: null;
    if ($street !== null && strlen($street) > 100) fail('Street can be at most 100 characters.');
    if ($city !== null && !preg_match("/^[A-Za-z][A-Za-z .'-]{0,49}$/", $city)) fail('City can only contain letters.');
    if ($state !== null && !preg_match('/^[A-Z]{2}$/', $state)) fail('State must be a 2-letter code, like NY.');
    if ($zip !== null && !preg_match('/^\d{5}$/', $zip)) fail('ZIP code must be 5 digits.');
    return [$street, $city, $state, $zip];
}

function updateAddress(int $userId, array $form): array
{
    [$street, $city, $state, $zip] = cleanAddress($form);
    run('UPDATE User SET street = ?, city = ?, state = ?, zip_Code = ? WHERE user_ID = ?', [$street, $city, $state, $zip, $userId]);
    return ['message' => 'Your address was updated.'];
}

// ---------- Programs: majors (S-R24 to S-R27) and minors (S-R28 to S-R30) ----------

// A major's level comes from its required courses: B.S./B.A. majors are Undergraduate, M.S./M.A. are Graduate.
function findMajor(string $id): ?array
{
    return $id === '' ? null : one("SELECT m.major_ID, m.major_Name, m.dept_ID,
        (SELECT c.course_Type FROM Major_Course_Requirement r JOIN Course c ON c.course_ID = r.course_ID
          WHERE r.major_ID = m.major_ID LIMIT 1) AS level
      FROM Major m WHERE m.major_ID = ?", [$id]);
}

function findMinor(string $id): ?array
{
    return $id === '' ? null : one('SELECT minor_ID, minor_Name, dept_ID FROM Minor WHERE minor_ID = ?', [$id]);
}

// The student's level and programs, locked for the rest of the transaction.
function programsOf(int $studentId): array
{
    $student = one('SELECT student_ID, student_Type, major_ID FROM Student WHERE student_ID = ? FOR UPDATE', [$studentId]);
    $majors = rows('SELECT sm.major_ID, m.major_Name, m.dept_ID FROM Student_Major sm JOIN Major m ON m.major_ID = sm.major_ID
      WHERE sm.student_ID = ? ORDER BY sm.major_ID = ? DESC, sm.date_Of_Choice FOR UPDATE', [$studentId, $student['major_ID']]);
    $minors = rows('SELECT sm.minor_ID, n.minor_Name, n.dept_ID FROM Student_Minor sm JOIN Minor n ON n.minor_ID = sm.minor_ID
      WHERE sm.student_ID = ? FOR UPDATE', [$studentId]);
    return [$student, $majors, $minors];
}

// A valid major for this student (S-F19): it exists, matches the student's level and isn't already declared.
function checkNewMajor(string $id, array $student, array $majors, array $minors): array
{
    $major = findMajor($id);
    if (!$major) fail('That major does not exist.');
    if ($major['level'] !== $student['student_Type']) {
        fail("{$major['major_Name']} is a " . strtolower($major['level']) . ' program. ' . $student['student_Type'] . ' students cannot declare it.');
    }
    if (in_array($id, array_column($majors, 'major_ID'), true)) fail("You have already declared {$major['major_Name']}.");
    foreach ($minors as $n) {
        if ($n['dept_ID'] === $major['dept_ID']) fail("You have a {$n['minor_Name']}. Drop that minor before declaring a major in the same department.");
    }
    return $major;
}

function checkNewMinor(string $id, array $student, array $majors, array $minors): array
{
    if ($student['student_Type'] !== 'Undergraduate') fail('Minors are only for undergraduate students.');
    $minor = findMinor($id);
    if (!$minor) fail('That minor does not exist.');
    if (in_array($id, array_column($minors, 'minor_ID'), true)) fail("You have already declared the {$minor['minor_Name']}.");
    foreach ($majors as $m) {
        if ($m['dept_ID'] === $minor['dept_ID']) fail("You can't minor in the department of your major ({$m['major_Name']}).");
    }
    return $minor;
}

// After the primary major changes, the student's department follows it.
function setPrimaryMajor(int $studentId, string $majorId, string $deptId): void
{
    run('UPDATE Student SET major_ID = ? WHERE student_ID = ?', [$majorId, $studentId]);
    run('UPDATE Undergraduate_Student SET dept_ID = ? WHERE student_ID = ?', [$deptId, $studentId]);
    run('UPDATE Graduate_Student SET dept_ID = ? WHERE student_ID = ?', [$deptId, $studentId]);
}

// Advisors follow the student's programs (S-R11): one full-time advisor (F-F10) in the department of each
// major, then the minor, at most 2 in total (A-F12), each advising at most 15 students (F-F9).
// Returns a sentence about any advisor change, or '' when nothing changed.
function syncAdvisors(int $studentId): string
{
    $depts = array_column(rows("SELECT m.dept_ID FROM Student_Major sm JOIN Major m ON m.major_ID = sm.major_ID
        JOIN Student s ON s.student_ID = sm.student_ID WHERE sm.student_ID = ? ORDER BY sm.major_ID = s.major_ID DESC, sm.date_Of_Choice", [$studentId]), 'dept_ID');
    foreach (rows('SELECT n.dept_ID FROM Student_Minor sm JOIN Minor n ON n.minor_ID = sm.minor_ID WHERE sm.student_ID = ?', [$studentId]) as $n) {
        $depts[] = $n['dept_ID'];
    }
    $wanted = array_slice(array_values(array_unique($depts)), 0, MAX_ADVISORS);

    $changes = [];
    $covered = [];
    $current = rows("SELECT a.faculty_ID, CONCAT(u.first_Name, ' ', u.last_Name) AS name,
        (SELECT GROUP_CONCAT(fd.dept_ID) FROM Faculty_Department fd WHERE fd.faculty_ID = a.faculty_ID) AS depts
      FROM Advisor a JOIN User u ON u.user_ID = a.faculty_ID WHERE a.student_ID = ? ORDER BY a.date_Of_Appointment FOR UPDATE", [$studentId]);
    foreach ($current as $a) {
        $match = array_values(array_diff(array_intersect(explode(',', (string) $a['depts']), $wanted), $covered));
        if ($match) {
            $covered[] = $match[0];
        } else {
            run('DELETE FROM Advisor WHERE faculty_ID = ? AND student_ID = ?', [$a['faculty_ID'], $studentId]);
            $changes[] = "{$a['name']} is no longer your advisor";
        }
    }
    foreach (array_diff($wanted, $covered) as $deptId) {
        $advisor = one("SELECT f.faculty_ID, CONCAT(u.first_Name, ' ', u.last_Name) AS name,
            (SELECT COUNT(*) FROM Advisor a WHERE a.faculty_ID = f.faculty_ID) AS n
          FROM Faculty f JOIN Faculty_Department fd ON fd.faculty_ID = f.faculty_ID JOIN User u ON u.user_ID = f.faculty_ID
          WHERE fd.dept_ID = ? AND f.faculty_Type = 'Full-time'
            AND f.faculty_ID NOT IN (SELECT faculty_ID FROM Advisor WHERE student_ID = ?)
          HAVING n < ? ORDER BY n, f.faculty_ID LIMIT 1 FOR UPDATE", [$deptId, $studentId, MAX_ADVISEES]);
        if ($advisor) {
            run('INSERT INTO Advisor (faculty_ID, student_ID, date_Of_Appointment) VALUES (?, ?, CURDATE())', [$advisor['faculty_ID'], $studentId]);
            $changes[] = "{$advisor['name']} is now your advisor";
        }
    }
    return $changes ? ' ' . implode('. ', $changes) . '.' : '';
}

function addMajor(int $studentId, array $form): array
{
    return transaction(function () use ($studentId, $form) {
        [$student, $majors, $minors] = programsOf($studentId);
        $major = checkNewMajor((string) ($form['major_ID'] ?? ''), $student, $majors, $minors);
        if (count($majors) >= MAX_MAJORS) fail('You already have ' . MAX_MAJORS . ' majors, the most allowed. Drop or change one first.');
        run('INSERT INTO Student_Major (student_ID, major_ID, date_Of_Choice) VALUES (?, ?, CURDATE())', [$studentId, $major['major_ID']]);
        return ['message' => "{$major['major_Name']} was added as your second major." . syncAdvisors($studentId)];
    });
}

function changeMajor(int $studentId, array $form): array
{
    return transaction(function () use ($studentId, $form) {
        [$student, $majors, $minors] = programsOf($studentId);
        $fromId = (string) ($form['from_ID'] ?? '');
        $from = array_values(array_filter($majors, fn ($m) => $m['major_ID'] === $fromId))[0] ?? null;
        if (!$from) fail('You can only change a major you have declared.');  // S-F21
        $to = checkNewMajor((string) ($form['major_ID'] ?? ''), $student, $majors, $minors);
        run('DELETE FROM Student_Major WHERE student_ID = ? AND major_ID = ?', [$studentId, $fromId]);
        run('INSERT INTO Student_Major (student_ID, major_ID, date_Of_Choice) VALUES (?, ?, CURDATE())', [$studentId, $to['major_ID']]);
        if ($student['major_ID'] === $fromId) setPrimaryMajor($studentId, $to['major_ID'], $to['dept_ID']);
        return ['message' => "Your major was changed from {$from['major_Name']} to {$to['major_Name']}." . syncAdvisors($studentId)];
    });
}

function dropMajor(int $studentId, array $form): array
{
    return transaction(function () use ($studentId, $form) {
        [$student, $majors] = programsOf($studentId);
        $id = (string) ($form['major_ID'] ?? '');
        $major = array_values(array_filter($majors, fn ($m) => $m['major_ID'] === $id))[0] ?? null;
        if (!$major) fail('You can only drop a major you have declared.');  // S-F21
        if (count($majors) === 1) fail('You must keep at least one major. To switch to a different one, use Change a major.');
        run('DELETE FROM Student_Major WHERE student_ID = ? AND major_ID = ?', [$studentId, $id]);
        $rest = array_values(array_filter($majors, fn ($m) => $m['major_ID'] !== $id))[0];
        if ($student['major_ID'] === $id) setPrimaryMajor($studentId, $rest['major_ID'], $rest['dept_ID']);
        return ['message' => "{$major['major_Name']} was dropped. Your major is now {$rest['major_Name']}." . syncAdvisors($studentId)];
    });
}

function addMinor(int $studentId, array $form): array
{
    return transaction(function () use ($studentId, $form) {
        [$student, $majors, $minors] = programsOf($studentId);
        $minor = checkNewMinor((string) ($form['minor_ID'] ?? ''), $student, $majors, $minors);
        if (count($minors) >= MAX_MINORS) fail("You already have the {$minors[0]['minor_Name']}. Change or drop it first.");
        run('INSERT INTO Student_Minor (student_ID, minor_ID, date_Of_Choice) VALUES (?, ?, CURDATE())', [$studentId, $minor['minor_ID']]);
        return ['message' => "The {$minor['minor_Name']} was added to your record." . syncAdvisors($studentId)];
    });
}

function changeMinor(int $studentId, array $form): array
{
    return transaction(function () use ($studentId, $form) {
        [$student, $majors, $minors] = programsOf($studentId);
        $fromId = (string) ($form['from_ID'] ?? '');
        $from = array_values(array_filter($minors, fn ($n) => $n['minor_ID'] === $fromId))[0] ?? null;
        if (!$from) fail('You can only change a minor you have declared.');  // S-F23
        $to = checkNewMinor((string) ($form['minor_ID'] ?? ''), $student, $majors, $minors);
        run('DELETE FROM Student_Minor WHERE student_ID = ? AND minor_ID = ?', [$studentId, $fromId]);
        run('INSERT INTO Student_Minor (student_ID, minor_ID, date_Of_Choice) VALUES (?, ?, CURDATE())', [$studentId, $to['minor_ID']]);
        return ['message' => "Your minor was changed from the {$from['minor_Name']} to the {$to['minor_Name']}." . syncAdvisors($studentId)];
    });
}

function dropMinor(int $studentId, array $form): array
{
    return transaction(function () use ($studentId, $form) {
        [, , $minors] = programsOf($studentId);
        $id = (string) ($form['minor_ID'] ?? '');
        $minor = array_values(array_filter($minors, fn ($n) => $n['minor_ID'] === $id))[0] ?? null;
        if (!$minor) fail('You can only drop a minor you have declared.');  // S-F23
        run('DELETE FROM Student_Minor WHERE student_ID = ? AND minor_ID = ?', [$studentId, $id]);
        return ['message' => "The {$minor['minor_Name']} was dropped." . syncAdvisors($studentId)];
    });
}
