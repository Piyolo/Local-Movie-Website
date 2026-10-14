<?php
/**
 * Video delivery — MIME mapping, HTTP Range parsing, and bounded streaming.
 * Never reads a whole file into memory.
 */
declare(strict_types=1);

if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}

/** Container → MIME type map for the delivery endpoint. */
function video_mime(string $ext): string
{
    static $map = [
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/mp4',
        'webm' => 'video/webm',
        'mkv'  => 'video/x-matroska',
        'mov'  => 'video/quicktime',
        'avi'  => 'video/x-msvideo',
        'wmv'  => 'video/x-ms-wmv',
        'ts'   => 'video/mp2t',
        'mpg'  => 'video/mpeg',
        'mpeg' => 'video/mpeg',
        'ogv'  => 'video/ogg',
    ];
    $ext = strtolower($ext);
    return $map[$ext] ?? 'application/octet-stream';
}

/**
 * Parse an HTTP Range header against a resource size.
 *
 * @return array{mode: 'full'|'part'|'unsatisfiable', start: int, end: int}
 *   mode 'full'          → serve 200 with the whole entity
 *   mode 'part'          → serve 206 for bytes start..end (inclusive)
 *   mode 'unsatisfiable' → serve 416 with Content-Range: bytes * /size
 *
 * Multi-range requests return 'full' (RFC-permitted: a server may ignore Range).
 * Unit-testable: pure function, no I/O.
 */
function parse_range_header(?string $header, int $size): array
{
    $result = ['mode' => 'full', 'start' => 0, 'end' => $size > 0 ? $size - 1 : 0];
    if ($header === null || trim($header) === '' || $size <= 0) {
        return $result;
    }
    $header = trim($header);
    if (!preg_match('/^bytes=(.+)$/i', $header, $m)) {
        // Unknown unit → RFC 7233 says ignore or 416; we serve 416.
        return ['mode' => 'unsatisfiable', 'start' => 0, 'end' => 0];
    }
    $spec = trim($m[1]);
    if (strpos($spec, ',') !== false) {
        return $result; // multi-range: serve the full entity instead
    }
    if (!preg_match('/^(\d*)-(\d*)$/', $spec, $p)) {
        return ['mode' => 'unsatisfiable', 'start' => 0, 'end' => 0];
    }
    $startRaw = $p[1];
    $endRaw = $p[2];

    if ($startRaw === '' && $endRaw === '') {
        return ['mode' => 'unsatisfiable', 'start' => 0, 'end' => 0];
    }

    if ($startRaw === '') {
        // Suffix range: last N bytes.
        $len = (int)$endRaw;
        if ($len <= 0) {
            return ['mode' => 'unsatisfiable', 'start' => 0, 'end' => 0];
        }
        $start = max(0, $size - $len);
        $end = $size - 1;
    } else {
        // Guard against absurd values without integer overflow.
        if (strlen($startRaw) > 18 || strlen($endRaw) > 18) {
            return ['mode' => 'unsatisfiable', 'start' => 0, 'end' => 0];
        }
        $start = (int)$startRaw;
        $end = ($endRaw === '') ? ($size - 1) : (int)$endRaw;
        if ($end < $start) {
            return ['mode' => 'unsatisfiable', 'start' => 0, 'end' => 0];
        }
        if ($start >= $size) {
            return ['mode' => 'unsatisfiable', 'start' => 0, 'end' => 0];
        }
        $end = min($end, $size - 1);
    }

    return ['mode' => 'part', 'start' => $start, 'end' => $end];
}

/**
 * Stream a validated file with proper range support.
 * Bounded 1 MB chunks, flush per chunk, stops on client disconnect.
 */
function stream_file(string $absPath, int $size, string $mime, ?string $rangeHeader, string $method = 'GET', ?string $lastModified = null): void
{
    global $CONFIG;

    $range = parse_range_header($rangeHeader, $size);

    @set_time_limit(0);
    @ini_set('memory_limit', '64M'); // chunks are tiny; keep PHP honest

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    if ($lastModified !== null) {
        header('Last-Modified: ' . $lastModified);
    }

    if ($range['mode'] === 'unsatisfiable') {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        header('Content-Length: 0');
        return;
    }

    $start = $range['start'];
    $length = ($range['mode'] === 'part') ? ($range['end'] - $range['start'] + 1) : $size;

    if ($range['mode'] === 'part') {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $range['end'] . '/' . $size);
    } else {
        http_response_code(200);
    }
    header('Content-Length: ' . $length);

    if ($method === 'HEAD') {
        return;
    }

    $fh = @fopen($absPath, 'rb');
    if ($fh === false) {
        // Headers already sent; nothing more we can do besides stopping.
        return;
    }
    try {
        if ($start > 0 && fseek($fh, $start) !== 0) {
            return;
        }
        $chunkSize = max(65536, (int)$CONFIG['stream_chunk_bytes']);
        $remaining = $length;
        while ($remaining > 0) {
            if (connection_aborted()) {
                break; // client disconnected — stop reading immediately
            }
            $want = min($chunkSize, $remaining);
            $data = fread($fh, $want);
            if ($data === false || $data === '') {
                break; // truncated file (shorter than announced size)
            }
            echo $data;
            flush();
            $remaining -= strlen($data);
        }
    } finally {
        fclose($fh);
    }
}
