<?php
/**
 * Phase 104e (a beta tester GH #6) — mobile session isolation.
 *
 * Two session profiles now coexist:
 *
 *   * DESKTOP — the historical default. PHPSESSID cookie, default
 *     save_path, browser-session cookie lifetime, default
 *     gc_maxlifetime (1440 s). Idle dispatcher workstations expire
 *     quickly, which is what CJIS/security review has asked for.
 *
 *   * MOBILE — TCADMOBILE cookie, dedicated save_path under
 *     sys_get_temp_dir()/tcad-mobile, 30-day cookie lifetime, 30-day
 *     gc_maxlifetime. Field responders re-open the PWA over the
 *     course of a shift without a fresh login.
 *
 * The old fix (commit 9dbc802) only touched mobile.php's ini_set()
 * for gc_maxlifetime. PHP session GC is process-wide though — a
 * desktop-page request running at the default lifetime GCs files
 * older than 24 minutes across the ENTIRE save_path directory,
 * including the mobile files. Cookies survived; files didn't; hence
 * the "PWA re-asks for login" symptom.
 *
 * The fix: isolate mobile session files into their own directory
 * (so desktop GC can't reach them) AND rename the session cookie so
 * a mobile client's session is never confused with a desktop session
 * living side-by-side in the same browser cookie jar. api/auth.php
 * detects which profile to run by checking which cookie the client
 * sent — mobile cookie present → mobile profile, otherwise desktop.
 *
 * Login.php also honours the profile — when a mobile PWA reaches
 * login.php (either directly or via a mobile.php redirect), it
 * bootstraps the mobile profile so the session created by the
 * login POST lives in the mobile store from the start.
 */

if (!defined('SESS_MOBILE_COOKIE_NAME'))    define('SESS_MOBILE_COOKIE_NAME',   'TCADMOBILE');
if (!defined('SESS_MOBILE_LIFETIME_SECS'))  define('SESS_MOBILE_LIFETIME_SECS', 60 * 60 * 24 * 30);

/**
 * Return the save_path for mobile sessions, creating it if missing.
 * NULL if the base tmp path isn't writable — caller falls back to
 * default save_path so the app still functions.
 */
function _sess_mobile_save_path(): ?string {
    $base = ini_get('session.save_path');
    if (!$base) $base = sys_get_temp_dir();
    $base = rtrim($base, DIRECTORY_SEPARATOR);
    $path = $base . '/tcad-mobile';
    if (!is_dir($path)) {
        @mkdir($path, 0700, true);
    }
    if (is_dir($path) && is_writable($path)) return $path;
    return null;
}

/**
 * Configure the session for a mobile client (PWA / field responder).
 * Call BEFORE session_start(). Idempotent; safe to call once per
 * request.
 */
