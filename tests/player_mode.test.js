#!/usr/bin/env node
/* Unit test for the player's device split — js/player.js classifyMode(ua).
 *
 * classifyMode is a pure UA classifier; this test extracts it straight from
 * player.js (so it can never drift from the shipped code) and runs it
 * against real-world user-agent strings:
 *
 *   node tests/player_mode.test.js
 *
 * Expected modes:
 *   mobile → phone player (native fullscreen, auto-hiding controls)
 *   tv     → TV player (pseudo-fullscreen, always-visible controls)
 *   other  → desktop; isTvLike() then falls back to pointer/hover media
 *            queries for unknown touch devices.
 */
'use strict';

var fs = require('fs');
var path = require('path');

var src = fs.readFileSync(path.join(__dirname, '..', 'js', 'player.js'), 'utf8');
var m = src.match(/function classifyMode\(ua\) \{[\s\S]*?\n  \}/);
if (!m) {
  console.error('FAIL: classifyMode() not found in js/player.js');
  process.exit(1);
}
/* eslint-disable no-eval */
var classifyMode = eval('(' + m[0] + ')');

var cases = [
  // --- phones → mobile -----------------------------------------------------
  ['Chrome on Samsung Galaxy S23',
    'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
    'mobile'],
  ['Chrome on Google Pixel 8',
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Mobile Safari/537.36',
    'mobile'],
  ['Samsung Internet on Galaxy',
    'Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0.0.0 Mobile Safari/537.36',
    'mobile'],
  ['Safari on iPhone',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    'mobile'],
  ['Safari on iPad',
    'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    'mobile'],
  ['Firefox on Android phone',
    'Mozilla/5.0 (Android 14; Mobile; rv:122.0) Gecko/122.0 Firefox/122.0',
    'mobile'],

  // --- TVs → tv ------------------------------------------------------------
  ['Chrome on Android TV (no "Mobile" token)',
    'Mozilla/5.0 (Linux; Android 14; ADT-3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'tv'],
  ['Google TV',
    'Mozilla/5.0 (Linux; Android 7.1.2; GoogleTV/1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/105.0.0.0 Safari/537.36',
    'tv'],
  ['Samsung Tizen TV',
    'Mozilla/5.0 (SMART-TV; Linux; Tizen 6.5) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 TV Safari/537.36',
    'tv'],
  ['LG webOS TV',
    'Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
    'tv'],
  ['Roku',
    'Mozilla/5.0 (Roku/DVP-9.10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/104.0.0.0 Safari/537.36',
    'tv'],
  ['Hisense VIDAA',
    'Mozilla/5.0 (Philips; Linux; VIDAA 6.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Safari/537.36',
    'tv'],
  ['LG NetCast',
    'Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 NetCast/4.0 Safari/537.36',
    'tv'],

  // --- desktops → other ----------------------------------------------------
  ['Chrome on Windows',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
    'other'],
  ['Safari on macOS',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    'other'],
  ['Firefox on Linux',
    'Mozilla/5.0 (X11; Linux x86_64; rv:122.0) Gecko/20100101 Firefox/122.0',
    'other']
];

var failed = 0;
cases.forEach(function (c) {
  var label = c[0], ua = c[1], want = c[2];
  var got = classifyMode(ua);
  var ok = got === want;
  if (!ok) failed++;
  console.log((ok ? 'PASS' : 'FAIL') + '  ' + label + '  → ' + got + (ok ? '' : ' (expected ' + want + ')'));
});

console.log('\n' + (cases.length - failed) + '/' + cases.length + ' passed');
process.exit(failed ? 1 : 0);
