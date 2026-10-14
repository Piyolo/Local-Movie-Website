<?php
/**
 * MovieWebsite — security helpers.
 *
 * Threat model: trusted home LAN (no internet exposure, no router port forwarding).
 * LAN restriction is NOT authentication — anyone on the network can reach the site.
 * These helpers ensure a client can never read or execute anything outside the
 * configured library, and that errors never leak filesystem paths.
 */
declare(strict_types=1);

if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}

/**
 * Resolve a movie path safely INSIDE THE ACTIVE LIBRARY root.
 * Accepts a library-relative path; returns the absolute path, or null when
 * the path escapes the root, the library drive is missing, or the file is
 * not a regular file. Absolute client paths are always rejected.
 */
function safe_movie_path(string $relativePath): ?string
{
    $relativePath = str_replace('\\', '/', $relativePath);
    if ($relativePath === '' || strpos($relativePath, "\0") !== false) {
        return null;
    }
    if ($relativePath[0] === '/' || preg_match('#^[A-Za-z]:#', $relativePath)) {
        return null; // absolute paths from clients are always rejected
    }
    if (strpos($relativePath, '..') !== false) {
        return null;
    }

    $root = realpath(movies_dir()); // false when the drive is disconnected
    if ($root === false) {
        return null;
    }
    $candidate = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if ($candidate === false) {
        return null;
    }

    // Canonical containment check (realpath resolves symlinks/junctions).
    $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($candidate, $rootPrefix) !== 0 && $candidate !== $root) {
        return null;
    }
    if (!is_file($candidate)) {
        return null;
    }
    return $candidate;
}

/** Validate a movie id (40-char sha1 hex) coming from any client. */
function valid_movie_id(?string $id): bool
{
    return is_string($id) && preg_match('/^[a-f0-9]{40}$/', $id) === 1;
}

/** Validate a cache file key (basename only, from our config). */
function safe_data_file(string $name): string
{
    $base = basename($name);
    if ($base !== $name || strpos($base, '..') !== false || !preg_match('/^[A-Za-z0-9._-]+\.json$/', $base)) {
        http_response_code(400);
        exit('Bad request');
    }
    return DIR_DATA . DIRECTORY_SEPARATOR . $base;
}

/**
 * Generic JSON error helper that never reveals filesystem paths.
 */
function api_error(string $publicMessage, int $status = 400, array $extra = []): void
{
    json_response(array_merge(['ok' => false, 'error' => $publicMessage], $extra), $status);
}

/**
 * Reject direct HTTP hits on internal includes (defence in depth — the
 * includes/.htaccess already denies them; this holds even if overrides fail).
 */
function assert_not_direct_access(): void
{
    if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
        http_response_code(404);
        exit('Not found');
    }
}
