# User Guide

## Watching movies

- **Home** shows a hero (most recent movie), *Continue Watching*, *Recently
  Added*, *All Movies*, and genre rows (genres appear once TMDB metadata is
  configured).
- **All Movies / Search** page: search by title, filter by genre, sort by
  title/year/date added.
- **Details page**: file info, subtitle list, Play / Resume.

### Player controls

| Action | Mouse/Touch | Keyboard | TV remote |
|---|---|---|---|
| Play/Pause | buttons / click video | Space or K | OK / Play button |
| Seek ±10 s | drag seek bar | ← / → | ← / → |
| Volume | volume slider | ↑ / ↓ (also M = mute) | ↑ / ↓ |
| Subtitles | "Subtitles" menu | C (cycles) | OK on menu |
| Speed | "1×" menu | — | OK on menu |
| Fullscreen | ⛶ button | F | Fullscreen button |
| Back | — | Esc | Back / Return |

Progress is saved to the server every few seconds and when you leave the page —
resume works across devices. Movies count as *finished* at 90 %.

## Subtitles

MovieWebsite finds subtitles automatically. No configuration needed.

**Where it looks** (in priority order):

1. Same folder as the movie, same filename: `Interstellar (2014).srt`
2. Same folder, filename + language: `Interstellar (2014).eng.srt`,
   `Movie.pt-BR.srt` (language tokens like `en`, `eng`, `English`, `spa`,
   `Brazilian.por`, `SDH` are recognized)
3. A `Subs\` (or `Subtitles\`) subfolder next to a **single** movie — the YTS
   pack layout: `Movie (2025)/Subs/English.srt`, `.../ara.srt`, etc.
4. The shared `assets\subtitles` folder, when the filename matches exactly one
   movie in the library (never guessed when ambiguous)

Matching is deliberately conservative: a subtitle is never attached on a weak
or ambiguous match; unmatched files are simply ignored (visible to the
administrator in scan results).

**Formats**: `.srt` is converted to WebVTT automatically (the original file is
never modified) and always plays. `.vtt` plays directly. `.ass/.ssa/.sub` are
discovered and listed but **cannot play in browsers** — convert them to SRT
first if needed.

**In the player**: **English is selected automatically** whenever the movie
has an English track — no menu digging needed. Open the *Subtitles* menu to
switch to another language or *Off* (that choice applies to the current movie;
the next load starts with English again). SDH/hearing-impaired variants are
labelled.

New subtitle files appear after the next scan (**Library Settings → Scan
Library**, or automatically within ~30 s).

## Library Settings

The gear button opens **Library Settings** — the administrator panel.

- **Current library** — the active folder path (e.g. `D:\Movies`) and whether
  it is the default or a custom folder.
- **Status** — *Available*, or *Unavailable* with the reason (drive missing /
  unreadable). Your setting is always kept.
- **Movies discovered** — count from the last successful scan.
- **Last successful scan** — timestamp.
- **Change Folder** — on the PC that runs Apache, this opens the **native
  Windows folder picker** (the same dialog as File Explorer); pick a folder
  and confirm with *Use this folder*. On a phone or TV it opens the in-site
  drive/folder list instead — a dialog window can only appear on the PC's own
  screen, so other devices never get one. Either way, only directories are
  selectable, Windows system folders are rejected, and the server validates
  every path.
- **Use Default Folder** — return to `assets\movies`.
- **Scan Library** — force a full rescan now (also happens automatically).

Changing the folder requires the administrator password (first run creates it).
Watching does not.

## Movies on an external drive / another disk

To save internal-disk space, keep movies on an external USB drive or another
disk — nothing needs to live inside XAMPP.

1. Plug the drive in (e.g. it appears as `E:`).
2. Library Settings → **Change Folder** → pick the drive → your movie folder →
   **Use this folder**.
3. Play. Streaming, seeking, resume, and subtitles all work from any drive.

### If the drive is disconnected

- The site shows *"The movie library folder is unavailable"* — the saved path
  is **not** reset, and metadata, artwork, and progress are **not** deleted.
- Reconnect the drive → **Scan Library** (or wait ~30 s) → everything returns.

### If Windows gives the drive a different letter

Windows sometimes assigns a different drive letter after reconnecting
(`E:` becomes `F:`). The saved path then points to the old letter and the site
reports the library unavailable. Open **Change Folder** and select the folder
on its new letter — done. (Tip: in Disk Management you can assign a permanent
letter to the drive so this does not recur.)

## Optional: TMDB metadata

Add `includes\config.local.php` with your TMDB key (see README). Posters,
overviews, genres, runtimes, and ratings then appear. Enrichment runs in small
background batches after the page loads — never blocking scanning or playback.

- **Refresh one movie**: `api/metadata.php?action=refresh&id=<movie id>`
- **Refresh everything**: `api/metadata.php?action=refresh&all=1`

## Never-touched files guarantee

The app never moves, renames, modifies, or deletes your movie or subtitle
files. It only reads them and caches derived data (IDs, metadata, progress) in
the web-protected `data\` folder.
