<?php
/**
 * MovieWebsite unit tests — run from CLI:
 *   C:\xampp\php\php.exe tests\unit.php
 * Exit code 0 = all pass.
 */
declare(strict_types=1);

define('MOVIEWEBSITE', true);
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/cache.php';
require __DIR__ . '/../includes/library_config.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/folder_browser.php';
require __DIR__ . '/../includes/native_picker.php';
require __DIR__ . '/../includes/filename_parser.php';
require __DIR__ . '/../includes/subtitles.php';
require __DIR__ . '/../includes/scanner.php';
require __DIR__ . '/../includes/video_service.php';

$pass = 0;
$fail = 0;
function ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
}

/* ---------------- filename parsing ---------------- */
echo "== filename parser ==\n";
ok(parse_movie_filename('Interstellar (2014).mp4') === ['title' => 'Interstellar', 'year' => 2014], 'Interstellar (2014)');
$dk = parse_movie_filename('The.Dark.Knight.2008.1080p.BluRay.x264.mkv');
ok($dk['title'] === 'The Dark Knight' && $dk['year'] === 2008, 'The Dark Knight scene name');
$dc = parse_movie_filename("Movie Name - Director's Cut (2020).mkv");
ok($dc['title'] === "Movie Name - Director's Cut" && $dc['year'] === 2020, 'Director\'s Cut kept in title');
$w = parse_movie_filename('Weapons.2025.HYBRID.1080p.BluRay.x264.AAC5.1-[YTS.MX].mp4');
ok($w['title'] === 'Weapons' && $w['year'] === 2025, 'YTS pack name');
$noYear = parse_movie_filename('Another Movie.mp4');
ok($noYear['title'] === 'Another Movie' && $noYear['year'] === null, 'no year');
$asw = parse_movie_filename('2001.A.Space.Odyssey.1968.1080p.mkv');
ok($asw['year'] === 1968 && strpos($asw['title'], '2001') !== false, '2001 A Space Odyssey (title year ≠ release year)');

/* ---------------- subtitle language labels ---------------- */
echo "== language labels ==\n";
ok(subtitle_language_info('eng')['label'] === 'English', 'eng → English');
ok(subtitle_language_info('English')['code'] === 'en', 'English → en');
ok(subtitle_language_info('Brazilian.por')['label'] === 'Portuguese (Brazil)', 'Brazilian.por');
ok(subtitle_language_info('SDH.eng.HI')['label'] === 'English (SDH)', 'SDH.eng.HI');
ok(subtitle_language_info('Simplified.chi')['code'] === 'zh-Hans', 'Simplified.chi');
ok(subtitle_language_info('Latin American.spa')['label'] === 'Spanish (Latin American)', 'Latin American.spa');
ok(subtitle_language_info('ara')['label'] === 'Arabic', 'ara');
ok(subtitle_language_info('zzz')['label'] === '', 'unknown token → empty label');

/* ---------------- subtitle matching ---------------- */
echo "== subtitle matching ==\n";
$vid = static fn (string $rel) => ['root' => 'library', 'rel' => $rel, 'base' => strtolower(pathinfo($rel, PATHINFO_FILENAME)),
    'dir' => dirname($rel) === '.' ? '' : str_replace('\\', '/', dirname($rel)), 'ext' => strtolower(pathinfo($rel, PATHINFO_EXTENSION)), 'size' => 100, 'mtime' => 1];
$sub = static fn (string $rel, int $size = 100) => ['root' => 'library', 'rel' => $rel, 'base' => strtolower(pathinfo($rel, PATHINFO_FILENAME)),
    'dir' => dirname($rel) === '.' ? '' : str_replace('\\', '/', dirname($rel)), 'ext' => strtolower(pathinfo($rel, PATHINFO_EXTENSION)), 'size' => $size, 'mtime' => 1];

// 1. exact basename match
$r = match_subtitles([$vid('Movie.mkv')], [$sub('Movie.srt')]);
ok(isset($r['tracks'][0]) && $r['tracks'][0][0]['label'] === 'English' && $r['tracks'][0][0]['match'] === 'exact', 'exact Movie.srt');

// 2. language suffix
$r = match_subtitles([$vid('Movie.mkv')], [$sub('Movie.eng.srt')]);
ok(isset($r['tracks'][0]) && $r['tracks'][0][0]['match'] === 'suffix' && $r['tracks'][0][0]['code'] === 'en', 'Movie.eng.srt suffix');

// 3. multiple languages via suffix
$r = match_subtitles([$vid('Movie.mkv')], [$sub('Movie.eng.srt'), $sub('Movie.spa.srt'), $sub('Movie.fre.srt')]);
ok(count($r['tracks'][0] ?? []) === 3, 'three language suffixes');

// 4. no subtitles
$r = match_subtitles([$vid('Movie.mkv')], []);
ok(!isset($r['tracks'][0]) && $r['unmatched'] === [], 'no subtitles at all');

