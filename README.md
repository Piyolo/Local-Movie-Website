# MovieWebsite

A personal, Netflix-style movie streaming site for your own movie collection.
Runs entirely on your Windows PC inside XAMPP (Apache + PHP) — no cloud, no
database, no account system. Works on PC, phone, and Smart-TV browsers over
your home Wi-Fi.

## What it does

- **Auto-scans a movie folder** you choose (any drive: internal, external USB/HDD)
  — no importing, no copying files into the project.
- **Plays movies in the browser** with HTTP range streaming (seek instantly,
  resume across devices), custom TV-remote-friendly player controls.
- **External subtitles**: sidecar `.srt` files, `Subs\` folders (YTS packs),
  and a shared `assets/subtitles` folder — matched conservatively by filename,
  converted SRT→WebVTT on the fly, with a subtitle menu in the player.
- **Optional TMDB metadata** (posters, overviews, genres) via a key you provide;
  everything works without a key using filename-derived titles and fallback art.
- **Library Settings**: change the movie folder from the website itself —
  pick any drive/folder through a protected folder navigator, scan, and see
  counts and last-scan time. Works with external drives; keeps your choice when
  a drive is unplugged and recovers when it returns.

## Quick start

1. Install XAMPP and start **Apache** (PHP 8.0+).
2. Copy this folder to `C:\xampp\htdocs\MovieWebsite`.
3. Open `http://localhost/MovieWebsite/` — the default library folder is
   `assets\movies` inside the project.
4. Click **Library Settings** → sign in (first run creates the admin password)
   → **Change Folder** → pick your movie folder on any drive → **Use this folder**.
5. Watch. Detailed steps: `docs/INSTALLATION.md` and `docs/USER_GUIDE.md`.

## Security model (home LAN)

- The movie library (browsing, details, playback, subtitles) is open to every
  device on your LAN — that's by design for a home media site.
- **Changing the library folder, browsing server folders, and rescanning are
  administrator-only**: password + server-enforced session + CSRF tokens.
  Hiding the settings button is *not* the protection — the endpoints refuse
  unauthorized requests (401/403).
- Folder navigation lists directories only (never file contents), hides Windows
  system folders, rejects `..` traversal and symlink/junction escapes, and
  canonicalizes every path server-side.
- Private files are unreachable over HTTP: `includes/`, `data/` (config +
  progress + metadata), `tests/`, `tools/`, `.htaccess` and `.git` all deny
  web access. Movie files are served only through the validated streaming
  endpoint — raw filesystem paths never appear in URLs.
- **Limitations** (by design, documented honestly): plain HTTP on your LAN —
  anyone on the same Wi-Fi can *watch*; only *administration* is password
  protected. There is no TLS, no user accounts, and no protection against an
  attacker who already has admin rights on your PC. Keep it off the internet
  (no port forwarding / no tunnels).

### Forgot the administrator password?

Delete `data\auth.json` and open Library Settings again — first-run setup
creates a new password.

## Configurable library directory

The active movie folder is stored in `data\library.json` (web-denied, server
side). Default is `assets\movies`. You can point it at e.g.
`D:\Movies`, `E:\My Movie Collection\`, or any other valid folder — including
removable drives. If the drive is disconnected:

- your setting is **kept** (never silently reset),
- the site shows a clear "library unavailable" message,
- metadata, artwork, subtitles index, and resume progress are **not** deleted,
- everything recovers automatically on the next successful scan after you
  reconnect (re-select the folder only if Windows assigns a different drive
  letter).

Details, including how external drives behave: `docs/USER_GUIDE.md`.
TV playback problems (endless spinner): `docs/TROUBLESHOOTING.md`.

## Optional TMDB metadata

Create `includes\config.local.php` (gitignored) returning your key:

```php
<?php
return ['tmdb' => ['api_key' => 'YOUR_TMDB_KEY_V3']];
```

Without a key the app is fully functional: filename-derived titles/years,
fallback posters, an honest "No TMDB API key configured" note. TMDB
attribution is shown wherever metadata is displayed.

## Project layout

```
MovieWebsite/
  index.php  movie.php  watch.php  search.php    pages
  api/       JSON endpoints (movies, stream, subtitles, progress, library…)
  includes/  PHP core (scanner, parser, subtitles, metadata, auth…)  [HTTP-denied]
  css/ js/   vanilla CSS + JS, no build step
  assets/    movies/ (default library)  posters/  subtitles/  icons/
  data/      runtime state: library config, progress, metadata cache  [HTTP-denied]
  tests/     CLI unit tests (php tests\unit.php)
  tools/     optional helper scripts (make_tv_copy.ps1)               [HTTP-denied]
  docs/      installation, user guide, troubleshooting, TV compatibility
```

## Tests

```
C:\xampp\php\php.exe tests\unit.php
```

76 unit tests: filename parsing, subtitle matching, SRT→WebVTT conversion,
HTTP range parsing, library path validation, folder-browser safety, ID stability.

## Docs

- `docs/INSTALLATION.md` — install, first run, LAN/Wi-Fi access
- `docs/USER_GUIDE.md` — watching, subtitles, Library Settings, external drives
- `docs/TROUBLESHOOTING.md` — common problems (incl. TV endless spinner)
- `docs/TV_COMPATIBILITY.md` — what is and isn't verified on TVs

TMDB metadata © TMDB. This product uses the TMDB API but is not endorsed or
certified by TMDB.
