<?php
/**
 * Video delivery endpoint — the ONLY way movie bytes leave the server.
 *
 * Safety design:
 *   - the browser supplies just a movie id (40-char sha1); no paths ever
 *   - the id is resolved against the scanned library (TTL-cached scan, so
 *     playback never triggers a full rescan)
 *   - the resolved path is canonicalised and must live inside assets/movies
 *   - HTTP Range: proper 206 partial content, 416 for invalid ranges
 *   - bounded 1 MB chunks; never file_get_contents() on a video
 */
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$id = (string)($_GET['id'] ?? '');
if (!valid_movie_id($id)) {
    api_error('Invalid movie id.', 400);
}

// Distinguish "drive disconnected" from "bad id" before touching the scan.
if (!is_dir(movies_dir())) {
    api_error('The movie library is currently unavailable. Check that its drive is connected.', 503, ['code' => 'library_unavailable']);
}

$scan = scan_library(false);
if (!empty($scan['error'])) {
    api_error('The movie directory is unavailable.', 503);
}

$movie = find_movie($scan, $id);
if ($movie === null) {
    api_error('Movie not found.', 404);
}

$abs = safe_movie_path($movie['rel']);
if ($abs === null) {
    // The file was moved/deleted after the last scan.
    api_error('Movie file is missing or inaccessible.', 404);
}

$size = (int)@filesize($abs);
if ($size <= 0) {
    api_error('Movie file is empty or unreadable.', 410);
}

$rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$lastModified = gmdate('D, d M Y H:i:s', (int)@filemtime($abs)) . ' GMT';

stream_file($abs, $size, video_mime($movie['ext']), $rangeHeader, $method, $lastModified);
exit;
