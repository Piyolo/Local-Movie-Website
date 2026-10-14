<?php
/**
 * Local administrator authorization for state-changing library actions.
 *
 * Threat model: personal app on a trusted home LAN over plain HTTP.
 *  - Reading the library: allowed to every LAN client (by design).
 *  - Changing the library directory, browsing the server filesystem,
 *    logging in: require an administrator session (password + CSRF).
 *
 * This is password authentication, NOT network isolation: hiding the
 * settings button is UX only — every protected endpoint verifies server-side.
 *
 * Password storage: data/auth.json (web-denied) holding a password_hash().
 * First run (no password yet) allows a one-time setup call.
 */
declare(strict_types=1);

if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}

function auth_file(): string
{
    return DIR_DATA . DIRECTORY_SEPARATOR . 'auth.json';
}

function auth_throttle_file(): string
{
    return DIR_DATA . DIRECTORY_SEPARATOR . 'login_throttle.json';
}

/** Start a hardened session once per request (idempotent). */
function auth_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $inCli = PHP_SAPI === 'cli';
    if ($inCli) {
        return; // unit tests run stateless
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => false, // plain-HTTP LAN; 'secure' would break login
    ]);
    session_name('mwadmin');
    session_start();
}

function auth_password_set(): bool
{
    $data = json_read(auth_file());
    return !empty($data['password_hash']) && is_string($data['password_hash']);
}

/** One-time setup: create the admin password (only when none exists). */
function auth_setup(string $password): bool
{
    if (auth_password_set()) {
        return false;
    }
    if (strlen($password) < 8) {
        return false;
    }
    return json_write(auth_file(), [
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at'    => time(),
    ]);
}

/** Simple per-IP throttle: max 5 failed attempts per 15 minutes. */
function auth_throttled(string $ip): bool
{
    $t = json_read(auth_throttle_file());
    $now = time();
    $entry = $t[$ip] ?? ['fails' => 0, 'window_start' => $now];
    if ($now - (int)$entry['window_start'] > 900) {
        return false; // window expired
    }
    return (int)$entry['fails'] >= 5;
}

function auth_throttle_fail(string $ip): void
{
    $now = time();
    json_update(auth_throttle_file(), function (array $t) use ($ip, $now) {
        $entry = $t[$ip] ?? ['fails' => 0, 'window_start' => $now];
        if ($now - (int)$entry['window_start'] > 900) {
            $entry = ['fails' => 0, 'window_start' => $now];
        }
        $entry['fails']++;
        $t[$ip] = $entry;
        return $t;
    });
}

function auth_throttle_clear(string $ip): void
{
    json_update(auth_throttle_file(), function (array $t) use ($ip) {
        unset($t[$ip]);
        return $t;
    });
}

function auth_client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Verify the admin password and start an authenticated session. */
function auth_login(string $password): bool
{
    auth_boot();
    $ip = auth_client_ip();
    if (auth_throttled($ip)) {
        return false;
    }
    $data = json_read(auth_file());
    $hash = $data['password_hash'] ?? '';
    if ($hash === '' || !password_verify($password, $hash)) {
        auth_throttle_fail($ip);
        return false;
    }
    auth_throttle_clear($ip);
    session_regenerate_id(true);
    $_SESSION['library_admin'] = true;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['admin_since'] = time();
    return true;
}

function auth_logout(): void
{
    auth_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

function auth_is_admin(): bool
{
    auth_boot();
    return !empty($_SESSION['library_admin']);
}

/** Issue a CSRF token to an authenticated admin session. */
function auth_csrf_token(): string
{
    auth_boot();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Validate the CSRF token on a state-changing request. */
function auth_csrf_ok(): bool
{
    auth_boot();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    return is_string($sent) && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

/**
 * Gate a state-changing admin endpoint: dies with a structured 401/403 JSON.
 * $requireCsrf = true for POST/DELETE actions.
 */
function require_admin(bool $requireCsrf = true): void
{
    if (!auth_is_admin()) {
        api_error('Administrator sign-in required.', 401, ['code' => 'auth_required']);
    }
    if ($requireCsrf && !auth_csrf_ok()) {
        api_error('Invalid or missing security token. Reload the page and try again.', 403, ['code' => 'csrf']);
    }
}
