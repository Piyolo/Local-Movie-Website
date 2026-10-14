<?php
/**
 * Metadata service — optional TMDB integration with local JSON caching,
 * bounded request budgets, graceful offline behaviour, and artwork caching.
 *
 * Nothing here ever blocks the library: callers pass a budget and stop when
 * it is exhausted; the next library load continues the work.
 *
 * API key supply: create includes/config.local.php (gitignored) containing:
 *   <?php return ['tmdb' => ['api_key' => 'YOUR_TMDB_V3_API_KEY']];
 * A TMDB v4 read access token ("ey...") is also accepted (Bearer auth).
 */
declare(strict_types=1);

if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}

function metadata_store_file(): string
{
    return DIR_DATA . DIRECTORY_SEPARATOR . 'metadata.json';
}

function overrides_store_file(): string
{
    return DIR_DATA . DIRECTORY_SEPARATOR . 'overrides.json';
}

function tmdb_configured(): bool
{
    global $CONFIG;
    return trim((string)$CONFIG['tmdb']['api_key']) !== '';
}

function tmdb_is_bearer_token(): bool
{
    global $CONFIG;
    return strpos(trim((string)$CONFIG['tmdb']['api_key']), 'ey') === 0;
}

/* ------------------------------------------------------------------ */
/* HTTP                                                                 */
/* ------------------------------------------------------------------ */

/** GET JSON from TMDB. Returns ['ok'=>bool,'code'=>int,'json'=>?array,'err'=>string]. */
function tmdb_get(string $url, array $params = []): array
{
    global $CONFIG;

    $key = trim((string)$CONFIG['tmdb']['api_key']);
    if (tmdb_is_bearer_token()) {
        $params = array_diff_key($params, ['api_key' => 1]);
    } else {
        $params['api_key'] = $key;
    }
    $full = $url . (strpos($url, '?') !== false ? '&' : '?') . http_build_query($params);

    $ch = curl_init($full);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_USERAGENT      => 'MovieWebsite/1.0 (personal LAN media library)',
        CURLOPT_HTTPHEADER     => tmdb_is_bearer_token()
            ? ['Authorization: Bearer ' . $key, 'Accept: application/json']
            : ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        return ['ok' => false, 'code' => 0, 'json' => null, 'err' => 'network'];
    }
    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'code' => $code, 'json' => null, 'err' => 'bad_json'];
    }
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'json' => $json, 'err' => $code >= 300 ? 'http_' . $code : ''];
}

/** Title similarity 0..100 (conservative matching aid). */
function title_similarity(string $a, string $b): float
{
    $a = normalize_title($a);
    $b = normalize_title($b);
    if ($a === '' || $b === '') {
        return 0.0;
    }
    if ($a === $b) {
        return 100.0;
    }
    similar_text($a, $b, $percent);
    return (float)$percent;
}

/**
 * Search TMDB for a movie and pick a result only when confident.
 * @return array|null normalized metadata fragment or null when not confident
 */
function tmdb_search_best(string $title, ?int $year): ?array
{
    global $CONFIG;

    $resp = tmdb_get($CONFIG['tmdb']['search_url'], array_filter([
        'query'     => $title,
        'year'      => $year,
        'language'  => $CONFIG['tmdb']['language'],
        'include_adult' => 'false',
    ], static fn ($v) => $v !== null && $v !== ''));
    if (!$resp['ok']) {
        throw new RuntimeException($resp['err']); // caller maps to offline/error status
    }
    $results = $resp['json']['results'] ?? [];
    if (!is_array($results) || !$results) {
        return null; // genuinely not found
    }

    $best = null;
    $bestScore = -1.0;
    foreach (array_slice($results, 0, 6) as $r) {
        $candTitle = (string)($r['title'] ?? '');
        $candYear = null;
        if (!empty($r['release_date']) && preg_match('/^(\d{4})/', (string)$r['release_date'], $m)) {
            $candYear = (int)$m[1];
        }
        $sim = title_similarity($title, $candTitle);
        $yearMatch = ($year !== null && $candYear !== null && abs($year - $candYear) <= 1);
        $score = $sim + ($yearMatch ? 15.0 : 0.0);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = ['r' => $r, 'sim' => $sim, 'year_match' => $yearMatch, 'year' => $candYear];
        }
    }
    if ($best === null) {
        return null;
    }

    $confident = $best['sim'] >= 70 || ($best['year_match'] && $best['sim'] >= 50);
    if (!$confident) {
        return null;
    }

    $r = $best['r'];
    $genres = [];
    foreach (explode(',', (string)($r['genre_ids'] ?? '')) as $gid) {
        $name = tmdb_genre_name((int)$gid);
        if ($name !== null) {
            $genres[] = $name;
        }
    }

    return [
        'tmdb_id'      => (int)($r['id'] ?? 0),
        'title'        => (string)($r['title'] ?? ''),
        'year'         => $best['year'],
        'overview'     => trim((string)($r['overview'] ?? '')),
        'genres'       => $genres,
        'runtime'      => null,      // search endpoint lacks these; detail fetch fills them
        'rating'       => isset($r['vote_average']) && $r['vote_average'] > 0 ? round((float)$r['vote_average'], 1) : null,
        'poster_path'  => $r['poster_path'] ?? null,
        'backdrop_path'=> $r['backdrop_path'] ?? null,
        'detail_id'    => (int)($r['id'] ?? 0),
    ];
}

