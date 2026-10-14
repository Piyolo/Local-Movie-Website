/**
 * MovieWebsite — viewer preferences (localStorage).
 * Used for: preferred subtitle language and other small UI choices.
 */
(function () {
  'use strict';
  var MW = (window.MW = window.MW || {});

  var PREFIX = 'mw_pref_';
  var memory = {};

  function safeGet(key) {
    try {
      return window.localStorage.getItem(PREFIX + key);
    } catch (e) {
      return memory[key] !== undefined ? memory[key] : null;
    }
  }
  function safeSet(key, value) {
    memory[key] = value;
    try {
      window.localStorage.setItem(PREFIX + key, value);
    } catch (e) { /* private mode / TV browser: memory fallback */ }
  }

  MW.prefs = {
    get: function (key, fallback) {
      var v = safeGet(key);
      return v === null || v === undefined || v === '' ? fallback : v;
    },
    set: safeSet,
    /** Preferred subtitle language code ('' or 'off' disables auto-select). */
    getSubtitleLang: function () { return safeGet('subtitle_lang') || ''; },
    setSubtitleLang: function (code) { safeSet('subtitle_lang', code || ''); },
  };
})();
