<?php
/**
 * MovieWebsite — helper utilities shared by pages and API endpoints.
 */
declare(strict_types=1);

if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}

/**
 * Base URL path of the application, e.g. "/MovieWebsite".
 * Derived from the executed script so the app works on any host/port
 * (localhost, 192.168.x.x) and under any docroot alias — never hardcoded.
 */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = str_replace('\\', '/', dirname($script));
    if (basename($dir) === 'api') {
        $dir = dirname($dir);
    }
    $dir = rtrim($dir, '/');
    $base = ($dir === '/' || $dir === '.' || $dir === '') ? '' : $dir;
    return $base;
}

/** Build an application-relative URL: url('watch.php', ['id' => $id]). */
function url(string $path, array $query = []): string
{
    $u = base_url() . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

/**
 * URL for a CSS/JS asset with a file-mtime version parameter.
 * TV browsers cache aggressively; changing content otherwise keeps serving
 * stale player code until the user clears the cache.
 */
function asset_url(string $path): string
{
    $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));
    $v = @filemtime($abs);
    return url($path, ['v' => (string)($v !== false ? $v : 0)]);
}

/** Percent-encode one path segment (filenames with spaces/unicode/#/%). */
function encode_segment(string $s): string
{
    return rawurlencode($s);
}

/** HTML-escape for safe output. */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Stable internal identifier for a library file (path-derived, lowercase-normalised). */
function movie_id(string $relativePath): string
{
    $normalized = strtolower(str_replace('\\', '/', ltrim($relativePath, '/')));
    return sha1($normalized);
}

/** 1600000000 -> "2 h 40 min" ; null-safe. */
function format_runtime(?int $minutes): string
{
    if (!$minutes || $minutes <= 0) {
        return '';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h === 0) {
        return $m . ' min';
    }
    return $h . ' h ' . $m . ' min';
}

/** Human file size. */
function format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float)$bytes;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    return ($v >= 100 || $i === 0 ? number_format($v, 0) : number_format($v, 1)) . ' ' . $units[$i];
}

/** JSON response helper for API endpoints. */
function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    // API responses are per-user state — keep proxies from caching them.
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Public projection of one subtitle track (no filesystem paths exposed). */
function track_summary(array $track): array
{
    return [
        'id'        => $track['id'],
        'label'     => $track['label'],
        'code'      => $track['code'],
        'ext'       => $track['ext'],
        'filename'  => $track['filename'],
        'supported' => $track['supported'],
        'flags'     => $track['flags'] ?? [],
        'match'     => $track['match'] ?? '',
    ];
}

/** Standard movie summary shape returned to every client (pages + JS). */
function movie_summary(array $movie, ?array $meta = null): array
{
    global $CONFIG;
    $meta = $meta ?? [];
    $tracks = array_map('track_summary', $movie['subtitles'] ?? []);
    return [
        'id'          => $movie['id'],
        'title'       => $meta['title'] ?: $movie['title'],
        'year'        => $meta['year'] ?: $movie['year'],
        'filename'    => $movie['filename'],
        'ext'         => $movie['ext'],
        'size'        => $movie['size'],
        'size_human'  => format_bytes((int)$movie['size']),
        'mtime'       => $movie['mtime'],
        'added'       => date('c', (int)$movie['mtime']),
        'poster'      => !empty($meta['poster'])  ? url($meta['poster'])  : null,
        'backdrop'    => !empty($meta['backdrop'])? url($meta['backdrop']): null,
        'overview'    => $meta['overview'] ?? '',
        'genres'      => $meta['genres'] ?? [],
        'runtime'     => $meta['runtime'] ?? null,
        'rating'      => $meta['rating'] ?? null,
        'tmdb_id'     => $meta['tmdb_id'] ?? null,
        'meta_status' => $meta['status'] ?? 'pending', // pending|ok|not_found|no_key|offline|error|overridden
        'subtitles'   => $tracks,
        'subtitle_count' => count($tracks),
        'browser_plausible' => in_array($movie['ext'], $CONFIG['browser_plausible'], true),
        'watch_url'   => url('watch.php', ['id' => $movie['id']]),
        'details_url' => url('movie.php', ['id' => $movie['id']]),
    ];
}
