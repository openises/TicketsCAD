<?php
/**
 * NewUI v4.0 — Security Headers
 *
 * Sets HTTP security headers on all pages to mitigate common web attacks.
 * Call set_security_headers() at the top of every page/endpoint.
 *
 * USAGE:
 *   require_once __DIR__ . '/security-headers.php';
 *   set_security_headers();
 */

/**
 * Set standard security headers.
 * Safe to call multiple times (headers are overwritten, not duplicated).
 *
 * @return void
 */
function set_security_headers(): void {
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');

    // Prevent clickjacking — only allow framing from same origin
    header('X-Frame-Options: SAMEORIGIN');

    // Note: X-XSS-Protection is intentionally NOT set. Modern browsers
    // ignore it; older Chrome/Edge versions implementing it had exploitable
    // bugs that make `1; mode=block` net-harmful. Use CSP instead.

    // Control referrer information sent with requests
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Content Security Policy — `default-src 'self'` confines fetches to
    // our own origin. We allow `unsafe-inline` for script and style because
    // multiple existing pages have inline event handlers and inline style
    // attributes; tightening that requires a UI refactor (tracked separately).
    // `data:` images are allowed for embedded SVG/avatars; `blob:` for
    // file downloads served via api/upload.php. `connect-src` covers the
    // SSE stream, the Zello WebSocket proxy on the LAN (ws/wss `*:8090`),
    // and Meshtastic / OwnTracks endpoints configured at runtime.
    // Phase 43e: extended allowlist to cover every tile + geocoder + lookup
    // host actually used by NewUI today. Wildcards used sparingly — only
    // where the provider rotates subdomains (basemaps.cartocdn.com, the
    // openstreetmap *.tile pool, mesonet's wms cluster).
    $csp = [
        "default-src 'self'",
        // img-src: tile providers (OSM, Carto dark, OpenWeatherMap weather,
        // Esri World Imagery satellite, USGS basemap, IEM/mesonet WMS,
        // RainViewer + NOAA/NWS MRMS precipitation radar — situation.php #53),
        // Nominatim geocoder (its result icons), plus data:/blob: for SVGs
        // and downloads.
        "img-src 'self' data: blob: "
            . "https://*.tile.openstreetmap.org "
            . "https://*.basemaps.cartocdn.com "
            . "https://tile.openweathermap.org "
            . "https://server.arcgisonline.com "
            . "https://basemap.nationalmap.gov "
            . "https://mesonet.agron.iastate.edu "
            . "https://*.rainviewer.com "
            . "https://mapservices.weather.noaa.gov "
            . "https://nominatim.openstreetmap.org",
        "style-src 'self' 'unsafe-inline'",
        // Phase 84-followup: blob: needed so the radio widget's
        // AudioWorklet (constructed via URL.createObjectURL(new Blob(...)))
        // can load. Without it, browsers silently fall back to the
        // deprecated ScriptProcessor which produces no audio on some
        // builds of Firefox.
        "script-src 'self' 'unsafe-inline' blob:",
        "font-src 'self' data:",
        // connect-src: SSE, Zello/Meshtastic websockets on the LAN, the
        // callsign + radio-id lookup APIs, Nominatim geocoding (XHR), the
        // aprs.fi API used by the location-providers test path, and the
        // RainViewer frame catalog fetched by the situation radar (#53).
        "connect-src 'self' ws: wss: "
            . "https://callook.info "
            . "https://*.radioid.net "
            . "https://nominatim.openstreetmap.org "
            . "https://api.aprs.fi "
            . "https://*.rainviewer.com",
        "frame-ancestors 'self'",
        "form-action 'self'",
        "base-uri 'self'",
        "object-src 'none'",
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));

    // Prevent browsers from caching sensitive pages
    // (individual pages can override this if they serve public/static content)
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    // HTTPS-specific headers
    if (_is_https()) {
        // HSTS — tell browser to only use HTTPS for 1 year, opt-in to the
        // browser preload list (Constitution rule #28 — `preload` flag).
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

        // Harden session cookie for HTTPS
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            // Rewrite session cookie with Secure and SameSite=Strict
            if (isset($_COOKIE[session_name()])) {
                setcookie(
                    session_name(),
                    session_id(),
                    [
                        'expires'  => $params['lifetime'] > 0 ? time() + $params['lifetime'] : 0,
                        'path'     => $params['path'],
                        'domain'   => $params['domain'],
                        'secure'   => true,
                        'httponly' => true,
                        'samesite' => 'Strict',
                    ]
                );
            }
        }
    }

    // Permissions Policy — restrict browser features we don't use.
    //
    // Eric beta 2026-06-30 — microphone was hard-blocked here (=())
    // which took precedence over Chrome's user-granted per-site mic
    // permission. Result: getUserMedia returned "Permission denied"
    // on Chrome (Firefox was more lenient and worked sometimes)
    // even though the browser permission list showed the site as
    // allowed. Zello widget + Radio widget both need mic for PTT.
    // (self) means "only our own origin can use it" — no cross-
    // origin iframe can grab the mic through this page.
    //
    // The sibling file inc/security.php already had microphone=(self)
    // set correctly; this one drifted. Keeping both in sync now.
    header('Permissions-Policy: camera=(), microphone=(self), geolocation=(self), payment=()');
}

/**
 * Check if the current request is over HTTPS.
 *
 * @return bool
 */
function _is_https(): bool {
    // Direct HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    // Behind a reverse proxy
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        return true;
    }
    // Standard port check
    if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    return false;
}

/**
 * Build a URL using the current scheme (http or https).
 *
 * @param string $path  Relative path (e.g., 'api/config-admin.php?section=settings')
 * @return string       Full URL with scheme and host
 */
function site_url(string $path = ''): string {
    $scheme = _is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

    // Strip leading slash if present
    $path = ltrim($path, '/');

    // Determine base path from script
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

    return $scheme . '://' . $host . $basePath . '/' . $path;
}

/**
 * Return the WebSocket scheme based on current HTTP scheme.
 *
 * @return string 'wss' if HTTPS, 'ws' otherwise
 */
function ws_scheme(): string {
    return _is_https() ? 'wss' : 'ws';
}
