<?php
/**
 * Metadata control endpoint.
 *
 *   GET ?action=enrich              → run ONE bounded enrichment pass
 *                                     (client repeats until done=false)
 *   GET ?action=status              → progress summary
 *   GET/POST ?action=refresh&id=X   → reset one movie
 *   GET/POST ?action=refresh&all=1  → reset every movie
 */
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$action = (string)($_GET['action'] ?? 'enrich');

if ($action === 'status') {
    $scan = scan_library(false);
    $store = json_read(DIR_DATA . DIRECTORY_SEPARATOR . 'metadata.json');
    $counts = ['ok' => 0, 'pending' => 0, 'not_found' => 0, 'error' => 0, 'offline' => 0, 'no_key' => 0];
    foreach ($scan['movies'] ?? [] as $m) {
        $st = $store[$m['id']]['status'] ?? 'pending';
        if (!isset($counts[$st])) {
            $counts[$st] = 0;
        }
        $counts[$st]++;
    }
    json_response([
        'ok' => true,
        'configured' => tmdb_configured(),
        'counts' => $counts,
        'total' => count($scan['movies'] ?? []),
    ]);
}

if ($action === 'refresh') {
    $all = isset($_GET['all']) && $_GET['all'] === '1';
    $id = (string)($_GET['id'] ?? '');
    $scan = scan_library(true);
    if ($all) {
        $ids = array_column($scan['movies'] ?? [], 'id');
        $n = metadata_reset_all($ids);
        json_response(['ok' => true, 'reset' => $n]);
    }
    if (!valid_movie_id($id)) {
        api_error('Invalid movie id.', 400);
    }
    $ok = metadata_reset($id);
    json_response(['ok' => $ok, 'reset' => $ok ? 1 : 0]);
}

if ($action === 'enrich') {
    $scan = scan_library(false);
    if (!empty($scan['error'])) {
        api_error('The library is unavailable.', 503);
    }
    $configured = tmdb_configured();
    // One bounded pass: never blocks the client for long, never hammers TMDB.
    metadata_enrich_batch($scan['movies'], [
        'lookups'  => $configured ? 4 : 0,
        'artworks' => $configured ? 4 : 0,
        'seconds'  => $configured ? 4.0 : 0.0,
    ]);

    // Report how much work remains so the client can decide to continue.
    $store = json_read(DIR_DATA . DIRECTORY_SEPARATOR . 'metadata.json');
    $pending = 0;
    $now = time();
    foreach ($scan['movies'] as $m) {
        $entry = $store[$m['id']] ?? null;
        if (metadata_needs_lookup($entry, $configured, $now) || metadata_needs_artwork($entry)) {
            $pending++;
        } elseif ($entry === null && $configured) {
            $pending++;
        }
    }
    json_response(['ok' => true, 'done' => $pending === 0, 'pending' => $pending, 'configured' => $configured]);
}

api_error('Unknown action.', 400);
