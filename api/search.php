<?php
/**
 * Server-side search/filter/sort over the scanned library.
 * (The UI also filters client-side for speed; this endpoint exists for large
 * libraries and for TV clients that prefer one round trip.)
 *
 * Params: q, genre, year, sort=title|year|added|filename, order=asc|desc
 */
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$scan = scan_library(false);
if (!empty($scan['error'])) {
    api_error('The movie directory is unavailable.', 503);
}

$q = trim((string)($_GET['q'] ?? ''));
$genre = trim((string)($_GET['genre'] ?? ''));
$year = trim((string)($_GET['year'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'added');
$order = strtolower((string)($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

$movies = [];
foreach ($scan['movies'] as $movie) {
    $movies[] = movie_summary($movie, metadata_view($movie));
}

if ($q !== '') {
    $needle = normalize_title($q);
    $movies = array_values(array_filter($movies, static function ($m) use ($needle) {
        return strpos(normalize_title($m['title']), $needle) !== false
            || strpos(normalize_title($m['filename']), $needle) !== false;
    }));
}
if ($genre !== '') {
    $movies = array_values(array_filter($movies, static function ($m) use ($genre) {
        foreach ($m['genres'] as $g) {
            if (strcasecmp($g, $genre) === 0) {
                return true;
            }
        }
        return false;
    }));
}
if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
    $movies = array_values(array_filter($movies, static fn ($m) => (string)$m['year'] === $year));
}

usort($movies, static function ($a, $b) use ($sort, $order) {
    $cmp = 0;
    switch ($sort) {
        case 'title':
            $cmp = strcasecmp($a['title'], $b['title']); break;
        case 'year':
            $cmp = ((int)$a['year']) <=> ((int)$b['year']); break;
        case 'filename':
            $cmp = strcasecmp($a['filename'], $b['filename']); break;
        case 'added':
        default:
            $cmp = ((int)$a['mtime']) <=> ((int)$b['mtime']);
    }
    if ($cmp === 0) {
        $cmp = strcasecmp($a['title'], $b['title']);
    }
    return $order === 'asc' ? $cmp : -$cmp;
});

json_response([
    'ok'     => true,
    'movies' => $movies,
    'total'  => count($movies),
    'query'  => ['q' => $q, 'genre' => $genre, 'year' => $year, 'sort' => $sort, 'order' => $order],
]);
