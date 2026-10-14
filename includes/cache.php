<?php
/**
 * JSON file cache helpers — atomic writes, shared read/modify/write lock.
 * Used for: metadata cache, overrides, playback progress, scan cache.
 */
declare(strict_types=1);

if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}

/** Read a JSON object from disk. Corrupt/missing files yield $default. */
function json_read(string $file, array $default = []): array
{
    if (!is_file($file)) {
        return $default;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $default; // tolerate corruption rather than crashing the app
    }
    return $data;
}

/** Atomically write a JSON object (write to temp file, then rename). */
function json_write(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    $tmp = $dir . DIRECTORY_SEPARATOR . '.' . basename($file) . '.' . getmypid() . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    // Windows: rename() over an existing file fails, so remove first.
    if (is_file($file)) {
        @unlink($file);
    }
    $ok = @rename($tmp, $file);
    if (!$ok) {
        @unlink($tmp);
    }
    return $ok;
}

/**
 * Locked read-modify-write for shared JSON state (e.g. progress written from
 * two devices at once). $mutator receives the current array and returns the
 * new array, or null to abort without writing.
 */
function json_update(string $file, callable $mutator): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    $lockPath = $file . '.lock';
    $fh = @fopen($lockPath, 'c+');
    if ($fh === false) {
        return false;
    }
    $result = false;
    try {
        if (!flock($fh, LOCK_EX)) {
            return false;
        }
        $current = json_read($file);
        $next = $mutator($current);
        if ($next !== null) {
            $result = json_write($file, $next);
        } else {
            $result = true; // no change requested
        }
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    return $result;
}
