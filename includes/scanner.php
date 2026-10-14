<?php
/**
 * Library scanner — discovers video files and external subtitles in the
 * ACTIVE library directory (see library_config.php: movies_dir()), matches
 * subtitles conservatively, and serves a TTL-cached scan result so playback
 * never rescans the whole directory.
 *
 * File records carry a root tag:
 *   'library' → inside the active movie directory (movies_dir())
 *   'shared'  → inside the app's assets/subtitles directory (Pattern B)
 * and a path RELATIVE to that root. Stable movie IDs are derived from the
 * library-relative path only, so switching drives with the same folder
 * structure preserves IDs (metadata + progress survive).
 *
 * Matching rules (conservative — never attached on a weak guess):
 *   1. exact     same folder, identical basename           → Movie.mkv + Movie.srt
 *   2. suffix    same folder, basename + "." + lang token  → Movie.mkv + Movie.eng.srt
 *   3. companion the movie's folder (or its Subs\ subfolder) containing exactly
 *                ONE video → every subtitle in it belongs to that video (YTS
 *                pack layout). 2+ videos ⇒ deliberately unmatched.
 *   4. shared    assets/subtitles file matching ONE unique video basename
 *                across the library. Ambiguous ⇒ unmatched.
 */
declare(strict_types=1);

if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}

/**
 * Recursively collect media files under a root. Never descends into Windows
 * system folders or other denied names.
 *
 * @param string $rootTag 'library' | 'shared'
 * @param string $relPrefix path prefix relative to the root ('' for root itself)
 */
function discover_media(string $absDir, string $rootTag, string $relPrefix, int $maxDepth, bool $collectVideos, array &$videos, array &$subs, int $depth = 0): void
{
    global $CONFIG;

    $entries = @scandir($absDir);
    if ($entries === false) {
        return;
    }
    $skip = library_scan_skip_dirs();
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue;
        }
        $abs = $absDir . DIRECTORY_SEPARATOR . $entry;
        $rel = $relPrefix === '' ? $entry : $relPrefix . '/' . $entry;
        if (is_dir($abs)) {
            if (in_array(strtolower($entry), $skip, true)) {
                continue; // never descend into system/irrelevant folders
            }
            if ($depth < $maxDepth) {
                discover_media($abs, $rootTag, $rel, $maxDepth, $collectVideos, $videos, $subs, $depth + 1);
            }
            continue;
        }
        if (!is_file($abs)) {
            continue;
        }
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        $size = @filesize($abs);
        $mtime = @filemtime($abs);
        $record = [
            'root'  => $rootTag,
            'rel'   => $rel,
            'base'  => strtolower(pathinfo($entry, PATHINFO_FILENAME)),
            'dir'   => dirname($rel) === '.' ? '' : str_replace('\\', '/', dirname($rel)),
            'ext'   => $ext,
            'size'  => $size === false ? 0 : (int)$size,
            'mtime' => $mtime === false ? 0 : (int)$mtime,
        ];
        if ($collectVideos && in_array($ext, $CONFIG['allowed_extensions'], true)) {
            $videos[] = $record;
        } elseif (in_array($ext, $CONFIG['subtitles']['extensions'], true)) {
            $subs[] = $record;
        }
    }
}

/**
 * Match discovered subtitles to videos. Pure function (unit-testable).
 *
 * @param array $videos file records (root, rel, base, dir, ext, size, mtime)
 * @param array $subs   file records
 * @return array{tracks: array<string, array>, unmatched: array}
 */
