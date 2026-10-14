<?php
/**
 * MovieWebsite — central configuration.
 * Loaded first by every entry point. Defines bootstrap constants,
 * default settings, then merges optional local overrides
 * (includes/config.local.php — gitignored, holds secrets like the TMDB key).
 */
declare(strict_types=1);

if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}

define('APP_ROOT', dirname(__DIR__));

/* ---------- Paths (server side only; never exposed to the browser) ----------
 * DIR_MOVIES is the DEFAULT library directory. The ACTIVE library directory
 * may differ (see includes/library_config.php → movies_dir()); everything
 * that touches media must use movies_dir(), never this constant directly. */
define('DIR_MOVIES',   APP_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'movies');
define('DIR_POSTERS',  APP_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'posters');
define('DIR_BACKDROPS',APP_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'backdrops');
define('DIR_SUBTITLES',APP_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'subtitles');
define('DIR_ICONS',    APP_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'icons');
define('DIR_DATA',     APP_ROOT . DIRECTORY_SEPARATOR . 'data');
define('DIR_INCLUDES', APP_ROOT . DIRECTORY_SEPARATOR . 'includes');

/* ---------- Application defaults ---------- */
$CONFIG = [
    // Recognised video extensions (recognition != browser can decode — see video_service.php)
    'allowed_extensions' => ['mp4', 'm4v', 'webm', 'mkv', 'mov', 'avi', 'wmv', 'ts'],

    // Scan the movie root plus its subdirectories (organised libraries are common).
    'recursive_scan' => true,
    'scan_max_depth' => 8,

    // HTML5 <video> plausibly decodable in modern desktop browsers.
    // Used ONLY to label a file "may not play in this browser" — never to hide it.
    'browser_plausible' => ['mp4', 'm4v', 'webm', 'mov', 'ts'],

    // Streaming
    'stream_chunk_bytes' => 1048576, // 1 MB bounded chunks, never whole-file reads

    // Fresh scan results are reused briefly so playback requests never
    // rescan the whole library; loading the library forces a fresh scan.
    'scan_cache_ttl' => 30, // seconds

    // External subtitle discovery (YTS packs ship sidecar + Subs\ files).
    'subtitles' => [
        'extensions'          => ['srt', 'vtt', 'ass', 'ssa', 'sub'],
        // Formats we can actually serve to an <track> element:
        //   vtt served as-is, srt converted to WebVTT server-side.
        // ass/ssa/sub are discovered and shown, but marked unsupported in-browser.
        'serveable'           => ['srt', 'vtt'],
        // Companion folders inside a movie's directory that hold its subtitles.
        'companion_dirs'      => ['subs', 'subtitles'],
        // Shared flat directory (relative to APP_ROOT): assets/subtitles — Pattern B.
        'shared_dir'          => 'assets/subtitles',
    ],

    // Metadata provider (TMDB). Optional: without a key the app still works
    // with filename-derived titles and local fallback artwork.
    'tmdb' => [
        'api_key'     => '',            // overridden by includes/config.local.php
        'language'    => 'en-US',
        'cache_days'  => 30,            // revalidate metadata after this many days
        'search_url'  => 'https://api.themoviedb.org/3/search/movie',
        'find_url'    => 'https://api.themoviedb.org/3/find/',
        'image_base'  => 'https://image.tmdb.org/t/p/',
        'attribution' => 'This product uses the TMDB API but is not endorsed or certified by TMDB.',
    ],

    // Server-side playback progress (shared across devices on the LAN).
    'progress' => [
        'file'       => 'progress.json',
        'complete_ratio' => 0.95,   // consider finished at 95 %
        'min_resume_seconds' => 10, // ignore trivial positions
    ],

    // Cache file names inside data/
    'files' => [
        'metadata' => 'metadata.json',
        'overrides' => 'overrides.json',
        'progress' => 'progress.json',
    ],
];

/* ---------- Local overrides (secrets, tunables) ---------- */
$_local = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
if (is_file($_local)) {
    $_localConfig = require $_local;
    if (is_array($_localConfig)) {
        $CONFIG = array_replace_recursive($CONFIG, $_localConfig);
    }
    unset($_localConfig);
}
unset($_local);

/**
 * Merge-time guard: the movie directory must live inside APP_ROOT.
 * (Defence in depth — config is server-side, but never trust a stray override.)
 */
if (strpos(DIR_MOVIES, APP_ROOT) !== 0) {
    http_response_code(500);
    exit('Configuration error');
}
