<?php
/**
 * Library endpoint — always performs a fresh directory scan (this is the
 * ONLY place a full rescan happens on demand), returns movie summaries.
 * No external metadata requests happen here; the client calls
 * api/metadata.php?action=enrich separately after first paint.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$scan = scan_library(true);

if (!empty($scan['error'])) {
    $public = ($scan['error'] === 'library_unavailable')
        ? 'The movie library is currently unavailable. Check that its drive is connected, then rescan.'
        : 'The movie directory exists but cannot be read. Check its permissions.';
    api_error($public, 503, ['code' => $scan['error']]);
}

$movies = [];
foreach ($scan['movies'] as $movie) {
    $movies[] = movie_summary($movie, metadata_view($movie));
}

// Genres derived strictly from retrieved metadata (never invented).
$genres = [];
foreach ($movies as $m) {
    foreach ($m['genres'] as $g) {
        $genres[$g] = ($genres[$g] ?? 0) + 1;
    }
}
$genreList = [];
foreach ($genres as $name => $count) {
    $genreList[] = ['name' => $name, 'count' => $count];
}
usort($genreList, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

json_response([
    'ok'       => true,
    'movies'   => $movies,
    'genres'   => $genreList,
    'counts'   => $scan['counts'],
    'unmatched_subtitles' => $scan['unmatched_subtitles'],
    'scanned_at' => date('c', (int)$scan['scanned_at']),
]);
