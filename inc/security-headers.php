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

require_once __DIR__ . '/https.php';   // is_https(), is_https_verified()

/**
 * Build the Content-Security-Policy header value.
 *
 * Split out of set_security_headers() so the policy can be asserted on
 * directly. Under the CLI SAPI header() is a no-op and headers_list()
 * comes back empty, so a test that only called set_security_headers()
 * could observe nothing — which is how the missing `media-src` below
 * survived: the CSP had regression tests, and every one of them grepped
 * this file's source for a substring rather than reading the policy the
 * function actually produces. A directive that was never there passes a
 * grep for the directives that were. (Same lesson as `tile_mode` in
 * CLAUDE.md — assert on an observable output, not on wiring.)
 *
 * @return string  The policy, directives joined with '; '.
 */
function build_csp_policy(): string {
    // Geocoder origins the BROWSER is allowed to contact.
    //
    // Under the shipped default (geocoding_mode=server) this is EMPTY, and
    // that is the point: the browser has no business talking to a geocoder,
    // so a twelfth hardcoded call site fails visibly on every install instead
    // of silently disclosing the incident address on all of them. In direct
    // mode it carries exactly the configured provider's origin — which also
    // fixes a latent bug: before this, direct mode against any provider other
    // than Nominatim would have been CSP-blocked, because only Nominatim was
    // ever named here.
    //
    // Wrapped, because a CSP builder must never be the thing that takes a page
    // down. On any failure we fall back to the historical allowance.
    $geocodeHosts = ['https://nominatim.openstreetmap.org'];
    try {
        require_once __DIR__ . '/geocode.php';
        if (function_exists('geocode_csp_connect_hosts') && function_exists('get_variable')) {
            $geocodeHosts = geocode_csp_connect_hosts();
        }
    } catch (Throwable $e) {
        error_log('[geocode] CSP host resolution failed: ' . $e->getMessage());
    }
    $geocodeCsp = $geocodeHosts ? (' ' . implode(' ', $geocodeHosts)) : '';

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
        // plus data:/blob: for SVGs and downloads. The geocoder is NOT a fixed
        // entry any more: $geocodeCsp is empty in the shipped server mode and
        // carries the configured provider's origin in direct mode.
        // 'self' also covers api/tile-proxy.php: in the default proxy mode the
        // basemaps that CAN be proxied are same-origin, so they need no
        // allowlist entry at all. The hosts below are what DIRECT mode needs.
        "img-src 'self' data: blob: "
            . "https://*.tile.openstreetmap.org "
            // Found 2026-07-31 while wiring the tile proxy: the Terrain
            // basemap MapPrefs ships (OpenTopoMap) was never in this list, so
            // in direct mode the browser blocked every one of its tiles and
            // the layer rendered empty. Proxy mode masks it (same-origin), so
            // it must stay listed for anyone who switches to direct.
            . "https://*.tile.opentopomap.org "
            . "https://*.basemaps.cartocdn.com "
            // The keyed CARTO address (GH#150, and the built-in Dark/Light
            // with a CARTO key) is the bare host, which the wildcard above
            // does not match: a CSP `*.` only covers subdomains (2026-10-15).
            . "https://basemaps.cartocdn.com "
            . "https://tile.openweathermap.org "
            // openweathermap.org (no `tile.`) serves the City Weather marker
            // icons. Only the TILE host was listed, so those icons were
            // CSP-blocked as well as being requested over plain http — see
            // the imageUrl* overrides in assets/js/app.js.
            . "https://openweathermap.org "
            . "https://server.arcgisonline.com "
            . "https://basemap.nationalmap.gov "
            . "https://mesonet.agron.iastate.edu "
            . "https://*.rainviewer.com "
            . "https://mapservices.weather.noaa.gov"
            . $geocodeCsp,
        // media-src: <audio>/<video>/<track> sources. Public issue #27 —
        // there was no media-src at all, so media fell back to
        // `default-src 'self'` and every non-http(s) source was refused.
        // `'self'` does NOT cover the data: or blob: schemes (they are
        // opaque origins, which is exactly why img-src and script-src
        // above already have to name them), so both must be listed:
        //
        //   data:  api/tts.php returns the Voice & Speech test sample as
        //          `data:audio/wav;base64,...` and voice-speech.php feeds
        //          it to <audio id="ttsTestAudio">. Chromium refuses it
        //          with "Media load rejected by URL safety check", which
        //          is its wording for a CSP refusal, not a codec problem.
        //   blob:  zello-widget.js binds a MediaSource to <audio> via
        //          URL.createObjectURL() for live incoming voice. Same
        //          refusal, and it was silently broken by the same gap.
        //
        // Deliberately no host allowlist and no wildcard: every media
        // source NewUI has is same-origin (api/dmr-audio.php, the Zello
        // archive clips) or one of those two schemes.
        "media-src 'self' data: blob:",
        "style-src 'self' 'unsafe-inline'",
        // Phase 84-followup: blob: needed so the radio widget's
        // AudioWorklet (constructed via URL.createObjectURL(new Blob(...)))
        // can load. Without it, browsers silently fall back to the
        // deprecated ScriptProcessor which produces no audio on some
        // builds of Firefox.
        "script-src 'self' 'unsafe-inline' blob:",
        "font-src 'self' data:",
        // connect-src: SSE, Zello/Meshtastic websockets on the LAN, the
        // callsign + radio-id lookup APIs, the aprs.fi API used by the
        // location-providers test path, and the RainViewer frame catalog
        // fetched by the situation radar (#53). Geocoding via $geocodeCsp —
        // empty in server mode, on purpose.
        "connect-src 'self' ws: wss: "
            . "https://callook.info "
            . "https://*.radioid.net "
            . "https://api.aprs.fi "
            . "https://*.rainviewer.com"
            . $geocodeCsp,
        "frame-ancestors 'self'",
        "form-action 'self'",
        "base-uri 'self'",
        "object-src 'none'",
    ];

    return implode('; ', $csp);
}

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

    header('Content-Security-Policy: ' . build_csp_policy());

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
    // Thin alias — logic lives in inc/https.php so every site agrees.
    // Required at the top of this file, not here: config.php calls
    // set_security_headers() long before inc/functions.php loads.
    // Best-effort variant: correct for its callers (HSTS emission, URL
    // and WebSocket scheme). An access gate wants is_https_verified().
    return is_https();
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