// 5. unmatched subtitle (different movie name, multi-video dir → ambiguous)
$r = match_subtitles([$vid('MovieA.mkv'), $vid('MovieB.mkv')], [$sub('Other.srt')]);
ok($r['unmatched'] === ['Other.srt'], 'unmatched subtitle reported');

// companion rule: single-video folder with Subs\
$videos = [$vid('Weapons (2025)/Weapons.2025.mp4')];
$subs = [
    $sub('Weapons (2025)/Subs/English.srt'),
    $sub('Weapons (2025)/Subs/ara.srt'),
    $sub('Weapons (2025)/Subs/SDH.eng.HI.srt'),
    $sub('Weapons (2025)/Subs/Brazilian.por.srt'),
    $sub('Weapons (2025)/Weapons.2025.srt', 90), // sidecar with distinct size → not deduped
];
$r = match_subtitles($videos, $subs);
ok(isset($r['tracks'][0]) && count($r['tracks'][0]) === 5, 'YTS pack: 4 Subs + sidecar all matched (dup sidecar deduped if identical)');
$labels = array_column($r['tracks'][0], 'label');
ok(in_array('English', $labels, true) && in_array('Arabic', $labels, true) && in_array('Portuguese (Brazil)', $labels, true), 'companion language labels');
ok(in_array('English (SDH)', $labels, true), 'SDH label present');

// companion rule with 2 videos in folder → unmatched (conservative)
$r = match_subtitles([$vid('Pack/filmA.mkv'), $vid('Pack/filmB.mkv')], [$sub('Pack/Subs/English.srt')]);
ok($r['unmatched'] === ['English.srt'], 'ambiguous companion (2 videos) → unmatched');

// spaces/unicode filename matching
$r = match_subtitles([$vid('Film Ünïcode (2020).mkv')], [$sub('Film Ünïcode (2020).srt')]);
ok(isset($r['tracks'][0]), 'unicode + spaces match');

// shared dir (Pattern B) unique match
$shared = ['root' => 'shared', 'rel' => 'Movie.srt', 'base' => 'movie', 'dir' => '', 'ext' => 'srt', 'size' => 100, 'mtime' => 1];
$r = match_subtitles([$vid('folder/Movie.mkv')], [$shared]);
ok(isset($r['tracks'][0]) && $r['tracks'][0][0]['match'] === 'shared', 'shared dir unique match');
// shared dir ambiguous (2 copies) → unmatched
$r = match_subtitles([$vid('a/Movie.mkv'), $vid('b/Movie.mkv')], [$shared]);
ok($r['unmatched'] === ['Movie.srt'], 'shared dir ambiguous → unmatched');

/* ---------------- stable IDs ---------------- */
echo "== stable ids ==\n";
ok(movie_id('Interstellar (2014).mp4') === movie_id('Interstellar (2014).mp4'), 'id deterministic');
ok(movie_id('a/Movie.mkv') !== movie_id('b/Movie.mkv'), 'same filename, different folders → different ids');
ok(movie_id('Movie.mkv') !== movie_id('Movie.mp4'), 'different extensions → different ids');
ok(movie_id('Movie.mkv') === movie_id('MOVIE.MKV'), 'case-insensitive stable id');

/* ---------------- SRT → VTT conversion ---------------- */
echo "== srt to vtt ==\n";
$srt = "1\r\n00:00:01,000 --> 00:00:02,500\r\nHello world\r\n\r\n2\r\n00:00:03,250 --> 00:00:04,000\r\nSecond line\r\n";
$vtt = srt_to_vtt($srt);
ok($vtt !== null && strpos($vtt, "WEBVTT\n") === 0, 'VTT header');
ok($vtt !== null && strpos($vtt, '00:00:01.000 --> 00:00:02.500') !== false, 'timestamp format converted');
ok($vtt !== null && strpos($vtt, "\n1\n") !== false, 'cue numbering');
$srtBom = "\xEF\xBB\xBF" . $srt;
ok(srt_to_vtt($srtBom) !== null, 'BOM handled');
$bad = "1\r\nnot a timestamp\r\nbroken text\r\n\r\n";
ok(srt_to_vtt($bad) === null, 'malformed file → null');
$vttNative = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHi\n";
ok(srt_to_vtt($vttNative) !== null, 'vtt-content-in-srt passes through');
ok(validate_vtt($vttNative) !== null && validate_vtt('garbage') === null, 'vtt validation');

