/**
 * MovieWebsite — shared client core.
 * Exposes window.MW with: api(), dom helpers, card rendering, movie cache,
 * progress access, formatting, toast + library banner handling.
 *
 * Security note: DOM is built with createElement/textContent only —
 * filenames and titles are never injected as HTML.
 */
(function () {
  'use strict';

  var MW = (window.MW = window.MW || {});

  var baseMeta = document.querySelector('meta[name="mw-base"]');
  MW.base = baseMeta ? baseMeta.getAttribute('content') || '' : '';

  /* URLSearchParams polyfill — older smart-TV browsers lack it. */
  if (typeof window.URLSearchParams === 'undefined') {
    window.URLSearchParams = function (search) {
      var self = this;
      this._map = {};
      String(search || '').replace(/^\?/, '').split('&').forEach(function (pair) {
        if (!pair) return;
        var kv = pair.split('=');
        try {
          self._map[decodeURIComponent(kv[0])] = decodeURIComponent((kv[1] || '').replace(/\+/g, ' '));
        } catch (e) { /* malformed pair */ }
      });
      this.get = function (k) {
        return Object.prototype.hasOwnProperty.call(self._map, k) ? self._map[k] : null;
      };
    };
  }

  /* ---------------- mobile nav menu ----------------
   * The burger button is only displayed <=860px by CSS; on desktop and
   * TVs this wires up but the button never renders and nothing changes. */
  var navRoot = document.getElementById('topnav');
  var navBurger = document.getElementById('nav-burger');
  var navPanel = document.getElementById('nav-links');
  if (navRoot && navBurger && navPanel) {
    var setNavOpen = function (open) {
      navRoot.classList.toggle('menu-open', open);
      navBurger.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    navBurger.addEventListener('click', function (e) {
      e.stopPropagation();
      setNavOpen(!navRoot.classList.contains('menu-open'));
    });
    navPanel.addEventListener('click', function () { setNavOpen(false); }); // link chosen
    document.addEventListener('click', function (e) {
      if (navRoot.classList.contains('menu-open') && !navRoot.contains(e.target)) setNavOpen(false);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') setNavOpen(false);
    });
  }

  /* ---------------- API ---------------- */

  MW.api = function (path, opts) {
    opts = opts || {};
    var init = { method: opts.method || 'GET' };
    if (opts.body !== undefined) {
      init.headers = { 'Content-Type': 'application/json' };
      init.body = JSON.stringify(opts.body);
    }
    if (opts.csrf) {
      init.headers = init.headers || {};
      init.headers['X-CSRF-Token'] = opts.csrf;
    }
    return fetch(MW.base + '/' + String(path).replace(/^\/+/, ''), init).then(function (res) {
      return res
        .json()
        .catch(function () {
          return null;
        })
        .then(function (data) {
          if (!res.ok) {
            var err = new Error((data && data.error) || 'Request failed (' + res.status + ')');
            err.status = res.status;
            err.code = data && data.code;
            err.data = data;
            throw err;
          }
          return data;
        });
    });
  };

  /* ---------------- DOM helpers ---------------- */

  MW.el = function (tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (key) {
        var val = attrs[key];
        if (val === null || val === undefined || val === false) return;
        if (key === 'text') node.textContent = String(val);
        else if (key === 'class') node.className = String(val);
        else if (key === 'href') node.setAttribute('href', String(val));
        else if (key === 'style' && typeof val === 'object') Object.assign(node.style, val);
        else node.setAttribute(key, val === true ? '' : String(val));
      });
    }
    (children || []).forEach(function (child) {
      if (child) node.appendChild(child);
    });
    return node;
  };

  /* Inline icon from the sprite in page_header.php: MW.icon('play') → <svg class="i"> */
  MW.icon = function (name) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'i');
    svg.setAttribute('aria-hidden', 'true');
    var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', '#i-' + name);
    use.setAttribute('xlink:href', '#i-' + name);
    svg.appendChild(use);
    return svg;
  };

  MW.qs = function (sel, root) { return (root || document).querySelector(sel); };
  MW.qsa = function (sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  };

  /* ---------------- formatting ---------------- */

  MW.fmtTime = function (sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    var mm = h > 0 ? (m < 10 ? '0' + m : String(m)) : String(m);
    var ss = s < 10 ? '0' + s : String(s);
    return (h > 0 ? h + ':' : '') + mm + ':' + ss;
  };

  MW.fmtDate = function (iso) {
    if (!iso) return '';
    try {
      return new Date(iso).toLocaleString();
    } catch (e) {
      return String(iso);
    }
  };

  /* ---------------- toast + banner ---------------- */

  var toastTimer = null;
  MW.toast = function (message, kind) {
    var t = document.getElementById('toast');
    if (!t) return;
    t.textContent = message;
    t.className = 'toast' + (kind ? ' ' + kind : '');
    t.hidden = false;
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      t.hidden = true;
    }, kind === 'err' ? 5200 : 3200);
  };

  MW.banner = function (message, actionLabel, action) {
    var b = document.getElementById('global-banner');
    if (!b) return;
    b.textContent = '';
    if (!message) {
      b.hidden = true;
      return;
    }
    b.appendChild(document.createTextNode(message));
    if (actionLabel) {
      var btn = MW.el('button', { type: 'button', class: 'btn btn-sm', text: actionLabel });
      btn.addEventListener('click', action || function () {});
      b.appendChild(btn);
    }
    b.hidden = false;
  };

  /* ---------------- movie list cache ---------------- */
  // api/movies.php performs a fresh scan; cache client-side for 30s so page
  // navigation does not rescan the directory repeatedly.

  var MOVIE_CACHE_TTL = 30000;
  var movieCache = null; // { t: ms, data: {...} }

  MW.loadMovies = function (force) {
    if (!force && movieCache && Date.now() - movieCache.t < MOVIE_CACHE_TTL) {
      return Promise.resolve(movieCache.data);
    }
    return MW.api('api/movies.php').then(function (data) {
      movieCache = { t: Date.now(), data: data };
      return data;
    });
  };

  MW.invalidateMovies = function () {
    movieCache = null;
  };

  /* ---------------- progress ---------------- */

  MW.getProgress = function () {
    return MW.api('api/progress.php').then(function (data) {
      return data && data.progress ? data.progress : {};
    }).catch(function () {
      return {};
    });
  };

  MW.saveProgress = function (id, position, duration, useBeacon) {
    var payload = JSON.stringify({ id: id, position: position, duration: duration });
    if (useBeacon && navigator.sendBeacon) {
      navigator.sendBeacon(MW.base + '/api/progress.php', new Blob([payload], { type: 'application/json' }));
      return;
    }
    fetch(MW.base + '/api/progress.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: payload,
      keepalive: true,
    }).catch(function () {});
  };

  /* ---------------- poster / card rendering ---------------- */

  /* Deterministic per-title hue (0–359): the same movie always gets the same
   * generated cover art, and different movies look different. */
  MW.titleHue = function (text) {
    var s = String(text || 'movie');
    var hash = 0;
    for (var i = 0; i < s.length; i++) hash = ((hash * 31) + s.charCodeAt(i)) >>> 0;
    return hash % 360;
  };

  /* Up-to-two-letter monogram: "The Dark Knight" -> "DK", "Weapons" -> "W". */
  MW.titleInitials = function (text) {
    var words = String(text || '').split(/[\s\-._:]+/).filter(Boolean);
    if (!words.length) return 'M';
    var keep = words.filter(function (w) { return !/^(the|a|an|of|and|le|la|el)$/i.test(w); });
    var src = keep.length ? keep : words;
    if (src.length === 1) return src[0].charAt(0).toUpperCase();
    return (src[0].charAt(0) + src[1].charAt(0)).toUpperCase();
  };

  /* Title-hue gradient for hero/details backdrops when no real banner exists. */
  MW.artGradient = function (title) {
    var hue = MW.titleHue(title);
    return 'radial-gradient(95% 120% at 22% 8%, hsla(' + hue + ', 68%, 44%, .5), transparent 62%), ' +
      'linear-gradient(160deg, hsl(' + hue + ', 40%, 21%), #0a0c11 80%)';
  };

  MW.posterFallback = function (title, year) {
    var t = title || 'Movie';
    var wrap = MW.el('div', { class: 'card-poster-fallback' });
    wrap.style.setProperty('--fb-h', String(MW.titleHue(t)));
    wrap.appendChild(MW.el('span', { class: 'fp-initials', text: MW.titleInitials(t) }));
    wrap.appendChild(MW.el('span', { class: 'fp-title', text: t }));
    if (year) wrap.appendChild(MW.el('span', { class: 'fp-year', text: String(year) }));
    return wrap;
  };

  MW.posterImage = function (movie, altText) {
    if (!movie.poster) return null;
    var img = MW.el('img', {
      class: 'card-poster',
      loading: 'lazy',
      decoding: 'async',
      alt: altText || '',
      src: movie.poster,
    });
    img.addEventListener('error', function () {
      var fallback = MW.posterFallback(movie.title, movie.year);
      if (img.parentNode) img.parentNode.replaceChild(fallback, img);
    });
    return img;
  };

  /**
   * Build one movie card (<a> to details, poster, title, meta, badges,
   * optional continue-watching progress bar).
   */
  MW.card = function (movie, progressEntry) {
    var href = movie.details_url || MW.base + '/movie.php?id=' + encodeURIComponent(movie.id);
    var card = MW.el('a', { class: 'card', href: href, title: movie.title });

    var pw = MW.el('div', { class: 'card-poster-wrap' });
    var img = MW.posterImage(movie);
    if (img) pw.appendChild(img);
    else pw.appendChild(MW.posterFallback(movie.title, movie.year));
    var hint = MW.el('div', { class: 'card-playhint', 'aria-hidden': 'true' });
    hint.appendChild(MW.icon('play'));
    pw.appendChild(hint);
    card.appendChild(pw);

    card.appendChild(MW.el('div', { class: 'card-title', text: movie.title }));

    var sub = MW.el('div', { class: 'card-sub' });
    sub.appendChild(MW.el('span', { text: movie.year ? String(movie.year) : (movie.ext || '').toUpperCase() }));
    if (movie.subtitle_count > 0) {
      sub.appendChild(MW.el('span', { class: 'card-subs-badge', text: 'CC ' + movie.subtitle_count }));
    }
    card.appendChild(sub);

    if (progressEntry && !progressEntry.finished && progressEntry.position > 20 && progressEntry.duration > 0) {
      var pct = Math.min(100, (progressEntry.position / progressEntry.duration) * 100);
      var bar = MW.el('div', { class: 'card-progress' });
      var fill = MW.el('div', { class: 'card-progress-fill' });
      fill.style.width = pct.toFixed(1) + '%';
      bar.appendChild(fill);
      card.appendChild(bar);
      card.setAttribute('aria-label', movie.title + ' — ' + Math.round(pct) + '% watched');
    }
    return card;
  };

  /* ---------------- row builder ---------------- */

  MW.fillScroller = function (scroller, movies, progressMap) {
    scroller.textContent = '';
    movies.forEach(function (m) {
      scroller.appendChild(MW.card(m, progressMap ? progressMap[m.id] : null));
    });
    var row = scroller.closest('.row');
    if (row) row.hidden = movies.length === 0;
  };

  /* ---------------- shared error handling ---------------- */

  MW.handleLibraryError = function (err, retry) {
    if (err && err.code === 'library_unavailable') {
      MW.banner(
        'The movie library folder is unavailable — its drive may be disconnected.',
        retry ? 'Try again' : null,
        retry || function () {}
      );
      return true;
    }
    if (err && err.status === 401) return true; // handled by caller UI
    MW.toast(err && err.message ? err.message : 'Something went wrong.', 'err');
    return true;
  };
})();
