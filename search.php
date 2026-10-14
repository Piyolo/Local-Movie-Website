<?php
/**
 * Browse / search page — filter by text + genre, sort, grid of all movies.
 */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$PAGE_TITLE     = 'All Movies';
$ACTIVE_NAV     = 'movies';
$PAGE_BODY_CLASS = 'page-browse';
$PAGE_SCRIPTS   = ['js/search.js'];
require __DIR__ . '/includes/page_header.php';
?>

<section class="browse-head">
  <h1>All Movies</h1>
  <div class="browse-controls">
    <label class="field field-inline">
      <span class="sr-only">Search titles</span>
      <input type="search" id="browse-q" placeholder="Search titles…" autocomplete="off">
    </label>
    <label class="field field-inline">
      <span class="sr-only">Genre</span>
      <select id="browse-genre"><option value="">All genres</option></select>
    </label>
    <label class="field field-inline">
      <span class="sr-only">Sort</span>
      <select id="browse-sort">
        <option value="added-desc">Recently added</option>
        <option value="added-asc">Oldest added</option>
        <option value="title-asc">Title A→Z</option>
        <option value="title-desc">Title Z→A</option>
        <option value="year-desc">Year (new→old)</option>
        <option value="year-asc">Year (old→new)</option>
      </select>
    </label>
    <span class="muted" id="browse-count"></span>
  </div>
</section>

<div id="browse-grid" class="grid"></div>

<div id="browse-empty" class="state-block" hidden>
  <h2 id="browse-empty-title">No movies match</h2>
  <p class="muted" id="browse-empty-msg">Try a different search or filter.</p>
</div>

<div id="browse-loading" class="state-block">
  <span class="spinner" aria-hidden="true"></span>
  <p class="muted">Loading library…</p>
</div>

<?php require __DIR__ . '/includes/page_footer.php'; ?>
