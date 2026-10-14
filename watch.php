<?php
/**
 * Watch page — /watch.php?id=<40-hex>
 * HTML5 custom player: seek, resume, volume, speed, subtitles menu,
 * keyboard/remote control, error states. Streams via api/stream.php.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$id = (string)($_GET['id'] ?? '');
if (!valid_movie_id($id)) {
    http_response_code(400);
    $PAGE_TITLE = 'Invalid link';
    $PAGE_BODY_CLASS = 'page-error';
    require __DIR__ . '/includes/page_header.php';
    echo '<section class="state-block"><h1>Invalid movie link</h1><p class="muted">Go back to the <a href="' . h(url('index.php')) . '">home page</a>.</p></section>';
    require __DIR__ . '/includes/page_footer.php';
    exit;
}

$PAGE_TITLE     = 'Watch';
$PAGE_BODY_CLASS = 'page-watch';
$PAGE_SCRIPTS   = ['js/player.js'];
require __DIR__ . '/includes/page_header.php';
?>

<div class="player" id="player" data-movie-id="<?= h($id) ?>" data-stream-url="<?= h(url('api/stream.php', ['id' => $id])) ?>" data-progress-url="<?= h(url('api/progress.php')) ?>" data-movie-url="<?= h(url('api/movie.php', ['id' => $id])) ?>" data-subs-url="<?= h(url('api/subtitles.php')) ?>" data-index-url="<?= h(url('index.php')) ?>">

  <div class="player-stage" id="player-stage">
    <video id="video" preload="metadata" playsinline></video>

    <div class="player-loading" id="player-loading"><span class="spinner"></span><span>Loading…</span></div>

    <div class="player-error" id="player-error" hidden>
      <h1 id="player-error-title">Playback error</h1>
      <p id="player-error-msg"></p>
      <div class="dialog-actions">
        <button type="button" class="btn btn-primary" id="player-retry">Try again</button>
        <a class="btn" id="player-back" href="#">Back to details</a>
      </div>
    </div>

    <button type="button" class="player-bigplay" id="bigplay" aria-label="Play or pause">▶</button>

    <div class="player-controls" id="controls">
      <div class="pc-row">
        <button type="button" class="pc-btn" id="pc-play" aria-label="Play or pause">▶</button>
        <span class="pc-time" id="pc-current">0:00</span>
        <div class="pc-seek" id="pc-seek" role="slider" tabindex="0" aria-label="Seek" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
          <div class="pc-seek-buffer" id="pc-buffer"></div>
          <div class="pc-seek-fill" id="pc-fill"></div>
          <div class="pc-seek-thumb" id="pc-thumb"></div>
        </div>
        <span class="pc-time" id="pc-duration">0:00</span>
        <button type="button" class="pc-btn" id="pc-mute" aria-label="Mute">🔊</button>
        <div class="pc-vol" id="pc-vol" role="slider" tabindex="0" aria-label="Volume" aria-valuemin="0" aria-valuemax="100" aria-valuenow="100">
          <div class="pc-vol-fill" id="pc-vol-fill"></div>
        </div>
        <button type="button" class="pc-btn pc-text" id="pc-subs" aria-haspopup="true" aria-expanded="false">Subtitles</button>
        <button type="button" class="pc-btn pc-text" id="pc-speed" aria-haspopup="true" aria-expanded="false">1×</button>
        <button type="button" class="pc-btn" id="pc-full" aria-label="Fullscreen">⛶</button>
      </div>

      <div class="pc-menu" id="menu-subs" hidden role="menu" aria-label="Subtitles"></div>
      <div class="pc-menu pc-menu-speed" id="menu-speed" hidden role="menu" aria-label="Playback speed"></div>
    </div>
  </div>

  <div class="watch-title" id="watch-title"></div>
</div>

<?php require __DIR__ . '/includes/page_footer.php'; ?>
