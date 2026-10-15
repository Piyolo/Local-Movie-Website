/**
 * MovieWebsite — movie details page renderer.
 */
(function () {
  'use strict';
  var MW = window.MW;
  var container = document.getElementById('details');

  function $(id) { return document.getElementById(id); }

  document.addEventListener('DOMContentLoaded', function () {
    var id = container.getAttribute('data-movie-id');

    Promise.all([MW.api('api/movie.php?id=' + encodeURIComponent(id)), MW.getProgress()])
      .then(function (results) {
        var movie = results[0].movie;
        var progress = results[1][movie.id];

        document.title = movie.title + ' · MovieWebsite';
        $('details-loading').hidden = true;
        $('details-body').hidden = false;

        $('details-title').textContent = movie.title;

        var metaBits = [];
        if (movie.year) metaBits.push(String(movie.year));
        if (movie.runtime) metaBits.push(movie.runtime);
        if (movie.rating) metaBits.push({ icon: 'star', text: String(movie.rating) });
        var meta = $('details-meta');
        meta.textContent = '';
        metaBits.forEach(function (bit, i) {
          if (i) meta.appendChild(document.createTextNode('·'));
          if (bit && typeof bit === 'object') {
            var sp = document.createElement('span');
            sp.appendChild(MW.icon(bit.icon));
            sp.appendChild(document.createTextNode(' ' + bit.text));
            meta.appendChild(sp);
          } else {
            meta.appendChild(MW.el('span', { text: bit }));
          }
        });
        if (movie.genres && movie.genres.length) {
          meta.appendChild(MW.el('span', { class: 'badge', text: movie.genres.join(' / ') }));
        }

        $('details-overview').textContent = movie.overview || '';
        $('details-overview').hidden = !movie.overview;

        $('details-play').setAttribute('href', movie.watch_url);

        if (progress && !progress.finished && progress.position > 20) {
          var resume = $('details-resume');
          resume.hidden = false;
          resume.textContent = 'Resume at ' + MW.fmtTime(progress.position);
          resume.addEventListener('click', function () {
            location.href = movie.watch_url + '&t=' + Math.floor(progress.position);
          });
        }

        if (movie.backdrop) $('details-backdrop').style.backgroundImage = 'url("' + movie.backdrop + '")';
        else $('details-backdrop').style.backgroundImage = MW.artGradient(movie.title);

        var poster = $('details-poster');
        if (movie.poster) {
          poster.src = movie.poster;
          poster.alt = movie.title + ' poster';
          poster.addEventListener('error', function () {
            poster.replaceWith(MW.posterFallback(movie.title, movie.year));
          });
        } else {
          poster.replaceWith(MW.posterFallback(movie.title, movie.year));
        }

        var facts = $('details-facts');
        facts.textContent = '';
        function fact(label, value) {
          if (!value) return;
          facts.appendChild(MW.el('dt', { text: label }));
          facts.appendChild(MW.el('dd', { text: value }));
        }
        fact('File', movie.filename);
        fact('Size', movie.size_human);
        fact('Added', MW.fmtDate(movie.added));
        if (movie.meta_status === 'no_key') {
          fact('Metadata', 'No TMDB API key configured — showing filename-derived info only.');
        } else if (movie.meta_status === 'not_found') {
          fact('Metadata', 'No match found on TMDB.');
        } else if (movie.meta_status === 'offline') {
          fact('Metadata', 'TMDB unreachable — showing local info.');
        }

        if (movie.subtitle_count > 0) {
          $('details-subs').hidden = false;
          var list = $('details-sub-list');
          list.textContent = '';
          movie.subtitles.forEach(function (t) {
            var label = t.label + (t.ext === 'srt' ? '' : ' .' + t.ext);
            var li = MW.el('li', {
              text: label + (t.supported ? '' : ' (not playable in browser)'),
              class: t.supported ? '' : 'unsupported',
              title: t.filename,
            });
            list.appendChild(li);
          });
        }
      })
      .catch(function (err) {
        $('details-loading').hidden = true;
        $('details-error').hidden = false;
        if (err.status === 404) {
          $('details-error-title').textContent = 'Movie not found';
          $('details-error-msg').textContent = 'It may have been renamed, moved, or removed from the library.';
        } else if (err.code === 'library_unavailable') {
          $('details-error-title').textContent = 'Library unavailable';
          $('details-error-msg').textContent = 'The movie folder cannot be found — its drive may be disconnected.';
        } else {
          $('details-error-title').textContent = 'Could not load movie';
          $('details-error-msg').textContent = err.message;
        }
      });
  });
})();
