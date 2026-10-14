/**
 * MovieWebsite — HTML5 player for watch.php.
 * Custom controls (TV-remote friendly), resume, subtitle menu with
 * mid-playback switching and preferred-language memory, playback speed,
 * fullscreen, progress saving, and specific error states.
 */
(function () {
  'use strict';
  var MW = window.MW;
  var root = document.getElementById('player');
  if (!root) return;

  var movieId = root.getAttribute('data-movie-id');
  var streamUrl = root.getAttribute('data-stream-url');
  var subsUrl = root.getAttribute('data-subs-url');
  var progressUrl = root.getAttribute('data-progress-url');

  var video = document.getElementById('video');
  var stage = document.getElementById('player-stage');

  var movie = null;
  var tracks = [];            // subtitle tracks from api/movie.php
  var activeTrackId = null;
  var trackEl = null;         // single managed <track>
  var duration = 0;
  var lastSavedAt = 0;
  var hideTimer = null;
  var menuOpen = null;

  function $(id) { return document.getElementById(id); }

  /* ---------------- error states ---------------- */

  function showError(title, message) {
    $('player-error-title').textContent = title;
    $('player-error-msg').textContent = message;
    $('player-error').hidden = false;
    $('player-loading').hidden = true;
    $('bigplay').classList.add('hide');
  }

  function apiErrorText(err) {
    if (err.code === 'library_unavailable') {
      return ['Library unavailable', 'The movie folder cannot be found — its drive may be disconnected. Reconnect it and try again.'];
    }
    if (err.status === 404) {
      return ['Movie not found', 'This file is no longer in the library. It may have been renamed or removed.'];
    }
    if (err.status === 503) {
      return ['Library unavailable', 'The movie library cannot be read right now.'];
    }
    return ['Playback error', err.message || 'The video could not be loaded.'];
  }

  function mediaErrorText() {
    var code = video.error ? video.error.code : 0;
    if (code === MediaError.MEDIA_ERR_DECODE) {
      return ['Cannot decode this video', 'The browser could not decode the video stream (unsupported or damaged codec). H.264 MP4 files work best.'];
    }
    if (code === MediaError.MEDIA_ERR_SRC_NOT_SUPPORTED) {
      return ['Format not supported', 'This browser cannot play this file format or codec. Try an MP4 (H.264) file.'];
    }
    if (code === MediaError.MEDIA_ERR_NETWORK) {
      return ['Network interrupted', 'The stream stopped because of a network problem. Check the Wi-Fi connection and try again.'];
    }
    return ['Playback error', 'The video could not be played.'];
  }

  /* ---------------- subtitle menu ---------------- */

  function buildSubsMenu() {
    var menu = $('menu-subs');
    menu.textContent = '';
    menu.appendChild(MW.el('div', { class: 'pc-menu-label', text: 'Subtitles' }));

    var off = MW.el('button', { type: 'button', class: 'pc-menu-item', role: 'menuitem', 'data-track': '' });
    off.appendChild(MW.el('span', { class: 'mi-check', text: activeTrackId ? '' : '✓' }));
    off.appendChild(MW.el('span', { text: 'Off' }));
    off.addEventListener('click', function () { selectTrack(null); });
    menu.appendChild(off);

    if (!tracks.length) {
      menu.appendChild(MW.el('div', { class: 'pc-menu-label', text: 'No subtitles found for this movie' }));
      return;
    }

    tracks.forEach(function (t) {
      var item = MW.el('button', {
        type: 'button',
        class: 'pc-menu-item' + (t.supported ? '' : ' unsupported'),
        role: 'menuitem',
        'data-track': t.id,
      });
      item.appendChild(MW.el('span', { class: 'mi-check', text: t.id === activeTrackId ? '✓' : '' }));
      item.appendChild(MW.el('span', { text: t.label + (t.supported ? '' : ' (unsupported format)') }));
      if (!t.supported) {
        item.title = 'Browsers cannot play .' + t.ext + ' subtitles; convert to SRT or WebVTT.';
      } else {
        item.addEventListener('click', function () { selectTrack(t.id); });
      }
      menu.appendChild(item);
    });
  }

  function trackById(id) {
    for (var i = 0; i < tracks.length; i++) if (tracks[i].id === id) return tracks[i];
    return null;
  }

  function selectTrack(id, keepTime) {
    var t = id ? trackById(id) : null;
    if (t && !t.supported) return;

    if (trackEl && trackEl.parentNode) trackEl.parentNode.removeChild(trackEl);
    trackEl = null;
    activeTrackId = null;

    if (t) {
      trackEl = document.createElement('track');
      trackEl.kind = 'subtitles';
      trackEl.label = t.label;
      trackEl.srclang = t.code || 'xx';
      trackEl.src = subsUrl + '?movie=' + encodeURIComponent(movieId) + '&track=' + encodeURIComponent(t.id);
      video.appendChild(trackEl);
      activeTrackId = t.id;
      var applyMode = function () {
        if (trackEl && trackEl.track) {
          trackEl.track.mode = 'showing';
          $('pc-subs').textContent = 'CC';
          $('pc-subs').style.color = 'var(--accent)';
        }
      };
      if (trackEl.track) applyMode();
      else trackEl.addEventListener('load', applyMode);
      MW.prefs.setSubtitleLang(t.code || '');
    } else {
      $('pc-subs').textContent = 'Subtitles';
      $('pc-subs').style.color = '';
      MW.prefs.setSubtitleLang('');
    }

    var at = keepTime !== false ? video.currentTime : null;
    buildSubsMenu();
    if (at !== null && !isNaN(at) && at > 0) {
      // Switching tracks must not disturb playback position.
      var restore = function () {
        if (Math.abs(video.currentTime - at) > 1.5) video.currentTime = at;
        video.removeEventListener('timeupdate', restore);
      };
      video.addEventListener('timeupdate', restore);
    }
  }

  function autoSelectSubtitle() {
    var pref = MW.prefs.getSubtitleLang();
    if (!pref) return;
    for (var i = 0; i < tracks.length; i++) {
      if (tracks[i].supported && tracks[i].code && tracks[i].code.toLowerCase() === pref.toLowerCase()) {
        selectTrack(tracks[i].id, false);
        return;
      }
    }
  }

  /* ---------------- speed menu ---------------- */

  var SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 2];
  function buildSpeedMenu() {
    var menu = $('menu-speed');
    menu.textContent = '';
    menu.appendChild(MW.el('div', { class: 'pc-menu-label', text: 'Playback speed' }));
    SPEEDS.forEach(function (s) {
      var item = MW.el('button', { type: 'button', class: 'pc-menu-item', role: 'menuitem' });
      item.appendChild(MW.el('span', { class: 'mi-check', text: video.playbackRate === s ? '✓' : '' }));
      item.appendChild(MW.el('span', { text: s === 1 ? 'Normal (1×)' : s + '×' }));
      item.addEventListener('click', function () {
        video.playbackRate = s;
        $('pc-speed').textContent = s === 1 ? '1×' : s + '×';
        closeMenus();
        buildSpeedMenu();
      });
      menu.appendChild(item);
    });
  }

  /* ---------------- menus ---------------- */

  function openMenu(id) {
    closeMenus();
    menuOpen = id;
    $(id).hidden = false;
    if (id === 'menu-subs') { $('pc-subs').setAttribute('aria-expanded', 'true'); buildSubsMenu(); }
    if (id === 'menu-speed') { $('pc-speed').setAttribute('aria-expanded', 'true'); buildSpeedMenu(); }
    var first = $(id).querySelector('.pc-menu-item');
    if (first) first.focus();
  }

  function closeMenus() {
    ['menu-subs', 'menu-speed'].forEach(function (id) { $(id).hidden = true; });
    $('pc-subs').setAttribute('aria-expanded', 'false');
    $('pc-speed').setAttribute('aria-expanded', 'false');
    menuOpen = null;
  }

  /* ---------------- controls visibility ---------------- */

  function showControls() {
    $('controls').classList.remove('hide');
    if (hideTimer) clearTimeout(hideTimer);
    hideTimer = setTimeout(function () {
      if (!video.paused && !menuOpen && $('player-error').hidden) {
        $('controls').classList.add('hide');
      }
    }, 3400);
  }

  /* ---------------- seek / volume sliders ---------------- */

  function sliderRatio(el, clientX) {
    var rect = el.getBoundingClientRect();
    return Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
  }

  function wireSlider(el, onRatio) {
    var dragging = false;
    el.addEventListener('pointerdown', function (e) {
      dragging = true;
      el.setPointerCapture && el.setPointerCapture(e.pointerId);
      onRatio(sliderRatio(el, e.clientX));
    });
    el.addEventListener('pointermove', function (e) {
      if (dragging) onRatio(sliderRatio(el, e.clientX));
    });
    el.addEventListener('pointerup', function () { dragging = false; });
    el.addEventListener('pointercancel', function () { dragging = false; });
  }

  function updateSeekUI() {
    var pct = duration > 0 ? (video.currentTime / duration) * 100 : 0;
    $('pc-fill').style.width = pct + '%';
    $('pc-thumb').style.left = pct + '%';
    $('pc-current').textContent = MW.fmtTime(video.currentTime);
    $('pc-seek').setAttribute('aria-valuenow', Math.round(pct));
    try {
      if (video.buffered.length && duration > 0) {
        var end = video.buffered.end(video.buffered.length - 1);
        $('pc-buffer').style.width = Math.min(100, (end / duration) * 100) + '%';
      }
    } catch (e) { /* buffered not ready */ }
  }

  function seekBy(delta) {
    if (!duration) return;
    video.currentTime = Math.min(duration, Math.max(0, video.currentTime + delta));
    showControls();
  }

  function setVolumeBy(delta) {
    video.volume = Math.min(1, Math.max(0, video.volume + delta));
    updateVolumeUI();
    showControls();
  }

  function updateVolumeUI() {
    $('pc-vol-fill').style.width = Math.round(video.volume * 100) + '%';
    $('pc-vol').setAttribute('aria-valuenow', Math.round(video.volume * 100));
    $('pc-mute').textContent = video.muted || video.volume === 0 ? '🔇' : '🔊';
  }

  /* ---------------- progress saving ---------------- */

  function saveProgress(beacon) {
    if (!duration || duration < 30) return;
    MW.saveProgress(movieId, Math.floor(video.currentTime), Math.floor(duration), beacon);
    lastSavedAt = Date.now();
  }

  /* ---------------- playback wiring ---------------- */

  var stallTimer = null;
  var stallStartPos = null;

  function armStallWatchdog() {
    if (stallTimer) clearTimeout(stallTimer);
    stallStartPos = video.currentTime;
    stallTimer = setTimeout(function () {
      // Still waiting after 12s with no progress → the decoder is stuck.
      // (Very common on smart-TV browsers with 5.1 surround audio tracks.)
      if (!video.paused && video.readyState < 3 &&
          Math.abs(video.currentTime - (stallStartPos || 0)) < 0.5) {
        showError(
          'This device cannot play the video',
          'The TV/browser started streaming but its video decoder never began playback. ' +
          'The most common cause is the 5.1 surround-sound audio track in this file — many smart-TV browsers only support stereo. ' +
          'See docs/TROUBLESHOOTING.md ("Playback spins forever on a TV") for fixes.'
        );
      }
    }, 12000);
  }

  function disarmStallWatchdog() {
    if (stallTimer) { clearTimeout(stallTimer); stallTimer = null; }
  }

  function togglePlay() {
    if (video.paused) video.play().catch(function () {});
    else video.pause();
  }

  function wireEvents() {
    $('pc-play').addEventListener('click', togglePlay);
    $('bigplay').addEventListener('click', togglePlay);
    video.addEventListener('click', togglePlay);

    video.addEventListener('play', function () {
      $('pc-play').textContent = '❚❚';
      $('bigplay').classList.add('hide');
      $('player-loading').hidden = true;
      showControls();
    });
    video.addEventListener('pause', function () {
      $('pc-play').textContent = '▶';
      $('bigplay').classList.remove('hide');
      $('controls').classList.remove('hide');
      saveProgress(false);
    });
    video.addEventListener('waiting', function () { $('player-loading').hidden = false; armStallWatchdog(); });
    video.addEventListener('stalled', function () { armStallWatchdog(); });
    video.addEventListener('playing', function () { $('player-loading').hidden = true; disarmStallWatchdog(); });
    video.addEventListener('canplay', function () { $('player-loading').hidden = true; disarmStallWatchdog(); });

    video.addEventListener('loadedmetadata', function () {
      duration = video.duration || 0;
      $('pc-duration').textContent = MW.fmtTime(duration);
      updateSeekUI();

      var params = new URLSearchParams(location.search);
      var tParam = parseInt(params.get('t') || '0', 10);
      if (tParam > 0 && tParam < duration) {
        video.currentTime = tParam;
        MW.toast('Resuming from ' + MW.fmtTime(tParam));
      }
    });
    video.addEventListener('timeupdate', function () {
      updateSeekUI();
      if (duration > 0 && video.currentTime - lastSavedAt / 1000 > 10) saveProgress(false);
      if (duration > 0 && video.currentTime / duration >= 0.9) {
        // Mark finished once, near the end.
        if (!video.dataset.finished) {
          video.dataset.finished = '1';
          MW.saveProgress(movieId, Math.floor(duration), Math.floor(duration), false);
        }
      }
    });
    video.addEventListener('progress', updateSeekUI);
    video.addEventListener('ended', function () {
      MW.saveProgress(movieId, Math.floor(duration), Math.floor(duration), false);
      $('bigplay').classList.remove('hide');
    });
    video.addEventListener('error', function () {
      if (!video.src && !video.currentSrc) return; // src swap noise
      var pair = mediaErrorText();
      showError(pair[0], pair[1]);
    });

    wireSlider($('pc-seek'), function (ratio) {
      if (!duration) return;
      video.currentTime = ratio * duration;
      updateSeekUI();
    });
    wireSlider($('pc-vol'), function (ratio) {
      video.volume = ratio;
      video.muted = ratio === 0;
      updateVolumeUI();
    });

    $('pc-seek').addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { seekBy(-10); e.preventDefault(); }
      if (e.key === 'ArrowRight') { seekBy(10); e.preventDefault(); }
    });
    $('pc-vol').addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { setVolumeBy(-0.1); e.preventDefault(); }
      if (e.key === 'ArrowUp') { setVolumeBy(0.1); e.preventDefault(); }
    });

    $('pc-mute').addEventListener('click', function () {
      video.muted = !video.muted;
      updateVolumeUI();
    });

    $('pc-subs').addEventListener('click', function () {
      if (menuOpen === 'menu-subs') closeMenus(); else openMenu('menu-subs');
      showControls();
    });
    $('pc-speed').addEventListener('click', function () {
      if (menuOpen === 'menu-speed') closeMenus(); else openMenu('menu-speed');
      showControls();
    });

    $('pc-full').addEventListener('click', function () {
      if (document.fullscreenElement || document.webkitFullscreenElement) {
        (document.exitFullscreen || document.webkitExitFullscreen).call(document);
      } else if (stage.requestFullscreen) stage.requestFullscreen();
      else if (stage.webkitRequestFullscreen) stage.webkitRequestFullscreen();
      else if (video.webkitEnterFullscreen) video.webkitEnterFullscreen(); // iOS
    });

    $('player-retry').addEventListener('click', function () {
      $('player-error').hidden = true;
      $('player-loading').hidden = false;
      video.load();
    });

    document.addEventListener('mousemove', showControls);
    document.addEventListener('pointerdown', function () { showControls(); });

    document.addEventListener('keydown', function (e) {
      if (e.target && /INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) return;
      if (menuOpen && e.key === 'Escape') { closeMenus(); showControls(); return; }
      switch (e.key) {
        case ' ':
        case 'k':
        case 'K':
          togglePlay(); e.preventDefault(); break;
        case 'ArrowLeft':
          seekBy(-10); e.preventDefault(); break;
        case 'ArrowRight':
          seekBy(10); e.preventDefault(); break;
        case 'ArrowUp':
          setVolumeBy(0.1); e.preventDefault(); break;
        case 'ArrowDown':
          setVolumeBy(-0.1); e.preventDefault(); break;
        case 'm':
        case 'M':
          video.muted = !video.muted; updateVolumeUI(); break;
        case 'f':
        case 'F':
          $('pc-full').click(); break;
        case 'c':
        case 'C': {
          var supported = tracks.filter(function (t) { return t.supported; });
          if (!supported.length) break;
          var idx = -1;
          for (var i = 0; i < supported.length; i++) if (supported[i].id === activeTrackId) idx = i;
          selectTrack(idx + 1 >= supported.length ? null : supported[idx + 1].id);
          showControls();
          break;
        }
        case 'Escape':
          if (document.fullscreenElement) document.exitFullscreen();
          else history.back();
          break;
      }
    });

    // Save progress when leaving (TV browsers included).
    window.addEventListener('pagehide', function () { saveProgress(true); });
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') saveProgress(true);
    });

    if ('mediaSession' in navigator && window.MediaMetadata) {
      navigator.mediaSession.setActionHandler('play', function () { video.play(); });
      navigator.mediaSession.setActionHandler('pause', function () { video.pause(); });
      navigator.mediaSession.setActionHandler('seekbackward', function () { seekBy(-10); });
      navigator.mediaSession.setActionHandler('seekforward', function () { seekBy(10); });
    }
  }

  /* ---------------- boot ---------------- */

  document.addEventListener('DOMContentLoaded', function () {
    wireEvents();
    updateVolumeUI();

    MW.api('api/movie.php?id=' + encodeURIComponent(movieId))
      .then(function (data) {
        movie = data.movie;
        tracks = (movie.subtitles || []).slice();
        document.title = 'Watch ' + movie.title + ' · MovieWebsite';
        $('watch-title').textContent = '';
        $('watch-title').appendChild(MW.el('h1', { text: movie.title }));
        $('watch-title').appendChild(MW.el('div', { class: 'muted', text: [movie.year, movie.runtime].filter(Boolean).join(' · ') }));
        $('player-back').setAttribute('href', movie.details_url);

        if ('mediaSession' in navigator && window.MediaMetadata) {
          try {
            navigator.mediaSession.metadata = new window.MediaMetadata({
              title: movie.title,
              artist: movie.year ? String(movie.year) : 'MovieWebsite',
            });
          } catch (e) { /* optional */ }
        }

        buildSubsMenu();
        autoSelectSubtitle();

        // Pre-check: does this browser claim ANY mp4/H.264 support at all?
        // (Some very old TV browsers return "" — fail fast with a clear message
        // instead of an eternal spinner.)
        var probe = document.createElement('video');
        var mp4 = probe.canPlayType('video/mp4');
        var h264 = probe.canPlayType('video/mp4; codecs="avc1.64001E, mp4a.40.2"') ||
                   probe.canPlayType('video/mp4; codecs="avc1.42E01E, mp4a.40.2"');
        if (mp4 === 'no' || (mp4 === '' && !h264)) {
          showError(
            'This browser cannot play MP4 video',
            'The device browser reports no support for H.264/MP4. Try the TV’s built-in media player app, or a different browser on this device.'
          );
          return;
        }

        video.src = streamUrl;
        $('player-loading').hidden = false;

        // Cross-device resume comes from the server-side progress store.
        MW.getProgress().then(function (map) {
          var entry = map[movieId];
          var params = new URLSearchParams(location.search);
          if (!params.get('t') && entry && !entry.finished && entry.position > 30 && entry.position < (entry.duration || Infinity) - 30) {
            var resumeAt = entry.position;
            var once = function () {
              video.currentTime = resumeAt;
              MW.toast('Resuming from ' + MW.fmtTime(resumeAt));
              video.removeEventListener('loadedmetadata', once);
            };
            video.addEventListener('loadedmetadata', once);
          }
        });
      })
      .catch(function (err) {
        var pair = apiErrorText(err);
        showError(pair[0], pair[1]);
      });
  });
})();
