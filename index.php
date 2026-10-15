<?php
/**
 * Homepage — hero, Continue Watching, Recently Added, All Movies, genre rows.
 * The list content is rendered client-side from api/movies.php so the page
 * shell paints instantly and never blocks on a scan.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$PAGE_TITLE     = 'Home';
$ACTIVE_NAV     = 'home';
$PAGE_BODY_CLASS = 'page-home';
$PAGE_SCRIPTS   = ['js/library.js'];
require __DIR__ . '/includes/page_header.php';
?>

<section id="hero" class="hero" hidden>
  <div class="hero-backdrop" id="hero-backdrop"></div>
  <div class="hero-body">
    <p class="hero-kicker">Featured</p>
    <h1 class="hero-title" id="hero-title"></h1>
    <p class="hero-meta" id="hero-meta"></p>
    <p class="hero-overview" id="hero-overview"></p>
    <div class="hero-actions">
      <a class="btn btn-primary btn-lg" id="hero-play" href="#"><svg class="i" aria-hidden="true"><use href="#i-play" xlink:href="#i-play"></use></svg> Play</a>
      <a class="btn btn-lg" id="hero-details" href="#">Details</a>
    </div>
  </div>
</section>

<section id="home-empty" class="state-block" hidden>
  <h2>Your library is empty</h2>
  <p class="muted">Drop movie files into your library folder, then scan — or point MovieWebsite at an existing folder on any drive.</p>
  <div class="dialog-actions">
    <button type="button" class="btn btn-primary" id="empty-open-settings">Open Library Settings</button>
  </div>
</section>

<div id="home-rows" hidden>
  <section class="row" id="row-continue" hidden>
    <div class="row-head"><h2>Continue Watching</h2></div>
    <div class="row-scroller" id="continue-scroller"></div>
  </section>

  <section class="row" id="row-recent" hidden>
    <div class="row-head"><h2>Recently Added</h2></div>
    <div class="row-scroller" id="recent-scroller"></div>
  </section>

  <section class="row" id="row-all" hidden>
    <div class="row-head">
      <h2>All Movies</h2>
      <a class="row-more" id="all-movies-link" href="search.php">Browse all <svg class="i" aria-hidden="true"><use href="#i-arrow-right" xlink:href="#i-arrow-right"></use></svg></a>
    </div>
    <div class="row-scroller" id="all-scroller"></div>
  </section>

  <div id="genre-rows"></div>
</div>

<div id="home-loading" class="state-block">
  <span class="spinner" aria-hidden="true"></span>
  <p class="muted">Loading library…</p>
</div>

<?php require __DIR__ . '/includes/page_footer.php'; ?>
