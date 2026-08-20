<?php
/**
 * inc/geocode.php — server-side geocoding: policy, SSRF-safe URL construction,
 * response normalisation, cache, throttle, circuit breaker.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 *
 * settings.php has offered a "Geocoding Provider" dropdown and an API key
 * field since Phase 43. Nothing has ever read either one:
 *
 *     git log --all -S geocoding_provider -- assets/ api/ inc/   → empty
 *
 * Not "the consumer was removed" — a consumer was never written, in any
 * commit, on any branch. Meanwhile all eleven geocoding calls hardcoded
 * `https://nominatim.openstreetmap.org` in the BROWSER
 * (app.js, new-incident.js, incident-detail.js, unit-edit.js,
 * facility-edit.js, config.js). Three consequences, each independently bad:
 *
 *   1. An administrator who set that dropdown to their own server believed
 *      they had configured offline geocoding, and was wrong. The setting lied.
 *   2. Offline geocoding was not merely unconfigured, it was IMPOSSIBLE.
 *      There was no server-side path at all, and the browser-side one cannot
 *      reach a LAN geocoder: an HTTPS-served page fetching http://10.x.x.x is
 *      blocked as mixed content, and inc/security-headers.php's connect-src
 *      allowlists nominatim.openstreetmap.org by name. Two independent walls.
 *   3. Every dispatcher's browser told OpenStreetMap which addresses were
 *      being typed — which is to say, where the incidents were — from N
 *      separate IPs, with no cache, no throttle and no identifying
 *      User-Agent.
 *
 * Point 3 is not just a privacy matter. The OSM Foundation's Nominatim usage
 * policy REQUIRES that results be cached, caps use at an absolute maximum of
 * one request per second, requires an identifying User-Agent, and explicitly
 * RECOMMENDS putting a proxy in front. Eleven uncoordinated browser fetches
 * can satisfy none of those. So the browser-direct architecture was a standing
 * violation of the one provider it hardcoded, and only a server-side path can
 * cure it.
 *
 * This is the same disease as `tile_mode` (see inc/tile-proxy.php's header),
 * found in the same audit, and it is fixed the same way: the setting becomes
 * real, the server does the fetching, and each provider carries an explicit,
 * sourced verdict instead of a convenient assumption.
 *
 * ── SSRF ─────────────────────────────────────────────────────────────────
 *
 * This endpoint NEVER accepts a URL from the client. The client sends a
 * provider-independent QUERY (an address string, or a lat/lon pair, plus an
 * optional viewbox and limit). The upstream URL is built here from a template
 * this file hardcodes, or — for the two self-hosted providers — from the
 * admin's `geocoding_url` setting, which is validated as an absolute http(s)
 * URL with no credentials and no control characters. The client contributes no
 * scheme, no host, no port, no path: only values that are re-serialised
 * server-side after being cast to float/int or percent-encoded.
 *
 * ── WHY A CACHE IS MANDATORY, NOT AN OPTIMISATION ────────────────────────
 *
 * Nominatim's policy states results "must be cached on your side". A dispatch
 * area also geocodes the same two dozen intersections forever, so the cache is
 * a large real win. It is bounded (entry cap + LRU eviction), it lives ABOVE
 * the web root, and it holds no user identity — see geocode_cache_dir().
 */

if (!function_exists('newui_version')) {
    require_once __DIR__ . '/version.php';
}

// ── Bounds ───────────────────────────────────────────────────────────────

/**
 * Connect / total transfer timeouts for one upstream geocode, seconds.
 *
 * Unlike a map tile, a geocode is ONE request per operator action, not forty
 * per pan — so the arithmetic that forced the tile proxy down to 2s/6s does
 * not apply here and a slightly more generous budget is affordable. What is
 * NOT affordable is an unbounded wait: a dispatcher pressing Lookup mid-call
 * needs an answer or an honest failure, and a PHP worker held open by a
 * black-holed provider is a worker not serving the console.
 *
 * 3s to connect, 8s in total. Measured against 203.0.113.1 (RFC 5737,
 * black-holed) this fails in ~3.0s instead of hanging.
 */
const GEOCODE_CONNECT_TIMEOUT = 3;
const GEOCODE_READ_TIMEOUT    = 8;

/** Largest upstream response we will read. A geocode reply is a few KB. */
const GEOCODE_MAX_BYTES = 1048576;

/** Default lifetime of a cached geocode result, hours. */
const GEOCODE_CACHE_DEFAULT_HOURS = 24;

/** Cache entry ceiling. Beyond this, least-recently-used entries are evicted. */
const GEOCODE_CACHE_DEFAULT_MAX_ENTRIES = 5000;

/** Consecutive transport failures before the breaker opens. */
const GEOCODE_BREAKER_THRESHOLD = 3;

/** How long the breaker stays open before letting one request probe, seconds. */
const GEOCODE_BREAKER_COOLOFF = 60;

/**
 * Longest we will BLOCK a request waiting for the provider throttle, ms.
 *
 * The throttle exists because Nominatim's policy caps us at 1 req/s. When two
 * dispatchers press Lookup in the same second, one of them has to wait — but
 * waiting is holding a PHP worker, so the wait is bounded and then the request
 * gives up with an honest "busy, try again" rather than queueing indefinitely.
 */
const GEOCODE_THROTTLE_MAX_WAIT_MS = 1200;

// ─────────────────────────────────────────────────────────────────────────
// PROVIDER POLICY
// ─────────────────────────────────────────────────────────────────────────

/**
 * Every geocoding provider TicketsCAD can talk to, and everything that is
 * true about it.
 *
 * Fields:
 *   label            string  shown in Settings.
 *   kind             string  'public' | 'self' | 'commercial'.
 *   needs_key        bool    requires geocoding_api_key.
 *   needs_url        bool    requires geocoding_url (a self-hosted instance).
 *   direct_supported bool    may the BROWSER fetch this provider itself?
 *                            See geocode_effective_mode() — false here does
 *                            not disable the provider, it forces server mode.
 *   direct_reason    string  why direct is unavailable, in plain language.
 *   throttle_ms      int     minimum interval between upstream calls. Derived
 *                            from the provider's own published policy; the
 *                            admin may raise it but the DEFAULT is the
 *                            provider's rule, not our preference.
 *   policy           string  what the provider's terms say about a server-side
 *                            proxy + caching, and where that came from.
 *   source           string  URL the policy finding rests on.
 *   caveat           string  '' when the terms are explicit; non-empty when
 *                            the verdict rests on silence or analogy.
 *   verified         string  'live'      — exercised against the real service
 *                            'documented'— adapter written from the provider's
 *                                          published response schema and
 *                                          covered by a fixture test, but not
 *                                          exercised against a live key here.
 *                            Surfaced in Settings, so an admin knows to press
 *                            Test before relying on it. Claiming otherwise
 *                            would be the same kind of lie this file exists to
 *                            remove.
 *   unsupported      string[] result fields this provider genuinely cannot
 *                            supply. Declared, so an empty cross-street box is
 *                            explainable instead of mysterious.
 *
 * @return array<string,array<string,mixed>>
 */
