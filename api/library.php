<?php
/**
 * Library Settings endpoint — the administrative control plane.
 *
 * Actions
 *   GET  ?action=status                     admin  → full status incl. path
 *   GET  ?action=drives                     admin  → drive list (probed, no shell)
 *   GET  ?action=browse&path=X              admin  → directories under X (dirs only)
 *   POST ?action=set      {path}            admin+CSRF → validate & save
 *   POST ?action=native_pick                admin+CSRF+localhost → Windows folder dialog
 *   POST ?action=reset                      admin+CSRF → back to default folder
 *   POST ?action=scan                       admin+CSRF → force rescan, return counts
 *   POST ?action=login    {password}        public (throttled) → start admin session
 *   POST ?action=logout                     admin  → end session
 *   GET  ?action=whoami                     public → {admin: bool} for UI state
 *
 * Security: directory changes are administrative. Every mutating action
 * requires an authenticated admin session AND a session-bound CSRF token.
 * Browse responses contain directory names and canonical paths only —
 * no file listings, no file contents. The only command execution in the
 * project is native_pick's FIXED powershell call (no request data in the
 * command line), further restricted to requests from the host machine.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/native_picker.php';

auth_boot();

$action = (string)($_GET['action'] ?? 'status');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------------- login / logout / whoami ---------------- */

if ($action === 'login' && $method === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    $password = is_array($payload) ? (string)($payload['password'] ?? '') : '';
    if ($password === '') {
        api_error('Enter the administrator password.', 400);
    }
    if (!auth_password_set()) {
        // First run: allow creating the password (only while none exists).
        if (auth_setup($password)) {
            auth_login($password);
            json_response(['ok' => true, 'created' => true, 'csrf' => auth_csrf_token()]);
        }
        api_error('Password must be at least 8 characters.', 400);
    }
    if (auth_login($password)) {
        json_response(['ok' => true, 'created' => false, 'csrf' => auth_csrf_token()]);
    }
    api_error('Sign-in failed. Too many attempts will lock this device out for 15 minutes.', 401, ['code' => 'bad_credentials']);
}

if ($action === 'logout' && $method === 'POST') {
    require_admin(true);
    auth_logout();
    json_response(['ok' => true]);
}

if ($action === 'whoami') {
    auth_boot();
    $admin = auth_is_admin();
    json_response([
        'ok'          => true,
        'admin'       => $admin,
        'setup_needed'=> !auth_password_set(),
        'csrf'        => $admin ? auth_csrf_token() : null,
    ]);
}

/* ---------------- everything below is admin-gated ---------------- */

if ($action === 'status') {
    require_admin(false);
    $lib = library_status();
    $scan = json_read(DIR_DATA . DIRECTORY_SEPARATOR . 'scan_cache.json');
    $lastScan = !empty($scan['scanned_at']) ? date('c', (int)$scan['scanned_at']) : null;
    $movieCount = null;
    if (!empty($scan['scanned_at']) && empty($scan['error'])) {
        $movieCount = count($scan['movies'] ?? []);
    }
    // Live count when available and no fresh cache exists.
    if ($lib['available'] && $movieCount === null) {
        $fresh = scan_library(false);
        if (empty($fresh['error'])) {
            $movieCount = count($fresh['movies']);
            $lastScan = date('c', (int)$fresh['scanned_at']);
        }
    }
    json_response([
        'ok'          => true,
        'csrf'        => auth_csrf_token(),
        'library'     => $lib,                       // includes full path (admin-only)
        'default'     => DIR_MOVIES,
        'last_scan'   => $lastScan,
        'movie_count' => $movieCount,
        'counts'      => $scan['counts'] ?? null,
        'is_host'     => picker_request_is_host(),   // Windows folder dialog allowed?
    ]);
}

if ($action === 'drives') {
    require_admin(false);
    json_response(['ok' => true, 'drives' => folder_browser_drives()]);
}

