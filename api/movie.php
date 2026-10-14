<?php
/** Single movie details by validated id. */
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$id = (string)($_GET['id'] ?? '');
if (!valid_movie_id($id)) {
    api_error('Invalid movie id.', 400);
}

$scan = scan_library(false); // cache-first: details pages never force a rescan
if (!empty($scan['error'])) {
    api_error(
        $scan['error'] === 'library_unavailable'
            ? 'The movie library is currently unavailable. Check that its drive is connected.'
            : 'The movie directory exists but cannot be read.',
        503,
        ['code' => $scan['error']]
    );
}

$movie = find_movie($scan, $id);
if ($movie === null) {
    api_error('Movie not found. It may have been renamed or removed.', 404);
}

json_response(['ok' => true, 'movie' => movie_summary($movie, metadata_view($movie))]);
