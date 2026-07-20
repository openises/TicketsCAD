<?php
/**
 * NewUI v4.0 - Security Helpers
 *
 * Security headers, HTTPS detection, URL generation, and
 * WebSocket scheme detection.
 */

/**
 * Send security-related HTTP headers.
 * Call early in the request (before any output).
 */
function security_headers()
{
    // Prevent MIME-type sniffing
    header('X-Content-Type-Options: nosniff');

    // Clickjacking protection — allow same-origin framing only
    header('X-Frame-Options: SAMEORIGIN');

    // XSS filter (legacy browsers)
    header('X-XSS-Protection: 1; mode=block');

    // Referrer policy — send origin only to cross-origin requests
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Permissions policy — disable dangerous features
    header('Permissions-Policy: camera=(), microphone=(self), geolocation=(self), payment=()');

    // HTTPS-specific headers
    if (fe_is_https()) {
        // HSTS — tell browsers to always use HTTPS for one year
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

        // Upgrade insecure requests
        header('Content-Security-Policy: upgrade-insecure-requests');
    }
}

/**
 * Apply secure cookie flags when on HTTPS.
 * Call after session_start() if desired.
 */
function security_cookie_flags()
{
    if (fe_is_https()) {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
}

/**
 * Generate a full URL respecting the current scheme (http/https).
 *
 * @param string $path  Relative path from the newui root (e.g., "index.php", "api/incidents.php")
 * @return string       Full URL
 */
// Guard: inc/security-headers.php declares the same function. Both can
// end up loaded in the same request (api/auth.php pulls security-headers
// then a deeper endpoint pulls security.php), which would fatal on
// redeclare. Phase 9 (2026-06-08) surfaced this when the change-password
// flow chained both.
if (!function_exists('site_url')) {
function site_url($path = '')
{
    $scheme = fe_is_https() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

    // Determine the base path from SCRIPT_NAME
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    $basePath = rtrim($scriptDir, '/\\');

    $path = ltrim($path, '/');
    return $scheme . '://' . $host . $basePath . '/' . $path;
}
} // end if (!function_exists('site_url'))

/**
 * Return the correct WebSocket scheme based on current protocol.
 *
 * @return string 'wss' if HTTPS, 'ws' if HTTP
 */
if (!function_exists('ws_scheme')) {
function ws_scheme()
{
    return fe_is_https() ? 'wss' : 'ws';
}
}

/**
 * Generate an HTML warning banner for non-HTTPS connections.
 * Returns empty string if already on HTTPS.
 *
 * @return string HTML for the warning banner
 */
function https_warning_banner()
{
    if (fe_is_https()) {
        return '';
    }

    return '<div class="alert alert-warning py-2 mb-2 small d-flex align-items-center" role="alert" id="httpsWarning">'
         . '<i class="bi bi-exclamation-triangle-fill me-2"></i>'
         . '<span>This connection is not fully encrypted. Configure HTTPS for complete security.</span>'
         . '<button type="button" class="btn-close btn-close-sm ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>'
         . '</div>';
}