function match_subtitles(array $videos, array $subs): array
{
    global $CONFIG;

    $companionDirs = $CONFIG['subtitles']['companion_dirs'];
    $serveable = $CONFIG['subtitles']['serveable'];

    // Group videos by "root|dir" and by basename.
    $dirVideos = [];
    foreach ($videos as $i => $v) {
        $dirVideos[$v['root'] . '|' . $v['dir']][] = $i;
    }

    $tracks = [];
    $unmatched = [];

    foreach ($subs as $sub) {
        $matchIdx = null;
        $matchType = null;
        $langName = '';

        $group = $dirVideos[$sub['root'] . '|' . $sub['dir']] ?? null;

        // --- Rules 1/2: same directory as at least one video (same root) ---
        if ($group !== null) {
            $exact = null;
            $prefixed = [];
            foreach ($group as $vi) {
                $vBase = $videos[$vi]['base'];
                if ($sub['base'] === $vBase) {
                    $exact = $vi;
                } elseif (strpos($sub['base'], $vBase . '.') === 0) {
                    $prefixed[] = $vi;
                }
            }
            if ($exact !== null) {
                $matchIdx = $exact;
                $matchType = 'exact';
                $langName = '';
            } elseif ($prefixed) {
                usort($prefixed, static fn ($a, $b) => strlen($videos[$b]['base']) <=> strlen($videos[$a]['base']));
                $matchIdx = $prefixed[0];
                $matchType = 'suffix';
                $langName = ltrim(substr($sub['base'], strlen($videos[$matchIdx]['base'])), '. ');
            }
        }

        // --- Rule 3: companion folder (Subs\) of a single-video directory ---
        if ($matchIdx === null && $sub['root'] === 'library' && $sub['dir'] !== '') {
            $parentDir = dirname($sub['dir']);
            $parentDir = $parentDir === '.' ? '' : str_replace('\\', '/', $parentDir);
            $folderName = basename($sub['dir']);
            $parentGroup = $dirVideos['library|' . $parentDir] ?? null;
            if (in_array(strtolower($folderName), $companionDirs, true) && $parentGroup !== null) {
                if (count($parentGroup) === 1) {
                    $matchIdx = $parentGroup[0];
                    $matchType = 'companion';
                    $langName = $sub['base'];
                }
                // 2+ videos in that folder ⇒ unmatched (ambiguous by design).
            }
        }

        // --- Rule 4: shared assets/subtitles library ---
        if ($matchIdx === null && $sub['root'] === 'shared') {
            $candidates = [];
            foreach ($videos as $vi => $v) {
                if ($sub['base'] === $v['base'] || strpos($sub['base'], $v['base'] . '.') === 0) {
                    $candidates[$vi] = true;
                }
            }
            if (count($candidates) === 1) {
                $matchIdx = (int)array_key_first($candidates);
                $matchType = 'shared';
                $vBase = $videos[$matchIdx]['base'];
                $langName = ($sub['base'] === $vBase) ? '' : ltrim(substr($sub['base'], strlen($vBase)), '. ');
            }
            // 0 or 2+ candidates ⇒ unmatched (ambiguous by design).
        }

        if ($matchIdx === null) {
            $unmatched[] = basename($sub['rel']);
            continue;
        }

        if ($matchType === 'exact' || $langName === '') {
            // Exact-basename sidecars (YTS convention) default to English —
            // documented assumption, correctable via overrides.
            $lang = ['code' => 'en', 'label' => 'English', 'flags' => []];
        } else {
            $lang = subtitle_language_info($langName);
            if ($lang['label'] === '') {
                $lang = ['code' => '', 'label' => 'Unknown', 'flags' => []];
            }
        }

        $track = [
            'id'        => sha1($sub['root'] . '|' . strtolower(str_replace('\\', '/', $sub['rel']))),
            'root'      => $sub['root'],
            'rel'       => $sub['rel'],
            'filename'  => basename($sub['rel']),
            'ext'       => $sub['ext'],
            'code'      => $lang['code'],
            'label'     => $lang['label'],
            'flags'     => $lang['flags'],
            'supported' => in_array($sub['ext'], $serveable, true),
            'size'      => $sub['size'],
            'mtime'     => $sub['mtime'],
            'match'     => $matchType,
        ];

        // Dedupe identical-looking tracks (YTS ships the same English SRT both
        // beside the video and inside Subs\): same label + code + byte size.
        $dup = false;
        foreach ($tracks[$matchIdx] ?? [] as $existing) {
            if ($existing['label'] === $track['label']
                && $existing['code'] === $track['code']
                && $existing['size'] === $track['size']) {
                $dup = true;
                break;
            }
        }
        if (!$dup) {
            $tracks[$matchIdx][] = $track;
        }
    }

    foreach ($tracks as $idx => $list) {
        usort($list, static function ($a, $b) {
            if ($a['supported'] !== $b['supported']) {
                return $a['supported'] ? -1 : 1;
            }
            return strcasecmp($a['label'], $b['label']);
        });
        $tracks[$idx] = $list;
    }

    return ['tracks' => $tracks, 'unmatched' => $unmatched];
}

