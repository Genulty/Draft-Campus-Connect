<?php
// No real mail delivery is configured. Recovery is disabled by default; the local
// demonstration stores mail for authenticated owners only, never ID/DOB visitors.
declare(strict_types=1);
require_once __DIR__ . '/security.php';
const RESET_MINUTES = 15;
const RESET_MAX_TRIES = 5;
const RESET_ERROR = 'This code or link is invalid or unavailable. Request a new one.';
function sendMail(int $userId, string $subject, string $body): void
{
    $to = one('SELECT user_Email FROM Login WHERE user_ID = ?', [$userId])['user_Email'];
    run('INSERT INTO Email_Message (user_ID, to_Email, subject, body, sent_At) VALUES (?, ?, ?, ?, NOW())', [$userId,$to,$subject,$body]);
}
function inbox(int $userId, int $limit = 50): array
{
    $limit = max(1,min(50,$limit));
    $body = demoRecoveryEnabled() ? 'body' : "CASE WHEN subject = 'Your Campus Connect verification code' THEN 'Recovery content is unavailable. Contact your administrator.' ELSE body END";
    return rows("SELECT message_ID AS id,to_Email AS `to`,subject,$body AS body,DATE_FORMAT(sent_At,'%b %e, %Y %l:%i %p') AS sent,
      read_At IS NULL AS unread FROM Email_Message WHERE user_ID = ? ORDER BY sent_At DESC,message_ID DESC LIMIT $limit",[$userId]);
}
function startReset(int $userId, string $purpose, ?int $adminId = null): void
{
    requireRecoveryDelivery();
    if (!lockResetAccount($userId)) fail(RESET_ERROR);
    run('UPDATE Password_Reset SET expires_At = NOW() WHERE user_ID = ? AND used_At IS NULL AND expires_At > NOW()',[$userId]);
    $code = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
    $token = bin2hex(random_bytes(32));
    run('INSERT INTO Password_Reset (user_ID,purpose,requested_By,code_Hash,token_Hash,created_At,expires_At)
      VALUES (?,?,?,?,?,NOW(),NOW() + INTERVAL '.RESET_MINUTES.' MINUTE)',
        [$userId,$purpose,$adminId,password_hash($code,PASSWORD_DEFAULT),hash('sha256',$token)]);
    sendMail($userId,'Your Campus Connect verification code',
        "Local development recovery demonstration.\n\nYour verification code is: $code\n\n/#reset?token=$token\n\n"
        .'Expires in '.RESET_MINUTES.' minutes. It can complete one password change.');
}
function pendingReset(int $userId): ?array
{
    return one('SELECT * FROM Password_Reset WHERE user_ID = ? AND used_At IS NULL AND expires_At > NOW()
      ORDER BY reset_ID DESC LIMIT 1 FOR UPDATE',[$userId]);
}
function checkCode(int $userId, mixed $code): array
{
    requireRecoveryDelivery();
    $result = transaction(function () use ($userId,$code) {
        if (!lockResetAccount($userId)) return ['error'=>true];
        $reset = pendingReset($userId);
        if (!$reset || $reset['attempts'] >= RESET_MAX_TRIES) return ['error'=>true];
        if (!is_string($code) || !preg_match('/\A[0-9]{6}\z/',$code) || !password_verify($code,$reset['code_Hash'])) {
            run('UPDATE Password_Reset SET attempts = attempts + 1 WHERE reset_ID = ?',[$reset['reset_ID']]);
            return ['error'=>true]; // Commit the increment; throw ONLY outside the transaction.
        }
        return $reset;
    });
    if (isset($result['error'])) fail(RESET_ERROR);
    return $result;
}
function grantReset(array $reset): array
{
    // Forget any authenticated identity before accepting an unauthenticated reset grant.
    clearAuthentication();
    $_SESSION['reset_user'] = (int)$reset['user_ID'];
    $_SESSION['reset_verified'] = (int)$reset['reset_ID'];
    return ['message'=>'Verification accepted. Choose a new password.'];
}
function finishReset(array $form, ?int $mustBeUser = null): array
{
    requireRecoveryDelivery();
    $userId = (int)($_SESSION['reset_user'] ?? 0);
    $resetId = (int)($_SESSION['reset_verified'] ?? 0);
    checkPassword($form['password'] ?? null,$form['confirm'] ?? null);
    $result = transaction(function () use ($form,$userId,$resetId,$mustBeUser) {
        if (!$userId || !$resetId || ($mustBeUser !== null && $mustBeUser !== $userId)) fail(RESET_ERROR);
        if (!lockResetAccount($userId)) fail(RESET_ERROR);
        $reset = pendingReset($userId);
        if (!$reset || (int)$reset['reset_ID'] !== $resetId || $reset['attempts'] >= RESET_MAX_TRIES) fail(RESET_ERROR);
        run('UPDATE Login SET user_Password = ?, no_Of_Tries = 0, lock_Var = 0 WHERE user_ID = ?',
            [password_hash($form['password'],PASSWORD_DEFAULT),$userId]);
        run('UPDATE Password_Reset SET used_At = NOW() WHERE reset_ID = ?',[$resetId]);
        run('UPDATE Password_Reset SET expires_At = NOW() WHERE user_ID = ? AND used_At IS NULL AND expires_At > NOW()',[$userId]);
        sendMail($userId,'Your Campus Connect password was changed','Your password was changed. If you did not do this, contact your administrator.');
        return ['message'=>'Your password was changed. Sign in again with your new password.'];
    });
    clearAuthentication(); // All other sessions fail their credential check on their next request.
    return $result;
}
function requestForgotReset(array $form): array
{
    requireRecoveryDelivery();
    unset($_SESSION['reset_user'],$_SESSION['reset_verified']);
    $name = is_string($form['login'] ?? null) ? trim($form['login']) : '';
    $login = $name === '' ? null : findLogin($name);
    if ($login && ($login['no_Of_Tries'] >= RESET_AFTER || $login['lock_Var'])) {
        transaction(fn()=>startReset((int)$login['user_ID'],'Forgot'));
    }
    return ['message'=>'If the account is eligible, recovery instructions are available through its authenticated local-demo inbox.'];
}
function verifyForgotCode(array $form): array
{
    requireRecoveryDelivery();
    unset($_SESSION['reset_user'],$_SESSION['reset_verified']);
    $name = is_string($form['login'] ?? null) ? trim($form['login']) : '';
    $login = $name === '' ? null : findLogin($name);
    if (!$login) fail(RESET_ERROR);
    return grantReset(checkCode((int)$login['user_ID'],$form['code'] ?? null));
}
function verifyResetLink(array $form): array
{
    requireRecoveryDelivery();
    unset($_SESSION['reset_user'],$_SESSION['reset_verified']);
    $token = $form['token'] ?? null;
    if (!is_string($token) || !preg_match('/\A[0-9a-f]{64}\z/',$token)) fail(RESET_ERROR);
    // Discover the owner before acquiring locks; revalidate under Login -> reset locks.
    $owner = one('SELECT user_ID FROM Password_Reset WHERE token_Hash = ?',[hash('sha256',$token)]);
    if (!$owner) fail(RESET_ERROR);
    $reset = transaction(function () use ($owner,$token) {
        if (!lockResetAccount((int)$owner['user_ID'])) return null;
        $reset = pendingReset((int)$owner['user_ID']);
        return $reset && $reset['attempts'] < RESET_MAX_TRIES && hash_equals($reset['token_Hash'],hash('sha256',$token)) ? $reset : null;
    });
    if (!$reset) fail(RESET_ERROR);
    return grantReset($reset); // Never return the account email from a recovery endpoint.
}
function demoMail(array $form): array
{
    requireRecoveryDelivery();
    $user = authenticatedSession($_SESSION['user'] ?? null);
    if (!$user) fail('Sign in to read your own local-demo inbox. ID and date of birth do not grant access.');
    if (isset($form['user_ID']) && ((!is_int($form['user_ID']) && !is_string($form['user_ID']))
        || (string)$form['user_ID'] !== (string)$user['id'])) fail('Only your own inbox is available.');
    return ['messages'=>inbox((int)$user['id'],20)];
}
function requestPasswordChange(int $userId, array $form): array
{
    requireRecoveryDelivery();
    transaction(function () use ($userId,$form) {
        $login = lockResetAccount($userId);
        if (!$login || !is_string($form['current'] ?? null) || !password_verify($form['current'],$login['user_Password'])) fail('Your current password is not correct.');
        startReset($userId,'Change');
    });
    $_SESSION['reset_user'] = $userId;
    unset($_SESSION['reset_verified']);
    return ['message'=>'A code is available in your authenticated local-demo Inbox.'];
}
function verifyPasswordChangeCode(int $userId, array $form): array
{
    unset($_SESSION['reset_verified']);
    $reset = checkCode($userId,$form['code'] ?? null);
    // Preserve this authenticated identity until completion, which signs it out.
    session_regenerate_id(true);
    $_SESSION['reset_user'] = $userId;
    $_SESSION['reset_verified'] = (int)$reset['reset_ID'];
    return ['message'=>'Code accepted. Choose your new password below.'];
}
function adminSendReset(int $adminId, array $form): array
{
    requireRecoveryDelivery();
    $admin = one('SELECT security_Level FROM Admin WHERE admin_ID = ?',[$adminId]);
    if (!$admin || $admin['security_Level'] !== 'Read-Write') fail('Read-Only admins cannot reset passwords.');
    $name = is_string($form['login'] ?? null) ? trim($form['login']) : '';
    $login = $name === '' ? null : findLogin($name);
    if (!$login) fail('No account matches that email or ID.');
    return transaction(function () use ($adminId,$login) {
        startReset((int)$login['user_ID'],'Admin',$adminId);
        run("INSERT INTO Audit_Log (admin_ID,user_ID,action_Type,field_Name,old_Value,new_Value,date_Time)
          VALUES (?,?,'Update','user_Password',NULL,'Local demo reset requested',NOW())",[$adminId,$login['user_ID']]);
        return ['message'=>'A reset is available in the account owner’s authenticated local-demo Inbox.'];
    });
}
