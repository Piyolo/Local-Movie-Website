<?php
/**
 * Shared page chrome: <head> + top navigation + toast/banner hosts.
 * Server-included (includes/ is HTTP-denied). Expects optional:
 *   $PAGE_TITLE (string), $ACTIVE_NAV ('home'|'movies'|'search'|'')
 *   $PAGE_BODY_CLASS (string)
 */
declare(strict_types=1);
if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}
$PAGE_TITLE     = $PAGE_TITLE ?? 'Home';
$ACTIVE_NAV     = $ACTIVE_NAV ?? '';
$PAGE_BODY_CLASS = $PAGE_BODY_CLASS ?? '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="mw-base" content="<?= h(base_url()) ?>">
<title><?= h($PAGE_TITLE) ?> · MovieWebsite</title>
<link rel="icon" href="<?= h(url('assets/icons/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= h(asset_url('css/style.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_url('css/responsive.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_url('css/tv.css')) ?>">
</head>
<body class="<?= h($PAGE_BODY_CLASS) ?>">

<?php
/* Inline SVG sprite — Phosphor Icons (MIT, assets/icons/). Symbols are
 * referenced as <svg class="i"><use href="#i-play"/></svg> from markup and
 * from JS (app.js MW.icon, player.js). Fixed list, no user input. */
$MW_ICON_SPRITE = ['play', 'pause', 'star', 'arrow-right', 'x', 'speaker-high', 'speaker-x', 'arrows-out'];
$mw_sprite = '';
foreach ($MW_ICON_SPRITE as $mw_ico) {
    $mw_file = dirname(__DIR__) . '/assets/icons/' . $mw_ico . '.svg';
    if (!is_file($mw_file)) {
        continue;
    }
    $mw_raw = file_get_contents($mw_file);
    $mw_inner = preg_replace('/^.*?<svg[^>]*>/s', '', $mw_raw);
    $mw_inner = preg_replace('/<\/svg>\s*$/s', '', $mw_inner);
    $mw_sprite .= '  <symbol id="i-' . h($mw_ico) . '" viewBox="0 0 256 256">' . $mw_inner . "</symbol>\n";
}
?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="display:none" aria-hidden="true" focusable="false">
<?= $mw_sprite ?>
</svg>

<header class="nav" id="topnav">
  <div class="nav-inner">
    <a class="nav-logo" href="<?= h(url('index.php')) ?>" aria-label="MovieWebsite home">
      <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" class="nav-logo-icon">
        <rect x="2" y="5" width="20" height="14" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/>
        <path d="M7 5v14M17 5v14M2 12h20" stroke="currentColor" stroke-width="1.2" opacity=".55"/>
        <path d="M10.4 9.2v5.6l4.4-2.8z" fill="currentColor"/>
      </svg>
      <span>MovieWebsite</span>
    </a>
    <nav class="nav-links" id="nav-links" aria-label="Main">
      <a href="<?= h(url('index.php')) ?>" class="nav-link<?= $ACTIVE_NAV === 'home' ? ' active' : '' ?>">Home</a>
      <a href="<?= h(url('search.php')) ?>" class="nav-link<?= $ACTIVE_NAV === 'movies' ? ' active' : '' ?>">All Movies</a>
      <a href="<?= h(url('search.php')) ?>?view=search" class="nav-link<?= $ACTIVE_NAV === 'search' ? ' active' : '' ?>">Search</a>
    </nav>
    <div class="nav-actions">
      <button type="button" class="nav-btn nav-burger" id="nav-burger" aria-label="Menu" aria-expanded="false" aria-controls="nav-links">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
          <path d="M3.5 6.5h17M3.5 12h17M3.5 17.5h17" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
        </svg>
      </button>
      <button type="button" id="btn-library-settings" class="nav-btn" title="Library Settings" aria-haspopup="dialog">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
          <path fill="currentColor" d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm9.4 4a7.6 7.6 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7.7 7.7 0 0 0-2-1.2L16.5 3h-4l-.4 2.6a7.7 7.7 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.6a7.9 7.9 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-1c.6.5 1.3.9 2 1.2l.4 2.6h4l.4-2.6c.7-.3 1.4-.7 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2z"/>
        </svg>
        <span class="nav-btn-label">Library Settings</span>
      </button>
    </div>
  </div>
</header>

<div id="global-banner" class="banner" hidden role="status"></div>

<main class="main" id="main">
