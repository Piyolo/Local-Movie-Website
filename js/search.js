/**
 * MovieWebsite — browse/search page.
 * Client-side filter+sort over one library load (debounced input).
 */
(function () {
  'use strict';
  var MW = window.MW;
  var movies = [];
  var progressMap = {};

  function $(id) { return document.getElementById(id); }

  function currentFilters() {
    return {
      q: $('browse-q').value.trim().toLowerCase(),
      genre: $('browse-genre').value,
      sort: $('browse-sort').value,
    };
  }

  function apply() {
    var f = currentFilters();
    var list = movies.slice();

    if (f.q) {
      list = list.filter(function (m) {
        return m.title.toLowerCase().indexOf(f.q) !== -1 ||
               (m.filename || '').toLowerCase().indexOf(f.q) !== -1;
      });
    }
    if (f.genre) {
      list = list.filter(function (m) {
        return (m.genres || []).indexOf(f.genre) !== -1;
      });
    }

    var parts = f.sort.split('-');
    var key = parts[0];
    var dir = parts[1] === 'asc' ? 1 : -1;
    list.sort(function (a, b) {
      var cmp = 0;
      if (key === 'title') cmp = a.title.localeCompare(b.title) * dir;
      else if (key === 'year') cmp = ((a.year || 0) - (b.year || 0)) * dir;
      else cmp = ((a.mtime || 0) - (b.mtime || 0)) * dir;
      if (cmp === 0) cmp = a.title.localeCompare(b.title);
      return cmp;
    });

    var grid = $('browse-grid');
    grid.textContent = '';
    list.forEach(function (m) { grid.appendChild(MW.card(m, progressMap[m.id])); });
    grid.hidden = list.length === 0;

    $('browse-count').textContent = list.length + ' of ' + movies.length + ' movies';
    $('browse-empty').hidden = list.length !== 0;
    if (list.length === 0 && movies.length > 0) {
      $('browse-empty-title').textContent = 'No movies match';
      $('browse-empty-msg').textContent = 'Try a different search or filter.';
    }
  }

  var debounceTimer = null;
  function debounceApply() {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(apply, 180);
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Prefill from URL (?q=...&view=search focuses the field).
    var params = new URLSearchParams(location.search);
    if (params.get('q')) $('browse-q').value = params.get('q');

    Promise.all([MW.loadMovies(false), MW.getProgress()])
      .then(function (results) {
        movies = results[0].movies;
        progressMap = results[1];

        $('browse-loading').hidden = true;

        var seen = {};
        movies.forEach(function (m) {
          (m.genres || []).forEach(function (g) { seen[g] = (seen[g] || 0) + 1; });
        });
        Object.keys(seen).sort().forEach(function (g) {
          $('browse-genre').appendChild(MW.el('option', { value: g, text: g + ' (' + seen[g] + ')' }));
        });

        $('browse-q').addEventListener('input', debounceApply);
        $('browse-genre').addEventListener('change', apply);
        $('browse-sort').addEventListener('change', apply);

        if (params.get('view') === 'search') $('browse-q').focus();
        apply();
      })
      .catch(function (err) {
        $('browse-loading').hidden = true;
        if (err.code === 'library_unavailable' || err.code === 'library_unreadable') {
          $('browse-empty').hidden = false;
          $('browse-empty-title').textContent = 'Library unavailable';
          $('browse-empty-msg').textContent = 'The movie folder cannot be found — its drive may be disconnected.';
          MW.banner('The movie library folder is unavailable.', 'Retry', function () { location.reload(); });
        } else {
          MW.toast(err.message, 'err');
        }
      });
  });
})();
