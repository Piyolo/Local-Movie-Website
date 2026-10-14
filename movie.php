<?php
/**
 * Movie details page — /movie.php?id=<40-hex>
 * Server validates the id; content rendered client-side from api/movie.php.
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

$PAGE_TITLE     = 'Movie';
$ACTIVE_NAV     = 'movies';
$PAGE_BODY_CLASS = 'page-details';
$PAGE_SCRIPTS   = ['js/details.js'];
require __DIR__ . '/includes/page_header.php';
?>

<article class="details" id="details" data-movie-id="<?= h($id) ?>">
  <div id="details-loading" class="state-block">
    <span class="spinner" aria-hidden="true"></span>
    <p class="muted">Loading details…</p>
  </div>
  <div id="details-error" class="state-block" hidden>
    <h1 id="details-error-title">Movie not found</h1>
    <p class="muted" id="details-error-msg"></p>
    <p><a class="btn" href="<?= h(url('index.php')) ?>">Back to home</a></p>
  </div>
  <div id="details-body" hidden>
    <div class="details-backdrop" id="details-backdrop"></div>
    <div class="details-grid">
      <div class="details-poster-wrap"><img id="details-poster" class="details-poster" alt="" loading="lazy"></div>
      <div class="details-info">
        <h1 id="details-title"></h1>
        <p class="details-meta" id="details-meta"></p>
        <p class="details-overview" id="details-overview"></p>
        <div class="details-actions">
          <a class="btn btn-primary btn-lg" id="details-play" href="#">▶ Play</a>
          <button type="button" class="btn" id="details-resume" hidden>Resume</button>
        </div>
        <dl class="details-facts" id="details-facts"></dl>
        <section class="details-subs" id="details-subs" hidden>
          <h3>Subtitles</h3>
          <ul id="details-sub-list"></ul>
        </section>
        <p class="muted small" id="details-fileinfo"></p>
      </div>
    </div>
  </div>
</article>

<?php require __DIR__ . '/includes/page_footer.php'; ?>
