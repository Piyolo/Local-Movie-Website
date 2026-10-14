# MovieWebsite — Implementation Plan (living checklist)

Track progress here. Keep the app runnable at every step.

## Phase 1 — Inspect and plan ✅
- [x] XAMPP PHP 8.0.30 present (curl, fileinfo, json, mbstring, openssl)
- [x] Apache 2.4.58 installed, not running, port 80 free
- [x] AllowOverride All present in httpd.conf
- [x] Target: C:\xampp\htdocs\MovieWebsite (IbajayDelivery workspace left untouched)

## Phase 2 — Foundation ✅
- [x] Directory structure + .gitignore (repo gitignored for now; movies/data/config excluded)
- [x] includes/config.php, security.php, helpers.php
- [x] .htaccess (deny includes/data/movies/tests/.git, sensitive files)
- [x] api/health.php
- [x] Apache running; http://localhost/MovieWebsite/ + api/health.php verified via curl

## Phase 3 — Library ✅
- [x] filename_parser.php (title/year extraction, conservative rules)
- [x] scanner.php (recursive discovery, stable library-relative IDs, error codes)
- [x] api/movies.php (fresh scan + JSON), cache TTL for playback endpoints
- [x] Unit tests: parser cases, empty dir, unicode/space names (tests/unit.php)

## Phase 4 — Metadata (optional TMDB) ✅
- [x] config.local.php mechanism (gitignored)
- [x] metadata_service.php (v3/v4 auth, confidence scoring, data/metadata.json cache)
- [x] artwork caching to assets/posters + assets/backdrops, local fallback art
- [x] api/metadata.php (bounded enrich passes, refresh, status)
- [x] No key → graceful no_key fallback (verified: metadata.configured=false)

## Phase 4b — Configurable library directory (feature request) ✅
- [x] includes/library_config.php — active library in data/library.json; default preserved;
      saved path NEVER reset on unavailable drive; library_validate_path (traversal,
      system-dir denylist, drive-letter, spaces, UNC syntax)
- [x] includes/auth.php — admin password (data/auth.json, password_hash, one-time setup),
      session auth, CSRF tokens, login throttle (5/15 min per IP)
- [x] includes/folder_browser.php — protected navigator: drives probed without shell,
      dirs only, system dirs hidden, canonicalized, no traversal
- [x] api/library.php — whoami/login/logout/status/drives/browse/set/reset/scan
      (mutations = admin session + CSRF)
- [x] scanner/stream/subtitles/health refactored to movies_dir() root; stable IDs are
      library-relative so switching drives preserves metadata + progress
- [x] External-drive integration tests: D:\Movies scan (2 movies, 40 tracks), range
      206/416, byte-identical full download, drive-loss simulation → 503
      library_unavailable with config kept → auto-recovery on reconnect, reset to
      default, unauthorized set (401), bad CSRF (403), traversal attempts (400/404)
- [x] 76 unit tests pass (paths, browser filtering, ID stability, matcher, converter, range)

## Phase 5 — Premium interface ✅
- [x] css/style.css, responsive.css, tv.css (dark cinematic, hero, rows, grid)
- [x] index.php: nav (+ Library Settings), hero, continue watching, recently added,
      all movies, genres, empty + unavailable states
- [x] movie.php details page; watch.php player page; search.php browse/filter/sort
- [x] js/app.js, library.js, search.js, details.js, preferences.js
- [x] js/settings.js — Library Settings dialog: admin login, current path, Change
      Folder (drive/folder navigator), Use Default Folder, Scan Library, counts,
      last scan time, loading indicator, success/error messages
- [x] Verified in browser against real D:\Movies library: hero, rows, cards with
      subtitle counts, details page, Library Settings login + status + navigator
      (drive list w/ free space, D:\ folder list, hidden system dirs)

## Phase 6 — Playback ✅
- [x] api/stream.php (Range 206/416, bounded chunks, MIME map, library-unavailable 503)
- [x] api/subtitles.php (SRT→WebVTT conversion + validation, root-tagged safe paths)
- [x] api/progress.php (server-side JSON, cross-device resume) — tested POST/GET/DELETE
- [x] watch.php + js/player.js (custom controls, resume, errors, speed, subtitles menu,
      TV remote keys) — browser-verified: resume at saved position, play, seek,
      subtitle menu (Off + 36 languages), track attach (1379 cues, mode=showing),
      zero console errors/warnings

## Phase 7 — TV optimization ✅
- [x] css/tv.css (large focus targets, visible focus, reduced motion)
- [x] Remote/d-pad focus order, no hover-only affordances (56px targets, ←/→ seek,
      ↑/↓ volume, OK play/pause, C subtitles)
- [x] Player TV safeguards: 12s stall detection (5.1-AAC explanation), MP4/H.264
      canPlayType pre-check, URLSearchParams polyfill
- [x] TV-compatible-copy helper: tools/make_tv_copy.ps1 (stereo remux, original untouched)
- [x] TV fullscreen: CSS pseudo-fullscreen on coarse-pointer devices (no system-player
      takeover); Back/Esc exits fullscreen before leaving the page; desktop keeps
      native Fullscreen API
- [x] Controls never auto-hide on TV/touch (mouse devices keep the 3.4s cinematic hide)
- [x] Subtitles: English auto-selected on every load (per user request; saved prefs
      no longer affect it) — browser-verified incl. poisoned-pref test
- [x] Native Windows folder picker: tools/folder_picker.ps1 (Explorer-style dialog,
      -SelfTest compile check) + includes/native_picker.php (fixed proc_open command,
      120s timeout) + api/library.php action=native_pick (admin + CSRF + host-only)
      + settings.js UI with in-site fallback — live-tested end-to-end by user
- [x] Documented untested TV behavior honestly (docs/TV_COMPATIBILITY.md)

## Phase 8 — Security + docs ✅
- [x] Private data unreachable via HTTP (includes/data/tests/tools/.git → 403; verified)
- [x] Path traversal rejected (crafted ids → 400; browse traversal → 404)
- [x] Auth model: reads open to LAN, directory changes admin+CSRF only (verified:
      401 unauth set, 403 bad CSRF, throttle, session)
- [x] Docs: README, docs/INSTALLATION, USER_GUIDE, TROUBLESHOOTING, TV_COMPATIBILITY
      (external-drive + Library Settings + TV endless-spinner sections)

## Phase 9 — Final verification
- [x] Unit tests: 76/76 pass (parser, subtitle matcher, SRT→VTT, range, path
      validation, folder-browser safety, ID stability)
- [x] Integration (curl): health, scan, details, search, progress, range 206/416,
      subtitle VTT, external D:\Movies scan (2 movies, 40 tracks), byte-identical
      full download, drive-loss → 503 config kept → recovery, reset to default,
      unauthorized access denied, traversal denied
- [x] Browser E2E (PC/Edge): homepage, details, watch (play/resume/seek/subtitles),
      Library Settings (login/status/navigator) — zero console errors
- [x] TV retest (Haier Google TV): playback verified working by device owner;
      fullscreen-overlay + sticky-controls + auto-English fixes deployed,
      TV-side fullscreen retest pending
- [x] Honest summary + known limitations documented