function geocode_policy(): array
{
    return [
        'nominatim' => [
            'label' => 'Nominatim / OpenStreetMap (public, no key)',
            'kind' => 'public', 'needs_key' => false, 'needs_url' => false,
            'direct_supported' => true,
            'direct_reason' => '',
            'throttle_ms' => 1000,
            'policy' => 'The OSM Foundation\'s Nominatim usage policy sets an absolute maximum of one '
                      . 'request per second, requires that results be cached on your side, requires a '
                      . 'User-Agent that identifies the application, and explicitly recommends putting '
                      . 'a proxy in front. Server mode is the only configuration that can satisfy all '
                      . 'four — eleven independent browser fetches can cache nothing, throttle nothing '
                      . 'and identify nothing.',
            'source' => 'https://operations.osmfoundation.org/policies/nominatim/',
            'caveat' => 'The public instance is run on donated hardware for occasional use. A busy '
                      . 'agency should self-host (see docs/OFFLINE-OPERATION.md) rather than lean on it.',
            'verified' => 'live',
            'unsupported' => [],
        ],
        'nominatim_self' => [
            'label' => 'Nominatim — self-hosted (your own server)',
            'kind' => 'self', 'needs_key' => false, 'needs_url' => true,
            'direct_supported' => true,
            'direct_reason' => '',
            // Your server, your rules. No artificial delay.
            'throttle_ms' => 0,
            'policy' => 'Your own instance. No third party is involved, nothing leaves your network, '
                      . 'and there are no terms to violate. This is the configuration that makes '
                      . 'address lookup work with no internet at all.',
            'source' => 'admin-configured (geocoding_url)',
            'caveat' => '',
            'verified' => 'live',
            'unsupported' => [],
        ],
        'photon' => [
            'label' => 'Photon (self-hosted, or the public komoot instance)',
            'kind' => 'self', 'needs_key' => false, 'needs_url' => true,
            'direct_supported' => false,
            'direct_reason' => 'Photon answers in GeoJSON, which is a different shape from the one '
                             . 'TicketsCAD\'s forms read. The translation happens on the server, so '
                             . 'this provider always uses server mode.',
            'throttle_ms' => 0,
            'policy' => 'Photon is open source (Apache 2.0) and designed to be self-hosted; a '
                      . 'prebuilt index can be downloaded rather than imported. If you point this at '
                      . 'komoot\'s public instance instead, their terms ask for reasonable use and '
                      . 'no bulk querying.',
            'source' => 'https://github.com/komoot/photon',
            'caveat' => 'The public komoot instance publishes no formal rate limit or uptime '
                      . 'guarantee. For dispatch use, self-host.',
            'verified' => 'documented',
            'unsupported' => ['neighbourhood', 'suburb'],
        ],
        'locationiq' => [
            'label' => 'LocationIQ (commercial, key required)',
            'kind' => 'commercial', 'needs_key' => true, 'needs_url' => false,
            'direct_supported' => false,
            'direct_reason' => 'This provider needs an API key. Direct-from-browser mode would have to '
                             . 'hand that key to every dispatcher\'s browser, where anyone can read it '
                             . 'and spend your quota — so keyed providers always use server mode, '
                             . 'which keeps the key on the server.',
            'throttle_ms' => 0,
            'policy' => 'A paid API accessed with your own key. Their fair-use terms govern volume; '
                      . 'caching results reduces your billed request count.',
            'source' => 'https://locationiq.com/',
            'caveat' => 'Their free tier has a daily cap. The server-side cache in this file is what '
                      . 'keeps a busy shift inside it.',
            'verified' => 'documented',
            'unsupported' => [],
        ],
        'geoapify' => [
            'label' => 'Geoapify (commercial, key required)',
            'kind' => 'commercial', 'needs_key' => true, 'needs_url' => false,
            'direct_supported' => false,
            'direct_reason' => 'Keyed provider — see LocationIQ. The key stays on the server.',
            'throttle_ms' => 0,
            'policy' => 'A paid API accessed with your own key.',
            'source' => 'https://www.geoapify.com/',
            'caveat' => '',
            'verified' => 'documented',
            'unsupported' => ['neighbourhood'],
        ],
        'google' => [
            'label' => 'Google Maps Geocoding (commercial, key required)',
            'kind' => 'commercial', 'needs_key' => true, 'needs_url' => false,
            'direct_supported' => false,
            'direct_reason' => 'Keyed provider — see LocationIQ. The key stays on the server.',
            'throttle_ms' => 0,
            'policy' => 'Google Maps Platform terms restrict caching of geocoding results to a limited '
                      . 'period for performance, and prohibit displaying their content alongside a '
                      . 'non-Google map. TicketsCAD renders in Leaflet, so read those terms before '
                      . 'selecting this provider for anything beyond evaluation.',
            'source' => 'https://cloud.google.com/maps-platform/terms',
            'caveat' => 'The no-non-Google-map restriction is a real one and it applies to this '
                      . 'application. TicketsCAD surfaces the choice rather than making it for you, '
                      . 'but the safe providers here are Nominatim (self-hosted) and Photon.',
            'verified' => 'documented',
            'unsupported' => [],
        ],
        'here' => [
            'label' => 'HERE Geocoding &amp; Search (commercial, key required)',
            'kind' => 'commercial', 'needs_key' => true, 'needs_url' => false,
            'direct_supported' => false,
            'direct_reason' => 'Keyed provider — see LocationIQ. The key stays on the server.',
            'throttle_ms' => 0,
            'policy' => 'A paid API accessed with your own key.',
            'source' => 'https://www.here.com/docs/',
            'caveat' => '',
            'verified' => 'documented',
            'unsupported' => ['neighbourhood', 'suburb'],
        ],
    ];
}

/** Is this a syntactically valid provider identifier? Also the traversal guard. */
function geocode_valid_provider_id(string $provider): bool
{
    return $provider !== ''
        && strlen($provider) <= 40
        && preg_match('/^[a-z0-9_]+$/', $provider) === 1;
}

/** The configured provider, falling back to the shipped default. */
function geocode_provider_id(string $configured = ''): string
{
    $configured = trim(strtolower($configured));
    return isset(geocode_policy()[$configured]) ? $configured : 'nominatim';
}

// ─────────────────────────────────────────────────────────────────────────
// SETTINGS
// ─────────────────────────────────────────────────────────────────────────

/**
 * Read the admin's geocoding settings, with defaults.
 *
 * Reads via get_variable() — the `settings` table — NOT get_setting()/`config`.
 * Crossing those two stores is a documented way to make an admin toggle read
 * as its default forever (see the two-settings-stores pitfall in CLAUDE.md).
 *
 * @return array<string,mixed>
 */
function geocode_settings(): array
{
    $get = function (string $k) {
        if (!function_exists('get_variable')) { return ''; }
        $v = get_variable($k);
        return ($v === false || $v === null) ? '' : (string) $v;
    };

    $provider = geocode_provider_id($get('geocoding_provider'));
    $policy   = geocode_policy()[$provider];

    $throttle = $get('geocoding_min_interval_ms');
    $hours    = (int) $get('geocoding_cache_hours');
    $entries  = (int) $get('geocoding_cache_max_entries');

    return [
        'mode'         => geocode_mode_value($get('geocoding_mode')),
        'provider'     => $provider,
        'api_key'      => $get('geocoding_api_key'),
        'url'          => $get('geocoding_url'),
        'user_agent'   => $get('geocoding_user_agent'),
        // '' means "use the provider's own published rule", which is the
        // honest default. An explicit '0' from the admin means no throttle.
        'throttle_ms'  => ($throttle === '') ? (int) $policy['throttle_ms'] : max(0, (int) $throttle),
        'cache_ttl'    => ($hours   > 0 ? $hours   : GEOCODE_CACHE_DEFAULT_HOURS) * 3600,
        'max_entries'  => ($entries > 0 ? $entries : GEOCODE_CACHE_DEFAULT_MAX_ENTRIES),
    ];
}

/** Normalise a stored geocoding_mode value. Unknown/empty = the install default. */
function geocode_mode_value(string $raw): string
{
    $raw = trim(strtolower($raw));
    return in_array($raw, ['server', 'direct', 'off'], true) ? $raw : 'server';
}

/**
 * The mode that will ACTUALLY be used, which is not always the one configured.
 *
 * This is the single function the server, the injected JS config and the
 * browser all agree on — the thing whose absence WAS the bug: the setting said
 * one thing and the URL builder did another, with nothing reconciling them.
 *
 * `off` is absolute: an air-gapped install that switches geocoding off must
 * not have any code path quietly reach the internet.
 *
 * `direct` is a request, not a guarantee. A keyed provider cannot be fetched
 * from the browser without shipping the key there, and a GeoJSON provider
 * cannot be parsed by the forms — so those resolve to `server` and say why.
 *
 * @return array{mode:string,requested:string,reason:string}
 */
