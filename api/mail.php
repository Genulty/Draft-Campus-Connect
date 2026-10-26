<?php
// Campus mail and emailed password-reset codes.
// Campus email addresses are not real mailboxes, so "sending" an email saves it in Email_Message;
// users read it on the Inbox page, or (signed out) on the sign-in page's demo mail screen.
declare(strict_types=1);

const RESET_MINUTES = 15;   // a code or link works for 15 minutes
const RESET_MAX_TRIES = 5;  // then the code stops working and a new one must be requested

function sendMail(int $userId, string $subject, string $body): void
{
    $to = one('SELECT user_Email FROM Login WHERE user_ID = ?', [$userId])['user_Email'];
    run('INSERT INTO Email_Message (user_ID, to_Email, subject, body, sent_At) VALUES (?, ?, ?, ?, NOW())', [$userId, $to, $subject, $body]);
}

function inbox(int $userId, int $limit = 50): array
{
    return rows("SELECT message_ID AS id, to_Email AS `to`, subject, body, DATE_FORMAT(sent_At, '%b %e, %Y %l:%i %p') AS sent,
        read_At IS NULL AS unread FROM Email_Message WHERE user_ID = ? ORDER BY sent_At DESC, message_ID DESC LIMIT $limit", [$userId]);
}

// Starts a password reset: a 6-digit code and a link are emailed to the user. Any earlier unused
// request stops working. Only hashes of the code and link are stored.
function startReset(int $userId, string $purpose, ?int $adminId = null): void
{
    run('UPDATE Password_Reset SET expires_At = NOW() WHERE user_ID = ? AND used_At IS NULL AND expires_At > NOW()', [$userId]);
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $token = bin2hex(random_bytes(32));
    run('INSERT INTO Password_Reset (user_ID, purpose, requested_By, code_Hash, token_Hash, created_At, expires_At)
      VALUES (?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL ' . RESET_MINUTES . ' MINUTE)',
        [$userId, $purpose, $adminId, password_hash($code, PASSWORD_DEFAULT), hash('sha256', $token)]);
    $name = one('SELECT first_Name FROM User WHERE user_ID = ?', [$userId])['first_Name'];
    $why = [
        'Forgot' => 'We received a request to reset your Campus Connect password.',
        'Change' => 'You asked to change your Campus Connect password.',
        'Admin' => 'An administrator started a password reset for your Campus Connect account.',
    ][$purpose];
    sendMail($userId, 'Your Campus Connect verification code', "Hello $name,\n\n$why\n\n"
        . "Your verification code is: $code\n\n"
        . "Or open this link to choose a new password:\n/#reset?token=$token\n\n"
        . 'The code and the link expire in ' . RESET_MINUTES . " minutes and work once.\n"
        . "If you did not ask for this, ignore this email: your password stays the same.\n\n"
        . 'Harvest Hill University · Campus Connect');
}

// The user's current reset request, locked for the rest of the transaction, or null.
function pendingReset(int $userId): ?array
{
    return one('SELECT * FROM Password_Reset WHERE user_ID = ? AND used_At IS NULL AND expires_At > NOW()
      ORDER BY reset_ID DESC LIMIT 1 FOR UPDATE', [$userId]);
}

// Checks a 6-digit code. Wrong codes are counted; after 5 the request stops working.
function checkCode(int $userId, string $code): array
{
    return transaction(function () use ($userId, $code) {
        $reset = pendingReset($userId);
        if (!$reset) fail('This code has expired or was already used. Ask for a new one.');
        if ($reset['attempts'] >= RESET_MAX_TRIES) fail('Too many wrong codes. Ask for a new one.');
        if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $reset['code_Hash'])) {
            run('UPDATE Password_Reset SET attempts = attempts + 1 WHERE reset_ID = ?', [$reset['reset_ID']]);
            $left = RESET_MAX_TRIES - $reset['attempts'] - 1;
            fail($left > 0 ? "That code is not correct. $left tries left." : 'That code is not correct. Ask for a new one.');
        }
        return $reset;
    });
}

// Saves the new password chosen after a verified code or link, and unlocks the account.
function finishReset(array $form, ?int $mustBeUser = null): array
{
    $resetId = (int) ($_SESSION['reset_verified'] ?? 0);
    return transaction(function () use ($form, $resetId, $mustBeUser) {
        $reset = $resetId ? one('SELECT * FROM Password_Reset WHERE reset_ID = ? AND used_At IS NULL AND expires_At > NOW() FOR UPDATE', [$resetId]) : null;
        if (!$reset || ($mustBeUser !== null && (int) $reset['user_ID'] !== $mustBeUser)) {
            fail('Your code has expired. Ask for a new one.');
        }
        checkPassword($form['password'] ?? null, $form['confirm'] ?? null);
        run('UPDATE Login SET user_Password = ?, no_Of_Tries = 0, lock_Var = 0 WHERE user_ID = ?',
            [password_hash((string) $form['password'], PASSWORD_DEFAULT), $reset['user_ID']]);
        run('UPDATE Password_Reset SET used_At = NOW() WHERE reset_ID = ?', [$reset['reset_ID']]);
        sendMail((int) $reset['user_ID'], 'Your Campus Connect password was changed',
            "Your password was changed on " . date('M j, Y \a\t g:i A') . ".\n\n"
            . "If you did not do this, contact the registrar's office right away.\n\nHarvest Hill University · Campus Connect");
        unset($_SESSION['reset_user'], $_SESSION['reset_verified']);
        return ['message' => 'Your password was changed. You can sign in with it now.'];
    });
}

// ---------- Forgot password (signed out), after 3 failed sign-ins (S-R2, S-F2) ----------

