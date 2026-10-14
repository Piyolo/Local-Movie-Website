<?php
/**
 * Playback progress — server-side JSON store so resume follows the viewer
 * across devices (single-user LAN model).
 *
 *   GET    → all progress
 *   POST   JSON {id, position, duration}  → save (throttled by the client)
 *   DELETE ?id=...                         → clear one movie's progress
 */
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

global $CONFIG;

$file = DIR_DATA . DIRECTORY_SEPARATOR . $CONFIG['files']['progress'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $all = json_read($file);
    // Prune entries whose movie vanished from the library (cheap: ids only).
    json_response(['ok' => true, 'progress' => $all]);
}

if ($method === 'DELETE') {
    $id = (string)($_GET['id'] ?? '');
    if (!valid_movie_id($id)) {
        api_error('Invalid movie id.', 400);
    }
    json_update($file, static function (array $all) use ($id) {
        unset($all[$id]);
        return $all;
    });
    json_response(['ok' => true]);
}

if ($method !== 'POST') {
    api_error('Method not allowed.', 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    api_error('Invalid JSON payload.', 400);
}
$id = (string)($payload['id'] ?? '');
$position = (float)($payload['position'] ?? 0);
$duration = (float)($payload['duration'] ?? 0);

if (!valid_movie_id($id)) {
    api_error('Invalid movie id.', 400);
}
if ($position < 0 || $duration < 0 || $position > 86400 * 12 || $duration > 86400 * 12) {
    api_error('Invalid progress values.', 400);
}

$completeRatio = (float)$CONFIG['progress']['complete_ratio'];
$minResume = (int)$CONFIG['progress']['min_resume_seconds'];

$state = 'progress';
if ($duration > 0 && $position >= $duration * $completeRatio) {
    $state = 'finished';      // completed → clear resume point
} elseif ($duration > 0 && $position < $minResume) {
    $state = 'fresh';         // barely started
}

json_update($file, static function (array $all) use ($id, $state, $position, $duration, $completeRatio, $minResume) {
    if ($state === 'finished') {
        $all[$id] = ['position' => 0, 'duration' => (int)$duration, 'finished' => true, 'updated' => time()];
        return $all;
    }
    if ($state === 'fresh') {
        unset($all[$id]); // nothing worth resuming
        return $all;
    }
    $all[$id] = [
        'position' => (int)round($position),
        'duration' => (int)round($duration),
        'finished' => false,
        'updated'  => time(),
    ];
    return $all;
});

json_response(['ok' => true, 'state' => $state]);
