<?php
/**
 * Subtitle service — discovery helpers, language labelling, safe path
 * resolution, and SRT → WebVTT conversion.
 *
 * Supported in the browser: .vtt (native), .srt (converted server-side).
 * Discovered but NOT served to <track>: .ass/.ssa/.sub (reliable rendering
 * would require an extra dependency — documented limitation).
 */
declare(strict_types=1);

if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}

/* ------------------------------------------------------------------ */
/* Language detection                                                   */
/* ------------------------------------------------------------------ */

/** token => [code, label] for common subtitle language tokens. */
function subtitle_language_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $pairs = [
        'en' => 'en|English', 'eng' => 'en|English', 'english' => 'en|English',
        'es' => 'es|Spanish', 'spa' => 'es|Spanish', 'spanish' => 'es|Spanish', 'esp' => 'es|Spanish',
        'pt' => 'pt|Portuguese', 'por' => 'pt|Portuguese', 'portuguese' => 'pt|Portuguese',
        'fr' => 'fr|French', 'fre' => 'fr|French', 'fra' => 'fr|French', 'french' => 'fr|French',
        'de' => 'de|German', 'ger' => 'de|German', 'deu' => 'de|German', 'german' => 'de|German',
        'it' => 'it|Italian', 'ita' => 'it|Italian', 'italian' => 'it|Italian',
        'ru' => 'ru|Russian', 'rus' => 'ru|Russian', 'russian' => 'ru|Russian',
        'ar' => 'ar|Arabic', 'ara' => 'ar|Arabic', 'arabic' => 'ar|Arabic',
        'nl' => 'nl|Dutch', 'dut' => 'nl|Dutch', 'nld' => 'nl|Dutch', 'dutch' => 'nl|Dutch',
        'pl' => 'pl|Polish', 'pol' => 'pl|Polish', 'polish' => 'pl|Polish',
        'tr' => 'tr|Turkish', 'tur' => 'tr|Turkish', 'turkish' => 'tr|Turkish',
        'hu' => 'hu|Hungarian', 'hun' => 'hu|Hungarian', 'hungarian' => 'hu|Hungarian',
        'cs' => 'cs|Czech', 'cze' => 'cs|Czech', 'ces' => 'cs|Czech', 'czech' => 'cs|Czech',
        'ro' => 'ro|Romanian', 'rum' => 'ro|Romanian', 'ron' => 'ro|Romanian', 'romanian' => 'ro|Romanian',
        'bg' => 'bg|Bulgarian', 'bul' => 'bg|Bulgarian', 'bulgarian' => 'bg|Bulgarian',
        'el' => 'el|Greek', 'gre' => 'el|Greek', 'ell' => 'el|Greek', 'greek' => 'el|Greek',
        'da' => 'da|Danish', 'dan' => 'da|Danish', 'danish' => 'da|Danish',
        'fi' => 'fi|Finnish', 'fin' => 'fi|Finnish', 'finnish' => 'fi|Finnish',
        'no' => 'no|Norwegian', 'nor' => 'no|Norwegian', 'norwegian' => 'no|Norwegian',
        'sv' => 'sv|Swedish', 'swe' => 'sv|Swedish', 'swedish' => 'sv|Swedish',
        'sk' => 'sk|Slovak', 'slo' => 'sk|Slovak', 'slk' => 'sk|Slovak', 'slovak' => 'sk|Slovak',
        'sl' => 'sl|Slovenian', 'slv' => 'sl|Slovenian', 'slovenian' => 'sl|Slovenian',
        'uk' => 'uk|Ukrainian', 'ukr' => 'uk|Ukrainian', 'ukrainian' => 'uk|Ukrainian',
        'th' => 'th|Thai', 'tha' => 'th|Thai', 'thai' => 'th|Thai',
        'id' => 'id|Indonesian', 'ind' => 'id|Indonesian', 'indonesian' => 'id|Indonesian',
        'vi' => 'vi|Vietnamese', 'vie' => 'vi|Vietnamese', 'vietnamese' => 'vi|Vietnamese',
        'ko' => 'ko|Korean', 'kor' => 'ko|Korean', 'korean' => 'ko|Korean',
        'ja' => 'ja|Japanese', 'jpn' => 'ja|Japanese', 'japanese' => 'ja|Japanese',
        'zh' => 'zh|Chinese', 'chi' => 'zh|Chinese', 'zho' => 'zh|Chinese', 'chinese' => 'zh|Chinese',
        'he' => 'he|Hebrew', 'heb' => 'he|Hebrew', 'hebrew' => 'he|Hebrew',
        'hin' => 'hi|Hindi', 'hindi' => 'hi|Hindi',
        'ms' => 'ms|Malay', 'may' => 'ms|Malay', 'msa' => 'ms|Malay', 'malay' => 'ms|Malay',
        'sr' => 'sr|Serbian', 'scc' => 'sr|Serbian', 'srp' => 'sr|Serbian', 'serbian' => 'sr|Serbian',
        'hr' => 'hr|Croatian', 'hrv' => 'hr|Croatian', 'croatian' => 'hr|Croatian',
        'bs' => 'bs|Bosnian', 'bos' => 'bs|Bosnian', 'bosnian' => 'bs|Bosnian',
        'et' => 'et|Estonian', 'est' => 'et|Estonian', 'estonian' => 'et|Estonian',
        'lv' => 'lv|Latvian', 'lav' => 'lv|Latvian', 'latvian' => 'lv|Latvian',
        'lt' => 'lt|Lithuanian', 'lit' => 'lt|Lithuanian', 'lithuanian' => 'lt|Lithuanian',
        'fa' => 'fa|Persian', 'per' => 'fa|Persian', 'fas' => 'fa|Persian', 'persian' => 'fa|Persian',
        'bn' => 'bn|Bengali', 'ben' => 'bn|Bengali', 'bengali' => 'bn|Bengali',
        'ta' => 'ta|Tamil', 'tam' => 'ta|Tamil', 'tamil' => 'ta|Tamil',
        'te' => 'te|Telugu', 'tel' => 'te|Telugu', 'telugu' => 'te|Telugu',
        'ur' => 'ur|Urdu', 'urd' => 'ur|Urdu', 'urdu' => 'ur|Urdu',
        'ca' => 'ca|Catalan', 'cat' => 'ca|Catalan', 'catalan' => 'ca|Catalan',
        'eu' => 'eu|Basque', 'baq' => 'eu|Basque', 'eus' => 'eu|Basque', 'basque' => 'eu|Basque',
        'gl' => 'gl|Galician', 'glg' => 'gl|Galician', 'galician' => 'gl|Galician',
        'is' => 'is|Icelandic', 'ice' => 'is|Icelandic', 'isl' => 'is|Icelandic', 'icelandic' => 'is|Icelandic',
        'ga' => 'ga|Irish', 'gle' => 'ga|Irish', 'irish' => 'ga|Irish',
        'cy' => 'cy|Welsh', 'wel' => 'cy|Welsh', 'cym' => 'cy|Welsh', 'welsh' => 'cy|Welsh',
        'sq' => 'sq|Albanian', 'alb' => 'sq|Albanian', 'sqi' => 'sq|Albanian', 'albanian' => 'sq|Albanian',
        'mk' => 'mk|Macedonian', 'mac' => 'mk|Macedonian', 'mkd' => 'mk|Macedonian', 'macedonian' => 'mk|Macedonian',
        'ka' => 'ka|Georgian', 'kat' => 'ka|Georgian', 'georgian' => 'ka|Georgian',
        'hy' => 'hy|Armenian', 'arm' => 'hy|Armenian', 'hye' => 'hy|Armenian', 'armenian' => 'hy|Armenian',
        'az' => 'az|Azerbaijani', 'aze' => 'az|Azerbaijani', 'azerbaijani' => 'az|Azerbaijani',
        'kk' => 'kk|Kazakh', 'kaz' => 'kk|Kazakh', 'kazakh' => 'kk|Kazakh',
        'uz' => 'uz|Uzbek', 'uzb' => 'uz|Uzbek', 'uzbek' => 'uz|Uzbek',
        'mn' => 'mn|Mongolian', 'mon' => 'mn|Mongolian', 'mongolian' => 'mn|Mongolian',
        'ne' => 'ne|Nepali', 'nep' => 'ne|Nepali', 'nepali' => 'ne|Nepali',
        'si' => 'si|Sinhala', 'sin' => 'si|Sinhala', 'sinhala' => 'si|Sinhala',
        'my' => 'my|Burmese', 'bur' => 'my|Burmese', 'mya' => 'my|Burmese', 'burmese' => 'my|Burmese',
        'km' => 'km|Khmer', 'khm' => 'km|Khmer', 'khmer' => 'km|Khmer',
        'sw' => 'sw|Swahili', 'swa' => 'sw|Swahili', 'swahili' => 'sw|Swahili',
        'af' => 'af|Afrikaans', 'afr' => 'af|Afrikaans', 'afrikaans' => 'af|Afrikaans',
        'am' => 'am|Amharic', 'amh' => 'am|Amharic', 'amharic' => 'am|Amharic',
        'ku' => 'ku|Kurdish', 'kur' => 'ku|Kurdish', 'kurdish' => 'ku|Kurdish',
        'ps' => 'ps|Pashto', 'pus' => 'ps|Pashto', 'pashto' => 'ps|Pashto',
        'ml' => 'ml|Malayalam', 'mal' => 'ml|Malayalam', 'malayalam' => 'ml|Malayalam',
        'mr' => 'mr|Marathi', 'mar' => 'mr|Marathi', 'marathi' => 'mr|Marathi',
        'pa' => 'pa|Punjabi', 'pan' => 'pa|Punjabi', 'punjabi' => 'pa|Punjabi',
        'yo' => 'yo|Yoruba', 'yor' => 'yo|Yoruba', 'yoruba' => 'yo|Yoruba',
        'ha' => 'ha|Hausa', 'hau' => 'ha|Hausa', 'hausa' => 'ha|Hausa',
        'so' => 'so|Somali', 'som' => 'so|Somali', 'somali' => 'so|Somali',
        'ka' => 'ka|Georgian',
    ];
    $map = [];
    foreach ($pairs as $token => $spec) {
        list($code, $label) = explode('|', $spec);
        $map[$token] = ['code' => $code, 'label' => $label];
    }
    return $map;
}