/** Fetch runtime + genres from the movie detail endpoint (best effort). */
function tmdb_enrich_detail(array $frag): array
{
    global $CONFIG;
    if (empty($frag['detail_id'])) {
        return $frag;
    }
    $resp = tmdb_get('https://api.themoviedb.org/3/movie/' . (int)$frag['detail_id'], [
        'language' => $CONFIG['tmdb']['language'],
    ]);
    if ($resp['ok'] && is_array($resp['json'])) {
        $d = $resp['json'];
        if (!empty($d['runtime']) && $frag['runtime'] === null) {
            $frag['runtime'] = (int)$d['runtime'];
        }
        if (!empty($d['genres']) && !$frag['genres']) {
            $names = [];
            foreach ($d['genres'] as $g) {
                if (!empty($g['name'])) {
                    $names[] = (string)$g['name'];
                }
            }
            $frag['genres'] = $names;
        }
        if (isset($d['vote_average']) && $d['vote_average'] > 0) {
            $frag['rating'] = round((float)$d['vote_average'], 1);
        }
    }
    return $frag;
}

/** Static TMDB genre id → name (avoids an extra API call). */
function tmdb_genre_name(int $id): ?string
{
    static $map = [
        28 => 'Action', 12 => 'Adventure', 16 => 'Animation', 35 => 'Comedy',
        80 => 'Crime', 99 => 'Documentary', 18 => 'Drama', 10751 => 'Family',
        14 => 'Fantasy', 36 => 'History', 27 => 'Horror', 10402 => 'Music',
        9648 => 'Mystery', 10749 => 'Romance', 878 => 'Science Fiction',
        10770 => 'TV Movie', 53 => 'Thriller', 10752 => 'War', 37 => 'Western',
    ];
    return $map[$id] ?? null;
}

/* ------------------------------------------------------------------ */
/* Artwork caching                                                      */
/* ------------------------------------------------------------------ */

/** Download an image from the TMDB CDN only (never arbitrary URLs). */
function download_image(string $url, string $destAbs): bool
{
    if (is_file($destAbs) && filesize($destAbs) > 0) {
        return true;
    }
    $destDir = dirname($destAbs);
    if (!is_dir($destDir) || !is_writable($destDir)) {
        return false;
    }

    $body = '';
    $truncated = false;
    $maxBytes = 6 * 1024 * 1024;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'MovieWebsite/1.0 (personal LAN media library)',
        CURLOPT_WRITEFUNCTION  => static function ($ch, $data) use (&$body, &$truncated, $maxBytes) {
            $body .= $data;
            if (strlen($body) > $maxBytes) {
                $truncated = true;
                return 0; // abort transfer
            }
            return strlen($data);
        },
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($errno !== 0 || $code !== 200 || $truncated || $body === '') {
        return false;
    }
    if (stripos($ctype, 'image/') !== 0) {
        return false;
    }

    $tmp = $destAbs . '.tmp';
    if (@file_put_contents($tmp, $body) === false) {
        return false;
    }
    if (is_file($destAbs)) {
        @unlink($destAbs);
    }
    return @rename($tmp, $destAbs);
}