if ($action === 'browse') {
    require_admin(false);
    $path = (string)($_GET['path'] ?? '');
    if ($path === '') {
        // Default view: drive list folded into the browse response.
        json_response(['ok' => true, 'path' => null, 'parent' => null, 'dirs' => [], 'drives' => folder_browser_drives()]);
    }
    $result = folder_browser_list($path);
    if ($result['error'] !== null) {
        $msg = $result['error'] === 'unreadable'
            ? 'This folder cannot be read.'
            : 'Folder not found. It may be on a drive that is no longer connected.';
        api_error($msg, 404, ['code' => $result['error']]);
    }
    json_response(['ok' => true, 'drives' => [], 'path' => $result['path'], 'parent' => $result['parent'], 'dirs' => $result['dirs']]);
}

if ($method !== 'POST') {
    api_error('Method not allowed.', 405);
}

/* ---------------- mutations: admin session + CSRF ---------------- */

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = [];
}

if ($action === 'set') {
    require_admin(true);
    $path = (string)($payload['path'] ?? '');
    if ($path === '') {
        api_error('No folder path was provided.', 400);
    }
    $error = set_movies_dir($path);
    if ($error !== null) {
        $messages = [
            'invalid_path'        => 'That is not a valid absolute folder path.',
            'drive_not_available' => 'That drive is not currently connected. Is the device plugged in?',
            'path_not_found'      => 'The folder does not exist (or its drive is disconnected).',
            'not_a_directory'     => 'The selected path is a file, not a folder.',
            'unreadable'          => 'The folder exists but cannot be read. Check its permissions.',
            'denied_location'     => 'Windows system folders cannot be used as the movie library.',
            'config_write_failed' => 'The setting could not be saved. Check that data/ is writable.',
        ];
        api_error($messages[$error] ?? 'The folder could not be selected.', 400, ['code' => $error]);
    }
    $lib = library_status();
    $fresh = scan_library(true);
    json_response([
        'ok'          => true,
        'library'     => $lib,
        'scanned'     => empty($fresh['error']),
        'scan_error'  => $fresh['error'] ?? null,
        'movie_count' => empty($fresh['error']) ? count($fresh['movies']) : null,
        'counts'      => $fresh['counts'] ?? null,
        'last_scan'   => empty($fresh['error']) ? date('c', (int)$fresh['scanned_at']) : null,
    ]);
}

if ($action === 'native_pick') {
    require_admin(true);
    if (!picker_request_is_host()) {
        api_error(
            'The Windows folder picker can only be opened from this PC. On a phone or TV, use the folder list instead.',
            403,
            ['code' => 'host_only']
        );
    }
    $picked = native_folder_picker(120);
    if ($picked['cancelled']) {
        json_response(['ok' => true, 'cancelled' => true]);
    }
    if (!$picked['ok']) {
        $http = $picked['code'] === 'picker_timeout' ? 504 : 502;
        api_error($picked['message'], $http, ['code' => $picked['code']]);
    }
    json_response(['ok' => true, 'cancelled' => false, 'path' => $picked['path']]);
}

if ($action === 'reset') {
    require_admin(true);
    reset_movies_dir();
    $fresh = scan_library(true);
    json_response([
        'ok'          => true,
        'library'     => library_status(),
        'scanned'     => empty($fresh['error']),
        'scan_error'  => $fresh['error'] ?? null,
        'movie_count' => empty($fresh['error']) ? count($fresh['movies']) : null,
        'counts'      => $fresh['counts'] ?? null,
        'last_scan'   => empty($fresh['error']) ? date('c', (int)$fresh['scanned_at']) : null,
    ]);
}

if ($action === 'scan') {
    require_admin(true);
    if (!is_dir(movies_dir())) {
        api_error('The selected library folder is not available. Check that its drive is connected.', 503, ['code' => 'library_unavailable']);
    }
    $fresh = scan_library(true);
    if (!empty($fresh['error'])) {
        api_error('Scan failed: the library folder cannot be read.', 503, ['code' => $fresh['error']]);
    }
    json_response([
        'ok'          => true,
        'movie_count' => count($fresh['movies']),
        'counts'      => $fresh['counts'],
        'last_scan'   => date('c', (int)$fresh['scanned_at']),
        'unmatched_subtitles' => $fresh['unmatched_subtitles'],
    ]);
}

api_error('Unknown action.', 400);
