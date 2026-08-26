<?php
/**
 * NewUI v4.0 - Utility Functions
 *
 * Minimal utilities adapted from tickets/incs/functions.inc.php.
 * Only what NewUI actually needs — no legacy baggage.
 */

// newui_version(): the deployed code version, read from the git-tracked
// VERSION file. Required HERE (not from config.php) on purpose — every
// config.php ever shipped requires inc/functions.php at its bottom, so an
// install created years ago gets the function without editing config.php.
// See inc/version.php for why the tracked file outranks config.php.
require_once __DIR__ . '/version.php';
// is_https() / is_https_verified(). Required HERE, like version.php,
// because every config.php ever shipped requires inc/functions.php at
// its bottom — and config.php is gitignored, so that is the only route
// by which a helper reaches installs we cannot edit.
require_once __DIR__ . '/https.php';

/**
 * Get a setting value from the `settings` table.
 * Results are cached for the duration of the request.
 *
 * @param string $name Setting name
 * @return string|false The value, or FALSE if not found
 */
function get_variable(string $name)
{
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        $rows = db_fetch_all("SELECT `name`, `value` FROM " . db_table('settings'));
        foreach ($rows as $row) {
            $cache[$row['name']] = $row['value'];
        }
    }

    return $cache[$name] ?? false;
}

/**
 * Get a CSS color value from the day or night color table.
 *
 * @param string $element   CSS element name
 * @param string $day_night 'Day' or 'Night'
 * @return string|false The hex color, or FALSE if not found
 */
function get_css(string $element, string $day_night)
{
    static $day_cache = null;
    static $night_cache = null;

    if ($day_night === 'Day') {
        if ($day_cache === null) {
            $day_cache = [];
            $rows = db_fetch_all("SELECT `name`, `value` FROM " . db_table('css_day'));
            foreach ($rows as $row) {
                $day_cache[$row['name']] = $row['value'];
            }
        }
        return $day_cache[$element] ?? false;
    }

    if ($night_cache === null) {
        $night_cache = [];
        $rows = db_fetch_all("SELECT `name`, `value` FROM " . db_table('css_night'));
        foreach ($rows as $row) {
            $night_cache[$row['name']] = $row['value'];
        }
    }
    return $night_cache[$element] ?? false;
}

/**
 * Build a complete CSS custom properties map from the day/night tables.
 * Used by the theme API to send all colors to the frontend at once.
 *
 * @param string $day_night 'Day' or 'Night'
 * @return array Associative array of CSS element => hex color
 */
function get_all_css(string $day_night): array
{
    $table = ($day_night === 'Night') ? db_table('css_night') : db_table('css_day');
    $rows = db_fetch_all("SELECT `name`, `value` FROM {$table}");
    $colors = [];
    foreach ($rows as $row) {
        $colors[$row['name']] = $row['value'];
    }
    return $colors;
}

/**
 * HTML-escape a string for safe output.
 * Accepts null for convenience — returns empty string.
 */