/** Region/variant qualifiers combined with a language (YTS naming style). */
function subtitle_variant_map(): array
{
    return [
        'brazilian'     => 'brazilian',
        'brasilian'     => 'brazilian',
        'iberian'       => 'iberian',
        'latin american'=> 'latin-american',
        'latino'        => 'latin-american',
        'castilian'     => 'castilian',
        'parisian'      => 'parisian',
        'canadian'      => 'canadian',
        'simplified'    => 'simplified',
        'traditional'   => 'traditional',
        'hong kong'     => 'traditional',
        'british'       => 'british',
        'american'      => 'american',
    ];
}

/**
 * Parse a subtitle filename (WITHOUT the movie basename) into
 * language + label + flags.  Examples: "eng" / "English" / "Brazilian.por" /
 * "SDH.eng.HI" / "Simplified.chi".
 *
 * @return array{code: string, label: string, flags: array<int,string>}
 */
function subtitle_language_info(string $name): array
{
    $lowerName = mb_strtolower($name, 'UTF-8');

    // Multi-word variants ("Latin American", "Hong Kong") must be recognised
    // BEFORE tokenisation splits them apart.
    $preVariant = null;
    foreach (array_keys(subtitle_variant_map()) as $phrase) {
        if (strpos($phrase, ' ') !== false && strpos($lowerName, $phrase) !== false) {
            $preVariant = subtitle_variant_map()[$phrase];
            $lowerName = str_replace($phrase, ' ', $lowerName);
            break;
        }
    }

    $rawTokens = preg_split('/[.\s_\-]+/u', $lowerName);
    $tokens = [];
    foreach ((array)$rawTokens as $t) {
        $t = trim((string)$t);
        if ($t !== '') {
            $tokens[] = $t;
        }
    }
    $map = subtitle_language_map();
    $variants = subtitle_variant_map();

    $flags = [];
    $lang = null;
    $variant = $preVariant;

    foreach ($tokens as $token) {
        if ($token === 'sdh' || $token === 'hi' || $token === 'fh') {
            $flags['sdh'] = 'SDH';
            continue;
        }
        if ($token === 'cc') {
            $flags['cc'] = 'CC';
            continue;
        }
        if ($token === 'forced') {
            $flags['forced'] = 'Forced';
            continue;
        }
        if (isset($map[$token])) {
            $lang = $map[$token]; // last recognised language token wins
        }
        if (isset($variants[$token])) {
            $variant = $variants[$token];
        }
    }

    if ($lang === null) {
        return ['code' => '', 'label' => '', 'flags' => array_values($flags)];
    }

    $code = $lang['code'];
    $label = $lang['label'];

    // Variant-aware refinements.
    if ($variant !== null) {
        switch ($variant) {
            case 'brazilian':
                $code = 'pt-BR'; $label = 'Portuguese (Brazil)'; break;
            case 'iberian':
                $code = 'pt-PT'; $label = 'Portuguese (Portugal)'; break;
            case 'latin-american':
                $code = 'es-419'; $label = 'Spanish (Latin American)'; break;
            case 'castilian':
                $code = 'es-ES'; $label = 'Spanish (Castilian)'; break;
            case 'parisian':
            case 'french':
                $code = 'fr'; $label = 'French'; break;
            case 'simplified':
                $code = 'zh-Hans'; $label = 'Chinese (Simplified)'; break;
            case 'traditional':
                $code = 'zh-Hant'; $label = 'Chinese (Traditional)'; break;
            case 'british':
                if ($code === 'en') { $label = 'English (UK)'; }
                break;
            case 'american':
                if ($code === 'en') { $label = 'English (US)'; }
                break;
            case 'canadian':
                if ($code === 'fr') { $label = 'French (Canadian)'; }
                break;
        }
    }

    if (isset($flags['forced'])) {
        $label .= ' (Forced)';
    } elseif (isset($flags['sdh'])) {
        $label .= ' (SDH)';
    } elseif (isset($flags['cc'])) {
        $label .= ' (CC)';
    }

    return ['code' => $code, 'label' => $label, 'flags' => array_values($flags)];
}