/* ---------------- HTTP range parsing ---------------- */
echo "== range parsing ==\n";
ok(parse_range_header(null, 1000)['mode'] === 'full', 'no header → full');
$p = parse_range_header('bytes=0-499', 1000);
ok($p['mode'] === 'part' && $p['start'] === 0 && $p['end'] === 499, 'bytes=0-499');
$p = parse_range_header('bytes=500-', 1000);
ok($p['mode'] === 'part' && $p['start'] === 500 && $p['end'] === 999, 'bytes=500-');
$p = parse_range_header('bytes=-200', 1000);
ok($p['mode'] === 'part' && $p['start'] === 800 && $p['end'] === 999, 'suffix bytes=-200');
ok(parse_range_header('bytes=2000-3000', 1000)['mode'] === 'unsatisfiable', 'start ≥ size → 416');
ok(parse_range_header('bytes=500-100', 1000)['mode'] === 'unsatisfiable', 'end < start → 416');
ok(parse_range_header('bytes=abc', 1000)['mode'] === 'unsatisfiable', 'garbage → 416');
ok(parse_range_header('bytes=0-9999', 1000)['mode'] === 'part' && parse_range_header('bytes=0-9999', 1000)['end'] === 999, 'end clamped to size');
ok(parse_range_header('items=0-1', 1000)['mode'] === 'unsatisfiable', 'unknown unit → 416');
ok(parse_range_header('bytes=0-10,20-30', 1000)['mode'] === 'full', 'multi-range → full');

/* ---------------- library path validation ---------------- */
echo "== library path validation ==\n";
ok(library_path_syntax_valid('D:\\Movies'), 'valid drive path');
ok(library_path_syntax_valid('E:\\My Movie Collection'), 'spaces allowed');
ok(library_path_syntax_valid('G:\\YTS Downloads\\Sub Dir'), 'nested valid');
ok(!library_path_syntax_valid(''), 'empty rejected');
ok(!library_path_syntax_valid('relative\\path'), 'relative rejected');
ok(!library_path_syntax_valid('D:\\Movies\\..\\..\\Windows'), 'traversal rejected');
ok(!library_path_syntax_valid('D:\\Movies\\..'), 'dotdot rejected');
ok(!library_path_syntax_valid("D:\\Bad\0Path"), 'null byte rejected');
ok(library_validate_path('Q:\\No Such Folder') !== null, 'nonexistent path rejected');
ok(library_validate_path('Z:\\') === 'drive_not_available' || is_dir('Z:\\'), 'missing drive → drive_not_available');
ok(library_validate_path('C:\\Windows') === 'denied_location', 'C:\\Windows denied');
ok(library_validate_path('C:\\Program Files') === 'denied_location', 'Program Files denied');
$realVideos = realpath('C:\\Users\\' . get_current_user() . '\\Videos\\Movies');
ok($realVideos === false || library_validate_path($realVideos) === null, 'existing readable folder accepted');

/* ---------------- folder browser ---------------- */
echo "== folder browser ==\n";
$drives = folder_browser_drives();
ok(count($drives) >= 1 && $drives[0]['letter'] === 'C', 'drives probed (no shell)');
$listing = folder_browser_list('C:\\');
ok($listing['error'] === null, 'C:\\ listable');
ok($listing['path'] === 'C:\\', 'drive root reported as C:\\ not C:');
ok(folder_browser_resolve('C:\\') === 'C:\\', 'drive root resolves to itself (not CWD)');
$names = array_column($listing['dirs'], 'name');
ok(!in_array('Windows', $names, true), 'Windows hidden from listing');
ok(!in_array('Program Files', $names, true), 'Program Files hidden');
$windowsOnly = array_filter($names, static fn ($n) => in_array(strtolower($n), ['windows', 'program files', 'programdata'], true));
ok(count($windowsOnly) === 0, 'no system dirs in C:\\ listing');
ok(folder_browser_resolve('C:\\..\\..\\x') === null, 'browser traversal rejected');
ok(folder_browser_resolve('not a path') === null, 'browser non-path rejected');
ok(folder_browser_list('C:\\')['parent'] === null, 'C:\\ has no parent');

/* ---------------- native picker: host detection ---------------- */
echo "== native picker host detection ==\n";
ok(picker_request_is_host('127.0.0.1', '192.168.100.2'), 'loopback is the host');
ok(picker_request_is_host('::1', '192.168.100.2'), 'IPv6 loopback is the host');
ok(picker_request_is_host('192.168.100.2', '192.168.100.2'), 'PC reaching itself via LAN IP is the host');
ok(!picker_request_is_host('192.168.100.105', '192.168.100.2'), 'other LAN device is not the host');
ok(!picker_request_is_host('192.168.100.24', '192.168.100.2'), 'second interface of another machine is not the host');

/* ---------------- path normalization ---------------- */
echo "== path normalization ==\n";
ok(library_normalize_win_path('c:\\') === 'C:\\', 'lowercase drive root → C:\\');
ok(library_normalize_win_path('C:/Movies/') === 'C:\\Movies', 'forward slashes + trailing slash');
ok(library_normalize_win_path('E:\\My Movies') === 'E:\\My Movies', 'spaces preserved');
ok(substr(library_normalize_win_path('d:\\'), -2) === ':\\', 'drive root never becomes "d:" (CWD bug)');

/* ---------------- denied scan dirs ---------------- */
echo "== scan skip list ==\n";
ok(in_array('windows', library_scan_skip_dirs(), true), 'windows skipped during scans');
ok(in_array('$recycle.bin', library_scan_skip_dirs(), true), '$recycle.bin skipped');

echo "\n==================================\n";
echo "PASSED: $pass   FAILED: $fail\n";
exit($fail === 0 ? 0 : 1);
