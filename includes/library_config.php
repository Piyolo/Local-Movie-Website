<?php
/**
 * Active library configuration — one central place that decides which
 * directory the scanner, streamer, and subtitle resolver treat as the
 * movie library root.
 *
 * Persistence: data/library.json (inside the web-denied data/ directory).
 *   { "path": "E:\\My Movie Collection", "source": "custom", "set_at": 1712... }
 * When the file is absent the default (assets/movies inside the project) is
 * used. A saved path is NEVER silently discarded — if its drive is unplugged
 * the path stays saved and the app reports "library unavailable" instead.
 */
declare(strict_types=1);

if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}

function library_config_file(): string
{
    return DIR_DATA . DIRECTORY_SEPARATOR . 'library.json';
}

/** Windows system locations that may never become the library root. */
function library_denied_prefixes(): array
{
    static $deny = null;
    if ($deny === null) {
        $win = getenv('WINDIR') ?: 'C:\\Windows';
        $deny = [
            strtolower(rtrim(str_replace('/', '\\', $win), '\\')),                       // C:\Windows
            'c:\\program files',
            'c:\\program files (x86)',
            'c:\\programdata',
            'c:\\$recycle.bin',
            'c:\\system volume information',
            'c:\\recovery',
        ];
    }
    return $deny;
}

/** Directory names never descended into during a scan (any library root). */
function library_scan_skip_dirs(): array
{
    return [
        'windows', 'program files', 'program files (x86)', 'programdata',
        '$recycle.bin', 'system volume information', 'recovery', '.git',
        'node_modules', '$windows.~bt', '$windows.~ws',
    ];
}

/**
 * Resolve the active movie library directory (absolute, backslashes).
 * Falls back to the default only when no valid custom entry exists —
 * never because a drive is temporarily missing.
 */
function movies_dir(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }
    $entry = json_read(library_config_file());
    $path = isset($entry['path']) && is_string($entry['path']) ? trim($entry['path']) : '';
    if ($path !== '' && library_path_syntax_valid($path)) {
        $resolved = library_normalize_win_path($path);
        return $resolved;
    }
    $resolved = DIR_MOVIES; // default: <project>/assets/movies
    return $resolved;
}

/**
 * Normalise a Windows path: forward→backslashes, drive roots keep a trailing
 * slash ("C:" means current-working-directory on Windows and is NEVER valid
 * as a stored path), everything else loses its trailing slash.
 */
function library_normalize_win_path(string $p): string
{
    $p = trim(str_replace('/', '\\', $p));
    if (preg_match('#^[A-Za-z]:\\\\?$#', $p)) {
        return strtoupper($p[0]) . ':\\';
    }
    return rtrim($p, '\\');
}

/** true when the active library differs from the shipped default. */
function movies_dir_is_custom(): bool
{
    $entry = json_read(library_config_file());
    return isset($entry['source']) && $entry['source'] === 'custom'
        && is_string($entry['path'] ?? null) && trim($entry['path']) !== '';
}

/**
 * Full status of the active library — never throws, never resets config.
 * @return array{path:string, source:string, exists:bool, readable:bool,
 *               available:bool, is_default:bool, set_at:?int}
 */
function library_status(): array
{
    $path = movies_dir();
    $exists = is_dir($path);
    $readable = $exists && is_readable($path);
    $entry = json_read(library_config_file());
    return [
        'path'      => $path,
        'source'    => movies_dir_is_custom() ? 'custom' : 'default',
        'exists'    => $exists,
        'readable'  => $readable,
        'available' => $exists && $readable,
        'is_default'=> strcasecmp(rtrim($path, '\\/'), rtrim(DIR_MOVIES, '\\/')) === 0,
        'set_at'    => isset($entry['set_at']) ? (int)$entry['set_at'] : null,
    ];
}

/**
 * Syntactic validation only (no filesystem access): absolute Windows drive
 * path or UNC path, no traversal, no null bytes, sane length.
 */
function library_path_syntax_valid(string $path): bool
{
    $path = trim($path);
    if ($path === '' || strlen($path) > 260 || strpos($path, "\0") !== false) {
        return false;
    }
    // No traversal segments anywhere.
    $normalized = str_replace('/', '\\', $path);
    foreach (explode('\\', $normalized) as $seg) {
        if ($seg === '..' || $seg === '.') {
            return false;
        }
    }
    // Drive letter: C:\...   or UNC: \\server\share\...
    if (preg_match('#^[A-Za-z]:\\\\#', $normalized)) {
        return true;
    }
    if (strpos($normalized, '\\\\') === 0) {
        return true;
    }
    return false;
}

/**
 * Full server-side validation of a candidate library path.
 * Returns null on success (canonicalised path) or a public error code:
 *   invalid_path | path_not_found | not_a_directory | unreadable |
 *   denied_location
 */
function library_validate_path(string $path): ?string
{
    if (!library_path_syntax_valid($path)) {
        return 'invalid_path';
    }
    $path = str_replace('/', '\\', trim($path));
    if (preg_match('#^[A-Za-z]:$#', $path)) {
        $path .= '\\'; // drive root keeps its slash: "C:" would mean CWD
    } else {
        $path = rtrim($path, '\\');
    }

    $canonical = realpath($path); // resolves junctions/symlinks; false if missing
    if ($canonical === false) {
        // Distinguish a missing drive (E:\ not present) from a missing folder.
        $driveLetter = strtoupper($path[0] ?? '');
        if ($driveLetter !== '' && preg_match('#^[A-Z]$#', $driveLetter) && !is_dir($driveLetter . ':\\')) {
            return 'drive_not_available';
        }
        return 'path_not_found';
    }
    $canonical = library_normalize_win_path((string)str_replace('/', '\\', $canonical));

    if (!is_dir($canonical)) {
        return 'not_a_directory';
    }
    if (!is_readable($canonical)) {
        return 'unreadable';
    }

    $lower = strtolower($canonical);
    foreach (library_denied_prefixes() as $deny) {
        if ($lower === $deny || strpos($lower, $deny . '\\') === 0) {
            return 'denied_location';
        }
    }
    return null;
}

/**
 * Persist a validated library path. Returns null on success or a public
 * error code (same vocabulary as library_validate_path).
 */
function set_movies_dir(string $path): ?string
{
    $error = library_validate_path($path);
    if ($error !== null) {
        return $error;
    }
    $canonical = library_normalize_win_path((string)realpath(trim($path)));
    $ok = json_write(library_config_file(), [
        'path'    => $canonical,
        'source'  => 'custom',
        'set_at'  => time(),
        'set_by'  => 'library_settings',
    ]);
    if (!$ok) {
        return 'config_write_failed';
    }
    // New root ⇒ old scan cache is meaningless.
    @unlink(DIR_DATA . DIRECTORY_SEPARATOR . 'scan_cache.json');
    return null;
}

/** Switch back to the shipped default folder. */
function reset_movies_dir(): bool
{
    $ok = json_write(library_config_file(), [
        'path'    => DIR_MOVIES,
        'source'  => 'default',
        'set_at'  => time(),
        'set_by' => 'library_settings',
    ]);
    @unlink(DIR_DATA . DIRECTORY_SEPARATOR . 'scan_cache.json');
    return $ok;
}
