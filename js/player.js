/**
 * MovieWebsite — HTML5 player for watch.php.
 * Custom controls (TV-remote friendly, always visible on TV), resume,
 * subtitle menu with mid-playback switching and an always-English default,
 * playback speed, TV-safe fullscreen (CSS pseudo-fullscreen on TV browsers),
 * progress saving, and specific error states.
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

  // TV / touch-remote environments: no pointer to summon hidden controls,
  // and their system players hijack the browser Fullscreen API (the native
  // overlay you must Back out of). Phones are the opposite case: a tap
  // summons controls, and native fullscreen is what mobile users expect.
  //
  // classifyMode(ua) is the pure UA split (unit-tested in tests/
  // player_mode.test.js):
  //   'mobile' — carries the "Mobile"/iOS device token → phone player,
  //              even though the UA also says Android and the pointer is
  //              coarse (that used to shove every phone into TV mode).
  //   'tv'     — named TV runtimes, or Android WITHOUT the "Mobile" token
  //              (Android TV / Google TV browsers never say "Mobile").
  //   'other'  — desktops and unknowns; isTvLike() falls back to the
  //              touch-first media queries, so odd tablets and no-name TV
  //              browsers keep the safe always-visible-controls behavior.
  // MW.forceTv is a debug/test override; watch.php?tvdebug=1 shows a report.
  function classifyMode(ua) {
    if (/\b(Mobile|iPhone|iPad|iPod)\b/i.test(ua)) return 'mobile';
    if (/\b(GoogleTV|SmartTV|HbbTV|Tizen|Web0S|Roku|VIDAA|NetCast|Android)\b/i.test(ua)) return 'tv';
    return 'other';
  }
  function isTvLike() {
    if (MW.forceTv === true) return true;
    var mode = classifyMode(navigator.userAgent || '');
    if (mode === 'tv') return true;
    if (mode === 'mobile') return false;
    var m = window.matchMedia;
    if (m && (m('(pointer: coarse)').matches || m('(hover: none)').matches)) return true;
    return false;
  }
  function isTouchLike() {
    var m = window.matchMedia;
    return !!(m && m('(pointer: coarse)').matches);
  }
  var pseudoFull = false;
  var PLAYER_BUILD = 'tvfix3';

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
    // Always English when available — evaluated fresh on every load, so saved
    // preferences no longer affect which subtitles start playing.
    for (var i = 0; i < tracks.length; i++) {
      var code = (tracks[i].code || '').toLowerCase();
      if (tracks[i].supported && (code === 'en' || code.indexOf('en-') === 0)) {
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
    if (isTvLike()) return; // TV/remote: controls stay visible — nothing to summon them back
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

  /* ---------------- hover preview scrubbing (desktop pointers only) ----------------
   * A hidden second <video> seeks to the hovered position and its frame is
   * drawn into a floating bubble (canvas), Netflix-style. Positions snap to
   * 10s buckets; each bucket's frame is cloned in-page, so revisiting a spot
   * is instant. The hidden video's source is attached only on first hover —
   * TVs/phones build nothing and pay nothing. */

  var pvBubble = null, pvCanvas = null, pvCtx = null, pvVideo = null;
  var pvCache = {};        // bucket -> cloned canvas
  var pvCacheN = 0;
  var pvBucket = -1;       // last bucket shown (seek dedup)
  var pvPending = -1;      // bucket awaiting a frame
  var pvPendingT = 0;      // exact time requested (stale-seek guard)
  var pvHideTimer = null;
  var pvLastMove = 0;
  var PV_BUCKET = 10;      // seconds per preview position
  var PV_CACHE_MAX = 240;  // frames kept in-page (~40 min of film)

  function pvEnabled() {
    return !!(window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches);
  }

  function pvBuild() {
    if (pvBubble) return;
    pvBubble = document.createElement('div');
    pvBubble.className = 'pc-preview';
    pvCanvas = document.createElement('canvas');
    pvCanvas.width = 160; pvCanvas.height = 90;
    pvCtx = pvCanvas.getContext('2d');
    pvCtx.fillStyle = '#000'; pvCtx.fillRect(0, 0, 160, 90);
    pvBubble.appendChild(pvCanvas);
    var tm = document.createElement('span');
    tm.className = 'pv-time';
    tm.textContent = '0:00';
    pvBubble.appendChild(tm);
    // Inside the stage: visible in normal view, pseudo-fullscreen, AND the
    // desktop native fullscreen (only fullscreen-element descendants render).
    stage.appendChild(pvBubble);

    pvVideo = document.createElement('video');
    pvVideo.muted = true;
    pvVideo.defaultMuted = true;
    pvVideo.preload = 'auto';
    pvVideo.setAttribute('playsinline', '');
    pvVideo.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;left:0;top:0;';
    pvVideo.addEventListener('seeked', function () {
      if (pvPending < 0 || !pvCtx) return;
      if (Math.abs(pvVideo.currentTime - pvPendingT) > 0.5) return; // stale seek
      var vw = pvVideo.videoWidth, vh = pvVideo.videoHeight;
      if (!vw || !vh) { pvPending = -1; return; }
      // cover-fit the frame into the 160×90 bubble canvas
      var s = Math.max(160 / vw, 90 / vh);
      var dw = vw * s, dh = vh * s;
      pvCtx.fillStyle = '#000'; pvCtx.fillRect(0, 0, 160, 90);
      pvCtx.drawImage(pvVideo, (160 - dw) / 2, (90 - dh) / 2, dw, dh);
      var snap = document.createElement('canvas');
      snap.width = 160; snap.height = 90;
      snap.getContext('2d').drawImage(pvCanvas, 0, 0);
      pvCache[pvPending] = snap;
      pvCacheN++;
      if (pvCacheN > PV_CACHE_MAX) {
        // integer-like keys enumerate in ascending order = earliest film
        // positions evicted first (they were visited longest ago)
        for (var k in pvCache) { delete pvCache[k]; pvCacheN--; break; }
      }
      pvPending = -1;
    });
    pvVideo.addEventListener('error', function () { pvPending = -1; /* bubble keeps black frame + timestamp */ });
    pvVideo.src = streamUrl; // lazy: attached only when someone actually hovers
    stage.appendChild(pvVideo);
  }

  function pvSeek(bucket, t) {
    pvPending = bucket;
    pvPendingT = t;
    var go = function () {
      try { pvVideo.currentTime = t; } catch (e) { pvPending = -1; }
    };
    if (pvVideo.readyState >= 1) go();
    else pvVideo.addEventListener('loadedmetadata', function h() {
      pvVideo.removeEventListener('loadedmetadata', h);
      go();
    });
  }

  function pvShow(t, clientX) {
    if (!pvEnabled() || !duration) return;
    pvBuild();
    var timeEl = pvBubble.lastChild;
    timeEl.textContent = MW.fmtTime(t);

    var bucket = Math.floor(t / PV_BUCKET);
    var cached = pvCache[bucket];
    if (cached) {
      pvCtx.drawImage(cached, 0, 0);
      pvPending = -1; // an in-flight seek for another bucket is no longer wanted
    } else if (bucket !== pvBucket) {
      pvCtx.fillStyle = '#000'; pvCtx.fillRect(0, 0, 160, 90); // loading
      pvSeek(bucket, Math.min(duration - 0.2, bucket * PV_BUCKET + PV_BUCKET / 2));
    }
    pvBucket = bucket;

    pvBubble.classList.add('show');
    var half = (pvBubble.offsetWidth || 172) / 2;
    var rect = $('pc-seek').getBoundingClientRect();
    var x = rect.width < half * 2
      ? rect.left + rect.width / 2
      : Math.max(rect.left + half, Math.min(rect.right - half, clientX));
    pvBubble.style.left = x + 'px';
    pvBubble.style.top = (rect.top - pvBubble.offsetHeight - 10) + 'px';
  }

  function pvHide() {
    if (pvHideTimer) { clearTimeout(pvHideTimer); pvHideTimer = null; }
    pvBucket = -1;
    if (pvBubble) pvBubble.classList.remove('show');
  }

  function pvFromKeyboard() {
    if (!pvEnabled() || !duration) return;
    var rect = $('pc-seek').getBoundingClientRect();
    var ratio = video.currentTime / duration;
    pvShow(video.currentTime, rect.left + rect.width * ratio);
    if (pvHideTimer) clearTimeout(pvHideTimer);
    pvHideTimer = setTimeout(pvHide, 1500); // auto-hide after arrow-scrubbing
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

  /* ---------------- fullscreen ---------------- */

  function setPseudoFullscreen(on) {
    if (on === pseudoFull) return;
    pseudoFull = on;
    stage.classList.toggle('pseudo-full', on);
    document.body.classList.toggle('pseudo-full-active', on);
    if (on) {
      // Own history entry so the remote's Back button exits fullscreen
      // (popstate below) instead of leaving the watch page.
      try { history.pushState({ mwPseudoFull: true }, ''); } catch (e) { /* old browser */ }
    } else if (history.state && history.state.mwPseudoFull) {
      history.back(); // consume our entry; the popstate handler no-ops (pseudoFull already false)
    }
    showControls();
  }

  window.addEventListener('popstate', function () {
    if (pseudoFull) setPseudoFullscreen(false); // Back exits fullscreen, stays on page
  });

  // Best-effort landscape rotation while fullscreen (phones). Unsupported
  // browsers (desktop, iOS) reject/absorb it silently — the try/catch and
  // promise guard make it a no-op there. Always released on fullscreen exit
  // via the fullscreenchange listener below.
  function setOrientationLock(on) {
    try {
      var o = screen.orientation;
      if (!o) return;
      if (on) {
        if (!o.lock) return;
        var r = o.lock('landscape');
        if (r && typeof r.catch === 'function') r.catch(function () {});
      } else if (o.unlock) o.unlock();
    } catch (e) { /* unsupported */ }
  }

  ['fullscreenchange', 'webkitfullscreenchange'].forEach(function (ev) {
    document.addEventListener(ev, function () {
      if (!document.fullscreenElement && !document.webkitFullscreenElement) setOrientationLock(false);
    });
  });

  function toggleFullscreen() {
    if (isTvLike()) {
      // Never use the Fullscreen API on TVs: Android-TV browsers respond by
      // opening their own native player over the page. Our own full-bleed
      // stage keeps our controls on screen instead.
      setPseudoFullscreen(!pseudoFull);
      return;
    }
    if (document.fullscreenElement || document.webkitFullscreenElement) {
      (document.exitFullscreen || document.webkitExitFullscreen).call(document);
    } else if (stage.requestFullscreen) {
      var p = stage.requestFullscreen();
      if (p && typeof p.then === 'function') {
        p.then(function () { setOrientationLock(true); }).catch(function () {});
      } else setOrientationLock(true);
    } else if (stage.webkitRequestFullscreen) {
      stage.webkitRequestFullscreen();
      setOrientationLock(true);
    } else if (video.webkitEnterFullscreen) video.webkitEnterFullscreen(); // iOS rotates itself
  }

  function wireEvents() {
    $('pc-play').addEventListener('click', togglePlay);
    $('bigplay').addEventListener('click', togglePlay);
    video.addEventListener('click', function () {
      // Phone: the tap that summons a hidden overlay shouldn't also pause
      // the movie — only a tap on an already-visible overlay toggles.
      // (Desktop/TV keep plain click-to-toggle: controlsHiddenAtTap is only
      // honored when we're in phone mode with a touch pointer.)
      if (controlsHiddenAtTap && !isTvLike() && isTouchLike()) {
        controlsHiddenAtTap = false;
        return;
      }
      togglePlay();
    });

    // Direct route to the main menu — works even when the page was typed
    // straight into the address bar (no history entry to go "back" to).
    $('pc-home').addEventListener('click', function () {
      location.href = root.getAttribute('data-index-url');
    });

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

    // Hover preview: nothing is built or fetched on TVs/touch (pvEnabled false).
    if (pvEnabled()) {
      $('pc-seek').addEventListener('pointermove', function (e) {
        var now = Date.now();
        if (now - pvLastMove < 80) return;
        pvLastMove = now;
        pvShow(sliderRatio($('pc-seek'), e.clientX) * duration, e.clientX);
      });
      $('pc-seek').addEventListener('pointerleave', pvHide);
      $('pc-seek').addEventListener('pointerup', function () {
        if (!$('pc-seek').matches(':hover')) pvHide();
      });
    }

    $('pc-seek').addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { seekBy(-10); pvFromKeyboard(); e.preventDefault(); }
      if (e.key === 'ArrowRight') { seekBy(10); pvFromKeyboard(); e.preventDefault(); }
    });
    $('pc-seek').addEventListener('blur', pvHide);
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

    $('pc-full').addEventListener('click', toggleFullscreen);

    $('player-retry').addEventListener('click', function () {
      $('player-error').hidden = true;
      $('player-loading').hidden = false;
      video.load();
    });

    document.addEventListener('mousemove', showControls);
    // Captured here because this listener (bubble phase) runs before the
    // video's click handler: it records whether the overlay was hidden AT
    // TAP START, then reveals it.
    var controlsHiddenAtTap = false;
    document.addEventListener('pointerdown', function () {
      controlsHiddenAtTap = $('controls').classList.contains('hide');
      showControls();
    });

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
          if (pseudoFull) setPseudoFullscreen(false);
          else if (document.fullscreenElement) document.exitFullscreen();
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

  /* ---------------- optional debug overlay (watch.php?tvdebug=1) ---------------- */

  function showTvDebug() {
    var params;
    try { params = new URLSearchParams(location.search); } catch (e) { return; }
    if (params.get('tvdebug') !== '1') return;
    var m = window.matchMedia;
    function mm(q) { try { return m ? String(m(q).matches) : 'no matchMedia'; } catch (e) { return 'error'; } }
    var lines = [
      'MovieWebsite player debug',
      'build: ' + PLAYER_BUILD,
      'isTvLike(): ' + isTvLike(),
      'deviceMode: ' + classifyMode(navigator.userAgent || ''),
      'touchLike: ' + isTouchLike(),
      'userAgent: ' + (navigator.userAgent || ''),
      'pointer:coarse = ' + mm('(pointer: coarse)'),
      'hover:none = ' + mm('(hover: none)'),
      'hover:hover+pointer:fine = ' + mm('(hover: hover) and (pointer: fine)'),
      'fullscreen API = ' + !!(stage.requestFullscreen || stage.webkitRequestFullscreen),
      'url: ' + location.href,
      '',
      'isTvLike() true  → our fullscreen (expected on TVs)',
      'isTvLike() false → browser fullscreen API (expected on phones & desktop)'
    ];
    var box = document.createElement('pre');
    box.id = 'tvdebug';
    box.textContent = lines.join('\n');
    box.style.cssText = 'position:fixed;left:0;top:0;right:0;z-index:9999;margin:0;padding:10px;' +
      'background:rgba(0,0,0,.92);color:#4f4;font:13px/1.5 monospace;white-space:pre-wrap;pointer-events:none;';
    document.body.appendChild(box);
  }

  /* ---------------- boot ---------------- */

  document.addEventListener('DOMContentLoaded', function () {
    wireEvents();
    updateVolumeUI();
    showTvDebug();

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