/** @return string|null app-relative path of a cached image or null */
function cache_artwork(string $movieId, ?string $posterPath, ?string $backdropPath, array &$entry): void
{
    global $CONFIG;
    $base = $CONFIG['tmdb']['image_base'];

    if (!$entry['poster_local'] && is_string($posterPath) && $posterPath !== '') {
        if (download_image($base . 'w500' . $posterPath, DIR_POSTERS . DIRECTORY_SEPARATOR . $movieId . '.jpg')) {
            $entry['poster_local'] = 'assets/posters/' . $movieId . '.jpg';
        }
    }
    if (!$entry['backdrop_local'] && is_string($backdropPath) && $backdropPath !== '') {
        if (download_image($base . 'w1280' . $backdropPath, DIR_BACKDROPS . DIRECTORY_SEPARATOR . $movieId . '.jpg')) {
            $entry['backdrop_local'] = 'assets/backdrops/' . $movieId . '.jpg';
        }
    }
}

/* ------------------------------------------------------------------ */
/* Store + batch enrichment                                             */
/* ------------------------------------------------------------------ */

function metadata_needs_lookup(?array $entry, bool $configured, int $now): bool
{
    if (!$configured || $entry === null) {
        return false;
    }
    $status = $entry['status'] ?? 'pending';
    if ($status === 'overridden') {
        return false;
    }
    $last = (int)($entry['last_attempt'] ?? 0);
    if (in_array($status, ['pending', 'offline', 'error'], true)) {
        // offline/error: back off for 5 minutes to avoid hammering a dead network.
        $backoff = in_array($status, ['offline', 'error'], true) ? 300 : 0;
        return ($now - $last) >= $backoff;
    }
    // ok / not_found: revalidate after the configured cache period.
    $ttl = max(1, (int)$CONFIG['tmdb']['cache_days']) * 86400;
    return ($now - (int)($entry['fetched_at'] ?? 0)) >= $ttl;
}

function metadata_needs_artwork(?array $entry): bool
{
    if ($entry === null || ($entry['status'] ?? '') !== 'ok') {
        return false;
    }
    $hasPoster = !empty($entry['poster_local']) && is_file(APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$entry['poster_local']));
    $hasBackdrop = !empty($entry['backdrop_local']) && is_file(APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$entry['backdrop_local']));
    $wantPoster = !empty($entry['poster_path']);
    $wantBackdrop = !empty($entry['backdrop_path']);
    return ($wantPoster && !$hasPoster) || ($wantBackdrop && !$hasBackdrop);
}

/**
 * Enrich a batch of movies within a strict budget.
 * Mutates and persists the metadata store; returns nothing.
 *
 * @param array $movies scan movie records
 * @param array $budget ['lookups'=>int,'artworks'=>int,'seconds'=>float]
 */
function metadata_enrich_batch(array $movies, array $budget): void
{
    global $CONFIG;

    $store = json_read(metadata_store_file());
    $configured = tmdb_configured();
    $now = time();
    $changed = false;
    $deadline = microtime(true) + (float)$budget['seconds'];
    $lookups = (int)$budget['lookups'];
    $artworks = (int)$budget['artworks'];

    foreach ($movies as $movie) {
        $id = $movie['id'];
        $entry = $store[$id] ?? ['status' => 'pending', 'fetched_at' => 0, 'last_attempt' => 0,
            'poster_local' => null, 'backdrop_local' => null, 'poster_path' => null, 'backdrop_path' => null];

        if (metadata_needs_artwork($entry)) {
            if ($artworks <= 0 || microtime(true) > $deadline) {
                break;
            }
            $artworks--;
            cache_artwork($id, $entry['poster_path'], $entry['backdrop_path'], $entry);
            $store[$id] = $entry;
            $changed = true;
            continue;
        }

        if (metadata_needs_lookup($entry, $configured, $now)) {
            if ($lookups <= 0 || microtime(true) > $deadline) {
                break;
            }
            $lookups--;
            $entry['last_attempt'] = $now;
            try {
                $frag = tmdb_search_best($movie['title'], $movie['year']);
                if ($frag === null) {
                    $entry['status'] = 'not_found';
                    $entry['fetched_at'] = $now;
                } else {
                    $frag = tmdb_enrich_detail($frag);
                    $entry = array_merge($entry, $frag);
                    $entry['status'] = 'ok';
                    $entry['fetched_at'] = $now;
                    $entry['provider'] = 'tmdb';
                    // Artwork now, or on a later load if the budget is spent.
                    if ($artworks > 0 && microtime(true) <= $deadline) {
                        $artworks--;
                        cache_artwork($id, $entry['poster_path'], $entry['backdrop_path'], $entry);
                    }
                }
            } catch (RuntimeException $e) {
                $err = $e->getMessage();
                $entry['status'] = ($err === 'network') ? 'offline' : 'error';
                if ($err === 'http_401') {
                    $entry['status'] = 'error'; // bad key: backoff, user sees "metadata unavailable"
                }
                $entry['fetched_at'] = $now;
            }
            $store[$id] = $entry;
            $changed = true;
            continue;
        }

        // First sight of this movie: remember it as pending (cheap, no network).
        if (!isset($store[$id])) {
            $entry['status'] = $configured ? 'pending' : 'no_key';
            $store[$id] = $entry;
            $changed = true;
        }
    }

    if ($changed) {
        json_write(metadata_store_file(), $store);
    }
}