function requestForgotReset(array $form): array
{
    $name = trim((string) ($form['login'] ?? ''));
    $login = $name === '' ? null : findLogin($name);
    if (!$login) fail('No account matches that email or ID.');
    if ($login['no_Of_Tries'] < RESET_AFTER && !$login['lock_Var']) fail('Password reset is available after ' . RESET_AFTER . ' failed sign-in attempts.');
    transaction(fn () => startReset((int) $login['user_ID'], 'Forgot'));
    $_SESSION['reset_user'] = (int) $login['user_ID'];
    unset($_SESSION['reset_verified']);
    return ['message' => 'We sent a 6-digit code to ' . maskEmail($login['user_Email']) . '.'];
}

// The code typed on the sign-in page, for the account entered with it (a reset the user asked for,
// or one an admin sent them).
function verifyForgotCode(array $form): array
{
    $name = trim((string) ($form['login'] ?? ''));
    $login = $name === '' ? null : findLogin($name);
    if (!$login) fail('No account matches that email or ID.');
    $userId = (int) $login['user_ID'];
    $reset = checkCode($userId, trim((string) ($form['code'] ?? '')));
    $_SESSION['reset_user'] = $userId;
    $_SESSION['reset_verified'] = (int) $reset['reset_ID'];
    return ['message' => 'Code accepted. Choose a new password.'];
}

// The emailed link: any browser that has it may choose the new password.
function verifyResetLink(array $form): array
{
    $token = (string) ($form['token'] ?? '');
    $reset = preg_match('/^[0-9a-f]{64}$/', $token)
        ? one('SELECT reset_ID, user_ID FROM Password_Reset WHERE token_Hash = ? AND used_At IS NULL AND expires_At > NOW()', [hash('sha256', $token)])
        : null;
    if (!$reset) fail('This link has expired or was already used. Ask for a new code.');
    $_SESSION['reset_user'] = (int) $reset['user_ID'];
    $_SESSION['reset_verified'] = (int) $reset['reset_ID'];
    $who = one('SELECT user_Email FROM Login WHERE user_ID = ?', [$reset['user_ID']]);
    return ['message' => 'Link accepted. Choose a new password.', 'email' => $who['user_Email']];
}

function maskEmail(string $email): string
{
    [$local, $domain] = explode('@', $email, 2);
    return substr($local, 0, 1) . str_repeat('•', max(2, strlen($local) - 1)) . "@$domain";
}

// Demo mail on the sign-in page: signed-out users open their campus mail with their ID and date of
// birth. After 5 wrong tries the account's mail is blocked for 15 minutes.
function demoMail(array $form): array
{
    $id = trim((string) ($form['user_ID'] ?? ''));
    $key = "mail:$id";
    $blocked = verifyFailures(fn (array $data) => ['blocked' => ($data[$key]['count'] ?? 0) >= 5])['blocked'];
    if ($blocked) fail('Too many wrong tries. Try again in 15 minutes.');
    $user = ctype_digit($id) ? one('SELECT user_ID, DOB FROM User WHERE user_ID = ?', [(int) $id]) : null;
    if (!$user || trim((string) ($form['DOB'] ?? '')) !== $user['DOB']) {
        verifyFailures(function (array &$data) use ($key) {
            $data[$key] = ['count' => ($data[$key]['count'] ?? 0) + 1, 'until' => time() + 15 * 60];
        });
        fail('The ID and date of birth do not match an account.');
    }
    return ['messages' => inbox((int) $user['user_ID'], 20)];
}

// ---------- Change your own password (signed in): current password, then an emailed code ----------

function requestPasswordChange(int $userId, array $form): array
{
    $login = one('SELECT user_Password, user_Email FROM Login WHERE user_ID = ?', [$userId]);
    if (!password_verify((string) ($form['current'] ?? ''), $login['user_Password'])) fail('Your current password is not correct.');
    transaction(fn () => startReset($userId, 'Change'));
    $_SESSION['reset_user'] = $userId;
    unset($_SESSION['reset_verified']);
    return ['message' => 'We sent a 6-digit code to your campus inbox. Enter it below.'];
}

function verifyPasswordChangeCode(int $userId, array $form): array
{
    $reset = checkCode($userId, trim((string) ($form['code'] ?? '')));
    $_SESSION['reset_user'] = $userId;
    $_SESSION['reset_verified'] = (int) $reset['reset_ID'];
    return ['message' => 'Code accepted. Choose your new password below.'];
}

// ---------- Admin: send a password reset to a user (A-R6, A-R43, A-R44) ----------

function adminSendReset(int $adminId, array $form): array
{
    $admin = one('SELECT security_Level FROM Admin WHERE admin_ID = ?', [$adminId]);
    if (!$admin || $admin['security_Level'] !== 'Read-Write') fail('Read-Only admins cannot reset passwords.');  // A-F2
    $name = trim((string) ($form['login'] ?? ''));
    $login = $name === '' ? null : findLogin($name, ", CONCAT(u.first_Name, ' ', u.last_Name) AS name");
    if (!$login) fail('No account matches that email or ID.');
    return transaction(function () use ($adminId, $login) {
        startReset((int) $login['user_ID'], 'Admin', $adminId);
        run("INSERT INTO Audit_Log (admin_ID, user_ID, action_Type, field_Name, old_Value, new_Value, date_Time)
          VALUES (?, ?, 'Update', 'user_Password', NULL, 'Reset email sent', NOW())", [$adminId, $login['user_ID']]);
        return ['message' => "A password-reset email was sent to {$login['name']} ({$login['user_Email']}). They choose the new password themselves."];
    });
}