function e(?string $value): string
{
    if ($value === null) return '';
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Send a JSON response and exit.
 *
 * @param mixed $data    Data to encode
 * @param int   $status  HTTP status code
 */
function json_response($data, int $status = 200): void
{
    // Phase 121 (2026-07-25): if a storage-damaged table was hit while building
    // this response, do NOT return a cheerful empty/partial list — that reads as
    // "your data is gone". Replace it with a plain explanation naming the table
    // and pointing at the repair steps. Only affects 2xx replies, and only when
    // real damage was recorded (a merely-missing table is not damage).
    if (function_exists('db_damage_intercept')) {
        $damage = db_damage_intercept($status);
        if ($damage !== null) {
            $data   = $damage;
            $status = 503;   // service degraded, not a client error
        }
    }
    // Phase 125 (2026-07-26): if this request failed because the database is
    // missing a column the code writes to, say so. Otherwise the caller sees a
    // bare "Save failed: HTTP 400" and has no way to know the problem is schema
    // drift, not their input — which is exactly what happened on `teams`.
    // Only rewrites replies that are ALREADY errors; a successful request is
    // never touched.
    if ($status >= 400 && function_exists('schema_drift_seen') && schema_drift_seen()) {
        $data   = schema_drift_payload();
        $status = 503;   // the install is degraded, the request was not invalid
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    // Tell inc/api_guard.php the response body has been written. headers_sent()
    // alone is not enough: with output_buffering on (common in php.ini) this
    // echo sits in a buffer and headers_sent() is still false, so a shutdown
    // fatal would append a second JSON document to a complete one.
    $GLOBALS['__api_guard_body_sent'] = true;
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a JSON error response and exit.
 */
function json_error(string $message, int $status = 400): void
{
    json_response(['error' => $message], $status);
}

/**
 * M4 in code review 2026-07-03 — "Error information leakage": the
 * common pattern `json_error('X failed: ' . $e->getMessage(), 500)`
 * leaks driver-level detail to the client (SQL statements, table +
 * column names, PDO error text). This helper masks the response with
 * a caller-supplied generic message while sending the real exception
 * detail to the server error log with file + line + optional context
 * tag so a maintainer can still trace what went wrong.
 *
 * Use for any catch(Exception|Throwable) block that would otherwise
 * expose an SQL error string. Keeps the client actionable
 * ("Save failed. Check server logs.") without shipping the schema
 * out the door.
 *
 * Example:
 *   try { db_query("UPDATE …"); }
 *   catch (Throwable $e) { json_error_safe('Save failed', $e, 'regions.save'); }
 */
function json_error_safe(string $clientMessage, Throwable $e, string $tag = '', int $status = 500): void
{
    error_log(sprintf(
        '[%s] %s at %s:%d',
        $tag !== '' ? $tag : 'json_error_safe',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    json_response(['error' => $clientMessage], $status);
}

/**
 * Convert a MySQL datetime string to ISO 8601 with timezone offset.
 * e.g. "2026-03-16 22:52:00" → "2026-03-16T22:52:00-04:00"
 * Lets the browser's Date parser handle timezone conversion correctly.
 */
function toIso($dateStr) {
    if (!$dateStr || $dateStr === '0000-00-00 00:00:00') return null;
    try {
        $dt = new DateTime($dateStr);
        return $dt->format('c'); // ISO 8601 with offset
    } catch (Exception $e) {
        return $dateStr;
    }
}

/**
 * Verify a password against a stored hash.
 * Supports bcrypt and legacy MD5 hashes.
 *
 * @param string $password   Plaintext password
 * @param string $storedHash Hash from database
 * @return array ['valid' => bool, 'needs_rehash' => bool]
 */
function verify_password(string $password, string $storedHash): array
{
    if (password_verify($password, $storedHash)) {
        return [
            'valid' => true,
            'needs_rehash' => password_needs_rehash($storedHash, PASSWORD_BCRYPT, ['cost' => 12])
        ];
    }

    // Legacy MD5 fallback
    if ($storedHash === md5(strtolower($password)) || $storedHash === md5($password)) {
        return ['valid' => true, 'needs_rehash' => true];
    }

    return ['valid' => false, 'needs_rehash' => false];
}

/**
 * Hash a password using bcrypt.
 */
function hash_new_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Generate a CSRF token and store in session.
 */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a CSRF token.
 */
function csrf_verify(string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Phase 12 (2026-06-11): get_level_text() is a thin compatibility shim
 * that ignores its argument and returns the current user's RBAC role
 * name via current_role_name() (defined in inc/rbac.php).
 *
 * The legacy integer-to-display mapping (Super/Operator/Field Unit/etc.)
 * is sunsetted — every NewUI page now flows through RBAC, and the role
 * name is the canonical "what is this user?" answer. Existing callers
 * pass an int argument; we accept and ignore it so the migration can
 * happen file-by-file without breaking the build.
 *
 * Mark this function deprecated; a future cleanup phase deletes it
 * entirely after every page is migrated to call current_role_name()
 * directly.
 *
 * @deprecated Use current_role_name() instead.
 */
function get_level_text(int $level = 0): string
{
    // Lazy-load rbac.php so this shim works even if a caller forgot to
    // require_once inc/rbac.php first. Defensive against the Phase 12
    // refactor's order-of-load surprise that broke roster.php on 2026-06-11.
    if (!function_exists('current_role_name')) {
        @require_once __DIR__ . '/rbac.php';
    }
    if (function_exists('current_role_name')) {
        return current_role_name();
    }
    // Final fallback (only hit when rbac.php isn't loaded yet, e.g.,
    // during the very early bootstrap of the install wizard).
    return '—';
}

/**
 * Format a US phone number according to the configured format.
 * Only formats 10-digit US numbers; leaves others unchanged.
 *
 * @param string $phone Raw phone number
 * @param string|null $format Format style: 'us', 'dash', 'dots', 'off' (null = read from config)
 * @return string Formatted phone number
 */
function format_phone($phone, $format = null) {
    if (empty($phone)) return '';

    // Strip everything except digits
    $digits = preg_replace('/[^0-9]/', '', $phone);

    // Handle 11-digit numbers starting with 1 (US country code)
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }

    // Only format 10-digit US numbers
    if (strlen($digits) !== 10) {
        return $phone; // Return as-is
    }

    // Get format from config if not specified
    if ($format === null) {
        $format = get_setting('phone_format', 'off');
    }

    if ($format === 'off' || empty($format)) {
        return $phone;
    }

    $area = substr($digits, 0, 3);
    $pre  = substr($digits, 3, 3);
    $line = substr($digits, 6, 4);

    switch ($format) {
        case 'us':   return '(' . $area . ') ' . $pre . '-' . $line;
        case 'dash': return $area . '-' . $pre . '-' . $line;
        case 'dots': return $area . '.' . $pre . '.' . $line;
        default:     return $phone;
    }
}

/**
 * Get a system setting value.
 *
 * @param string $key Setting key
 * @param mixed  $default Default value
 * @return mixed
 */
function get_setting($key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = db_fetch_all("SELECT `key`, `value` FROM `" . ($GLOBALS['db_prefix'] ?? '') . "config`");
            foreach ($rows as $r) {
                $cache[$r['key']] = $r['value'];
            }
        } catch (Exception $e) {
            // Config table may not exist
        }
    }
    return isset($cache[$key]) ? $cache[$key] : $default;
}

/**
 * GH#109 (rjonesbsink, 2026-08-25) -- the admin-configurable
 * session_timeout_minutes setting (Settings -> Login Settings, default
 * 480 min / 8 hours) was never wired to PHP's OWN session lifetime. It
 * only ever drove inc/session-manager.php's application-level
 * active_sessions.expires_at check -- but that check can only run if
 * $_SESSION still exists to be read from in the first place. On a stock
 * PHP install with no custom tuning, session.gc_maxlifetime defaults to
 * 1440 seconds (24 minutes), so PHP's OWN garbage collector deleted the
 * session file out from under every desktop user well before the
 * configured 480-minute mark -- for every fresh install, out of the box,
 * regardless of what an admin configured. inc/session-bootstrap.php
 * already does exactly this reconciliation for the separate MOBILE
 * session profile (see SESS_MOBILE_LIFETIME_SECS) -- but its own comment
 * said "Desktop profile is PHP defaults -- nothing to set."
 *
 * CORRECTION (rjonesbsink, 2026-08-25, caught before this ever reached a
 * commit): the first version of this fix raised gc_maxlifetime to the
 * GLOBAL session_timeout_minutes value only, reasoning that "a per-role
 * override can only ever be SHORTER than the global setting". That is
 * wrong -- inc/session-manager.php's sm_get_timeout() takes the shortest
 * value AMONG A USER'S ROLES, and only falls back to the global setting
 * when NO role sets one; it never clamps a role's own value against the
 * global. A role deliberately configured LONGER than the global default
 * (exactly what the Per-Role Session Timeouts panel exists to allow) hit
 * this exact bug one level in: PHP would still collect the session file
 * at the global value, so the longer per-role setting could never take
 * effect -- active_sessions.expires_at would say the session is still
 * good, but the file it depends on would already be gone.
 *
 * Fixed by using the LARGER of the global setting and the longest
 * session_timeout_minutes configured on ANY role -- a single extra query,
 * no user needed (still runs before the specific user is known). PHP's
 * file-level GC becomes a ceiling generous enough for every configured
 * timeout in the system, not just the global default; a SHORTER per-role
 * override is still what active_sessions.expires_at enforces for that
 * user, which is the same division of labour as before, just no longer
 * silently defeated by a role that asks for something longer.
 *
 * Guarded exactly like config.php's own existing session ini_set block
 * (session_status() !== PHP_SESSION_ACTIVE), for the same documented
 * reason: ini_set() on session.* after session_start() emits a warning
 * that corrupted SSE streams in api/stream.php (Phase 84s).
 *
 * Placed here rather than in config.php on purpose, matching this same
 * file's own newui_version()/is_https() precedent a few lines up:
 * config.php is gitignored per-install, so a fix living only there would
 * never reach an already-deployed site via git pull. Every config.php
 * ever shipped requires this file, so this is the route that does reach
 * existing installs.
 *
 * A mobile-profile request (login.php / mobile.php / api/auth.php) calls
 * sess_bootstrap_mobile() AFTER config.php's require cascade (i.e. after
 * this file, and this block, have already run) -- so mobile's own 30-day
 * gc_maxlifetime always overrides this desktop default correctly, in the
 * right order, for real mobile clients.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $_sess_global_min = (int) (get_setting('session_timeout_minutes', 480) ?: 480);
    $_sess_role_max_min = 0;
    try {
        $_sess_role_max_min = (int) (db_fetch_value(
            "SELECT MAX(`session_timeout_minutes`) FROM " . db_table('roles')
        ) ?: 0);
    } catch (Exception $e) {
        // roles table / column not present yet (pre-Phase-37 install) --
        // fall through to the global-only ceiling.
    }
    $_sess_timeout_min = max($_sess_global_min, $_sess_role_max_min);
    if ($_sess_timeout_min > 0) {
        ini_set('session.gc_maxlifetime', (string) ($_sess_timeout_min * 60));
    }
    unset($_sess_global_min, $_sess_role_max_min, $_sess_timeout_min);
}

/**
 * GH#103 — generic, table-parameterized version of the GENERATED-column
 * discovery logic that already existed twice, both hardcoded to the
 * `member` table: api/members.php's getGeneratedColumnMap() and
 * inc/member-write.php's _member_write_remap_generated() (the latter is a
 * standalone copy because the external-API include chain can't reach
 * api/members.php). Neither of those is touched by this change — they
 * keep working exactly as before — this is a THIRD, generalized copy for
 * inc/import-export.php, which needs the same answer for whichever table
 * a given import/export target points at (member today; potentially
 * others later).
 *
 * Returns a map of generated_column_name => source_column_name for a
 * table, e.g. ['first_name' => 'field2', 'last_name' => 'field1', ...].
 * Empty array (not an error) when the table has no generated columns, or
 * when the schema can't be read at all — every caller's contract is "an
 * empty map means treat every column as a normal, directly-writable
 * column," which is the safe default.
 *
 * Cached per table for the lifetime of the request.
 */
function db_generated_column_map(string $table): array
{
    static $cache = [];
    $key = trim($table, '` ');
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $map = [];
    try {
        $cols = db_fetch_all(
            "SELECT COLUMN_NAME, GENERATION_EXPRESSION
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND GENERATION_EXPRESSION IS NOT NULL
               AND GENERATION_EXPRESSION != ''",
            [$key]
        );
        foreach ($cols as $col) {
            // GENERATION_EXPRESSION is like `field2` — strip backticks.
            $source = trim($col['GENERATION_EXPRESSION'], '` ');
            if ($source !== '') {
                $map[$col['COLUMN_NAME']] = $source;
            }
        }
    } catch (Exception $e) {
        // Can't read schema — return empty; callers fall back to treating
        // every column as directly writable, same as api/members.php's
        // getGeneratedColumnMap() already does.
    }
    $cache[$key] = $map;
    return $map;
}

/**
 * GH#103 — the set of column names that actually EXIST on a table, by
 * name only (no type/nullability detail needed by any caller so far).
 * "Never assume a column exists" (CLAUDE.md's Defensive Database
 * Patterns #2) applies to config-declared column names exactly as much
 * as to hand-written SQL — a config that names a column absent on some
 * install must not turn that into a query error.
 *
 * Cached per table for the lifetime of the request.
 */
function db_table_column_set(string $table): array
{
    static $cache = [];
    $key = trim($table, '` ');
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $set = [];
    try {
        $cols = db_fetch_all(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$key]
        );
        foreach ($cols as $col) {
            $set[$col['COLUMN_NAME']] = true;
        }
    } catch (Exception $e) {
        // Can't read schema — return empty; callers must treat this the
        // same as "column not found" (fail safe, not fail open).
    }
    $cache[$key] = $set;
    return $set;
}