function geocode_effective_mode(string $configured, string $provider): array
{
    $requested = geocode_mode_value($configured);
    if ($requested === 'off') {
        return ['mode' => 'off', 'requested' => 'off', 'reason' => ''];
    }
    if ($requested === 'server') {
        return ['mode' => 'server', 'requested' => 'server', 'reason' => ''];
    }
    $policy = geocode_policy();
    $p = $policy[$provider] ?? null;
    if ($p === null) {
        return ['mode' => 'server', 'requested' => 'direct',
                'reason' => 'Unknown provider — using server mode, which can report the problem.'];
    }
    if (empty($p['direct_supported'])) {
        return ['mode' => 'server', 'requested' => 'direct',
                'reason' => (string) $p['direct_reason']];
    }
    return ['mode' => 'direct', 'requested' => 'direct', 'reason' => ''];
}

// ─────────────────────────────────────────────────────────────────────────
// SSRF-SAFE UPSTREAM URL CONSTRUCTION
// ─────────────────────────────────────────────────────────────────────────

/**
 * Accept only absolute http/https URLs, with no embedded credentials.
 *
 * Same intent as tile_proxy_sanitize_template() in inc/tile-proxy.php, and
 * deliberately a separate copy: these guard different consumers with different
 * blast radii, and sharing one would mean a future tightening for tiles
 * silently changes what a geocoder base URL may be (or vice versa).
 */
function geocode_sanitize_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2000) {
        return '';
    }
    // Control characters and whitespace could split a request line.
    if (preg_match('/[\x00-\x20\x7F]/', $url)) {
        return '';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }
    if ((string) parse_url($url, PHP_URL_HOST) === '') {
        return '';
    }
    // user:pass@host in a configured URL is far more likely to be a mistake or
    // an injection than an intention, and it would end up in error logs.
    if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
        return '';
    }
    return $url;
}

/**
 * The admin's self-hosted base URL, validated, with any trailing slash and any
 * query string removed. Returns '' when unusable.
 */
function geocode_base_url(string $configured): string
{
    $url = geocode_sanitize_url($configured);
    if ($url === '') {
        return '';
    }
    // Keep scheme://host[:port]/path only. A configured query string would be
    // silently dropped when we append our own, so drop it here where it is
    // visible rather than letting it look honoured.
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $out = strtolower($parts['scheme']) . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $out .= ':' . (int) $parts['port'];
    }
    $path = rtrim((string) ($parts['path'] ?? ''), '/');
    return $out . $path;
}

/**
 * Sanitise a free-text geocoding query.
 *
 * It is percent-encoded into a query string parameter downstream, so the only
 * job here is to bound it and strip control characters that have no business
 * in an address.
 */
function geocode_clean_query(string $q): string
{
    $q = preg_replace('/[\x00-\x1F\x7F]/', ' ', $q);
    $q = trim(preg_replace('/\s+/', ' ', (string) $q));
    return substr($q, 0, 250);
}

/**
 * Validate a client-supplied viewbox (result biasing) into a canonical string.
 *
 * The client sends four numbers. They are cast to float and re-serialised
 * here, so nothing the client typed reaches the upstream URL verbatim.
 *
 * @return string 'w,n,e,s' with 4 decimals, or '' when unusable
 */
function geocode_clean_viewbox(string $raw): string
{
    $parts = explode(',', trim($raw));
    if (count($parts) !== 4) {
        return '';
    }
    $out = [];
    foreach ($parts as $i => $p) {
        if (!is_numeric(trim($p))) {
            return '';
        }
        $v = (float) trim($p);
        // 0,2 are longitudes; 1,3 are latitudes.
        $limit = ($i % 2 === 0) ? 180.0 : 90.0;
        if ($v < -$limit || $v > $limit) {
            return '';
        }
        $out[] = number_format($v, 4, '.', '');
    }
    return implode(',', $out);
}

/** Is this a real WGS84 coordinate pair? */
function geocode_valid_latlon($lat, $lon): bool
{
    if (!is_numeric($lat) || !is_numeric($lon)) {
        return false;
    }
    $lat = (float) $lat;
    $lon = (float) $lon;
    return $lat >= -90.0 && $lat <= 90.0 && $lon >= -180.0 && $lon <= 180.0;
}

/**
 * Build the upstream URL for one geocoding request.
 *
 * THE SSRF BOUNDARY. Everything variable in the result comes from either this
 * file's hardcoded template, the admin's validated `geocoding_url`, or values
 * that have already been cast to float/int or percent-encoded. The client
 * contributes no scheme, no host, no port and no path.
 *
 * @param string $provider policy key
 * @param string $action   'search' | 'reverse'
 * @param array  $params   ['q'=>string] or ['lat'=>float,'lon'=>float],
 *                         plus optional 'limit', 'viewbox', 'countrycodes'
 * @param array  $settings from geocode_settings()
 * @return string|null null when the provider is unknown or unconfigured
 */
function geocode_build_url(string $provider, string $action, array $params, array $settings): ?string
{
    if (!geocode_valid_provider_id($provider)) {
        return null;
    }
    $policy = geocode_policy();
    if (!isset($policy[$provider])) {
        return null;
    }
    if ($action !== 'search' && $action !== 'reverse') {
        return null;
    }
    $p = $policy[$provider];

    $key = (string) ($settings['api_key'] ?? '');
    if (!empty($p['needs_key']) && $key === '') {
        return null;
    }
    $base = '';
    if (!empty($p['needs_url'])) {
        $base = geocode_base_url((string) ($settings['url'] ?? ''));
        if ($base === '') {
            return null;
        }
    }

    $limit = (int) ($params['limit'] ?? 1);
    if ($limit < 1) { $limit = 1; }
    if ($limit > 10) { $limit = 10; }

    if ($action === 'search') {
        $q = geocode_clean_query((string) ($params['q'] ?? ''));
        if ($q === '') {
            return null;
        }
    } else {
        if (!geocode_valid_latlon($params['lat'] ?? null, $params['lon'] ?? null)) {
            return null;
        }
        $lat = number_format((float) $params['lat'], 7, '.', '');
        $lon = number_format((float) $params['lon'], 7, '.', '');
    }

    switch ($provider) {
        case 'nominatim':
        case 'nominatim_self':
            $root = ($provider === 'nominatim') ? 'https://nominatim.openstreetmap.org' : $base;
            if ($action === 'search') {
                $qs = ['format' => 'json', 'addressdetails' => '1', 'limit' => (string) $limit, 'q' => $q];
                $vb = geocode_clean_viewbox((string) ($params['viewbox'] ?? ''));
                if ($vb !== '') {
                    $qs['viewbox'] = $vb;
                    $qs['bounded'] = '0';
                }
                $cc = geocode_clean_countrycodes((string) ($params['countrycodes'] ?? ''));
                if ($cc !== '') {
                    $qs['countrycodes'] = $cc;
                }
                return $root . '/search?' . http_build_query($qs);
            }
            return $root . '/reverse?' . http_build_query([
                'format' => 'json', 'addressdetails' => '1', 'lat' => $lat, 'lon' => $lon,
            ]);

        case 'photon':
            if ($action === 'search') {
                $qs = ['q' => $q, 'limit' => (string) $limit];
                // Photon biases by a point, not a box, so use the viewbox centre.
                $c = geocode_viewbox_centre((string) ($params['viewbox'] ?? ''));
                if ($c !== null) {
                    $qs['lat'] = number_format($c[0], 4, '.', '');
                    $qs['lon'] = number_format($c[1], 4, '.', '');
                }
                return $base . '/api?' . http_build_query($qs);
            }
            return $base . '/reverse?' . http_build_query(['lat' => $lat, 'lon' => $lon]);

        case 'locationiq':
            // LocationIQ's /v1 API is Nominatim-compatible by design.
            if ($action === 'search') {
                $qs = ['key' => $key, 'format' => 'json', 'addressdetails' => '1',
                       'limit' => (string) $limit, 'q' => $q];
                $vb = geocode_clean_viewbox((string) ($params['viewbox'] ?? ''));
                if ($vb !== '') {
                    $qs['viewbox'] = $vb;
                    $qs['bounded'] = '0';
                }
                return 'https://us1.locationiq.com/v1/search?' . http_build_query($qs);
            }
            return 'https://us1.locationiq.com/v1/reverse?' . http_build_query([
                'key' => $key, 'format' => 'json', 'addressdetails' => '1',
                'lat' => $lat, 'lon' => $lon,
            ]);

        case 'geoapify':
            if ($action === 'search') {
                return 'https://api.geoapify.com/v1/geocode/search?' . http_build_query([
                    'text' => $q, 'limit' => (string) $limit, 'format' => 'json', 'apiKey' => $key,
                ]);
            }
            return 'https://api.geoapify.com/v1/geocode/reverse?' . http_build_query([
                'lat' => $lat, 'lon' => $lon, 'format' => 'json', 'apiKey' => $key,
            ]);

        case 'google':
            if ($action === 'search') {
                return 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
                    'address' => $q, 'key' => $key,
                ]);
            }
            return 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
                'latlng' => $lat . ',' . $lon, 'key' => $key,
            ]);

        case 'here':
            if ($action === 'search') {
                return 'https://geocode.search.hereapi.com/v1/geocode?' . http_build_query([
                    'q' => $q, 'limit' => (string) $limit, 'apiKey' => $key,
                ]);
            }
            return 'https://revgeocode.search.hereapi.com/v1/revgeocode?' . http_build_query([
                'at' => $lat . ',' . $lon, 'apiKey' => $key,
            ]);
    }
    return null;
}