/* ------------------------------------------------------------------ */
/* Safe path resolution                                                 */
/* ------------------------------------------------------------------ */

/**
 * Resolve a subtitle file safely inside one of the two allowed trees.
 * @param string $root 'library' (active movie dir) or 'shared' (assets/subtitles)
 * @param string $rel  path relative to that root
 * @return string|null absolute path or null
 */
function safe_subtitle_path(string $rel, string $root = 'library'): ?string
{
    global $CONFIG;

    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || strpos($rel, "\0") !== false) {
        return null;
    }
    if ($rel[0] === '/' || preg_match('#^[A-Za-z]:#', $rel)) {
        return null;
    }
    if (strpos($rel, '..') !== false) {
        return null;
    }

    $tree = ($root === 'shared') ? DIR_SUBTITLES : (($root === 'library') ? movies_dir() : null);
    if ($tree === null) {
        return null;
    }
    $treeReal = realpath($tree);
    if ($treeReal === false) {
        return null; // library drive disconnected
    }

    $candidate = realpath($treeReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    if ($candidate === false || !is_file($candidate)) {
        return null;
    }

    $treePrefix = rtrim($treeReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($candidate, $treePrefix) !== 0) {
        return null; // junction/symlink escape
    }

    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    if (!in_array($ext, $CONFIG['subtitles']['extensions'], true)) {
        return null;
    }
    return $candidate;
}

