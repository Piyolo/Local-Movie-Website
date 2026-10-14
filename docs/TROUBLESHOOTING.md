# Troubleshooting

## Playback spins forever on a TV ("endless loading")

**Symptom:** the site browses fine on the TV, but pressing Play shows the
loading spinner forever. PC and phone play the same file fine.

**Most likely cause: 5.1 surround audio.** Many smart-TV browsers only decode
*stereo* AAC. YTS "5.1" releases (e.g. `...AAC5.1-[YTS.MX].mp4`) carry a
6-channel AAC track — the TV starts streaming, its audio decoder refuses the
track, and the player stalls instead of erroring. MovieWebsite now detects a
12-second stall and shows *"This device cannot play the video"* with this
explanation instead of spinning silently.

**Fixes, in order of simplicity:**

1. **Try the TV's own player app.** Most smart TVs play 5.1 MP4s perfectly in
   their native media player (USB or DLNA) — the limitation is only the TV's
   *browser*.
2. **Create a TV-friendly copy** (original untouched): on the PC, run
   ```
   powershell -ExecutionPolicy Bypass -File tools\make_tv_copy.ps1 "D:\Movies\My Movie\My Movie.mp4"
   ```
   This produces `My Movie (TV Copy).mp4` next to the original — video copied
   bit-for-bit, audio re-encoded to stereo AAC, web-optimized. Rescan in
   Library Settings. If you don't want two entries listed, move the original
   out of the library folder yourself (the app never moves files).
   Requires ffmpeg (`winget install Gyan.FFmpeg`).
3. **Use a better TV browser.** On many TVs a sideloaded Firefox/Kodi browser
   or a casting solution (Chromecast/Miracast from phone or PC) decodes more
   codecs.

**Other causes to rule out:**

- **No MP4/H.264 support at all** (very old TVs): the player now pre-checks
  `canPlayType` and shows *"This browser cannot play MP4 video"* immediately.
- **Codec level**: files encoded High@L5.1+ (rare for YTS) may exceed older
  TV decoders. Check with `ffprobe -show_entries stream=profile,level file.mp4`.
- **Wi-Fi too weak for 1080p**: the stream stalls/restarts; try playing on PC
  at the same spot to compare, move the TV closer, or use the 5 GHz band.
- **Library unavailable on the TV** (banner instead of playback): the drive
  was disconnected — see below.

## Library unavailable (drive disconnected)

**Symptom:** banner *"The movie library folder is unavailable"* or Library
Settings shows *Unavailable*.

- Check the external drive is plugged in and powered on.
- Click **Scan Library** in Library Settings (or wait ~30 s and reload).
- If Windows re-assigned the drive letter, **Change Folder** → reselect on the
  new letter. Your setting is kept meanwhile — nothing is deleted.

## Movies do not appear after adding files

- **Library Settings → Scan Library** (fresh scans also happen automatically;
  the UI caches results for ~30 s, so give it half a minute or reload).
- Supported video types: `.mp4 .mkv .m4v .avi .webm .mov .mpg .mpeg .ts .m2ts`
  (note: MKV/AVI/TS often cannot play in *browsers* even though they are
  discovered — see the format table in TV_COMPATIBILITY.md).

## Subtitles do not appear

- File must sit next to the movie with the same name (`Movie.srt` or
  `Movie.eng.srt`), in the movie's `Subs\` folder, or in `assets\subtitles`
  with an exactly matching filename. See USER_GUIDE "Subtitles".
- `.ass/.ssa/.sub` are listed but not playable in browsers — convert to `.srt`.
- Rescan after adding subtitle files.

## Login / password problems

- Wrong password: 5 failed attempts lock that device out for 15 minutes.
- Forgot password: delete `data\auth.json`, reload Library Settings, create a
  new password on first run.
- "Session expired" while using settings: sign in again (passwords and sessions
  are server-enforced; this is normal after closing the browser on some TVs).

## The Windows folder dialog doesn't appear (or says it timed out)

**Symptom:** on the PC, *Change Folder* spins ("Waiting for the Windows folder
dialog…") and eventually shows an error and the built-in folder list.

- The dialog can **only appear on the PC running Apache** — by design it is
  rejected for phones/TVs (`host_only`), which use the in-site folder list.
- Apache must be running in your logged-in desktop session (normal when
  started from the XAMPP Control Panel). If Apache runs as a Windows service
  in a hidden session, no window can be shown — the site waits 2 minutes,
  reports the timeout, and falls back to the in-site list, which always works.
- The dialog opens on the PC's screen — check behind other windows. You have
  2 minutes to pick or cancel.

## The TV's own player still appears over the site's fullscreen

1. **Reload the movie page first** — all CSS/JS is cache-busted now
   (`?v=` version parameters), so a plain reload already fetches the newest
   player code; no cache clearing needed.
2. Add `&tvdebug=1` to the movie URL (or `?tvdebug=1` if it has no `?`). A
   green overlay appears showing `build`, `isTvLike()`, the user agent, and
   media-query values. Check the line `isTvLike()`:
   - **`true`** → our own fullscreen is active; if a native player still
     appears, note *exactly* which button opened fullscreen (the site's ⛶
     button vs. any browser/TV menu) and send that screenshot.
   - **`false`** → the TV browser is misreporting itself as a desktop; send
     the overlay text (especially the `userAgent` line) so its pattern can be
     added to detection.
3. The **Menu** button (left end of the player controls) always returns to the
   main menu — it does not depend on browser history, so typing the address
   directly is fine.

## Site opens but looks unstyled / broken

- Confirm the URL is exactly `http://<ip>/MovieWebsite/` (capital M and W as
  in the folder name on a case-sensitive setup, trailing slash fine).
- Hard-refresh the TV browser (TV caches aggressively); clear the browser cache.

## Apache won't start / port 80 busy

- Another web server (IIS, Skype, another Apache) may hold port 80:
  `netstat -ano | findstr :80` then check the PID.
- XAMPP's own log: `C:\xampp\apache\logs\error.log`.

## Verifying from another device with curl

```
curl http://192.168.100.2/MovieWebsite/api/health.php
curl -H "Range: bytes=0-1023" http://192.168.100.2/MovieWebsite/api/stream.php?id=<movie id>
```

The second command should return `206 Partial Content` with a `Content-Range`
header — that proves streaming works on the network path.