/** Two-letter ISO country codes, comma separated. Anything else is dropped. */
function geocode_clean_countrycodes(string $raw): string
{
    $out = [];
    foreach (explode(',', strtolower(trim($raw))) as $cc) {
        $cc = trim($cc);
        if (preg_match('/^[a-z]{2}$/', $cc)) {
            $out[] = $cc;
        }
        if (count($out) >= 8) { break; }
    }
    return implode(',', $out);
}

/** Centre of a viewbox string, or null. @return array{0:float,1:float}|null */
function geocode_viewbox_centre(string $raw): ?array
{
    $vb = geocode_clean_viewbox($raw);
    if ($vb === '') {
        return null;
    }
    [$w, $n, $e, $s] = array_map('floatval', explode(',', $vb));
    return [($n + $s) / 2.0, ($w + $e) / 2.0];
}

/**
 * The User-Agent this server identifies itself with upstream.
 *
 * Nominatim's policy requires a User-Agent that identifies the application and
 * blocks generic ones, so this must never be a bare library default.
 */
function geocode_user_agent(?string $configured = null, string $host = ''): string
{
    $configured = trim((string) $configured);
    if ($configured !== '' && strlen($configured) >= 8) {
        return substr($configured, 0, 160);
    }
    $ver  = function_exists('newui_version') ? newui_version() : '4';
    $host = trim($host) !== '' ? trim($host) : 'self-hosted';
    return 'TicketsCAD/' . $ver . ' (+https://' . $host . '; dispatch console)';
}

// ─────────────────────────────────────────────────────────────────────────
// RESPONSE NORMALISATION
// ─────────────────────────────────────────────────────────────────────────
//
// THE NEWUI GEOCODE RESULT SHAPE.
//
// Every provider is translated into this one shape, which is deliberately
// Nominatim-compatible because eleven existing call sites already read it:
//
//   {
//     lat: "44.9778", lon: "-93.2650",     // strings, as Nominatim sends
//     display_name: "…",
//     address: {
//       house_number, road, city, town, village, state, postcode,
//       neighbourhood, suburb, country, country_code, "ISO3166-2-lvl4"
//     }
//   }
//
// It is OURS, not Nominatim's, even though it currently matches: the name
// `ISO3166-2-lvl4` is a Nominatim-versioned key and we are free to add a
// stable alias later. Recorded here so the next maintainer knows the contract
// is a decision rather than an accident.
//
// Fields a provider genuinely cannot supply are DECLARED in geocode_policy()
// ['unsupported'] and echoed in the API response, so a blank cross-street box
// is explainable rather than mysterious. That is the answer to "who notices
// when an adapter silently stops filling a field": the response says so.

/**
 * Translate a provider's decoded JSON into a list of NewUI geocode results.
 *
 * PURE — no network, no clock, no filesystem — so every adapter is testable
 * from a recorded fixture. That matters especially for the providers whose
 * `verified` is 'documented': the shape is asserted in tests even where no key
 * exists in this environment to exercise the live service.
 *
 * @param string $provider policy key
 * @param string $action   'search' | 'reverse'
 * @param mixed  $decoded  json_decode(..., true) output
 * @return array<int,array<string,mixed>> possibly empty
 */
function geocode_normalize(string $provider, string $action, $decoded): array
{
    if ($decoded === null || $decoded === false) {
        return [];
    }
    switch ($provider) {
        case 'nominatim':
        case 'nominatim_self':
        case 'locationiq':
            // reverse returns a single object; search returns an array.
            $rows = ($action === 'reverse') ? [$decoded] : $decoded;
            if (!is_array($rows)) { return []; }
            $out = [];
            foreach ($rows as $r) {
                if (!is_array($r) || !isset($r['lat'], $r['lon'])) { continue; }
                $out[] = _geocode_result(
                    (string) $r['lat'], (string) $r['lon'],
                    (string) ($r['display_name'] ?? ''),
                    is_array($r['address'] ?? null) ? $r['address'] : []
                );
            }
            return $out;

        case 'photon':
            $feats = $decoded['features'] ?? null;
            if (!is_array($feats)) { return []; }
            $out = [];
            foreach ($feats as $f) {
                $coords = $f['geometry']['coordinates'] ?? null;
                if (!is_array($coords) || count($coords) < 2) { continue; }
                $pr = is_array($f['properties'] ?? null) ? $f['properties'] : [];
                $label = [];
                foreach (['name', 'street', 'city', 'state', 'country'] as $k) {
                    if (!empty($pr[$k])) { $label[] = (string) $pr[$k]; }
                }
                $out[] = _geocode_result(
                    (string) $coords[1], (string) $coords[0],
                    implode(', ', array_unique($label)),
                    [
                        'house_number' => (string) ($pr['housenumber'] ?? ''),
                        'road'         => (string) ($pr['street'] ?? ($pr['name'] ?? '')),
                        'city'         => (string) ($pr['city'] ?? ''),
                        'state'        => (string) ($pr['state'] ?? ''),
                        'postcode'     => (string) ($pr['postcode'] ?? ''),
                        'country'      => (string) ($pr['country'] ?? ''),
                        'country_code' => strtolower((string) ($pr['countrycode'] ?? '')),
                    ]
                );
            }
            return $out;

        case 'geoapify':
            // format=json gives a flat `results` array (not GeoJSON).
            $rows = $decoded['results'] ?? null;
            if (!is_array($rows)) { return []; }
            $out = [];
            foreach ($rows as $r) {
                if (!is_array($r) || !isset($r['lat'], $r['lon'])) { continue; }
                $iso = '';
                if (!empty($r['country_code']) && !empty($r['state_code'])) {
                    $iso = strtoupper((string) $r['country_code']) . '-' . strtoupper((string) $r['state_code']);
                }
                $out[] = _geocode_result(
                    (string) $r['lat'], (string) $r['lon'],
                    (string) ($r['formatted'] ?? ''),
                    [
                        'house_number'     => (string) ($r['housenumber'] ?? ''),
                        'road'             => (string) ($r['street'] ?? ''),
                        'city'             => (string) ($r['city'] ?? ''),
                        'state'            => (string) ($r['state'] ?? ''),
                        'postcode'         => (string) ($r['postcode'] ?? ''),
                        'suburb'           => (string) ($r['suburb'] ?? ($r['district'] ?? '')),
                        'country'          => (string) ($r['country'] ?? ''),
                        'country_code'     => strtolower((string) ($r['country_code'] ?? '')),
                        'ISO3166-2-lvl4'   => $iso,
                    ]
                );
            }
            return $out;

        case 'google':
            $rows = $decoded['results'] ?? null;
            if (!is_array($rows)) { return []; }
            $out = [];
            foreach ($rows as $r) {
                $loc = $r['geometry']['location'] ?? null;
                if (!is_array($loc) || !isset($loc['lat'], $loc['lng'])) { continue; }
                $out[] = _geocode_result(
                    (string) $loc['lat'], (string) $loc['lng'],
                    (string) ($r['formatted_address'] ?? ''),
                    _geocode_google_components(is_array($r['address_components'] ?? null)
                        ? $r['address_components'] : [])
                );
            }
            return $out;

        case 'here':
            $rows = $decoded['items'] ?? null;
            if (!is_array($rows)) { return []; }
            $out = [];
            foreach ($rows as $r) {
                $pos = $r['position'] ?? null;
                if (!is_array($pos) || !isset($pos['lat'], $pos['lng'])) { continue; }
                $a = is_array($r['address'] ?? null) ? $r['address'] : [];
                $iso = '';
                if (!empty($a['countryCode']) && !empty($a['stateCode'])) {
                    // HERE sends ISO-3166-1 alpha-3 for countryCode; the alpha-2
                    // form the forms expect is not derivable, so only emit the
                    // subdivision when we already have a 2-letter country.
                    $cc = (string) $a['countryCode'];
                    if (strlen($cc) === 2) {
                        $iso = strtoupper($cc) . '-' . strtoupper((string) $a['stateCode']);
                    }
                }
                $out[] = _geocode_result(
                    (string) $pos['lat'], (string) $pos['lng'],
                    (string) ($a['label'] ?? ($r['title'] ?? '')),
                    [
                        'house_number'   => (string) ($a['houseNumber'] ?? ''),
                        'road'           => (string) ($a['street'] ?? ''),
                        'city'           => (string) ($a['city'] ?? ''),
                        'state'          => (string) ($a['state'] ?? ''),
                        'postcode'       => (string) ($a['postalCode'] ?? ''),
                        'country'        => (string) ($a['countryName'] ?? ''),
                        'ISO3166-2-lvl4' => $iso,
                    ]
                );
            }
            return $out;
    }
    return [];
}

