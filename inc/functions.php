<?php
/**
 * NewUI v4.0 - Utility Functions
 *
 * Minimal utilities adapted from tickets/incs/functions.inc.php.
 * Only what NewUI actually needs — no legacy baggage.
 */

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
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
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
