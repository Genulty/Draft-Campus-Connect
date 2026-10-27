<?php
// Campus Connect API. Every request to /api/... comes here (nginx + PHP-FPM on the server,
// or PHP's built-in server for local testing) and is answered with JSON.
//
//   Local testing:  php -S 127.0.0.1:3000 -t public api/index.php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// With PHP's built-in server, let it serve the website's files (public/) itself.
if (PHP_SAPI === 'cli-server' && !str_starts_with($path, '/api/')) {
    return false;
}

require __DIR__ . '/db.php';
require __DIR__ . '/actions.php';
require __DIR__ . '/accounts.php';
require __DIR__ . '/student.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/views.php';

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Unexpected errors: log the details for the server, show a plain message to the user.
set_exception_handler(function (Throwable $e) {
    if ($e instanceof RuleError) respond(422, ['error' => $e->getMessage()]);
    error_log('Campus Connect: ' . $e);
    respond(500, ['error' => 'Something went wrong. Please try again.']);
});

// Sessions: a cookie scripts can't read, kept for 8 hours, sent only over HTTPS when the site uses it.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
ini_set('session.gc_maxlifetime', '28800');
session_name('campus_connect');
session_set_cookie_params(['lifetime' => 28800, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
session_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($body)) $body = [];
$user = $_SESSION['user'] ?? null;

function requireUser(?array $user): array
{
    if (!$user) respond(401, ['error' => 'Not logged in.']);
    return $user;
}

// ---------- Routes ----------
switch (true) {
    // Sign in / sign out / who am I
    case $method === 'POST' && $path === '/api/login':
        [$status, $response] = signIn($body);
        respond($status, $response);

    case $method === 'POST' && $path === '/api/logout':
        $_SESSION = [];
        session_destroy();
        respond(200, ['ok' => true]);

    case $method === 'GET' && $path === '/api/me':
        respond(200, ['user' => requireUser($user)]);

    // Welcome page (numbers and the master schedule) and password reset (public). New accounts are created by an admin (create-user below).
    case $method === 'GET' && $path === '/api/welcome':
        respond(200, welcomeInfo());

    case $method === 'GET' && $path === '/api/master-schedule':
        respond(200, ['blocks' => masterSchedule($_GET)]);

    case $method === 'GET' && $path === '/api/major-browser':
        respond(200, ['blocks' => publicMajorBrowser($_GET)]);

    // Forgot password: email a code, check the code (or the emailed link), then choose a new password.
    case $method === 'POST' && $path === '/api/reset/request':
        respond(200, requestForgotReset($body));

    case $method === 'POST' && $path === '/api/reset/verify':
        respond(200, isset($body['token']) ? verifyResetLink($body) : verifyForgotCode($body));

    case $method === 'POST' && $path === '/api/reset/complete':
        respond(200, finishReset($body));

    // Campus mail for signed-out users (opened with ID + date of birth): this is a demo, mail is not really sent.
    case $method === 'POST' && $path === '/api/demo-mail':
        respond(200, demoMail($body));

    // Dashboard pages for the signed-in user's role
    case $method === 'GET' && $path === '/api/views':
        respond(200, ['views' => viewsFor(requireUser($user)['role'])]);

    case $method === 'GET' && preg_match('#^/api/views/([a-z-]+)$#', $path, $m) === 1:
        $page = buildView(requireUser($user), $m[1], $_GET);
        if ($page === null) respond(404, ['error' => 'That page is not available for your role.']);
        respond(200, $page);

    // Actions that change the database, each limited to the roles allowed to make them
    case $method === 'POST' && preg_match('#^/api/actions/([a-z-]+)$#', $path, $m) === 1:
        $user = requireUser($user);
        $everyone = ['Student', 'Faculty', 'Admin', 'StatDept'];
        $allowed = [
            'add-section' => ['Student'], 'drop-section' => ['Student'],
            'add-major' => ['Student'], 'change-major' => ['Student'], 'drop-major' => ['Student'],
            'add-minor' => ['Student'], 'change-minor' => ['Student'], 'drop-minor' => ['Student'],
            'update-address' => $everyone, 'request-password-change' => $everyone,
            'verify-password-code' => $everyone, 'complete-password-change' => $everyone,
            'create-section' => ['Admin'], 'create-user' => ['Admin'], 'send-password-reset' => ['Admin'],
        ];
        if (!in_array($user['role'], $allowed[$m[1]] ?? [], true)) respond(403, ['error' => 'Your role cannot do that.']);
        respond(200, match ($m[1]) {
            'add-section' => addSection($user['id'], (int) ($body['CRN'] ?? 0)),
            'drop-section' => dropSection($user['id'], (int) ($body['CRN'] ?? 0)),
            'add-major' => addMajor($user['id'], $body),
            'change-major' => changeMajor($user['id'], $body),
            'drop-major' => dropMajor($user['id'], $body),
            'add-minor' => addMinor($user['id'], $body),
            'change-minor' => changeMinor($user['id'], $body),
            'drop-minor' => dropMinor($user['id'], $body),
            'update-address' => updateAddress($user['id'], $body),
            'request-password-change' => requestPasswordChange($user['id'], $body),
            'verify-password-code' => verifyPasswordChangeCode($user['id'], $body),
            'complete-password-change' => finishReset($body, $user['id']),
            'create-section' => createSection($user['id'], $body),
            'create-user' => createUser($user['id'], $body),
            'send-password-reset' => adminSendReset($user['id'], $body),
        });

    default:
        respond(404, ['error' => 'Not found.']);
}