/**
 * Assemble one result in the NewUI shape, with every address key present.
 *
 * Every key exists (as '' when absent) on purpose: the call sites do
 * `addr.city || addr.town || addr.village`, and a missing key versus an empty
 * one is the difference between "this provider does not do neighbourhoods" and
 * "the adapter forgot". Absence is declared in policy['unsupported'], not
 * discovered by an empty box.
 */
function _geocode_result(string $lat, string $lon, string $display, array $addr): array
{
    // Every key any call site reads. `pedestrian`, `path` and `hamlet` are here
    // because incident-detail.js reads them as road/city fallbacks — they were
    // found by grepping the READ side, not assumed. Adding a key here without a
    // reader is harmless; omitting one a reader uses is the API↔JS contract bug
    // this project gates against (tools/api_contract_audit.php).
    $keys = ['house_number', 'road', 'pedestrian', 'path', 'city', 'town', 'village',
             'hamlet', 'state', 'postcode', 'neighbourhood', 'suburb', 'county',
             'country', 'country_code', 'ISO3166-2-lvl4'];
    $clean = [];
    foreach ($keys as $k) {
        $clean[$k] = isset($addr[$k]) ? (string) $addr[$k] : '';
    }
    return [
        'lat'          => $lat,
        'lon'          => $lon,
        'display_name' => $display,
        'address'      => $clean,
    ];
}