/* ------------------------------------------------------------------ */
/* SRT → WebVTT conversion                                              */
/* ------------------------------------------------------------------ */

/** Normalise one SRT/VTT timestamp to "HH:MM:SS.mmm". */
function subtitle_normalize_timestamp(string $ts): string
{
    $ts = str_replace(',', '.', trim($ts));
    if (!preg_match('/^(\d{1,3}):(\d{2}):(\d{2})(?:\.(\d{1,3}))?$/', $ts, $m)) {
        return '';
    }
    $hours = str_pad($m[1], 2, '0', STR_PAD_LEFT);
    $frac = isset($m[4]) ? str_pad($m[4], 3, '0', STR_PAD_RIGHT) : '000';
    return $hours . ':' . $m[2] . ':' . $m[3] . '.' . $frac;
}

/**
 * Convert SRT content to WebVTT properly (headers, cue numbering,
 * timestamp format, malformed-cue skipping). Never a rename.
 * Returns null when no valid cue exists (unreadable subtitle).
 */
function srt_to_vtt(string $srt): ?string
{
    // Strip UTF-8 BOM.
    if (substr($srt, 0, 3) === "\xEF\xBB\xBF") {
        $srt = substr($srt, 3);
    }
    $srt = str_replace(["\r\n", "\r"], "\n", $srt);

    // Some downloads ship WebVTT content in a .srt file — detect and reuse.
    if (preg_match('/^WEBVTT/', $srt)) {
        return $srt;
    }

    $blocks = preg_split('/\n{2,}/', trim($srt));
    if ($blocks === false) {
        return null;
    }

    $out = "WEBVTT\n\n";
    $cueNo = 0;
    $timingRe = '/^(\d{1,3}:\d{2}:\d{2}[,.]\d{1,3})\s*-->\s*(\d{1,3}:\d{2}:\d{2}[,.]\d{1,3})(.*)$/';

    foreach ($blocks as $block) {
        $lines = explode("\n", trim($block));
        if (count($lines) < 2) {
            continue;
        }
        // Locate the timing line (index line optional).
        $tIdx = null;
        foreach ($lines as $i => $line) {
            if (preg_match($timingRe, trim($line))) {
                $tIdx = $i;
                break;
            }
        }
        if ($tIdx === null) {
            continue; // malformed cue — skip, never emit broken VTT
        }
        if (!preg_match($timingRe, trim($lines[$tIdx]), $m)) {
            continue;
        }
        $start = subtitle_normalize_timestamp($m[1]);
        $end = subtitle_normalize_timestamp($m[2]);
        if ($start === '' || $end === '') {
            continue;
        }

        $text = implode("\n", array_slice($lines, $tIdx + 1));
        // VTT does not support <font> tags; keep the rest of the inline markup.
        $text = (string)preg_replace('/<font[^>]*>/i', '', (string)$text);
        $text = str_replace('</font>', '', (string)$text);
        if (trim($text) === '') {
            continue;
        }

        $cueNo++;
        $out .= $cueNo . "\n" . $start . ' --> ' . $end . trim($m[3]) . "\n" . $text . "\n\n";
    }

    return $cueNo > 0 ? $out : null;
}

/**
 * Validate WebVTT content (for .vtt files served as-is).
 * Returns cleaned content or null when it is not usable VTT.
 */
function validate_vtt(string $vtt): ?string
{
    if (substr($vtt, 0, 3) === "\xEF\xBB\xBF") {
        $vtt = substr($vtt, 3);
    }
    if (!preg_match('/^WEBVTT/', $vtt)) {
        return null;
    }
    return $vtt;
}
