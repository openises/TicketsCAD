<?php
/**
 * NewUI v4.0 — Configuration template
 *
 * Copy this file to `config.php` and fill in your local values.
 * `config.php` is gitignored to keep credentials out of the repo.
 *
 * Fresh install:
 *   1. `cp config.example.php config.php`
 *   2. Edit the database credentials block below
 *   3. Run `php tools/install_fresh.php` to bring the schema up to date
 *   4. Run `php tools/test_all.php` to verify everything is wired up
 */

// ── Database ────────────────────────────────────────────────────
$db_host   = 'localhost';
$db_user   = 'newui';
$db_pass   = 'CHANGE-ME';      // Bcrypt-strong DB password — never reuse
$db_name   = 'newui';
$db_prefix = '';               // Table prefix (empty = legacy default)

// ── Public URL ──────────────────────────────────────────────────
// MUST match the hostname your TLS cert covers. Used by:
//   - HTTPS-only cookies + same-site session enforcement (inc/security.php)
//   - URLs generated for email notifications, OwnTracks links, etc.
// Production must be HTTPS. Dev/test installs without TLS can use http://.
$base_url  = 'https://cad.example.org';

// ── Application metadata ────────────────────────────────────────
// The version is NOT set here. It lives in the git-tracked `VERSION` file next
// to this one, so `git pull` actually changes what Help → About reports.
// (Before 2026-07 it was a define() in this file; because config.php is
// gitignored, every existing install stayed pinned to its install-time version
// forever.) inc/version.php defines NEWUI_VERSION from that file for anything
// that still reads the constant; app code calls newui_version().
require_once __DIR__ . '/inc/version.php';
define('NEWUI_ROOT',    __DIR__);
define('NEWUI_DEBUG',   false);   // true = dev-only verbose errors

// ── Web server account (optional) ───────────────────────────────
// `php tools/check-health.php` reports whether the WEB SERVER can write
// uploads/ and cache/ — not whether the account you ran it as can. It works
// that account out on its own (a running apache2/nginx/php-fpm worker, the
// ownership of this install's runtime directories, or the server's config),
// and reports UNKNOWN rather than guessing when it cannot.
//
// Set this to skip the inference and be certain. Substitute your own account:
// Debian/Ubuntu www-data · RHEL apache · Arch http · shared hosting your login.
// define('NEWUI_WEB_USER', 'www-data');

// Phase 43e: set HTTP security headers (CSP, X-Frame-Options, HSTS on HTTPS,
// hardened session cookie) on every request. Done here in config.php — which
// every entry point requires at the very top — so the headers land BEFORE any
// HTML output. (Earlier attempt in navbar.php fired too late: most pages echo
// <head> before including navbar, so headers_sent() was already true.)
require_once __DIR__ . '/inc/security-headers.php';
if (!headers_sent()) {
    set_security_headers();
}

/**
 * Asset version for cache busting. Uses the file's mtime in dev so JS/CSS
 * changes propagate; falls back to NEWUI_VERSION if the file is absent.
 */
function asset_v($relPath) {
    $full = NEWUI_ROOT . '/' . $relPath;
    if (file_exists($full)) {
        return NEWUI_VERSION . '.' . filemtime($full);
    }
    return NEWUI_VERSION;
}

// ── Timezone ────────────────────────────────────────────────────
date_default_timezone_set('America/Chicago');   // adjust per install

// ── Error handling ──────────────────────────────────────────────
// Production posture: log everything, display nothing.
error_reporting(E_ALL);
ini_set('display_errors', NEWUI_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// ── Session security ────────────────────────────────────────────
// PHP forbids ini_set() on session.* once the session is active, and
// emits a warning that leaks into response bodies — that corrupts SSE
// streams (Phase 84s debugging found this caused the legacy real-time
// indicator's reconnect loop when config was required AFTER
// session_start in api/stream.php). Only apply when no session is
// active yet.
// is_https() lives in inc/https.php, which is deliberately
// dependency-free so it can be required here — ahead of the DB layer.
require_once NEWUI_ROOT . '/inc/https.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (is_https()) {
        ini_set('session.cookie_secure', '1');
    }
}

// ── Bootstrap ───────────────────────────────────────────────────
require_once NEWUI_ROOT . '/inc/db.php';
require_once NEWUI_ROOT . '/inc/functions.php';