/** Google's address_components array → our flat address map. */
function _geocode_google_components(array $components): array
{
    $out = [];
    $state = '';
    $stateShort = '';
    $countryShort = '';
    foreach ($components as $c) {
        $types = (array) ($c['types'] ?? []);
        $long  = (string) ($c['long_name'] ?? '');
        $short = (string) ($c['short_name'] ?? '');
        if (in_array('street_number', $types, true))              { $out['house_number'] = $long; }
        if (in_array('route', $types, true))                      { $out['road'] = $long; }
        if (in_array('locality', $types, true))                   { $out['city'] = $long; }
        if (in_array('postal_town', $types, true) && empty($out['city'])) { $out['city'] = $long; }
        if (in_array('administrative_area_level_1', $types, true)) { $state = $long; $stateShort = $short; }
        if (in_array('administrative_area_level_2', $types, true)) { $out['county'] = $long; }
        if (in_array('postal_code', $types, true))                { $out['postcode'] = $long; }
        if (in_array('neighborhood', $types, true))               { $out['neighbourhood'] = $long; }
        if (in_array('sublocality', $types, true))                { $out['suburb'] = $long; }
        if (in_array('country', $types, true)) {
            $out['country'] = $long;
            $countryShort = $short;
            $out['country_code'] = strtolower($short);
        }
    }
    if ($state !== '')  { $out['state'] = $state; }
    if ($stateShort !== '' && $countryShort !== '') {
        $out['ISO3166-2-lvl4'] = strtoupper($countryShort) . '-' . strtoupper($stateShort);
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
// CACHE — required by Nominatim's policy, bounded by ours
// ─────────────────────────────────────────────────────────────────────────
//
// WHERE IT LIVES, AND WHY NOT UNDER cache/.
//
// The obvious home was cache/geocode, beside the weather cache. It is the
// wrong one, for the same reason the tile cache is not there: the documented
// install points the web root AT the application root, and both .htaccess and
// docs/nginx/ticketscad-hardening.conf list cache/ as a directory that stays
// reachable. A geocode cache under it would be the searchable address history
// of a dispatch centre, served to anyone, unauthenticated.
//
// So it goes ABOVE the web root, following BACKUP_DIR and TILE_CACHE_DIR. It
// needs no .htaccess, no nginx block and no IIS config: out of reach on any
// server in any configuration.
//
// It holds NO user identity. The key is a hash of (provider, action, query) —
// not of (user, query) — so the cache cannot answer "what did this dispatcher
// look up", only "has this address been looked up recently". Entries expire.

if (!defined('NEWUI_ROOT')) {
    define('NEWUI_ROOT', dirname(__DIR__));
}
// dirname(NEWUI_ROOT) alone is correct on POSIX and inside C:\inetpub\wwwroot
// on a stock Windows/IIS install — the same mistake GHSA-x9x6-w4fg-pmcc's
// round 2 made for zello-audio. served_dir_above_root() picks a
// platform-aware default instead; see inc/served-dir.php.
require_once __DIR__ . '/served-dir.php';
if (!defined('GEOCODE_CACHE_DIR')) {
    define('GEOCODE_CACHE_DIR', served_dir_above_root(NEWUI_ROOT, 'geocode-cache'));
}

/**
 * Root of the geocode cache — above the web root, platform-aware. See the
 * note above.
 *
 * The @mkdir() here is a FALLBACK, not the primary defense, and it cannot be
 * made to fully close the ownership race by itself: whichever process calls
 * this function FIRST becomes the directory's owner, and PHP has no way to
 * chgrp() the result to the web server's group unless the calling process
 * already belongs to that group (or is root) — see inc/install-permissions.php
 * for the full account of the your-server.example.com incident this guards
 * against. The real fix is provisioning: `tools/fix-permissions.php` (run on
 * every `tools/deploy.sh` deploy, and the documented shortcut for a
 * self-hosted admin) creates this directory owned by the web server BEFORE
 * either a real request or a CLI/SSH process can race to create it the wrong
 * way — see install_perm_targets()'s GEOCODE_CACHE_DIR entry.
 *
 * Mode 0775 (not 0700) matches INSTALL_PERM_MODE_WEB, the mode
 * tools/fix-permissions.php converges this directory to regardless of who
 * created it — an install that never runs that tool is no less private than
 * one that does, and the two paths agreeing means a later repair pass is a
 * no-op instead of unexpected churn. This directory holds no user identity
 * (see the header note above), and it is fenced against HTTP by
 * served_dir_harden() below in either case.
 */
function geocode_cache_dir(): string
{
    $dir = GEOCODE_CACHE_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    served_dir_harden($dir, 'Geocode lookup cache', true);
    return $dir;
}

/**
 * Cache key for one lookup. PURE, so the "same query, different spacing/case"
 * collapsing is testable.
 *
 * Deliberately excludes the API key and the user: two installs with different
 * keys still ask the same provider the same question, and the answer does not
 * depend on who asked.
 */
function geocode_cache_key(string $provider, string $action, array $params): string
{
    $norm = [
        'p' => $provider,
        'a' => $action,
        'q' => strtolower(geocode_clean_query((string) ($params['q'] ?? ''))),
        'lat' => isset($params['lat']) && is_numeric($params['lat'])
            ? number_format((float) $params['lat'], 5, '.', '') : '',
        'lon' => isset($params['lon']) && is_numeric($params['lon'])
            ? number_format((float) $params['lon'], 5, '.', '') : '',
        'l' => (int) ($params['limit'] ?? 1),
        'v' => geocode_clean_viewbox((string) ($params['viewbox'] ?? '')),
        'c' => geocode_clean_countrycodes((string) ($params['countrycodes'] ?? '')),
    ];
    return hash('sha256', json_encode($norm));
}

/** On-disk path for a cache key. The key is a hex hash, so no traversal. */
function geocode_cache_path(string $key): string
{
    $key = preg_replace('/[^a-f0-9]/', '', strtolower($key));
    if (strlen($key) < 8) {
        return '';
    }
    return geocode_cache_dir() . '/' . substr($key, 0, 2) . '/' . $key . '.json';
}

/**
 * Read a cached lookup.
 *
 * @return array{results:array,stored_at:int,fresh:bool}|null
 */
function geocode_cache_read(string $key, int $ttl, ?int $now = null): ?array
{
    $now  = $now ?? time();
    $path = geocode_cache_path($key);
    if ($path === '' || !is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['results']) || !is_array($j['results'])) {
        return null;
    }
    $storedAt = (int) ($j['stored_at'] ?? 0);
    return [
        'results'   => $j['results'],
        'stored_at' => $storedAt,
        // A STALE entry is still returned to the caller, which decides whether
        // to use it. Stale beats blank when upstream is unreachable — the same
        // judgement the tile proxy makes.
        'fresh'     => ($ttl > 0 && ($now - $storedAt) < $ttl),
    ];
}

/** Persist a lookup. Best effort: a cache we cannot write is not an error. */
function geocode_cache_write(string $key, array $results, int $maxEntries, ?int $now = null): bool
{
    $now  = $now ?? time();
    $path = geocode_cache_path($key);
    if ($path === '') {
        return false;
    }
    // Mode matches geocode_cache_dir()'s parent directory (0775, not 0700) —
    // see that function's docblock. A 0700 subdirectory under a 0775 parent
    // would deny the group access the parent just granted, which is exactly
    // the kind of inconsistency that reintroduces this bug one directory
    // level down.
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $ok = @file_put_contents(
        $path,
        json_encode(['stored_at' => $now, 'results' => $results]),
        LOCK_EX
    ) !== false;
    if ($ok) {
        geocode_cache_prune($maxEntries);
    }
    return $ok;
}

/**
 * PURE eviction plan: given (path => mtime) and a ceiling, decide what to
 * delete. Least-recently-written first, ties broken by path so the plan is
 * deterministic and a test cannot be flaky.
 *
 * @param array<string,int> $entries path => mtime
 * @return string[] paths to remove
 */
function geocode_cache_eviction_plan(array $entries, int $maxEntries): array
{
    $count = count($entries);
    if ($maxEntries <= 0 || $count <= $maxEntries) {
        return [];
    }
    uksort($entries, function ($a, $b) use ($entries) {
        $d = $entries[$a] <=> $entries[$b];
        return $d !== 0 ? $d : strcmp($a, $b);
    });
    // Evict down to 85% of the ceiling so a cache sitting exactly at the cap
    // does not re-sweep on every single write.
    $target = (int) ($maxEntries * 0.85);
    return array_slice(array_keys($entries), 0, max(0, $count - $target));
}

/** Walk the cache and enforce the entry ceiling. */
function geocode_cache_prune(int $maxEntries): int
{
    $dir = geocode_cache_dir();
    if ($maxEntries <= 0 || !@is_dir($dir)) {
        return 0;
    }
    $entries = [];
    foreach ((array) @glob($dir . '/*/*.json') as $f) {
        $entries[$f] = (int) @filemtime($f);
    }
    // Cheap guard: only pay the unlink loop when we are actually over.
    if (count($entries) <= $maxEntries) {
        return 0;
    }
    $removed = 0;
    foreach (geocode_cache_eviction_plan($entries, $maxEntries) as $path) {
        if (@unlink($path)) { $removed++; }
    }
    return $removed;
}

/**
 * Current cache size, for Settings and the health check.
 *
 * Deliberately does NOT call geocode_cache_dir() — that function creates the
 * directory on first call, and this one is read-only by contract. A status
 * page merely being viewed (Settings, or `php tools/check-health.php`, which
 * calls health_check_all() -> health_check_geocoding() -> this function) must
 * never itself be the "first caller" that wins the ownership race
 * geocode_cache_dir()'s own docblock describes — least of all the diagnostic
 * tool that exists to CATCH that exact problem. A directory that does not
 * exist yet simply has zero entries; report that, do not create anything.
 */
function geocode_cache_usage(): array
{
    $dir = defined('GEOCODE_CACHE_DIR') ? GEOCODE_CACHE_DIR : '';
    $files = ($dir !== '' && @is_dir($dir)) ? (array) @glob($dir . '/*/*.json') : [];
    $bytes = 0;
    foreach ($files as $f) {
        $bytes += (int) @filesize($f);
    }
    return ['entries' => count($files), 'bytes' => $bytes];
}

/** Delete every cached result. Exposed so an admin can clear address history. */
function geocode_cache_clear(): int
{
    $dir = geocode_cache_dir();
    $n = 0;
    foreach ((array) @glob($dir . '/*/*.json') as $f) {
        if (@unlink($f)) { $n++; }
    }
    return $n;
}

// ─────────────────────────────────────────────────────────────────────────
// THROTTLE + CIRCUIT BREAKER
// ─────────────────────────────────────────────────────────────────────────
//
// These are deliberately a separate, smaller implementation from the tile
// proxy's rather than a shared one. They guard a different consumer with a
// different cost model (one call per operator action, not forty per pan), and
// coupling geocoding to the tile cache directory would make a change to map
// behaviour able to alter address lookup. The shapes rhyme on purpose; the
// state does not overlap.

/**
 * Where throttle + breaker state live. Beside the cache, above the web root.
 *
 * Deliberately references GEOCODE_CACHE_DIR directly rather than calling
 * geocode_cache_dir() — that function creates the directory as a side
 * effect of being called at all, and this path is computed from READ
 * contexts too (geocode_breaker_read(), reached from health_check_geocoding()
 * -> health_check_all() -> `php tools/check-health.php`), which must never
 * itself win the ownership race by being the first process to touch the
 * cache tree. The WRITE side (geocode_throttle_claim(), geocode_breaker_write())
 * already does its own `@mkdir(..., true)` with recursive=true, which creates
 * GEOCODE_CACHE_DIR and .state together in one call when needed — so nothing
 * is lost on the path that is actually supposed to create it.
 */
function geocode_state_dir(): string
{
    return (defined('GEOCODE_CACHE_DIR') ? GEOCODE_CACHE_DIR : '') . '/.state';
}

/**
 * PURE throttle decision: given the last upstream call time and the minimum
 * interval, how long must this request wait?
 *
 * @return int milliseconds to wait; 0 = go now
 */
function geocode_throttle_wait_ms(int $lastCallMs, int $nowMs, int $intervalMs): int
{
    if ($intervalMs <= 0 || $lastCallMs <= 0) {
        return 0;
    }
    $elapsed = $nowMs - $lastCallMs;
    if ($elapsed < 0) {
        // Clock went backwards. Treat as "just called" rather than trusting it.
        return $intervalMs;
    }
    return $elapsed >= $intervalMs ? 0 : ($intervalMs - $elapsed);
}

/** Claim the next upstream slot. @return int ms the caller must sleep, or -1 to refuse */
function geocode_throttle_claim(string $provider, int $intervalMs, ?int $nowMs = null): int
{
    if ($intervalMs <= 0) {
        return 0;
    }
    $nowMs = $nowMs ?? (int) round(microtime(true) * 1000);
    $dir = geocode_state_dir();
    // Mode matches geocode_cache_dir()'s parent (0775, not 0700) — see that
    // function's docblock. A 0700 subdirectory under a 0775 parent denies the
    // group access the parent just granted.
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return 0;   // cannot track it — better to proceed than to block forever
    }
    $path = $dir . '/' . preg_replace('/[^a-z0-9_]/', '', $provider) . '.throttle';
    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        return 0;
    }
    $wait = 0;
    if (@flock($fh, LOCK_EX)) {
        $raw  = (string) stream_get_contents($fh);
        $last = (int) trim($raw);
        $wait = geocode_throttle_wait_ms($last, $nowMs, $intervalMs);
        if ($wait > GEOCODE_THROTTLE_MAX_WAIT_MS) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
            return -1;
        }
        // Reserve our slot at the moment we will actually call.
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) ($nowMs + $wait));
        fflush($fh);
        @flock($fh, LOCK_UN);
    }
    @fclose($fh);
    return $wait;
}