function sess_bootstrap_mobile(): void {
    $path = _sess_mobile_save_path();
    if ($path !== null) {
        ini_set('session.save_path', $path);
    }
    ini_set('session.gc_maxlifetime', (string) SESS_MOBILE_LIFETIME_SECS);
    session_name(SESS_MOBILE_COOKIE_NAME);
    session_set_cookie_params([
        'lifetime' => SESS_MOBILE_LIFETIME_SECS,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS'])
                      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Re-stamp the mobile cookie's expiry so its 30-day window is
 * rolling (measured from last use, not from first login). Call
 * AFTER session_start() when the client has a live session cookie.
 *
 * Eric emergency (2026-07-03): the previous version used
 * $_COOKIE[$cookieName] as the value — i.e. whatever the client
 * originally sent. With session.use_strict_mode=1 (set in
 * config.php:65) PHP REJECTS a client-provided session ID whose
 * file doesn't exist on disk (defense against session fixation)
 * and generates a fresh ID. session_start() then emits its own
 * Set-Cookie for the new ID — but this function's setcookie call
 * fired AFTER session_start and used $_COOKIE (the STALE client
 * value), overwriting the fresh ID with the dead one. Browser
 * kept the stale ID, sent it on the next POST, PHP regenerated
 * AGAIN to yet another new ID with an empty $_SESSION →
 * csrf_verify failed → "Security token expired" locked users out
 * of PWA logins entirely. Use session_id() (the ACTUAL current
 * ID after PHP's regeneration) instead so the cookie always
 * reflects the live session.
 */
function sess_touch_mobile_cookie(): void {
    if (session_name() !== SESS_MOBILE_COOKIE_NAME) return;
    $currentSid = session_id();
    if ($currentSid === '') return;
    setcookie(SESS_MOBILE_COOKIE_NAME, $currentSid, [
        'expires'  => time() + SESS_MOBILE_LIFETIME_SECS,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS'])
                      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * True if the current request came from a mobile PWA (has the
 * mobile cookie). Used by api/auth.php + login.php to route the
 * request to the mobile session profile.
 */
function sess_client_is_mobile(): bool {
    return isset($_COOKIE[SESS_MOBILE_COOKIE_NAME]) && $_COOKIE[SESS_MOBILE_COOKIE_NAME] !== '';
}

/**
 * Convenience: apply whichever profile matches the client.
 * Call BEFORE session_start(). If neither cookie is present but the
 * caller passed $mobileHint=true (e.g. mobile.php on first load),
 * bootstrap mobile. Otherwise default to desktop.
 */
function sess_bootstrap_auto(bool $mobileHint = false): void {
    if (sess_client_is_mobile() || $mobileHint) {
        sess_bootstrap_mobile();
    }
    // Desktop profile is PHP defaults — nothing to set.
}

/**
 * Read a desktop-profile session file directly from disk and return
 * its decoded $_SESSION contents. Empty array if the file doesn't
 * exist or the desktop session ID isn't valid.
 *
 * Used by mobile.php's "user came from desktop with valid PHPSESSID
 * cookie" rescue branch. Directly parses the on-disk file instead of
 * calling session_start() under a different session_name — every
 * previous attempt at the session-swap dance ran into PHP module
 * state that didn't cleanly reverse (session.save_path stuck at the
 * mobile path even after ini_restore, PHP-FPM opcache confusing the
 * second session_start, etc.).
 *
 * Safe because we don't touch $_SESSION — we snapshot it, run
 * session_decode into an isolated buffer, then restore. Idempotent
 * and side-effect-free.
 */
function sess_read_desktop_session(string $desktopSessionId): array {
    if ($desktopSessionId === '') return [];
    // Defense-in-depth (2026-07-04 security review): don't trust the
    // caller to have sanitized the id. PHP session IDs are
    // [A-Za-z0-9,-] only (comma/hyphen appear in hashed-subdir ids).
    // Reject anything else so a crafted PHPSESSID can never traverse
    // out of the session dir, even if a future caller forgets to strip
    // it. The current sole caller (mobile.php) already sanitizes.
    if (!preg_match('/^[A-Za-z0-9,\-]+$/', $desktopSessionId)) return [];
    // PHP session files are named "sess_<id>" in the configured
    // save_path. When no explicit save_path is set the fallback is
    // sys_get_temp_dir(); Debian/Ubuntu ships with save_path pointing
    // at /var/lib/php/sessions or similar via the apt package, so we
    // want the ORIGINAL php.ini value, not whatever sess_bootstrap_mobile
    // set at runtime.
    // ini_get_all('session', true) returns the nested per-directive
    // shape { local_value: ..., global_value: ..., access: ... }.
    // We want global_value — the original php.ini value — because
    // sess_bootstrap_mobile() has already changed the runtime
    // (local) session.save_path to the mobile subdirectory by the
    // time this function fires. ini_get() alone would return the
    // mobile path here.
    $all = ini_get_all('session', true);
    $path = $all['session.save_path']['global_value'] ?? '';
    if ($path === '') $path = sys_get_temp_dir();
    // save_path can be "N;/tmp/x" style for hashed subdirs; strip the
    // depth prefix so we can build the file path directly.
    if (preg_match('/^(\d+;)+(.*)$/', $path, $m)) $path = $m[2];
    $file = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $desktopSessionId;
    if (!is_file($file) || !is_readable($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return [];
    // session_decode() writes into $_SESSION. Snapshot the caller's
    // $_SESSION so we don't clobber it.
    $before = $_SESSION;
    $_SESSION = [];
    @session_decode($raw);
    $decoded = $_SESSION;
    $_SESSION = $before;
    return $decoded;
}

/**
 * Delete a desktop-profile session file from disk. Used after
 * sess_read_desktop_session() successfully migrated the auth into
 * the mobile profile — we don't want the stale desktop file (or the
 * client's PHPSESSID cookie) to keep pinning future requests back to
 * the desktop profile.
 */
function sess_delete_desktop_session(string $desktopSessionId): void {
    if ($desktopSessionId === '') return;
    // ini_get_all('session', true) returns the nested per-directive
    // shape { local_value: ..., global_value: ..., access: ... }.
    // We want global_value — the original php.ini value — because
    // sess_bootstrap_mobile() has already changed the runtime
    // (local) session.save_path to the mobile subdirectory by the
    // time this function fires. ini_get() alone would return the
    // mobile path here.
    $all = ini_get_all('session', true);
    $path = $all['session.save_path']['global_value'] ?? '';
    if ($path === '') $path = sys_get_temp_dir();
    if (preg_match('/^(\d+;)+(.*)$/', $path, $m)) $path = $m[2];
    $file = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $desktopSessionId;
    if (is_file($file)) @unlink($file);
}

/**
 * Migrate the currently-open session from the DESKTOP profile
 * (PHPSESSID) into the MOBILE profile (TCADMOBILE) so a user who
 * logged in on the login page (which runs under the desktop profile
 * by default) can land on mobile.php without being kicked back to
 * login.
 *
 * a beta tester GH #13-followup (2026-07-03): the login page called
 * sess_bootstrap_auto() with no mobile hint, so the login POST
 * wrote $_SESSION['user_id'] under PHPSESSID. login.php then
 * redirected to mobile.php because the user's role has
 * mobile_first=1. mobile.php called sess_bootstrap_mobile() which
 * opened an empty TCADMOBILE session, saw no user_id, and
 * redirected back to login. Endless loop / immediate logout.
 *
 * This helper solves it end-to-end:
 *   1. Snapshot the current desktop $_SESSION.
 *   2. Close and destroy the desktop session (both server-side
 *      data AND the PHPSESSID cookie).
 *   3. Bootstrap the mobile profile.
 *   4. Start a new mobile session.
 *   5. Restore the snapshot into the mobile $_SESSION.
 *   6. Close it so the redirect writes the new TCADMOBILE cookie.
 *
 * MUST be called BEFORE the redirect header is emitted. Caller is
 * responsible for the header + exit after this returns.
 *
 * No-op if the session is already running under the mobile profile
 * (idempotent — a login POST that happened to be routed through
 * mobile bootstrap in the first place won't get double-migrated).
 */
function sess_migrate_to_mobile(): void {
    if (session_name() === SESS_MOBILE_COOKIE_NAME) return;

    // Snapshot current session data (must be captured while the
    // session is still open — session_destroy() wipes $_SESSION).
    $snapshot = $_SESSION ?? [];

    // Close + destroy desktop session and clear the desktop cookie
    // so it doesn't linger and re-pin future requests to the
    // desktop profile.
    if (session_status() === PHP_SESSION_ACTIVE) {
        $desktopCookie = session_name();
        $params = session_get_cookie_params();
        session_destroy();
        if (!headers_sent()) {
            setcookie($desktopCookie, '', [
                'expires'  => time() - 86400,
                'path'     => $params['path'] ?? '/',
                'domain'   => $params['domain'] ?? '',
                'secure'   => $params['secure'] ?? false,
                'httponly' => $params['httponly'] ?? true,
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
    }

    // Switch to mobile profile and open a fresh session under
    // TCADMOBILE. The setcookie() call inside session_start()
    // creates the mobile cookie in the outgoing response so the
    // browser sends it on the next request (mobile.php).
    sess_bootstrap_mobile();
    session_start();

    // a beta tester GH #13 (2026-07-04) — the snapshot carries
    // $_SESSION['_sm_tracked']=1 from sm_create_session() during the
    // login POST, but that registration was for the OLD (desktop)
    // session id. This migrate just minted a NEW id that has no
    // active_sessions row. If we restore the marker without
    // re-registering, the very first API call's sm_is_session_valid()
    // sees "tracked + no row = force-destroyed", wipes the session,
    // and the user gets "Session expired" then "Not authenticated"
    // on everything — the exact video a beta tester posted. Drop the marker
    // from the snapshot and re-register the new id; if registration
    // fails (DB hiccup), the untracked session gets the benefit of
    // the doubt instead of being destroyed.
    unset($snapshot['_sm_tracked']);
    $_SESSION = $snapshot;
    if (function_exists('sm_create_session') && !empty($snapshot['user_id'])) {
        sm_create_session((int) $snapshot['user_id']);
    }
    session_write_close();
}
