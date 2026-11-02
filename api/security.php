<?php
// Session revocation and explicit local-only recovery demonstration settings.
declare(strict_types=1);
function demoRecoveryEnabled(): bool
{
    // The class server turns campus-mail recovery on in /etc/campus-connect/config.php
    // ('campus_mail_recovery' => true, written by deploy/lightsail-setup.sh).
    if ((config()['campus_mail_recovery'] ?? false) === true) return true;
    return getenv('APP_ENV') === 'development' && getenv('CAMPUS_DEMO_RECOVERY') === '1'
        && (PHP_SAPI === 'cli' || (PHP_SAPI === 'cli-server'
            && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)));
}
function requireRecoveryDelivery(): void
{
    if (!demoRecoveryEnabled()) fail('Password recovery delivery is unavailable. Contact your administrator.');
}
function credentialFingerprint(string $hash): string { return hash('sha256', $hash); }
function clearAuthentication(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
}
// Legacy sessions without a credential binding must sign in again. Never put the
// password hash or its fingerprint into API responses, browser storage, or logs.
function authenticatedSession(?array $user): ?array
{
    $fingerprint = $_SESSION['credential_fingerprint'] ?? null;
    if (!$user || !is_string($fingerprint) || !isset($user['id'],$user['role'])) {
        clearAuthentication(); return null;
    }
    $login = one('SELECT l.user_Password, l.lock_Var, l.user_Type AS login_role, u.user_Type AS account_role
      FROM Login l JOIN User u ON u.user_ID = l.user_ID WHERE l.user_ID = ?', [$user['id']]);
    if (!$login || $login['lock_Var'] || $login['login_role'] !== $user['role'] || $login['account_role'] !== $user['role']
        || !hash_equals(credentialFingerprint($login['user_Password']), $fingerprint)) {
        clearAuthentication(); return null;
    }
    return $user;
}
// All reset writers lock Login first, then Password_Reset. A current locking read
// serializes issuance, verification, and consumption across independent sessions.
function lockResetAccount(int $userId): ?array
{
    if (!db()->inTransaction()) throw new LogicException('Reset account lock requires a transaction.');
    return one('SELECT user_ID, user_Password, lock_Var FROM Login WHERE user_ID = ? FOR UPDATE', [$userId]);
}