/**
 * Is this upstream response evidence that the PROVIDER is down, as opposed to
 * evidence that one address does not exist?
 *
 * PURE. A 404 or an empty result set is a healthy provider telling the truth,
 * and counting it would open the breaker for everyone the first time a
 * dispatcher mistyped a street name.
 */
function geocode_upstream_is_down(int $status, string $error): bool
{
    if ($status === 0) return true;              // connect/read timeout, DNS, TLS
    if ($error !== '') return true;              // transport said something went wrong
    if ($status === 429) return true;            // rate-limited: backing off IS the fix
    return $status >= 500 && $status <= 599;
}

/**
 * PURE breaker decision. Same three states as the tile proxy's, expressed the
 * same way so a reader of one recognises the other.
 *
 * @return array{open:bool,half_open:bool,retry_in:int,fails:int,reason:string}
 */
function geocode_breaker_decide(
    array $state,
    int $now,
    int $threshold = GEOCODE_BREAKER_THRESHOLD,
    int $cooloff = GEOCODE_BREAKER_COOLOFF
): array {
    $fails    = max(0, (int) ($state['fails'] ?? 0));
    $openedAt = (int) ($state['opened_at'] ?? 0);
    $lastErr  = (string) ($state['last_error'] ?? '');

    if ($threshold <= 0 || $fails < $threshold) {
        return ['open' => false, 'half_open' => false, 'retry_in' => 0,
                'fails' => $fails, 'reason' => ''];
    }
    $elapsed = $now - $openedAt;
    if ($openedAt > 0 && $elapsed < $cooloff) {
        return ['open' => true, 'half_open' => false,
                'retry_in' => max(1, $cooloff - $elapsed), 'fails' => $fails,
                'reason' => 'the geocoder has not answered ' . $fails . ' times in a row'
                          . ($lastErr !== '' ? ' (' . $lastErr . ')' : '')];
    }
    return ['open' => false, 'half_open' => true, 'retry_in' => 0,
            'fails' => $fails, 'reason' => 'cool-off elapsed — probing upstream'];
}

function geocode_breaker_path(string $provider): string
{
    return geocode_state_dir() . '/' . preg_replace('/[^a-z0-9_]/', '', $provider) . '.breaker';
}

function geocode_breaker_read(string $provider): array
{
    $p = geocode_breaker_path($provider);
    $empty = ['fails' => 0, 'opened_at' => 0, 'last_error' => '', 'last_fail_at' => 0];
    if (!is_file($p)) return $empty;
    $raw = @file_get_contents($p);
    $st  = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    if (!is_array($st)) return $empty;
    return [
        'fails'        => max(0, (int) ($st['fails'] ?? 0)),
        'opened_at'    => (int) ($st['opened_at'] ?? 0),
        'last_error'   => substr((string) ($st['last_error'] ?? ''), 0, 180),
        'last_fail_at' => (int) ($st['last_fail_at'] ?? 0),
    ];
}

function geocode_breaker_write(string $provider, array $state): bool
{
    $dir = geocode_state_dir();
    // Mode matches geocode_cache_dir()'s parent (0775, not 0700) — see the
    // note in geocode_throttle_claim() above.
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
    return @file_put_contents(geocode_breaker_path($provider), json_encode($state), LOCK_EX) !== false;
}

/** Request-path gate; re-arms the half-open window so only one request probes. */
function geocode_breaker_check(string $provider, ?int $now = null): array
{
    $now = $now ?? time();
    $st  = geocode_breaker_read($provider);
    $d   = geocode_breaker_decide($st, $now);
    if ($d['half_open']) {
        $st['opened_at'] = $now;
        geocode_breaker_write($provider, $st);
    }
    return $d;
}

function geocode_breaker_record_failure(string $provider, string $error, ?int $now = null): void
{
    $now = $now ?? time();
    $st  = geocode_breaker_read($provider);
    $st['fails']        = $st['fails'] + 1;
    $st['last_error']   = substr((string) preg_replace('/[^\x20-\x7E]/', '', $error), 0, 180);
    $st['last_fail_at'] = $now;
    if ($st['fails'] >= GEOCODE_BREAKER_THRESHOLD && (int) $st['opened_at'] === 0) {
        $st['opened_at'] = $now;
    }
    geocode_breaker_write($provider, $st);
}

function geocode_breaker_record_success(string $provider): void
{
    $st = geocode_breaker_read($provider);
    if ($st['fails'] === 0 && (int) $st['opened_at'] === 0) return;
    geocode_breaker_write($provider, ['fails' => 0, 'opened_at' => 0,
                                      'last_error' => '', 'last_fail_at' => 0]);
}

function geocode_breaker_reset(string $provider): void
{
    @unlink(geocode_breaker_path($provider));
}

// ─────────────────────────────────────────────────────────────────────────
// UPSTREAM FETCH
// ─────────────────────────────────────────────────────────────────────────

/**
 * Minimal HTTP GET for geocoding.
 *
 * Lives here rather than in the endpoint so tests drive the REAL fetch path
 * instead of a stand-in — a test that exercises a re-implementation of the
 * thing it guards is a failure mode this project keeps hitting.
 *
 * @return array{status:int,body:string,error:string}
 */
function geocode_http_get(string $url, array $headers): array
{
    $out = ['status' => 0, 'body' => '', 'error' => ''];

    // Refuse anything that is not a plain http(s) URL, even here. Every caller
    // today passes a server-built URL; this means a future caller cannot turn
    // this into a general fetcher by accident.
    if (geocode_sanitize_url($url) === '') {
        $out['error'] = 'refused non-http(s) url';
        return $out;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_CONNECTTIMEOUT  => GEOCODE_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT         => GEOCODE_READ_TIMEOUT,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_BUFFERSIZE      => 16384,
            CURLOPT_NOPROGRESS      => false,
            // Stop reading a response that is far larger than any geocode
            // reply, rather than buffering whatever a hostile or broken
            // upstream decides to send.
            CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) {
                return ($dlNow > GEOCODE_MAX_BYTES) ? 1 : 0;
            },
        ]);
        $body = curl_exec($ch);
        $out['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false) {
            $out['error'] = curl_error($ch);
        } else {
            $out['body'] = substr((string) $body, 0, GEOCODE_MAX_BYTES);
        }
        curl_close($ch);
        return $out;
    }

    // No cURL — stream wrapper fallback.
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => GEOCODE_READ_TIMEOUT,
        'ignore_errors' => true,
        'max_redirects' => 3,
        'header'        => implode("\r\n", $headers),
    ]]);
    $body = @file_get_contents($url, false, $ctx, 0, GEOCODE_MAX_BYTES);
    if ($body === false) {
        $out['error'] = 'request failed';
        return $out;
    }
    $out['body'] = $body;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $out['status'] = (int) $m[1];
        }
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
// ORCHESTRATION
// ─────────────────────────────────────────────────────────────────────────

/**
 * Perform one geocoding lookup, end to end.
 *
 * Lives here, not in api/geocode.php, so a regression test drives the REAL
 * production path — cache, throttle, breaker, fetch, normalisation — rather
 * than a copy of it. (inc/unit_owntracks.php exists for the same reason; see
 * the OT_CONFIG_LIBRARY_ONLY pitfall in CLAUDE.md.)
 *
 * ORDER MATTERS, and the order is: cache → breaker → throttle → network.
 * A cached answer is served even when the breaker is open, because a cached
 * answer costs nothing and is exactly what an operator needs during an outage.
 *
 * @param string $action 'search' | 'reverse'
 * @param array  $params q | lat+lon, plus optional limit/viewbox/countrycodes
 * @param array|null $settings override for tests; defaults to geocode_settings()
 * @return array{ok:bool,results:array,provider:string,source:string,error:string,message:string,retry_in:int,unsupported:array}
 */
