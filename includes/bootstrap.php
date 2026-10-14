<?php
/**
 * Single bootstrap for every entry point (pages and API endpoints).
 * Usage:  require __DIR__ . '/includes/bootstrap.php';   (from project root)
 */
declare(strict_types=1);

// Refuse direct HTTP access to this file.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit('Not found');
}

if (!defined('MOVIEWEBSITE')) {
    define('MOVIEWEBSITE', true);
}

require __DIR__ . '/config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/security.php';
require __DIR__ . '/cache.php';
require __DIR__ . '/library_config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/folder_browser.php';
require __DIR__ . '/filename_parser.php';
require __DIR__ . '/subtitles.php';
require __DIR__ . '/scanner.php';
require __DIR__ . '/video_service.php';
require __DIR__ . '/metadata_service.php';
