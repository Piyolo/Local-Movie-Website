<?php
/**
 * Restricted server-side folder navigator (admin-only, used by the
 * Library Settings dialog).
 *
 * Design constraints:
 *  - lists DIRECTORIES only — never file contents, never reads files
 *  - only existing, canonicalised paths under a drive root; ".." rejected
 *  - Windows system locations are hidden and can never be selected
 *  - fixed + removable drives are probed without executing any command
 *  - callers must already be authenticated (api/library.php enforces this)
 */
declare(strict_types=1);

if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}

/** Directory names hidden everywhere during navigation. */
function folder_browser_hidden_names(): array
{
    return [
        'windows', 'program files', 'program files (x86)', 'programdata',
        '$recycle.bin', 'system volume information', 'recovery',
        '.git', '.svn', '.trash', '$windows.~bt', '$windows.~ws',
        'appdata', 'msocache', 'documents and settings',
    ];
}

/**
 * Enumerate local drives by probing letters — no shell, no exec().
 * @return array<int, array{letter:string, path:string, ready:bool, total:?int, free:?int}>
 */
function folder_browser_drives(): array
{
    $drives = [];
    foreach (range('C', 'Z') as $letter) {
        $root = $letter . ':\\';
        $ready = is_dir($root);
        $total = null;
        $free = null;
        if ($ready) {
            // disk_total_space works on Windows drive roots; guard warnings.
            $t = @disk_total_space($root);
            $f = @disk_free_space($root);
            $total = $t === false ? null : (int)$t;
            $free = $f === false ? null : (int)$f;
        }
        if ($ready || $letter === 'C') {
            $drives[] = ['letter' => $letter, 'path' => $root, 'ready' => $ready, 'total' => $total, 'free' => $free];
        }
    }
    return $drives;
}

/**
 * Validate a navigation target (admin-supplied absolute path).
 * @return string|null canonical path or null when rejected
 */
function folder_browser_resolve(string $path): ?string
{
    if (!library_path_syntax_valid($path)) {
        return null;
    }
    $path = library_normalize_win_path($path);
    $canonical = realpath($path);
    if ($canonical === false || !is_dir($canonical)) {
        return null;
    }
    $canonical = library_normalize_win_path((string)$canonical);
    $lower = strtolower($canonical);
    foreach (library_denied_prefixes() as $deny) {
        if ($lower === $deny || strpos($lower, $deny . '\\') === 0) {
            return null;
        }
    }
    // Must remain under a drive root (or UNC root) — always true after realpath,
    // but double-check no traversal survived.
    if (strpos($canonical, '\\..') !== false) {
        return null;
    }
    return $canonical;
}

/**
 * List subdirectories of a validated path (names + canonical child paths).
 * @return array{path:string, parent:?string, dirs:array, error:?string}
 */
function folder_browser_list(string $path): array
{
    $canonical = folder_browser_resolve($path);
    if ($canonical === null) {
        return ['path' => $path, 'parent' => null, 'dirs' => [], 'error' => 'path_not_found'];
    }

    $entries = @scandir($canonical);
    if ($entries === false) {
        return ['path' => $canonical, 'parent' => null, 'dirs' => [], 'error' => 'unreadable'];
    }

    $hidden = folder_browser_hidden_names();
    $dirs = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if ($entry[0] === '$' || $entry[0] === '.') {
            continue;
        }
        if (in_array(strtolower($entry), $hidden, true)) {
            continue;
        }
        $childAbs = $canonical . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($childAbs)) {
            continue; // directories only — files are never listed
        }
        // Hide directories the current user cannot read (would fail to scan).
        if (!is_readable($childAbs)) {
            continue;
        }
        $dirs[] = [
            'name' => $entry,
            'path' => $childAbs,
        ];
    }
    usort($dirs, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

    // Parent navigation (never above a drive root).
    $parent = null;
    $trimmed = rtrim($canonical, '\\');
    $slash = strrpos($trimmed, '\\');
    if ($slash !== false && $slash >= 2) {
        $candidate = substr($trimmed, 0, $slash);
        if (preg_match('#^[A-Za-z]:$#', $candidate)) {
            $candidate .= '\\';
        }
        if (folder_browser_resolve($candidate) !== null) {
            $parent = $candidate;
        }
    }

    return ['path' => $canonical, 'parent' => $parent, 'dirs' => $dirs, 'error' => null];
}
