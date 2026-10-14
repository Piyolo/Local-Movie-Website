<?php
/**
 * Conservative filename → {title, year} parsing.
 *
 * Goals (from spec):
 *   "Interstellar (2014).mp4"                    → Interstellar / 2014
 *   "The.Dark.Knight.2008.1080p.BluRay.x264.mkv" → The Dark Knight / 2008
 *   "Movie Name - Director's Cut (2020).mkv"     → Movie Name - Director's Cut / 2020
 *
 * Rules are deliberately conservative: the original filename is always
 * preserved separately, and metadata/overrides can correct the guess.
 */
declare(strict_types=1);

if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}

/** Release-tag tokens that are stripped from the tail of a guessed title. */
function release_tags(): array
{
    static $tags = null;
    if ($tags === null) {
        $tags = array_flip([
            '4k', '2160p', '1080p', '720p', '576p', '480p', '360p',
            'uhd', 'bluray', 'bdrip', 'brrip', 'bdremux', 'remux', 'dvdrip', 'dvdscr',
            'dvd', 'hdcam', 'camrip', 'cam', 'hdtv', 'hdrip', 'webrip', 'web-dl', 'webdl',
            'aac', 'ac3', 'eac3', 'ddp', 'dd5', 'dts', 'truehd', 'atmos',
            'x264', 'x265', 'h264', 'h265', 'hevc', 'avc', '10bit', '8bit', 'hdr', 'dv',
            '5.1', '7.1', '2.0', 'atmos', 'dual', 'audio', 'sub', 'subs', 'subbed',
            'proper', 'repack', 'extended', 'unrated', 'uncut', 'theatrical',
            'hybrid', 'remastered', 'restored', 'complete', 'internal',
            'yts', 'yify', 'yts.mx', 'yts.am', 'yts.lt',
            'imax',
        ]);
    }
    return $tags;
}

/**
 * Parse a movie filename (with or without extension).
 * @return array{title: string, year: int|null}
 */
function parse_movie_filename(string $filename): array
{
    $base = $filename;
    if (($pos = strrpos($base, '.')) !== false && $pos > 0) {
        $ext = strtolower(substr($base, $pos + 1));
        // Only strip a known media/document extension so "2001.A.Space.Odyssey"
        // style names are not truncated at an interior dot.
        static $mediaExt = ['mp4', 'm4v', 'webm', 'mkv', 'mov', 'avi', 'wmv', 'ts',
            'srt', 'vtt', 'ass', 'ssa', 'sub', 'mpg', 'mpeg', 'flv', 'ogv'];
        if (in_array($ext, $mediaExt, true)) {
            $base = substr($base, 0, $pos);
        }
    }

    $year = null;
    $title = $base;

    // Find plausible release years: (19|20)xx as a standalone number.
    if (preg_match_all('/(?<!\d)((?:19|20)\d{2})(?!\d)/', $base, $m, PREG_OFFSET_CAPTURE)) {
        // Prefer the LAST plausible year (release tags rarely contain years,
        // and title words like "2001" usually precede the real release year).
        $currentYear = (int)date('Y');
        foreach (array_reverse($m[0]) as $match) {
            $candidate = (int)$match[0];
            if ($candidate >= 1900 && $candidate <= $currentYear + 1) {
                $year = $candidate;
                $title = substr($base, 0, $match[1]);
                break;
            }
        }
    }

    $title = cleanup_title($title);
    if ($title === '') {
        $title = cleanup_title($base); // year-only prefix would be nonsense
    }

    return ['title' => $title, 'year' => $year];
}

/** Normalise separators, strip trailing release tags, trim punctuation. */
function cleanup_title(string $raw): string
{
    $t = preg_replace('/[._]+/', ' ', $raw);
    $t = (string)preg_replace('/\s+/u', ' ', $t);
    $t = trim($t);

    // Strip bracket groups that only contain tags/years: " [1080p]" " (YTS.MX)"
    $t = (string)preg_replace('/\s*[[({][^\])}]*[\])}]\s*$/u', ' ', $t);
    $t = trim($t);

    // Repeatedly remove known trailing release tags ("... BluRay x264").
    $tags = release_tags();
    for ($i = 0; $i < 8; $i++) {
        $trimmed = preg_replace('/\s+-\s+$/u', '', (string)trim($t));
        $trimmed = (string)trim((string)$trimmed, " \t-–—:");
        if ($trimmed === '') {
            break;
        }
        $parts = preg_split('/\s+/u', (string)$trimmed);
        $last = strtolower((string)end($parts));
        if ($last !== '' && isset($tags[$last])) {
            array_pop($parts);
            $t = implode(' ', $parts);
            continue;
        }
        $t = $trimmed;
        break;
    }

    $t = trim((string)$t, " \t-–—:.,([{");
    return ($t === '' || $t === '(' || $t === '[' || $t === '{') ? 'Unknown' : $t;
}

/** Normalised title used for conservative match comparisons. */
function normalize_title(string $title): string
{
    $t = mb_strtolower($title, 'UTF-8');
    $t = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
    return trim((string)preg_replace('/\s+/u', ' ', $t));
}
