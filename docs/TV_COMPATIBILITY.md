# Smart TV Compatibility

## Honest status

MovieWebsite's TV support is **designed** for d-pad/remote use (large focus
targets, visible focus rings, keyboard-mapped remote keys, no hover-only
affordances) and was verified on PC and phone browsers. **Real TV hardware
testing is limited** — results below separate what was actually verified from
what is expected.

| Environment | Status |
|---|---|
| PC browsers (Chromium/Edge) | ✅ verified: browse, play, seek, resume, subtitles |
| Phone browser (Chromium) | ✅ verified by device owner |
| Smart TV browser | ⚠️ browse + play verified on a **Haier Google/Android TV** (device owner); player overlays/stall fixes deployed, TV-side retest of fullscreen pending |
| Other TVs (LG webOS, Android TV, Roku…) | ❓ untested — expected to behave like other Chromium/WebKit TV browsers |

## The one real issue found on TV: 5.1 audio

*Update: after the HTTPS/URL fix, your Haier TV plays these same 5.1 files
fine — keep this section as the first suspect only if a TV stalls on a movie
(the player detects a 12-second stall and says so).*

Your movie files (`...AAC5.1-[YTS.MX].mp4`) contain **AAC 5.1 surround** audio.
Many TV browsers decode only stereo AAC: streaming starts, the audio decoder
refuses the track, and playback hangs. MP4 files with **stereo AAC** play on
the widest set of TV browsers.

Fixes: use the TV's native media player app, create a stereo "TV copy" with
`tools\make_tv_copy.ps1` (original untouched), or cast from another device.
Details: `TROUBLESHOOTING.md → "Playback spins forever on a TV"`.

## What the player does for TVs

- **Fullscreen never hands off to the system player.** On TV and touch devices
  (detected by platform UA — Android/Google TV, Tizen, webOS, Roku, phones —
  or by coarse-pointer/no-hover media queries) the fullscreen button uses our
  own full-bleed layout instead of the browser Fullscreen API, so TV browsers
  can't open their native player overlay on top of ours. Desktop keeps native
  fullscreen. Remote Back (or Esc) exits fullscreen first; a second Back — or
  the always-visible **Menu** button — reaches the main menu.
- **Assets are cache-busted** (`?v=filemtime` on every CSS/JS URL): TV browsers
  cache aggressively, and stale player code was the old cause of inconsistent
  fullscreen behavior between typed URLs and history navigation.
- **Diagnostics**: open a movie as `watch.php?id=…&tvdebug=1` — a green overlay
  reports the player build, the detection result, UA, and media-query values,
  which makes any future fullscreen issue reportable in one screenshot.
- **Controls stay visible while playing** on TV/touch devices — they only
  auto-hide for mouse users (where there is always a way to bring them back).
- Fail-fast messages instead of silent hangs: 12-second stall detection
  ("This device cannot play the video…") and an upfront `canPlayType` check
  for MP4/H.264 support.
- Remote-friendly controls: OK = play/pause, ←/→ = ±10 s seek, ↑/↓ = volume,
  C = cycle subtitles, Back/Esc = leave; large 56 px targets; subtitles usable
  without a pointer. **English subtitles are on by default** when available.
- `URLSearchParams` polyfill and ES5-style code for older TV browsers.
- WebVTT subtitles styled larger via `::cue`.
- Resume works from the server, so starting on the TV continues your PC position.

## Codec support on TVs (typical)

| Container/codec | TV browser support |
|---|---|
| MP4 H.264 (High@L4.1) + **stereo** AAC | ✅ widest support — recommended |
| MP4 H.264 + **5.1** AAC | ⚠️ often stalls (decoder-dependent) |
| MP4 HEVC/H.265 | ⚠️ newer TVs only |
| MKV (any codec) | ❌ browsers generally refuse MKV — file is listed but Play fails |
| AVI / TS / M2TS | ❌ not playable in browsers (still listed for completeness) |
| WebM VP9/AV1 | ✅ on Chromium-based TV browsers |

Recommendation for a mixed library: keep MP4/H.264-stereo for movies you watch
on the TV.

## If a TV still fails

1. Check the exact error the player now shows (it names the cause).
2. Verify the network stream independently:
   `curl -H "Range: bytes=0-1023" http://<pc-ip>/MovieWebsite/api/stream.php?id=<id>`
   → `206` proves the server side is fine.
3. Report/compare with `TROUBLESHOOTING.md`.

Contributions of real-world TV test results (model + browser + outcome) are
welcome — this table is meant to grow from actual tests, not guesses.
