<?php
/**
 * Shared page footer: scripts + closing tags.
 * Server-included. Expects optional: $PAGE_SCRIPTS (array of JS paths).
 */
declare(strict_types=1);
if (!defined('MOVIEWEBSITE')) {
    http_response_code(404);
    exit('Not found');
}
$PAGE_SCRIPTS = $PAGE_SCRIPTS ?? [];
?></main>

<div id="toast" class="toast" hidden role="status" aria-live="polite"></div>

<!-- Library Settings dialog (admin-gated server-side regardless of visibility) -->
<div id="libsettings-overlay" class="overlay" hidden>
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="libsettings-title">
    <div class="dialog-head">
      <h2 id="libsettings-title">Library Settings</h2>
      <button type="button" class="icon-btn" id="libsettings-close" aria-label="Close">✕</button>
    </div>

    <!-- Sign-in / first-run setup -->
    <section id="ls-login" class="dialog-section" hidden>
      <p class="muted" id="ls-login-hint">Changing the movie folder requires the administrator password.</p>
      <form id="ls-login-form" autocomplete="off">
        <label class="field">
          <span id="ls-password-label">Administrator password</span>
          <input type="password" id="ls-password" minlength="8" required>
        </label>
        <p class="field-hint" id="ls-password-hint" hidden>First run: this password will protect Library Settings. Use at least 8 characters.</p>
        <div class="dialog-actions">
          <button type="submit" class="btn btn-primary" id="ls-login-submit">Sign in</button>
        </div>
        <p class="form-msg" id="ls-login-msg" hidden></p>
      </form>
    </section>

    <!-- Status + actions (admin) -->
    <section id="ls-panel" class="dialog-section" hidden>
      <dl class="ls-status">
        <dt>Current library</dt>
        <dd><code id="ls-current-path">…</code> <span id="ls-source-badge" class="badge"></span></dd>
        <dt>Status</dt>
        <dd id="ls-availability">…</dd>
        <dt>Movies discovered</dt>
        <dd id="ls-movie-count">…</dd>
        <dt>Last successful scan</dt>
        <dd id="ls-last-scan">never</dd>
      </dl>

      <div class="dialog-actions ls-actions">
        <button type="button" class="btn btn-primary" id="ls-change-folder">Change Folder</button>
        <button type="button" class="btn" id="ls-use-default">Use Default Folder</button>
        <button type="button" class="btn" id="ls-rescan">Scan Library</button>
        <button type="button" class="btn btn-ghost" id="ls-signout" hidden>Sign out</button>
      </div>

      <div id="ls-busy" class="busy" hidden><span class="spinner" aria-hidden="true"></span><span id="ls-busy-text">Working…</span></div>
      <p class="form-msg" id="ls-msg" hidden></p>
    </section>

    <!-- Folder navigator -->
    <section id="ls-browser" class="dialog-section" hidden>
      <div class="browser-head">
        <button type="button" class="btn btn-sm" id="ls-browse-up" hidden>⬆ Up</button>
        <code id="ls-browse-path" class="browser-path"></code>
      </div>
      <ul id="ls-drives" class="browser-list" hidden></ul>
      <ul id="ls-dirs" class="browser-list"></ul>
      <p class="field-hint" id="ls-pick-hint" hidden>Selected in the Windows folder dialog. Click “Use this folder” to save it.</p>
      <div class="dialog-actions">
        <button type="button" class="btn btn-primary" id="ls-select-folder">Use this folder</button>
        <button type="button" class="btn" id="ls-browse-cancel">Cancel</button>
      </div>
    </section>
  </div>
</div>

<script src="<?= h(url('js/app.js')) ?>"></script>
<script src="<?= h(url('js/preferences.js')) ?>"></script>
<script src="<?= h(url('js/settings.js')) ?>"></script>
<?php foreach ($PAGE_SCRIPTS as $script) {
    echo '<script src="' . h(url($script)) . '"></script>' . "\n";
} ?>
</body>
</html>
