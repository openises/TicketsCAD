<?php
/**
 * NewUI v4.0 API - Agency logo, serving endpoint (GH#142, Phase 155).
 *
 * GET /api/branding-logo.php?k=<32 lowercase hex>
 *
 * PUBLIC, UNAUTHENTICATED BY DESIGN, and deliberately NOTHING ELSE: the login
 * page must be able to show the install-wide logo before anyone has signed in.
 * What makes that safe:
 *
 *   - The key is a RANDOM capability, generated fresh on every upload and
 *     stored on the row. There are no sequential ids to enumerate, so one
 *     agency's logo URL cannot be used to find another's; and because the key
 *     changes on every upload, a replaced logo has a new URL and the old one
 *     404s (which is also why the response can be cached for a year: the
 *     stale-cache problem GH#143 exposed is designed out here).
 *   - An unknown key, a malformed key, a missing table and a database error
 *     are INDISTINGUISHABLE: same status, same headers, same body. Nothing
 *     here tells a prober which of those happened.
 *   - It never starts a session and never reads one: no Set-Cookie on a static
 *     asset, no session lock. It loads config.php (for the shared security
 *     headers and the database), not api/auth.php.
 *   - The Content-Type comes from the stored allow-listed MIME, never from the
 *     client. The body is re-hashed against the stored sha256 before it is
 *     sent. nosniff, a sandboxed `default-src 'none'` CSP and
 *     Cross-Origin-Resource-Policy: same-origin keep the bytes inert and
 *     un-hotlinkable even if something upstream ever mis-served them.
 *   - Rate limited per client address, generously (a cached logo is fetched
 *     once per browser per version).
 *
 * inc/security-headers.php makes page responses `no-store`. This endpoint is
 * the deliberate exception: it overrides Cache-Control after config.php has
 * set the defaults.
 */

require_once __DIR__ . '/../inc/api_guard.php';
api_guard_install();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rate-limit.php';
require_once __DIR__ . '/../inc/client-ip.php';
require_once __DIR__ . '/../inc/branding.php';

ini_set('display_errors', '0');

/** The one 404, identical for every way a request can fail to find a logo. */
function _branding_logo_not_found(): void
{
    // Restore the shared default headers and drop every image-specific one, so
    // a 404 raised late (a corrupt row, after the image headers were set) is
    // byte-for-byte the same shape as one raised on a malformed key.
    set_security_headers();
    foreach (['ETag', 'Content-Disposition', 'Cross-Origin-Resource-Policy', 'Content-Length'] as $h) {
        header_remove($h);
    }
    http_response_code(404);
    header_remove('Pragma');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    echo json_encode(['error' => 'Not found.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if (!rate_limit_ok('branding_logo:' . client_ip(), BRANDING_LOGO_RATE_LIMIT, BRANDING_LOGO_RATE_WINDOW)) {
    rate_limit_reject(BRANDING_LOGO_RATE_WINDOW);
}

$key = isset($_GET['k']) && is_string($_GET['k']) ? $_GET['k'] : '';
if (!branding_valid_key($key)) {
    _branding_logo_not_found();
}

$row = null;
try {
    if (branding_table_exists()) {
        $row = db_fetch_one(
            "SELECT `mime`, `sha256`, `byte_size` FROM " . db_table('branding_logos') . " WHERE `asset_key` = ? LIMIT 1",
            [$key]
        );
    }
} catch (Throwable $e) {
    error_log('[branding-logo] lookup failed: ' . $e->getMessage());
    $row = null;
}
if (!$row || !in_array($row['mime'], ['image/png', 'image/jpeg'], true) || !preg_match('/^[a-f0-9]{64}$/', (string) $row['sha256'])) {
    _branding_logo_not_found();
}

// Validators first: a conditional request never reads the (large) body.
$etag = '"' . substr((string) $row['sha256'], 0, 32) . '"';
header_remove('Pragma');
header('Content-Type: ' . $row['mime']);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cross-Origin-Resource-Policy: same-origin');
header('X-Robots-Tag: noindex');
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);

$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNoneMatch !== '' && ($ifNoneMatch === $etag || $ifNoneMatch === 'W/' . $etag)) {
    http_response_code(304);
    exit;
}

try {
    $b64 = db_fetch_value(
        "SELECT `data_b64` FROM " . db_table('branding_logos') . " WHERE `asset_key` = ? LIMIT 1",
        [$key]
    );
} catch (Throwable $e) {
    error_log('[branding-logo] body read failed: ' . $e->getMessage());
    $b64 = false;
}
$bin = (is_string($b64) && $b64 !== '') ? base64_decode($b64, true) : false;
if ($bin === false || !hash_equals((string) $row['sha256'], hash('sha256', $bin))) {
    // A corrupt row is never served. Same 404 as everything else.
    error_log('[branding-logo] integrity check failed for a stored logo');
    header_remove('ETag');
    _branding_logo_not_found();
}

header('Content-Length: ' . strlen($bin));
header('Content-Disposition: inline; filename="logo.' . ($row['mime'] === 'image/png' ? 'png' : 'jpg') . '"');
if ($method !== 'HEAD') {
    echo $bin;
}
exit;
