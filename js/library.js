/**
 * MovieWebsite — homepage renderer.
 * Hero (most recent movie), Continue Watching, Recently Added, All Movies,
 * genre rows; empty and library-unavailable states; bounded metadata
 * enrichment kicked off after first paint.
 */
(function () {
  'use strict';
  var MW = window.MW;
  var REFRESH_AFTER_ENRICH = 12000; // re-render once metadata arrives

  function enrichPass(done) {
    // One bounded server pass; repeat while work remains, with breathing room
    // between passes so scanning/playback stay responsive.
    MW.api('api/metadata.php?action=enrich')
      .then(function (data) {
        if (!data.done) {
          setTimeout(function () { enrichPass(done); }, 1500);
        } else {
          done();
        }
      })
      .catch(function () { /* offline/TMDB absent: fallback art stays */ });
  }

  function kickEnrichment(whenDone) {
    var any = false;
    // Heuristic: enrich only if at least one movie still lacks artwork/meta.
    MW.qsa('img.card-poster').length; // no-op keepalive
    enrichPass(whenDone);
  }

  function renderHero(movie) {
    var hero = document.getElementById('hero');
    if (!movie) { hero.hidden = true; return; }
    hero.hidden = false;

    var bd = document.getElementById('hero-backdrop');
    /* Real banner when TMDB provided one; otherwise a title-hue generated
     * gradient (a portrait poster stretched across the hero reads as blur). */
    bd.style.backgroundImage = movie.backdrop
      ? 'url("' + movie.backdrop + '")'
      : MW.artGradient(movie.title);
    bd.style.backgroundColor = '';

    document.getElementById('hero-title').textContent = movie.title;

    var metaBits = [];
    if (movie.year) metaBits.push(String(movie.year));
    if (movie.runtime) metaBits.push(movie.runtime);
    if (movie.rating) metaBits.push('★ ' + movie.rating);
    if (movie.subtitle_count > 0) metaBits.push(movie.subtitle_count + ' subtitles');
    document.getElementById('hero-meta').textContent = metaBits.join('  ·  ');

    var ov = document.getElementById('hero-overview');
    ov.textContent = movie.overview || ('File: ' + movie.filename);
    ov.hidden = !movie.overview && !movie.filename;

    document.getElementById('hero-play').setAttribute('href', movie.watch_url);
    document.getElementById('hero-details').setAttribute('href', movie.details_url);
  }

  function render(movies, progressMap) {
    var loading = document.getElementById('home-loading');
    loading.hidden = true;

    if (movies.length === 0) {
      document.getElementById('home-empty').hidden = false;
      document.getElementById('home-rows').hidden = true;
      renderHero(null);
      return;
    }

    document.getElementById('home-empty').hidden = true;
    var rows = document.getElementById('home-rows');
    rows.hidden = false;

    // Hero: most recently added movie.
    var newest = movies.slice().sort(function (a, b) { return b.mtime - a.mtime; })[0];
    renderHero(newest);

    // Continue Watching: progress entries, newest first, still-existing movies.
    var continueList = [];
    Object.keys(progressMap).forEach(function (id) {
      var entry = progressMap[id];
      var movie = movies.filter(function (m) { return m.id === id; })[0];
      if (movie && entry && !entry.finished && entry.position > 20) {
        continueList.push({ movie: movie, entry: entry });
      }
    });
    continueList.sort(function (a, b) { return b.entry.updated - a.entry.updated; });
    var continueScroller = document.getElementById('continue-scroller');
    continueScroller.textContent = '';
    continueList.forEach(function (item) {
      continueScroller.appendChild(MW.card(item.movie, item.entry));
    });
    document.getElementById('row-continue').hidden = continueList.length === 0;

    // Recently Added (top 12).
    MW.fillScroller(
      document.getElementById('recent-scroller'),
      movies.slice().sort(function (a, b) { return b.mtime - a.mtime; }).slice(0, 12),
      progressMap
    );

    // All Movies preview (top 18 alphabetical).
    MW.fillScroller(
      document.getElementById('all-scroller'),
      movies.slice().sort(function (a, b) { return a.title.localeCompare(b.title); }).slice(0, 18),
      progressMap
    );
    document.getElementById('row-all').hidden = false;
    var allLink = document.getElementById('all-movies-link');
    allLink.setAttribute('href', MW.base + '/search.php');

    // Genre rows (only genres from real metadata).
    var genreHost = document.getElementById('genre-rows');
    genreHost.textContent = '';
    var byGenre = {};
    movies.forEach(function (m) {
      (m.genres || []).forEach(function (g) {
        (byGenre[g] = byGenre[g] || []).push(m);
      });
    });
    Object.keys(byGenre).sort(function (a, b) { return byGenre[b].length - byGenre[a].length; })
      .slice(0, 8)
      .forEach(function (genre) {
        var section = MW.el('section', { class: 'row' });
        section.appendChild(MW.el('div', { class: 'row-head' }, [
          MW.el('h2', { text: genre }),
        ]));
        var scroller = MW.el('div', { class: 'row-scroller', id: 'genre-' + genre.replace(/\W+/g, '-').toLowerCase() });
        section.appendChild(scroller);
        genreHost.appendChild(section);
        MW.fillScroller(scroller, byGenre[genre], progressMap);
      });
  }

  function needsEnrichment(movies) {
    return movies.some(function (m) {
      return m.meta_status === 'pending' || (!m.poster && m.meta_status !== 'not_found' && m.meta_status !== 'no_key');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    MW.banner(''); // clear stale banner
    Promise.all([MW.loadMovies(true), MW.getProgress()])
      .then(function (results) {
        var data = results[0];
        var progressMap = results[1];
        render(data.movies, progressMap);

        if (needsEnrichment(data.movies)) {
          setTimeout(function () {
            kickEnrichment(function () {
              // Quietly re-render with artwork once metadata resolves.
              MW.loadMovies(true).then(function (fresh) {
                render(fresh.movies, progressMap);
              }).catch(function () {});
            });
          }, REFRESH_AFTER_ENRICH);
        }
      })
      .catch(function (err) {
        document.getElementById('home-loading').hidden = true;
        if (err.code === 'library_unavailable' || err.code === 'library_unreadable') {
          document.getElementById('home-empty').hidden = false;
          document.querySelector('#home-empty h2').textContent = 'Library unavailable';
          document.querySelector('#home-empty .muted').textContent =
            'The movie folder cannot be found — its drive may be disconnected. Reconnect the drive, then rescan from Library Settings.';
          MW.banner('The movie library folder is unavailable. Movies will return once the drive is reconnected.', 'Retry', function () {
            location.reload();
          });
        } else {
          MW.toast(err.message, 'err');
        }
      });
  });
})();