/**
 * Build the metadata view for one movie (store + overrides merged).
 * Never invents values: missing fields stay null/empty.
 */
function metadata_view(array $movie): array
{
    global $CONFIG;

    static $storeCache = null;
    static $overrideCache = null;
    if ($storeCache === null) {
        $storeCache = json_read(metadata_store_file());
        $overrideCache = json_read(overrides_store_file());
    }

    $id = $movie['id'];
    $entry = $storeCache[$id] ?? ['status' => 'pending'];
    $override = $overrideCache[$id] ?? [];

    $configured = tmdb_configured();
    $status = (string)($entry['status'] ?? 'pending');
    if (!$configured && !in_array($status, ['ok', 'not_found', 'overridden'], true)) {
        $status = 'no_key';
    }

    $view = [
        'title'    => (string)($entry['title'] ?? '') !== '' ? $entry['title'] : $movie['title'],
        'year'     => $entry['year'] ?? $movie['year'],
        'overview' => (string)($entry['overview'] ?? ''),
        'genres'   => is_array($entry['genres'] ?? null) ? $entry['genres'] : [],
        'runtime'  => $entry['runtime'] ?? null,
        'rating'   => $entry['rating'] ?? null,
        'tmdb_id'  => $entry['tmdb_id'] ?? null,
        'status'   => $status,
        'poster'   => null,
        'backdrop' => null,
    ];

    // Artwork: local cache first, TMDB CDN second, none otherwise.
    if (!empty($entry['poster_local'])) {
        $view['poster'] = $entry['poster_local'];
    } elseif (!empty($entry['poster_path']) && $configured) {
        $view['poster'] = rtrim($CONFIG['tmdb']['image_base'], '/') . '/w500' . $entry['poster_path'];
    }
    if (!empty($entry['backdrop_local'])) {
        $view['backdrop'] = $entry['backdrop_local'];
    } elseif (!empty($entry['backdrop_path']) && $configured) {
        $view['backdrop'] = rtrim($CONFIG['tmdb']['image_base'], '/') . '/w1280' . $entry['backdrop_path'];
    }

    // Manual overrides always win.
    if ($override) {
        foreach (['title', 'year', 'overview', 'runtime', 'rating'] as $k) {
            if (array_key_exists($k, $override) && $override[$k] !== null && $override[$k] !== '') {
                $view[$k] = $override[$k];
            }
        }
        if (!empty($override['genres']) && is_array($override['genres'])) {
            $view['genres'] = $override['genres'];
        }
        if (!empty($override['poster_file'])) {
            $view['poster'] = 'assets/posters/' . rawurlencode($override['poster_file']); // user-provided local file
        }
        $view['status'] = 'overridden';
    }

    return $view;
}

/** Reset one movie's metadata (used by the Settings → Refresh action). */
function metadata_reset(string $id): bool
{
    $store = json_read(metadata_store_file());
    if (!isset($store[$id])) {
        return false;
    }
    $entry = $store[$id];
    $entry['status'] = 'pending';
    $entry['fetched_at'] = 0;
    $entry['last_attempt'] = 0;
    $store[$id] = $entry;
    return json_write(metadata_store_file(), $store);
}

/** Reset every movie's metadata (full refresh). */
function metadata_reset_all(array $movieIds): int
{
    $store = json_read(metadata_store_file());
    $n = 0;
    foreach ($movieIds as $id) {
        if (isset($store[$id])) {
            $store[$id]['status'] = 'pending';
            $store[$id]['fetched_at'] = 0;
            $store[$id]['last_attempt'] = 0;
            $n++;
        }
    }
    if ($n) {
        json_write(metadata_store_file(), $store);
    }
    return $n;
}