function geocode_lookup(string $action, array $params, ?array $settings = null): array
{
    $settings = $settings ?? geocode_settings();
    $provider = (string) $settings['provider'];
    $policy   = geocode_policy()[$provider] ?? null;

    $fail = function (string $err, string $msg, int $retryIn = 0) use ($provider, $policy) {
        return ['ok' => false, 'results' => [], 'provider' => $provider, 'source' => 'none',
                'error' => $err, 'message' => $msg, 'retry_in' => $retryIn,
                'unsupported' => (array) ($policy['unsupported'] ?? [])];
    };

    if ($policy === null) {
        return $fail('bad_provider', 'No geocoding provider is configured.');
    }
    if ($settings['mode'] === 'off') {
        return $fail('disabled', 'Address lookup is turned off on this system.');
    }
    if ($action !== 'search' && $action !== 'reverse') {
        return $fail('bad_request', 'Unsupported lookup type.');
    }
    if (!empty($policy['needs_key']) && trim((string) $settings['api_key']) === '') {
        return $fail('not_configured',
            $policy['label'] . ' needs an API key, and none is saved in Settings → API Keys.');
    }
    if (!empty($policy['needs_url']) && geocode_base_url((string) $settings['url']) === '') {
        return $fail('not_configured',
            $policy['label'] . ' needs the address of your own server, and none is saved in '
            . 'Settings → API Keys → Geocoding.');
    }

    $url = geocode_build_url($provider, $action, $params, $settings);
    if ($url === null) {
        return $fail('bad_request', 'That lookup could not be built — check the address or coordinates.');
    }

    $unsupported = (array) ($policy['unsupported'] ?? []);
    $key    = geocode_cache_key($provider, $action, $params);
    $cached = geocode_cache_read($key, (int) $settings['cache_ttl']);

    if ($cached !== null && $cached['fresh']) {
        return ['ok' => true, 'results' => $cached['results'], 'provider' => $provider,
                'source' => 'cache', 'error' => '', 'message' => '', 'retry_in' => 0,
                'unsupported' => $unsupported];
    }

    // Breaker: once upstream is known unreachable, stop paying the timeout.
    $breaker = geocode_breaker_check($provider);
    if ($breaker['open']) {
        if ($cached !== null) {
            // Stale beats blank.
            return ['ok' => true, 'results' => $cached['results'], 'provider' => $provider,
                    'source' => 'cache_stale', 'error' => '', 'message' => '',
                    'retry_in' => $breaker['retry_in'], 'unsupported' => $unsupported];
        }
        return $fail('upstream_down',
            'Address lookup is unavailable — ' . $breaker['reason'] . '. '
            . 'Retrying in ' . $breaker['retry_in'] . 's. You can still place the pin on the map.',
            $breaker['retry_in']);
    }

    // Throttle: the provider's own published rule, not our preference.
    $wait = geocode_throttle_claim($provider, (int) $settings['throttle_ms']);
    if ($wait < 0) {
        if ($cached !== null) {
            return ['ok' => true, 'results' => $cached['results'], 'provider' => $provider,
                    'source' => 'cache_stale', 'error' => '', 'message' => '', 'retry_in' => 1,
                    'unsupported' => $unsupported];
        }
        return $fail('busy',
            'Address lookup is busy — the geocoder allows a limited number of requests per second. '
            . 'Try again in a moment.', 1);
    }
    if ($wait > 0) {
        usleep($wait * 1000);
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $resp = geocode_http_get($url, [
        'User-Agent: ' . geocode_user_agent((string) $settings['user_agent'], $host),
        'Accept: application/json',
        'Accept-Language: en',
    ]);

    if (geocode_upstream_is_down($resp['status'], $resp['error'])) {
        geocode_breaker_record_failure($provider,
            $resp['error'] !== '' ? $resp['error'] : ('HTTP ' . $resp['status']));
        if ($cached !== null) {
            return ['ok' => true, 'results' => $cached['results'], 'provider' => $provider,
                    'source' => 'cache_stale', 'error' => '', 'message' => '', 'retry_in' => 0,
                    'unsupported' => $unsupported];
        }
        return $fail('upstream_down',
            'The address lookup service did not answer. You can still place the pin on the map.');
    }
    geocode_breaker_record_success($provider);

    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        // A 4xx is upstream answering — a bad key, a bad query, a quota. Not an
        // outage, so it does not touch the breaker, but it is not a result either.
        return $fail('upstream_error',
            'The address lookup service refused the request (HTTP ' . $resp['status'] . '). '
            . ($resp['status'] === 401 || $resp['status'] === 403
                ? 'Check the API key in Settings → API Keys.' : 'Try again shortly.'));
    }

    $decoded = json_decode($resp['body'], true);
    if ($decoded === null && trim($resp['body']) !== 'null') {
        return $fail('bad_response', 'The address lookup service sent something unreadable.');
    }

    $results = geocode_normalize($provider, $action, $decoded);

    // An empty result is a real, cacheable answer ("no such address") — caching
    // it is what stops a mistyped street being re-asked upstream on every retry.
    geocode_cache_write($key, $results, (int) $settings['max_entries']);

    return ['ok' => true, 'results' => $results, 'provider' => $provider, 'source' => 'upstream',
            'error' => '', 'message' => '', 'retry_in' => 0, 'unsupported' => $unsupported];
}

/**
 * The client-facing configuration: what the browser needs to know before it
 * builds its first lookup.
 *
 * Injected SYNCHRONOUSLY by inc/navbar.php as window.GEOCODING. An async
 * answer would be too late in the same way it was for tiles: the first Lookup
 * a dispatcher presses would already have gone somewhere.
 *
 * In `direct` mode this carries the provider's BASE URL (built server-side and
 * validated), never a template the browser could be tricked into rewriting,
 * and never an API key — keyed providers are not direct-capable at all.
 *
 * @return array<string,mixed>
 */
function geocode_client_config(?array $settings = null): array
{
    $settings = $settings ?? geocode_settings();
    $provider = (string) $settings['provider'];
    $eff      = geocode_effective_mode((string) $settings['mode'], $provider);
    $policy   = geocode_policy()[$provider] ?? [];

    $out = [
        'mode'        => $eff['mode'],
        'requested'   => $eff['requested'],
        'reason'      => $eff['reason'],
        'provider'    => $provider,
        'label'       => (string) ($policy['label'] ?? $provider),
        'endpoint'    => 'api/geocode.php',
        'direct_base' => '',
        'unsupported' => (array) ($policy['unsupported'] ?? []),
    ];

    if ($eff['mode'] === 'direct') {
        $out['direct_base'] = ($provider === 'nominatim')
            ? 'https://nominatim.openstreetmap.org'
            : geocode_base_url((string) $settings['url']);
        // A self-hosted base that will not validate leaves direct mode with
        // nowhere to go. Say so rather than emitting a broken config.
        if ($out['direct_base'] === '') {
            $out['mode']   = 'server';
            $out['reason'] = 'No usable address is saved for your own geocoding server, so lookups '
                           . 'go through this server instead.';
        }
    }
    return $out;
}

/**
 * Hosts the BROWSER must be allowed to contact for geocoding, for CSP.
 *
 * Returns [] in server and off mode — which is the point. Under the shipped
 * default the browser has no business talking to a geocoder at all, and
 * connect-src saying so turns a twelfth hardcoded call site into a visible
 * failure on every install rather than a silent leak on all of them.
 *
 * @return string[] scheme://host[:port] entries
 */
function geocode_csp_connect_hosts(?array $settings = null): array
{
    try {
        $cfg = geocode_client_config($settings);
    } catch (Throwable $e) {
        // Never let a CSP builder throw. Fall back to the historical allowance
        // so a misconfiguration degrades to "as before", not to a blank map.
        return ['https://nominatim.openstreetmap.org'];
    }
    if ($cfg['mode'] !== 'direct' || $cfg['direct_base'] === '') {
        return [];
    }
    $parts = parse_url($cfg['direct_base']);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return [];
    }
    $origin = strtolower($parts['scheme']) . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }
    return [$origin];
}
