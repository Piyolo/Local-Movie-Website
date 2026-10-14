<?php
/**
 * Subtitle delivery — serves validated subtitle tracks as WebVTT.
 *
 *   ?movie=<movie id>&track=<track id>
 *
 * Validation: both ids must resolve against the current scan, and the track's
 * stored application-relative path must canonicalise inside assets/movies or
 * assets/subtitles. Arbitrary paths are impossible to request.
 *
 * Formats:
 *   .vtt → validated and served as-is
 *   .srt → converted to WebVTT (timestamps/cues handled properly)
 *   .ass/.ssa/.sub → 415 (unsupported in browsers without extra libraries)
 */
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$movieId = (string)($_GET['movie'] ?? '');
$trackId = (string)($_GET['track'] ?? '');

if (!valid_movie_id($movieId) || !valid_movie_id($trackId)) {
    api_error('Invalid subtitle request.', 400);
}

$scan = scan_library(false); // cached scan: subtitles are fetched per playback
if (!empty($scan['error'])) {
    api_error('The library is unavailable.', 503);
}

$movie = find_movie($scan, $movieId);
if ($movie === null) {
    api_error('Movie not found.', 404);
}
$track = find_track($movie, $trackId);
if ($track === null) {
    api_error('Subtitle track not found. Rescan the library if it was just added.', 404);
}

$ext = strtolower($track['ext']);
if (!in_array($ext, $CONFIG['subtitles']['serveable'], true)) {
    api_error('This subtitle format cannot be displayed in the browser. Convert it to SRT or WebVTT.', 415);
}

$abs = safe_subtitle_path($track['rel'], $track['root']);
if ($abs === null) {
    api_error('Subtitle file is missing or inaccessible.', 404);
}

$size = (int)@filesize($abs);
if ($size <= 0 || $size > 20 * 1024 * 1024) {
    api_error('Subtitle file is empty or too large.', 410);
}

$raw = @file_get_contents($abs);
if ($raw === false) {
    api_error('Subtitle file could not be read.', 410);
}

if ($ext === 'vtt') {
    $vtt = validate_vtt($raw);
    if ($vtt === null) {
        api_error('This WebVTT file is malformed and cannot be shown.', 422);
    }
} else {
    $vtt = srt_to_vtt($raw);
    if ($vtt === null) {
        api_error('No readable subtitle cues were found in this file.', 422);
    }
}

http_response_code(200);
header('Content-Type: text/vtt; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
header('Content-Length: ' . strlen($vtt));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}
echo $vtt;
exit;
