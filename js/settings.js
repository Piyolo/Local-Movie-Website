/**
 * MovieWebsite — Library Settings dialog.
 * Admin login (first-run setup included), library status, folder navigator
 * (drives + directories only, server-validated), change/default/rescan actions.
 *
 * Security model: the dialog is UX only — every protected action is enforced
 * server-side (session + CSRF). A visitor without the password gets 401s.
 */
(function () {
  'use strict';
  var MW = (window.MW = window.MW || {});

  var csrf = null;
  var isAdmin = false;
  var lastFocus = null;

  function $(id) { return document.getElementById(id); }

  function show(section) {
    ['ls-login', 'ls-panel', 'ls-browser'].forEach(function (id) {
      $(id).hidden = id !== section;
    });
  }

  function msg(id, text, kind) {
    var node = $(id);
    if (!node) return;
    node.textContent = text || '';
    node.className = 'form-msg' + (kind ? ' ' + kind : '');
    node.hidden = !text;
  }

  function busy(on, text) {
    $('ls-busy').hidden = !on;
    if (text) $('ls-busy-text').textContent = text;
  }

  /* ---------------- open / auth state ---------------- */

  MW.openLibrarySettings = function () {
    lastFocus = document.activeElement;
    $('libsettings-overlay').hidden = false;
    msg('ls-login-msg', '');
    msg('ls-msg', '');
    MW.api('api/library.php?action=whoami')
      .then(function (data) {
        isAdmin = !!data.admin;
        csrf = data.csrf || null;
        if (isAdmin) {
          $('ls-signout').hidden = false;
          loadStatus();
        } else {
          $('ls-signout').hidden = true;
          var setup = !!data.setup_needed;
          $('ls-password-label').textContent = setup ? 'Create administrator password' : 'Administrator password';
          $('ls-password-hint').hidden = !setup;
          $('ls-login-hint').hidden = setup;
          $('ls-password').value = '';
          show('ls-login');
          $('ls-password').focus();
        }
      })
      .catch(function () {
        show('ls-login');
        msg('ls-login-msg', 'Could not reach the server.', 'err');
      });
  };

  function closeDialog() {
    $('libsettings-overlay').hidden = true;
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  /* ---------------- status ---------------- */

  function loadStatus() {
    show('ls-panel');
    busy(true, 'Loading status…');
    MW.api('api/library.php?action=status')
      .then(function (data) {
        busy(false);
        isAdmin = true;
        csrf = data.csrf || csrf;
        renderStatus(data);
      })
      .catch(function (err) {
        busy(false);
        if (err.status === 401) {
          MW.openLibrarySettings(); // session expired → login form
        } else {
          msg('ls-msg', err.message, 'err');
        }
      });
  }

  function renderStatus(data) {
    var lib = data.library || {};
    $('ls-current-path').textContent = lib.path || '—';
    var badge = $('ls-source-badge');
    badge.textContent = lib.source === 'custom' ? 'custom folder' : 'default';
    badge.hidden = lib.source !== 'custom';

    var av = $('ls-availability');
    if (lib.available) {
      av.textContent = 'Available';
      av.className = 'ok';
    } else if (lib.exists === false) {
      av.textContent = 'Unavailable — the folder or its drive cannot be found. Your setting is kept; reconnect the drive and rescan.';
      av.className = 'bad';
    } else {
      av.textContent = 'Unavailable — the folder cannot be read. Check permissions.';
      av.className = 'bad';
    }

    $('ls-movie-count').textContent = data.movie_count === null || data.movie_count === undefined
      ? (lib.available ? 'not scanned yet' : 'unknown (library unavailable)')
      : String(data.movie_count);
    $('ls-last-scan').textContent = data.last_scan ? MW.fmtDate(data.last_scan) : 'never';
  }

  /* ---------------- actions ---------------- */

  function post(action, body, busyText) {
    busy(true, busyText || 'Working…');
    return MW.api('api/library.php?action=' + action, { method: 'POST', body: body || {}, csrf: csrf })
      .then(function (data) {
        busy(false);
        MW.invalidateMovies();
        return data;
      })
      .catch(function (err) {
        busy(false);
        if (err.status === 401) {
          MW.openLibrarySettings();
        } else if (err.status === 403) {
          msg('ls-msg', 'Your session expired. Sign in again.', 'err');
          MW.openLibrarySettings();
        } else {
          msg('ls-msg', err.message, 'err');
        }
        throw err;
      });
  }

  function afterMutation(data) {
    if (data.library) renderStatus({ library: data.library, movie_count: data.movie_count, last_scan: data.last_scan });
    if (data.scanned === false && data.scan_error) {
      msg('ls-msg', 'Folder saved, but scanning failed — is the drive connected?', 'err');
    } else if (typeof data.movie_count === 'number') {
      msg('ls-msg', 'Done. Library now has ' + data.movie_count + ' movie' + (data.movie_count === 1 ? '' : 's') + '.', 'ok');
    } else {
      msg('ls-msg', 'Done.', 'ok');
    }
  }

  /* ---------------- folder navigator ---------------- */

  var browsePath = null;
  var lastParent = null;

  function bytesHuman(n) {
    if (n === null || n === undefined) return '';
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = 0;
    var v = n;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return (v >= 100 || i === 0 ? Math.round(v) : v.toFixed(1)) + ' ' + units[i] + ' free';
  }

  function openBrowser() {
    browsePath = null;
    show('ls-browser');
    $('ls-browse-path').textContent = 'Select a drive:';
    $('ls-browse-up').hidden = true;
    $('ls-select-folder').disabled = true;
    renderDrives();
    renderDirs([]);
    MW.api('api/library.php?action=drives')
      .then(function (data) { renderDrives(data.drives || []); })
      .catch(function (err) { msg('ls-msg', err.message, 'err'); });
  }

  function renderDrives(drives) {
    var list = $('ls-drives');
    list.textContent = '';
    list.hidden = false;
    if (!drives || !drives.length) {
      list.appendChild(MW.el('li', { class: 'browser-empty', text: 'Reading drives…' }));
      return;
    }
    drives.forEach(function (d) {
      var label = d.letter + ':\\';
      var meta = d.ready ? bytesHuman(d.free) : 'not connected';
      var btn = MW.el('button', {
        type: 'button', class: 'browser-item',
        text: label + (d.ready ? '' : '  (not connected)'),
        'aria-label': 'Drive ' + label,
      });
      btn.appendChild(MW.el('span', { class: 'bi-meta', text: meta }));
      if (!d.ready) btn.disabled = true;
      btn.addEventListener('click', function () { navigate(d.path); });
      list.appendChild(MW.el('li', {}, [btn]));
    });
  }

  function renderDirs(dirs) {
    var list = $('ls-dirs');
    list.textContent = '';
    list.hidden = false;
    if (!dirs || !dirs.length) {
      list.appendChild(MW.el('li', {
        class: 'browser-empty',
        text: browsePath ? 'No subfolders here — you can select this folder.' : 'Pick a drive above.',
      }));
      return;
    }
    dirs.forEach(function (dir) {
      var btn = MW.el('button', { type: 'button', class: 'browser-item', text: '📁 ' + dir.name });
      btn.addEventListener('click', function () { navigate(dir.path); });
      list.appendChild(MW.el('li', {}, [btn]));
    });
  }

  function navigate(path) {
    busy(true, 'Opening folder…');
    MW.api('api/library.php?action=browse&path=' + encodeURIComponent(path))
      .then(function (data) {
        busy(false);
        browsePath = data.path;
        lastParent = data.parent || null;
        $('ls-browse-path').textContent = data.path || '';
        $('ls-browse-up').hidden = !data.parent;
        $('ls-select-folder').disabled = !data.path;
        $('ls-drives').hidden = true;
        renderDirs(data.dirs || []);
        $('ls-dirs').scrollTop = 0;
      })
      .catch(function (err) {
        busy(false);
        msg('ls-msg', err.message, 'err');
      });
  }

  function browseUp() {
    if (lastParent) navigate(lastParent);
  }

  /* ---------------- wiring ---------------- */

  document.addEventListener('DOMContentLoaded', function () {
    var openBtn = $('btn-library-settings');
    if (openBtn) openBtn.addEventListener('click', MW.openLibrarySettings);

    var emptyBtn = $('empty-open-settings');
    if (emptyBtn) emptyBtn.addEventListener('click', MW.openLibrarySettings);

    $('libsettings-close').addEventListener('click', closeDialog);
    $('libsettings-overlay').addEventListener('click', function (e) {
      if (e.target === $('libsettings-overlay')) closeDialog();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !$('libsettings-overlay').hidden) closeDialog();
    });

    $('ls-login-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var pw = $('ls-password').value;
      if (pw.length < 8) {
        msg('ls-login-msg', 'Use at least 8 characters.', 'err');
        return;
      }
      busy(true, 'Signing in…');
      MW.api('api/library.php?action=login', { method: 'POST', body: { password: pw } })
        .then(function (data) {
          busy(false);
          isAdmin = true;
          csrf = data.csrf;
          $('ls-password').value = '';
          $('ls-signout').hidden = false;
          if (data.created) MW.toast('Administrator password created.', 'ok');
          msg('ls-login-msg', '');
          loadStatus();
        })
        .catch(function (err) {
          busy(false);
          msg('ls-login-msg', err.message, 'err');
          $('ls-password').select();
        });
    });

    $('ls-change-folder').addEventListener('click', function () {
      msg('ls-msg', '');
      openBrowser();
    });

    $('ls-browse-up').addEventListener('click', browseUp);
    $('ls-browse-cancel').addEventListener('click', function () { show('ls-panel'); });

    $('ls-select-folder').addEventListener('click', function () {
      if (!browsePath) return;
      var chosen = browsePath;
      show('ls-panel');
      post('set', { path: chosen }, 'Saving folder and scanning…')
        .then(function (data) {
          afterMutation(data);
          MW.toast('Library folder updated.', 'ok');
        })
        .catch(function () {});
    });

    $('ls-use-default').addEventListener('click', function () {
      post('reset', {}, 'Restoring default folder…')
        .then(function (data) {
          afterMutation(data);
          MW.toast('Default folder restored.', 'ok');
        })
        .catch(function () {});
    });

    $('ls-rescan').addEventListener('click', function () {
      post('scan', {}, 'Scanning library…')
        .then(function (data) {
          renderStatus({
            library: { path: $('ls-current-path').textContent, source: $('ls-source-badge').hidden ? 'default' : 'custom', available: true },
            movie_count: data.movie_count,
            last_scan: data.last_scan,
          });
          msg('ls-msg', 'Scan complete: ' + data.movie_count + ' movie' + (data.movie_count === 1 ? '' : 's') +
              ' (' + data.counts.subtitle_files + ' subtitle files, ' + data.counts.tracks + ' matched).', 'ok');
        })
        .catch(function () {});
    });

    $('ls-signout').addEventListener('click', function () {
      post('logout', {}).then(function () {
        isAdmin = false;
        csrf = null;
        MW.openLibrarySettings();
      }).catch(function () {});
    });
  });
})();