/** Full scan of the ACTIVE library (no caching). Distinguishes error causes. */
function perform_scan(): array
{
    global $CONFIG;

    $result = [
        'scanned_at' => time(),
        'movies' => [],
        'unmatched_subtitles' => [],
        'counts' => ['videos' => 0, 'subtitle_files' => 0, 'tracks' => 0, 'unmatched' => 0],
        'error' => null,
    ];

    $libRoot = movies_dir();
    if (!is_dir($libRoot)) {
        // Drive disconnected or folder removed — configuration is KEPT.
        $result['error'] = 'library_unavailable';
        return $result;
    }
    if (!is_readable($libRoot)) {
        $result['error'] = 'library_unreadable';
        return $result;
    }

    $videos = [];
    $subs = [];
    $maxDepth = max(1, (int)$CONFIG['scan_max_depth']);

    discover_media($libRoot, 'library', '', $CONFIG['recursive_scan'] ? $maxDepth : 0, true, $videos, $subs);

    // Pattern B: the app's shared subtitle directory.
    if (is_dir(DIR_SUBTITLES)) {
        $ignoredVideos = [];
        discover_media(DIR_SUBTITLES, 'shared', '', 2, false, $ignoredVideos, $subs);
    }

    $result['counts']['videos'] = count($videos);
    $result['counts']['subtitle_files'] = count($subs);

    $matched = match_subtitles($videos, $subs);
    $result['unmatched_subtitles'] = $matched['unmatched'];
    $result['counts']['unmatched'] = count($matched['unmatched']);

    foreach ($videos as $i => $v) {
        $parsed = parse_movie_filename(basename($v['rel']));
        $tracks = $matched['tracks'][$i] ?? [];
        $result['counts']['tracks'] += count($tracks);
        $result['movies'][] = [
            'id'        => movie_id($v['rel']), // library-relative → stable across drives
            'filename'  => basename($v['rel']),
            'root'      => $v['root'],
            'rel'       => $v['rel'],           // relative to the active library root
            'ext'       => $v['ext'],
            'size'      => $v['size'],
            'mtime'     => $v['mtime'],
            'title'     => $parsed['title'],
            'year'      => $parsed['year'],
            'subtitles' => $tracks,
        ];
    }

    usort($result['movies'], static function ($a, $b) {
        if ($a['mtime'] !== $b['mtime']) {
            return $b['mtime'] - $a['mtime'];
        }
        return strcasecmp($a['title'], $b['title']);
    });

    return $result;
}

/**
 * Scan with a short TTL cache. api/movies.php forces a rescan; playback
 * endpoints use the cache so streaming never rescans. Config is never
 * modified here — an unavailable drive yields an error result, not a reset.
 */
function scan_library(bool $force = false): array
{
    global $CONFIG;

    $cacheFile = DIR_DATA . DIRECTORY_SEPARATOR . 'scan_cache.json';

    if (!$force) {
        $cached = json_read($cacheFile);
        if (!empty($cached['scanned_at']) && (time() - (int)$cached['scanned_at']) < (int)$CONFIG['scan_cache_ttl']) {
            return $cached;
        }
    }

    $result = perform_scan();
    if (empty($result['error'])) {
        json_write($cacheFile, $result);
    }
    return $result;
}

/** Find one movie by id inside a scan result. */
function find_movie(array $scan, string $id): ?array
{
    foreach ($scan['movies'] as $movie) {
        if ($movie['id'] === $id) {
            return $movie;
        }
    }
    return null;
}

/** Find one subtitle track by id inside a movie record. */
function find_track(array $movie, string $trackId): ?array
{
    foreach ($movie['subtitles'] as $track) {
        if ($track['id'] === $trackId) {
            return $track;
        }
    }
    return null;
}
