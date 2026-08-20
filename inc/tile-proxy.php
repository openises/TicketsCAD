<?php
/**
 * inc/tile-proxy.php — server-side map tile proxy: policy, URL construction, cache.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 *
 * Every tile a dispatcher's browser requests tells the tile provider which
 * patch of ground that dispatcher is looking at — continuously, for a whole
 * shift. On a CAD screen, "which patch of ground" is "where the incident is".
 * That is the exposure `tile_mode = proxy` was supposed to close.
 *
 * It never did. From the Phase 41 commit (514ffe0, "tile proxy default") until
 * this file existed, `tile_mode` was written by three code paths, surfaced by
 * api/map-config.php, echoed back out of resolve_tile_config()… and read by
 * nothing. `git log --all -S tile_mode -- assets/` is empty: no JS consumer was
 * ever written, so none was removed. The Settings help text promised server
 * caching, key hiding and outage tolerance; all three were false. Two shipped
 * docs told admins to switch modes to fix a problem the switch could not touch.
 * The spec even wrote the escape hatch down —
 * specs/configurable-tile-providers-2026-06/plan.md: honour proxy mode
 * "where a proxy exists" — and a proxy never existed, so the clause was
 * vacuously true and the feature fell through it.
 *
 * ── THE CONSTRAINT THAT SHAPES THE DESIGN ────────────────────────────────
 *
 * Proxying is NOT universally permitted. Some providers' terms allow a
 * self-hosted proxy with caching; others prohibit caching or re-serving tiles
 * outside their own SDKs. So this is deliberately NOT a general relay: each
 * provider carries an explicit verdict, the proxy REFUSES the ones whose terms
 * forbid it, and those fall back to direct browser fetch. Getting a volunteer
 * fire department into a terms violation on its own behalf would be a worse
 * outcome than the privacy gap we are closing. See tile_proxy_policy() for the
 * per-provider verdicts and the sources they rest on.
 *
 * ── SSRF ─────────────────────────────────────────────────────────────────
 *
 * This endpoint NEVER accepts a URL from the client. The client sends a
 * provider IDENTIFIER plus integer z/x/y; the upstream URL is built here from
 * a template this file hardcodes (or, for 'custom', from the admin-configured
 * setting). Even the {s} subdomain is chosen server-side from the tile
 * coordinates, so no part of the hostname is client-influenced. An endpoint
 * that fetched a client-supplied URL would be an open proxy sitting inside a
 * dispatch centre.
 *
 * ── DISK ─────────────────────────────────────────────────────────────────
 *
 * A tile cache is an unbounded-growth machine pointed at a dispatch server's
 * disk. api/weather-proxy.php — the prior art this borrows its shape from —
 * caches with no size cap, no eviction and no free-space check. This one has
 * all three: a configurable maximum, LRU eviction, and a free-space reserve
 * that stops writing (but keeps serving) before the volume fills.
 *
 * Pure decision functions (policy, z/x/y bounds, space verdict, eviction
 * planning) are separated from the filesystem so they are testable without a
 * full disk or a live provider. tests/test_tile_proxy.php drives these.
 */

if (!function_exists('newui_version')) {
    require_once __DIR__ . '/version.php';
}

// ── Defaults (all admin-overridable via `settings`) ──────────────────────

/** Cache ceiling, MB. Beyond this, least-recently-used tiles are evicted. */
const TILE_CACHE_DEFAULT_MAX_MB = 512;

/** Free space that must REMAIN on the volume after a cache write, MB. */
const TILE_CACHE_DEFAULT_MIN_FREE_MB = 1024;

/** Max age for a cached tile when upstream does not say, days. */
const TILE_CACHE_DEFAULT_DAYS = 30;

/** Floor on cached-tile lifetime regardless of what upstream says, seconds. */
const TILE_CACHE_MIN_TTL = 3600;

/**
 * Connect / total transfer timeouts for an upstream tile fetch, seconds.
 *
 * These are deliberately SMALL, and the reason is arithmetic rather than
 * taste. A tile fetch is not one call: a 1920x1080 map viewport is about 40
 * tiles, so every second of timeout here is forty worker-seconds per pan.
 * Measured on 2026-07-31 against 203.0.113.1 (RFC 5737, black-holed), the
 * original 5s connect cost 5.02s per tile — ~200 worker-seconds for one pan
 * over uncached ground, enough for a handful of dispatchers to exhaust a
 * small server's ~150 request slots during the outage that made the map
 * matter.
 *
 * A tile is tens of kilobytes from a CDN edge. 2s to connect and 6s in total
 * is generous for the working case and an order of magnitude cheaper for the
 * failing one. The circuit breaker below is what stops the second pan paying
 * anything at all.
 */
const TILE_PROXY_CONNECT_TIMEOUT = 2;
const TILE_PROXY_READ_TIMEOUT    = 6;

/** Largest upstream tile we will accept. A tile is tens of KB; 4 MB is absurd. */
const TILE_PROXY_MAX_BYTES = 4194304;

/** Refresh an LRU mtime at most this often, to avoid a write per cache hit. */
const TILE_CACHE_TOUCH_INTERVAL = 3600;

// ── Circuit breaker + negative caching ───────────────────────────────────
//
// Per-call timeouts bound ONE tile. They do nothing about the aggregate, and
// the aggregate is the hazard: a failed tile was previously served with
// `Cache-Control: no-store`, so the browser re-requested the same dead tiles
// on every pan and the full cost was paid again, for the whole duration of
// the outage.
//
// Two cheap mechanisms fix that, and they are deliberately different in
// scope:
//
//   * The BREAKER is per provider and lives on the server. Once upstream has
//     failed at the transport level TILE_BREAKER_THRESHOLD times in a row,
//     no tile request touches the network for TILE_BREAKER_COOLOFF seconds —
//     they fail (or serve stale) immediately. This protects the server from
//     its own clients, including a client that has just been restarted.
//   * The NEGATIVE CACHE is per tile and lives in the browser: the blank
//     tile is served with a short max-age so a pan back over the same ground
//     does not even reach us. This protects the server from repetition.
//
// A 404 is NOT a breaker failure. "That tile does not exist" means upstream
// answered, and treating it as an outage would open the breaker on a
// perfectly healthy provider whenever a dispatcher zoomed past its coverage.
// Only a transport failure, a 5xx or a 429 counts. See tile_upstream_is_down().

/** Consecutive transport failures before the breaker opens. */
const TILE_BREAKER_THRESHOLD = 3;

/** How long the breaker stays open before letting one request probe, seconds. */
const TILE_BREAKER_COOLOFF = 60;

/** How long a browser may reuse a failed (blank) tile, seconds. */
const TILE_FAIL_MAX_AGE = 60;

// ─────────────────────────────────────────────────────────────────────────
// PROVIDER POLICY
// ─────────────────────────────────────────────────────────────────────────

/**
 * Per-provider proxy policy.
 *
 * `proxy` is the ONLY thing that decides whether this server will fetch a
 * provider's tiles on a browser's behalf. It reflects that provider's terms of
 * service, not our convenience. Where a provider forbids proxying/caching, the
 * answer is false and the map falls back to direct browser fetch — the privacy
 * gap stays open for that provider, visibly and on purpose, because the
 * alternative is breaching someone's terms on an agency's behalf.
 *
 * Verdicts were researched against the providers' live policy pages on
 * 2026-07-31; each entry records the URL it rests on. Terms move — OSMF's
 * policy page carries NO last-updated marker, so drift cannot be detected by
 * checking a date. Re-read the sources when touching this table.
 *
 * Fields:
 *   proxy       bool    may this server fetch + re-serve these tiles?
 *   reason      string  plain-language justification, shown in Settings.
 *   source      string  where the verdict came from (recorded so a future
 *                       maintainer can re-check when terms change).
 *   caveat      string  '' when the terms are explicit. Non-empty when the
 *                       verdict rests on silence or analogy rather than a
 *                       written grant — surfaced in Settings so an admin can
 *                       make their own call instead of inheriting ours.
 *   url         string  upstream template. {z}/{x}/{y} always; {s} subdomain
 *                       and {q} quadkey resolved server-side; {key} from
 *                       settings, never from the client.
 *   subdomains  string  characters to rotate through for {s}, or '' if none.
 *   max_zoom    int     hard upper bound on z accepted for this provider.
 *   attribution string  required attribution text (rendered client-side in
 *                       BOTH modes — proxying never removes the obligation).
 *
 * @return array<string,array{proxy:bool,reason:string,source:string,caveat:string,url:string,subdomains:string,max_zoom:int,attribution:string}>
 */
function tile_proxy_policy(): array
{
    return [
        // ── Permitted: community / public-sector / self-hosted ──────────
        'osm' => [
            'proxy' => true,
            'reason' => 'The OSM Foundation tile policy has a section for caching proxies: permitted, '
                      . 'though they do not recommend it, provided you cache, honour their cache '
                      . 'headers, send a contactable User-Agent rather than hiding behind a generic '
                      . 'one, and never pre-fetch tiles beyond what a user is actively viewing. '
                      . 'This proxy does all four.',
            'source' => 'https://operations.osmfoundation.org/policies/tiles/',
            'caveat' => '',
            'url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'subdomains' => '', 'max_zoom' => 19,
            'attribution' => '&copy; OpenStreetMap contributors',
        ],
        'osm_hot' => [
            'proxy' => true,
            'reason' => 'The Humanitarian layer is rendered and served by OpenStreetMap France. The '
                      . 'map data and rendering are CC-BY-SA, which does permit redistribution with '
                      . 'attribution, and caching reduces load on their donated infrastructure '
                      . 'rather than adding to it.',
            'source' => 'https://wiki.openstreetmap.org/wiki/Humanitarian_map_style',
            'caveat' => 'OpenStreetMap France publishes no tile usage policy for this server, so this '
                      . 'verdict applies the OSM Foundation\'s rules by analogy rather than resting on '
                      . 'a written grant. It runs on a small volunteer association\'s donated hardware; '
                      . 'keep use modest.',
            'url' => 'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
            'subdomains' => 'abc', 'max_zoom' => 19,
            'attribution' => '&copy; OpenStreetMap contributors, Tiles courtesy of Humanitarian OSM Team',
        ],
        'opentopomap' => [
            'proxy' => true,
            'reason' => 'OpenTopoMap render tiles are published under CC-BY-SA, which permits '
                      . 'redistribution with attribution. The project says heavy use is fine so long '
                      . 'as mass downloads do not strain their server — caching serves that interest.',
            'source' => 'https://opentopomap.org/about',
            'caveat' => 'A small volunteer project with no uptime guarantee and no published rate '
                      . 'limits. They ask to be told about significant use — worth an email if this '
                      . 'install runs busy.',
            'url' => 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
            'subdomains' => 'abc', 'max_zoom' => 17,
            'attribution' => 'Map data &copy; OpenStreetMap contributors, SRTM | Map style &copy; OpenTopoMap (CC-BY-SA)',
        ],
        'usgs_topo' => [
            'proxy' => true,
            'reason' => 'US Geological Survey National Map basemaps are US federal government works in '
                      . 'the public domain. The live service metadata declares no access constraints, '
                      . 'no terms of use and no usage limits. The safest provider here on both legal '
                      . 'and operational grounds: no commercial party can revoke access mid-incident.',
            'source' => 'https://www.usgs.gov/information-policies-and-instructions/copyrights-and-credits',
            'caveat' => '',
            'url' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}',
            'subdomains' => '', 'max_zoom' => 20,
            'attribution' => 'USGS The National Map',
        ],
        'usgs_imagery' => [
            'proxy' => true,
            'reason' => 'US federal government work, public domain — see usgs_topo.',
            'source' => 'https://www.usgs.gov/information-policies-and-instructions/copyrights-and-credits',
            'caveat' => '',
            'url' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryOnly/MapServer/tile/{z}/{y}/{x}',
            'subdomains' => '', 'max_zoom' => 20,
            'attribution' => 'USGS The National Map',
        ],
        'usgs_imagery_topo' => [
            'proxy' => true,
            'reason' => 'US federal government work, public domain — see usgs_topo.',
            'source' => 'https://www.usgs.gov/information-policies-and-instructions/copyrights-and-credits',
            'caveat' => '',
            'url' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryTopo/MapServer/tile/{z}/{y}/{x}',
            'subdomains' => '', 'max_zoom' => 20,
            'attribution' => 'USGS The National Map',
        ],
        'custom' => [
            // The admin's own tile server, or one their agency is entitled to
            // use. Only they can know its terms, so this is the one entry whose
            // verdict is a delegation rather than a finding. The URL still comes
            // from the SETTING, never from the client.
            'proxy' => true,
            'reason' => 'A tile server you configured. If it is your own or your agency\'s, proxying is '
                      . 'entirely your call and there is nothing to violate.',
            'source' => 'admin-configured (tile_server_url)',
            'caveat' => 'TicketsCAD cannot check the terms of a URL you supply. If you pointed this at '
                      . 'a commercial provider, confirm that provider permits server-side proxying and '
                      . 'caching before leaving proxy mode on.',
            'url' => '', 'subdomains' => 'abc', 'max_zoom' => 22,
            'attribution' => '',
        ],

        // ── Refused: terms prohibit proxying / caching / re-serving ──────
        'cartodb_positron' => [
            'proxy' => false,
            'reason' => 'CARTO scopes free basemap use to "CARTO grantees" and directs commercial use '
                      . 'to an Enterprise licence; nothing in the reachable terms grants a right to '
                      . 're-serve their tiles from your own server. Fetched directly by the browser.',
            'source' => 'https://docs.carto.com/faqs/carto-basemaps',
            'caveat' => 'CARTO\'s authoritative Basemap Terms of Service could not be read (the legal '
                      . 'page redirects to an unparseable PDF), so this is a refusal on absence of a '
                      . 'grant rather than on an explicit prohibition.',
            'url' => 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
            'subdomains' => 'abcd', 'max_zoom' => 19,
            'attribution' => '&copy; OpenStreetMap contributors &copy; CARTO',
        ],
        'cartodb_dark' => [
            'proxy' => false,
            'reason' => 'CARTO basemap — see cartodb_positron. Fetched directly by the browser.',
            'source' => 'https://docs.carto.com/faqs/carto-basemaps',
            'caveat' => 'CARTO\'s authoritative Basemap Terms of Service could not be read; refusal '
                      . 'rests on the absence of a grant rather than an explicit prohibition.',
            'url' => 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
            'subdomains' => 'abcd', 'max_zoom' => 19,
            'attribution' => '&copy; OpenStreetMap contributors &copy; CARTO',
        ],
        'esri_street' => [
            'proxy' => false,
            'reason' => 'Esri\'s Master Agreement bars customers from scraping, downloading or storing '
                      . 'Data, and from offering Data on behalf of a third party — which is what a '
                      . 'proxy does for its users. Its caching permission is narrow and named to a '
                      . 'specific Esri product. Fetched directly by the browser.',
            'source' => 'https://www.arcgis.com/home/termsofuse.html',
            'caveat' => '',
            'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
            'subdomains' => '', 'max_zoom' => 19,
            'attribution' => 'Tiles &copy; Esri',
        ],
        'esri_sat' => [
            'proxy' => false,
            'reason' => 'Esri ArcGIS Online basemap — see esri_street. Fetched directly by the browser.',
            'source' => 'https://www.arcgis.com/home/termsofuse.html',
            'caveat' => '',
            'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            'subdomains' => '', 'max_zoom' => 19,
            'attribution' => 'Tiles &copy; Esri',
        ],
        'esri_topo' => [
            'proxy' => false,
            'reason' => 'Esri ArcGIS Online basemap — see esri_street. Fetched directly by the browser.',
            'source' => 'https://www.arcgis.com/home/termsofuse.html',
            'caveat' => '',
            'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',
            'subdomains' => '', 'max_zoom' => 19,
            'attribution' => 'Tiles &copy; Esri',
        ],
        'mapbox' => [
            'proxy' => false,
            'reason' => 'The Mapbox Product Terms name both mechanisms this feature would use — they '
                      . 'forbid distributing map content from a cache and forbid proxying it. Caching '
                      . 'is permitted only on the end user\'s own device, via their SDKs. No tier, '
                      . 'free or paid, allows a server-side proxy. Fetched directly by the browser.',
            'source' => 'https://www.mapbox.com/legal/product-terms',
            'caveat' => '',
            'url' => 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/{z}/{x}/{y}?access_token={key}',
            'subdomains' => '', 'max_zoom' => 22,
            'attribution' => '&copy; Mapbox &copy; OpenStreetMap contributors',
        ],
        'google_street' => [
            'proxy' => false,
            'reason' => 'Google Maps Platform terms prohibit caching map content outside a few named '
                      . 'exceptions that do not include tiles, prohibit scraping content for use '
                      . 'outside their services, and prohibit using their content alongside a '
                      . 'non-Google map. Fetched directly by the browser.',
            'source' => 'https://developers.google.com/maps/documentation/tile/policies',
            'caveat' => 'These mt.google.com URLs are undocumented internal endpoints — there is no '
                      . 'API key, no accepted terms and no licence covering them at all, so this is '
                      . 'unlicensed access rather than a caching question. Direct mode does not make '
                      . 'it licensed. Separately, Google content may not be shown alongside a '
                      . 'non-Google map, and TicketsCAD renders in Leaflet. Migrate to another provider.',
            'url' => 'https://mt{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}',
            'subdomains' => '0123', 'max_zoom' => 20,
            'attribution' => '&copy; Google',
        ],
        'google_sat' => [
            'proxy' => false,
            'reason' => 'Google Maps Platform terms — see google_street. Fetched directly by the browser.',
            'source' => 'https://developers.google.com/maps/documentation/tile/policies',
            'caveat' => 'Undocumented, unlicensed endpoint used alongside a non-Google map — see '
                      . 'google_street. Migrate to another provider.',
            'url' => 'https://mt{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}',
            'subdomains' => '0123', 'max_zoom' => 20,
            'attribution' => '&copy; Google',
        ],
        'google_hybrid' => [
            'proxy' => false,
            'reason' => 'Google Maps Platform terms — see google_street. Fetched directly by the browser.',
            'source' => 'https://developers.google.com/maps/documentation/tile/policies',
            'caveat' => 'Undocumented, unlicensed endpoint used alongside a non-Google map — see '
                      . 'google_street. Migrate to another provider.',
            'url' => 'https://mt{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}',
            'subdomains' => '0123', 'max_zoom' => 20,
            'attribution' => '&copy; Google',
        ],
        'bing_road' => [
            'proxy' => false,
            'reason' => 'Microsoft\'s own documentation states that accessing tiles from a hardcoded '
                      . 'URL is not allowed — the supported path queries their metadata service with '
                      . 'a key at every start, partly so usage can be tracked, which a cache defeats '
                      . 'by design. Fetched directly by the browser.',
            'source' => 'https://learn.microsoft.com/en-us/bingmaps/rest-services/directly-accessing-the-bing-maps-tiles',
            'caveat' => 'Bing Maps for Enterprise was retired for all free/Basic accounts on '
                      . '2025-06-30, so for most TicketsCAD deployments there is no working tier left '
                      . 'at all. The URL configured here is the hardcoded form Microsoft prohibits. '
                      . 'Migrate to another provider.',
            'url' => 'https://ecn.t{s}.tiles.virtualearth.net/tiles/r{q}?g=1&mkt=en-US',
            'subdomains' => '01234567', 'max_zoom' => 19,
            'attribution' => '&copy; Microsoft',
        ],
        'bing_aerial' => [
            'proxy' => false,
            'reason' => 'Bing Maps — see bing_road. Fetched directly by the browser.',
            'source' => 'https://learn.microsoft.com/en-us/bingmaps/rest-services/directly-accessing-the-bing-maps-tiles',
            'caveat' => 'Retired for free/Basic accounts on 2025-06-30 and accessed via the hardcoded '
                      . 'URL form Microsoft prohibits. Migrate to another provider.',
            'url' => 'https://ecn.t{s}.tiles.virtualearth.net/tiles/a{q}?g=1',
            'subdomains' => '01234567', 'max_zoom' => 19,
            'attribution' => '&copy; Microsoft',
        ],
    ];
}

/**
 * May this server proxy the given provider?
 *
 * Unknown providers are refused. That is deliberate: an identifier we have no
 * policy finding for is an identifier whose terms nobody checked, and the safe
 * answer to "may we re-serve someone's tiles?" is no.
 *
 * @return array{allowed:bool,reason:string,caveat:string}
 */
function tile_proxy_verdict(string $provider): array
{
    $policy = tile_proxy_policy();
    if (!isset($policy[$provider])) {
        return ['allowed' => false,
                'caveat'  => '',
                'reason'  => 'Unknown tile provider "' . $provider . '" — no proxy policy on record, '
                           . 'so tiles are fetched directly by the browser.'];
    }
    $p = $policy[$provider];
    return [
        'allowed' => (bool) $p['proxy'],
        'reason'  => $p['reason'],
        'caveat'  => (string) ($p['caveat'] ?? ''),
    ];
}

/**
 * The mode that will ACTUALLY be used, which is not always the one configured.
 *
 * This is the single function the server, the JSON payload and the browser all
 * agree on. The bug this whole change fixes was precisely that no such shared
 * answer existed: the setting said one thing and the URL builder did another.
 *
 * @param string $configured 'proxy' | 'direct' | '' (empty = install default)
 * @param string $provider   provider identifier
 * @return string 'proxy' | 'direct'
 */
function tile_proxy_effective_mode(string $configured, string $provider): string
{
    // Empty/unrecognised means "install default", which is proxy — the value
    // Phase 41 has been seeding since 2026. Anything but an explicit 'direct'
    // is a request to proxy.
    $wantsProxy = ($configured !== 'direct');
    if (!$wantsProxy) {
        return 'direct';
    }
    $verdict = tile_proxy_verdict($provider);
    return $verdict['allowed'] ? 'proxy' : 'direct';
}

/**
 * The client-facing policy summary: every provider, whether proxy applies, why.
 *
 * Settings renders this so an admin can see at a glance which providers the
 * mode actually changes anything for, instead of trusting a select box.
 *
 * @return array<string,array{proxy:bool,reason:string,source:string,caveat:string,attribution:string}>
 */
function tile_proxy_policy_summary(): array
{
    $out = [];
    foreach (tile_proxy_policy() as $key => $p) {
        $out[$key] = [
            'proxy'       => (bool) $p['proxy'],
            'reason'      => $p['reason'],
            'source'      => $p['source'],
            'caveat'      => (string) ($p['caveat'] ?? ''),
            'attribution' => (string) ($p['attribution'] ?? ''),
        ];
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
// SSRF-SAFE UPSTREAM URL CONSTRUCTION
// ─────────────────────────────────────────────────────────────────────────

/**
 * Is this a syntactically valid provider identifier?
 *
 * Also the path-traversal guard: the identifier becomes a cache directory
 * name, so anything outside [a-z0-9_] never reaches the filesystem.
 */
function tile_proxy_valid_provider_id(string $provider): bool
{
    return $provider !== ''
        && strlen($provider) <= 40
        && preg_match('/^[a-z0-9_]+$/', $provider) === 1;
}

/**
 * Are these tile coordinates real?
 *
 * z within the provider's range, and x/y inside the 2^z grid that zoom level
 * actually has. Rejecting out-of-grid coordinates matters for more than
 * tidiness: without it, a client could walk x/y to unbounded values and mint
 * an unbounded number of distinct cache entries — a disk-fill vector dressed
 * up as map panning.
 */
function tile_proxy_valid_zxy(int $z, int $x, int $y, int $maxZoom = 22): bool
{
    if ($maxZoom < 0 || $maxZoom > 22) {
        $maxZoom = 22;
    }
    if ($z < 0 || $z > $maxZoom) {
        return false;
    }
    $limit = 1 << $z;               // 2^z tiles per axis
    return $x >= 0 && $x < $limit && $y >= 0 && $y < $limit;
}

/**
 * Bing/Virtual Earth quadkey for a tile. Server-side so {q} never comes from
 * the client.
 */
function tile_proxy_quadkey(int $z, int $x, int $y): string
{
    $key = '';
    for ($i = $z; $i > 0; $i--) {
        $digit = 0;
        $mask = 1 << ($i - 1);
        if (($x & $mask) !== 0) { $digit++; }
        if (($y & $mask) !== 0) { $digit += 2; }
        $key .= (string) $digit;
    }
    return $key;
}

/**
 * Build the upstream URL for one tile.
 *
 * THE SSRF BOUNDARY. Everything variable in the result comes from either this
 * file's hardcoded template, the admin's `tile_server_url` setting, or three
 * integers that have already been range-checked. The client contributes the
 * provider NAME (checked against the policy allowlist) and z/x/y. It cannot
 * contribute a scheme, a host, a port, a path or a query string.
 *
 * The {s} subdomain is picked from the coordinates rather than accepted from
 * the caller — that keeps tile distribution across a provider's subdomains
 * while leaving no client control over the hostname at all.
 *
 * @param string $provider   policy key, or 'custom'
 * @param int    $z,$x,$y    validated tile coordinates
 * @param string $apiKey     from settings; substituted for {key}/{access_token}
 * @param string $customTpl  admin-configured template, used only for 'custom'
 * @return string|null null when the provider is unknown, refused, or has no usable template
 */
function tile_proxy_upstream_url(
    string $provider,
    int $z,
    int $x,
    int $y,
    string $apiKey = '',
    string $customTpl = ''
): ?string {
    if (!tile_proxy_valid_provider_id($provider)) {
        return null;
    }
    $policy = tile_proxy_policy();
    if (!isset($policy[$provider]) || !$policy[$provider]['proxy']) {
        // Refusing here as well as at the endpoint is deliberate belt-and-braces:
        // a future caller that forgets the verdict check still cannot build a
        // URL for a provider whose terms forbid us fetching it.
        return null;
    }
    $p = $policy[$provider];

    $tpl = ($provider === 'custom') ? $customTpl : $p['url'];
    $tpl = tile_proxy_sanitize_template($tpl);
    if ($tpl === '') {
        return null;
    }
    if (!tile_proxy_valid_zxy($z, $x, $y, (int) $p['max_zoom'])) {
        return null;
    }

    $subs = (string) $p['subdomains'];
    $sub  = '';
    if ($subs !== '') {
        // Deterministic, coordinate-derived — not caller-supplied.
        $sub = $subs[(int) (($x + $y) % strlen($subs))];
    }

    $url = str_replace(
        ['{z}', '{x}', '{y}', '{s}', '{q}', '{r}', '{key}', '{access_token}'],
        [(string) $z, (string) $x, (string) $y, $sub, tile_proxy_quadkey($z, $x, $y), '', $apiKey, $apiKey],
        $tpl
    );

    // Final check: the substitutions must not have produced something that is
    // no longer a plain http(s) URL. Nothing above can do that today; this is
    // here so a future template change cannot quietly open a hole.
    return tile_proxy_sanitize_template($url) === '' ? null : $url;
}

/**
 * Accept only absolute http/https URLs.
 *
 * Same intent as tile_sanitize_url() in inc/tile-config.php (which guards the
 * URL handed to the BROWSER against javascript:/data:); this guards the URL
 * handed to the SERVER's HTTP client against file://, ftp://, gopher:// and
 * friends. Different consumer, different blast radius, so it gets its own
 * check rather than sharing one and drifting.
 */
function tile_proxy_sanitize_template(string $url): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2000) {
        return '';
    }
    // Reject control characters and whitespace that could split a request line.
    if (preg_match('/[\x00-\x20\x7F]/', $url)) {
        return '';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }
    $host = (string) parse_url($url, PHP_URL_HOST);
    return $host === '' ? '' : $url;
}

/**
 * The User-Agent this server identifies itself with upstream.
 *
 * OpenStreetMap's policy requires a genuine, identifying User-Agent and blocks
 * generic ones outright, so this must never be a bare library default. The
 * default names the app, its version and the install's own host so an operator
 * on the receiving end can tell who we are and get in touch.
 */
function tile_proxy_user_agent(?string $configured = null, string $host = ''): string
{
    $configured = trim((string) $configured);
    if ($configured !== '' && !tile_proxy_user_agent_is_generic($configured)) {
        return $configured;
    }
    $ver  = function_exists('newui_version') ? newui_version() : '4';
    $host = trim($host);
    if ($host === '') {
        $host = 'self-hosted';
    }
    return 'TicketsCAD/' . $ver . ' (+https://' . $host . '; dispatch console)';
}

/**
 * The Referer this server sends upstream.
 *
 * OSM's policy expects web-page tile requests to carry a valid Referer, and a
 * server-side proxy destroys the browser's — the whole point is that the
 * browser never makes the request. Synthesising one from the install's own
 * host keeps that condition satisfied and, unlike forwarding the real one,
 * leaks nothing about which page or which incident a dispatcher is on.
 */
function tile_proxy_referer(string $host = ''): string
{
    $host = trim($host);
    if ($host === '' || !preg_match('/^[A-Za-z0-9._:\-\[\]]+$/', $host)) {
        return '';
    }
    return 'https://' . $host . '/';
}

/**
 * Would this User-Agent read as generic to a tile operator?
 *
 * Used both to reject a useless override and to warn the admin in Settings.
 */
function tile_proxy_user_agent_is_generic(string $ua): bool
{
    $ua = strtolower(trim($ua));
    if ($ua === '' || strlen($ua) < 8) {
        return true;
    }
    foreach (['curl/', 'wget/', 'python-requests', 'php/', 'libwww', 'java/', 'okhttp', 'go-http-client'] as $bad) {
        if (strpos($ua, $bad) === 0) {
            return true;
        }
    }
    return in_array($ua, ['mozilla/5.0', 'unknown', 'tilecache', 'proxy'], true);
}

// ─────────────────────────────────────────────────────────────────────────
// CACHE — bounds first, convenience second
// ─────────────────────────────────────────────────────────────────────────

// ── Where the cache lives, and why it is NOT under cache/ ────────────────
//
// The obvious home was cache/tiles, beside the weather cache. It is the wrong
// one. The documented install points the web root AT the application root, and
// both .htaccess and docs/nginx/ticketscad-hardening.conf list cache/ as a
// directory that stays reachable — so cached tiles would be fetchable by
// anyone, unauthenticated, at /cache/tiles/osm/{z}/{x}/{y}.tile.
//
// That is not a generic "files are exposed" worry. The cache IS the record of
// which map areas this dispatch centre has looked at. Publishing it would
// disclose exactly the thing proxy mode exists to conceal, and would walk
// straight around the endpoint's auth gate. It is also the same mistake found
// in production on 2026-07-30 (see the header of .htaccess), so repeating it in
// a new feature would be inexcusable.
//
// So the cache goes ABOVE the web root, following BACKUP_DIR's precedent from
// that same hardening work.
//
// 2026-08-14 correction: the claim this comment used to make — "this needs no
// .htaccess, no nginx block and no IIS config: it is out of reach on any
// server in any configuration" — was false, and demonstrably so:
// GHSA-x9x6-w4fg-pmcc's round 2 made the IDENTICAL claim about zello-audio's
// directory and a reporter proved it wrong on a stock Windows/IIS install,
// where "above the web root" (C:\inetpub\wwwroot\<app>\..) is
// C:\inetpub\wwwroot itself — the Default Web Site's own root, bound to port
// 80. served_dir_above_root() now picks a platform-aware location instead of
// asserting the layout is safe, AND this directory is hardened with deny
// files as defense in depth — belt AND suspenders, not "no config needed".
if (!defined('NEWUI_ROOT')) {
    define('NEWUI_ROOT', dirname(__DIR__));
}
require_once __DIR__ . '/served-dir.php';
if (!defined('TILE_CACHE_DIR')) {
    define('TILE_CACHE_DIR', served_dir_above_root(NEWUI_ROOT, 'tile-cache'));
}

/**
 * Root of the tile cache — platform-aware, above the web root. See the note
 * above.
 *
 * Same ownership-race hazard GEOCODE_CACHE_DIR has, and the same fix: the
 * @mkdir() below is a fallback for installs that never provision this
 * directory in advance, not the primary defense. Whichever process calls
 * this function first becomes the owner, and PHP cannot reliably chgrp() the
 * result to the web server's group afterwards. The real fix is
 * tools/fix-permissions.php (run on every tools/deploy.sh deploy) creating
 * this directory owned by the web server up front — see
 * inc/install-permissions.php's TILE_CACHE_DIR entry and the account of the
 * your-server.example.com GEOCODE_CACHE_DIR incident there. Mode 0775
 * (was 0755) matches INSTALL_PERM_MODE_WEB, the mode fix-permissions.php
 * converges this directory to regardless of who created it, so an install
 * that never runs that tool is no less private than one that does.
 */
function tile_cache_dir(): string
{
    if (!is_dir(TILE_CACHE_DIR)) {
        @mkdir(TILE_CACHE_DIR, 0775, true);
    }
    served_dir_harden(TILE_CACHE_DIR, 'Map tile cache', true);
    return TILE_CACHE_DIR;
}

/**
 * On-disk path for one tile. `$provider` MUST already have passed
 * tile_proxy_valid_provider_id(); z/x/y are ints, so no traversal is possible.
 */
function tile_cache_path(string $provider, int $z, int $x, int $y): string
{
    return tile_cache_dir() . '/' . $provider . '/' . $z . '/' . $x . '/' . $y . '.tile';
}

/**
 * PURE disk-space decision — no filesystem, no clock. Modelled on
 * backup_space_verdict() in inc/backup_schedule.php, and for the same reason:
 * "exactly at the floor" and "free space unreadable" must be testable without
 * arranging a full disk.
 *
 * Note the asymmetry with backups. When free space cannot be determined a
 * backup proceeds (missing a backup is the worse outcome); a tile cache write
 * is SKIPPED (the tile is still served to the dispatcher — we just don't keep
 * a copy). Declining to cache costs a re-fetch; filling a dispatch server's
 * disk costs an outage.
 *
 * @param ?int $freeBytes  NULL = undeterminable
 * @param int  $needBytes  bytes about to be written
 * @param int  $floorBytes free space that must REMAIN afterwards
 * @return array{ok:bool,undetermined:bool,reason:string}
 */
function tile_cache_space_verdict(?int $freeBytes, int $needBytes, int $floorBytes): array
{
    if ($freeBytes === null) {
        return ['ok' => false, 'undetermined' => true,
                'reason' => 'free disk space could not be determined — not caching this tile '
                          . '(it is still served; only the on-disk copy is skipped)'];
    }
    $need  = max(0, $needBytes);
    $after = $freeBytes - $need;
    // >= the floor is within policy: the floor is space that must REMAIN,
    // not space that must be exceeded.
    if ($floorBytes > 0 && $after < $floorBytes) {
        return ['ok' => false, 'undetermined' => false,
                'reason' => 'not caching: ' . tile_format_size($freeBytes) . ' free, this tile needs '
                          . tile_format_size($need) . ', which would leave ' . tile_format_size(max(0, $after))
                          . ' — below the ' . tile_format_size($floorBytes) . ' reserve'];
    }
    return ['ok' => true, 'undetermined' => false, 'reason' => ''];
}

/**
 * PURE eviction plan: given (path => [size, mtime]) and a target, decide what
 * to delete. Least-recently-used first.
 *
 * Separated from unlink() so the ordering and the stopping condition can be
 * tested exactly, without creating hundreds of files.
 *
 * @param array<string,array{size:int,mtime:int}> $entries
 * @param int $currentBytes
 * @param int $targetBytes  evict until at or below this
 * @return array{paths:string[],freed:int,remaining:int}
 */
function tile_cache_eviction_plan(array $entries, int $currentBytes, int $targetBytes): array
{
    if ($currentBytes <= $targetBytes || !$entries) {
        return ['paths' => [], 'freed' => 0, 'remaining' => $currentBytes];
    }
    // Oldest touched first. Ties broken by path so the plan is deterministic
    // (a test that depends on tie order should not be flaky).
    uksort($entries, function ($a, $b) use ($entries) {
        $d = $entries[$a]['mtime'] <=> $entries[$b]['mtime'];
        return $d !== 0 ? $d : strcmp($a, $b);
    });

    $paths = [];
    $freed = 0;
    $remaining = $currentBytes;
    foreach ($entries as $path => $meta) {
        if ($remaining <= $targetBytes) {
            break;
        }
        $paths[] = $path;
        $freed += (int) $meta['size'];
        $remaining -= (int) $meta['size'];
    }
    return ['paths' => $paths, 'freed' => $freed, 'remaining' => max(0, $remaining)];
}

/**
 * PURE TTL decision from upstream caching headers.
 *
 * "Respect caching headers" with two clamps, because a tile server's advice is
 * input, not instruction: never shorter than TILE_CACHE_MIN_TTL (a provider
 * that says no-cache would otherwise make the cache pointless and the request
 * volume worse than direct mode), never longer than the admin's cache-days
 * setting (the operator's disk, the operator's ceiling).
 *
 * @param array<string,string> $headers lower-cased header name => value
 * @param int $defaultTtl seconds
 * @param int $maxTtl     seconds — the admin's tile_cache_days
 */
function tile_cache_ttl_from_headers(array $headers, int $defaultTtl, int $maxTtl): int
{
    $ttl = $defaultTtl;

    $cc = $headers['cache-control'] ?? '';
    if ($cc !== '' && preg_match('/max-age\s*=\s*(\d+)/i', $cc, $m)) {
        $ttl = (int) $m[1];
    } elseif (!empty($headers['expires'])) {
        $exp = strtotime($headers['expires']);
        if ($exp !== false) {
            $ttl = $exp - time();
        }
    }
    if ($ttl < TILE_CACHE_MIN_TTL) {
        $ttl = TILE_CACHE_MIN_TTL;
    }
    if ($maxTtl > 0 && $ttl > $maxTtl) {
        $ttl = $maxTtl;
    }
    return $ttl;
}

/** Free bytes on the volume holding $dir, or NULL when undeterminable. */
function tile_cache_free_bytes(string $dir): ?int
{
    $probe = rtrim($dir, '/\\');
    $guard = 0;
    while ($probe !== '' && !@is_dir($probe) && $guard++ < 64) {
        $parent = dirname($probe);
        if ($parent === $probe) { break; }
        $probe = $parent;
    }
    if ($probe === '' || !@is_dir($probe)) {
        return null;
    }
    $free = @disk_free_space($probe);
    if ($free === false || $free === null || !is_numeric($free)) {
        return null;
    }
    return (int) $free;
}

/**
 * Walk the cache. Returns every tile file with its size and mtime.
 *
 * @return array<string,array{size:int,mtime:int}>
 */
function tile_cache_entries(string $dir): array
{
    $out = [];
    if (!@is_dir($dir)) {
        return $out;
    }
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile() || substr($file->getFilename(), -5) !== '.tile') {
                continue;
            }
            $out[$file->getPathname()] = [
                'size'  => (int) $file->getSize(),
                'mtime' => (int) $file->getMTime(),
            ];
        }
    } catch (Throwable $e) {
        // A cache we cannot read is a cache we report as empty rather than a
        // request we fail. Logged, never swallowed silently.
        error_log('[tile-proxy] cache walk failed: ' . $e->getMessage());
    }
    return $out;
}

/** Current cache usage: files, bytes, and the newest/oldest timestamps. */
function tile_cache_usage(string $dir): array
{
    $entries = tile_cache_entries($dir);
    $bytes = 0;
    $oldest = null;
    foreach ($entries as $m) {
        $bytes += $m['size'];
        if ($oldest === null || $m['mtime'] < $oldest) { $oldest = $m['mtime']; }
    }
    return ['files' => count($entries), 'bytes' => $bytes, 'oldest' => $oldest];
}

/**
 * Enforce the cache ceiling. Evicts least-recently-used tiles until usage is
 * at or below $targetBytes (default 85% of the cap, so a cache sitting exactly
 * at the ceiling does not re-sweep on every single write).
 *
 * @return array{evicted:int,freed:int,remaining:int}
 */
function tile_cache_enforce_cap(string $dir, int $capBytes, ?int $targetBytes = null): array
{
    if ($capBytes <= 0) {
        return ['evicted' => 0, 'freed' => 0, 'remaining' => 0];
    }
    if ($targetBytes === null) {
        $targetBytes = (int) ($capBytes * 0.85);
    }
    $entries = tile_cache_entries($dir);
    $current = 0;
    foreach ($entries as $m) { $current += $m['size']; }

    $plan = tile_cache_eviction_plan($entries, $current, $targetBytes);
    $evicted = 0;
    foreach ($plan['paths'] as $path) {
        if (@unlink($path)) {
            $evicted++;
            @unlink($path . '.meta');
        }
    }
    return ['evicted' => $evicted, 'freed' => $plan['freed'], 'remaining' => $plan['remaining']];
}

/** Human-readable bytes. Mirrors backup_format_size()'s output shape. */
function tile_format_size(int $bytes): string
{
    if ($bytes >= 1073741824) { return round($bytes / 1073741824, 1) . ' GB'; }
    if ($bytes >= 1048576)    { return round($bytes / 1048576, 1) . ' MB'; }
    if ($bytes >= 1024)       { return round($bytes / 1024, 1) . ' KB'; }
    return $bytes . ' B';
}

/**
 * Read the admin's tile-proxy settings, with defaults.
 *
 * Reads via get_variable() — the `settings` table — NOT get_setting()/`config`.
 * Crossing those two stores is a documented way to make an admin toggle read
 * as its default forever (see the two-settings-stores pitfall in CLAUDE.md).
 */
function tile_proxy_settings(): array
{
    $get = function (string $k) {
        if (!function_exists('get_variable')) { return ''; }
        $v = get_variable($k);
        return ($v === false || $v === null) ? '' : (string) $v;
    };

    $maxMb  = (int) $get('tile_cache_max_mb');
    $freeMb = (int) $get('tile_cache_min_free_mb');
    $days   = (int) $get('tile_cache_days');

    return [
        'mode'          => $get('tile_mode'),
        'provider'      => $get('tile_provider'),
        'server_url'    => $get('tile_server_url'),
        'api_key'       => $get('tile_api_key'),
        'max_bytes'     => ($maxMb  > 0 ? $maxMb  : TILE_CACHE_DEFAULT_MAX_MB) * 1048576,
        'min_free_bytes' => ($freeMb > 0 ? $freeMb : TILE_CACHE_DEFAULT_MIN_FREE_MB) * 1048576,
        'max_ttl'       => ($days > 0 ? $days : TILE_CACHE_DEFAULT_DAYS) * 86400,
        'user_agent'    => $get('tile_proxy_user_agent'),
    ];
}

// ─────────────────────────────────────────────────────────────────────────
// CIRCUIT BREAKER — stop paying the timeout once upstream is known to be down
// ─────────────────────────────────────────────────────────────────────────

/**
 * Is this upstream response evidence that the PROVIDER is down, as opposed to
 * evidence that one tile does not exist?
 *
 * PURE, so the distinction can be tested without a network. It matters: a 404
 * from a provider that has answered us is a healthy provider telling us the
 * truth about coverage, and counting it would open the breaker for everybody
 * the first time a dispatcher zoomed past the edge of a map.
 *
 * @param int    $status HTTP status, 0 when the transfer never completed
 * @param string $error  transport error text, '' when there was none
 */
function tile_upstream_is_down(int $status, string $error): bool
{
    if ($status === 0) return true;              // connect/read timeout, DNS, TLS
    if ($error !== '') return true;              // transport said something went wrong
    if ($status === 429) return true;            // rate-limited: backing off IS the fix
    return $status >= 500 && $status <= 599;     // upstream broken, not us
}

/**
 * PURE breaker decision. Given the stored counters and a clock, should this
 * request be allowed to touch the network?
 *
 * Three states, expressed without a state machine because two booleans are
 * easier to test than an enum:
 *
 *   closed    fails < threshold                     → allow
 *   open      fails >= threshold, within cool-off   → refuse, retry_in > 0
 *   half-open fails >= threshold, cool-off elapsed  → allow ONE probe
 *
 * @param array $state    ['fails'=>int,'opened_at'=>int,'last_error'=>string]
 * @return array{open:bool,half_open:bool,retry_in:int,fails:int,reason:string}
 */
function tile_breaker_decide(
    array $state,
    int $now,
    int $threshold = TILE_BREAKER_THRESHOLD,
    int $cooloff = TILE_BREAKER_COOLOFF
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
        return [
            'open' => true, 'half_open' => false,
            'retry_in' => max(1, $cooloff - $elapsed),
            'fails' => $fails,
            'reason' => 'circuit breaker open after ' . $fails . ' consecutive upstream failures'
                      . ($lastErr !== '' ? ' (' . $lastErr . ')' : ''),
        ];
    }
    return ['open' => false, 'half_open' => true, 'retry_in' => 0,
            'fails' => $fails, 'reason' => 'cool-off elapsed — probing upstream'];
}

/** Where breaker state lives. Beside the cache, i.e. ABOVE the web root. */
function tile_breaker_dir(): string
{
    return tile_cache_dir() . '/.breaker';
}

/**
 * Path for one provider's breaker file. `$provider` MUST already have passed
 * tile_proxy_valid_provider_id() — it is a filename component.
 */
function tile_breaker_path(string $provider): string
{
    return tile_breaker_dir() . '/' . $provider . '.json';
}

/** Stored counters, or an empty (closed) state. Never throws. */
function tile_breaker_read(string $provider): array
{
    $p = tile_breaker_path($provider);
    if (!is_file($p)) return ['fails' => 0, 'opened_at' => 0, 'last_error' => '', 'last_fail_at' => 0];
    $raw = @file_get_contents($p);
    $st  = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    if (!is_array($st)) return ['fails' => 0, 'opened_at' => 0, 'last_error' => '', 'last_fail_at' => 0];
    return [
        'fails'        => max(0, (int) ($st['fails'] ?? 0)),
        'opened_at'    => (int) ($st['opened_at'] ?? 0),
        'last_error'   => substr((string) ($st['last_error'] ?? ''), 0, 180),
        'last_fail_at' => (int) ($st['last_fail_at'] ?? 0),
    ];
}

/**
 * Persist breaker state. Best effort: if the directory is not writable the
 * breaker simply never opens, which is the behaviour this feature replaced —
 * degraded, never broken.
 */
function tile_breaker_write(string $provider, array $state): bool
{
    $dir = tile_breaker_dir();
    // Mode matches tile_cache_dir()'s parent (0775, not 0755) — see that
    // function's docblock. A stricter subdirectory under a more permissive
    // parent denies the very group access the parent just granted.
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
    return @file_put_contents(tile_breaker_path($provider), json_encode($state), LOCK_EX) !== false;
}

/**
 * Read-only view of the breaker. Used by tests and by action=status; the
 * request path uses tile_breaker_check(), which also re-arms the half-open
 * window.
 */
function tile_breaker_is_open(string $provider, ?int $now = null): bool
{
    return tile_breaker_decide(tile_breaker_read($provider), $now ?? time())['open'];
}

/**
 * The request-path gate. Same decision as tile_breaker_decide(), plus the one
 * side effect that needs to happen exactly once: when the cool-off has
 * elapsed, re-stamp opened_at so that ONE request probes upstream and the
 * others keep failing fast instead of all 40 tiles in a viewport probing at
 * the same moment.
 *
 * Two concurrent requests can still slip through the re-stamp. That is fine:
 * two connect timeouts a minute is not the failure mode this exists to stop.
 */
function tile_breaker_check(string $provider, ?int $now = null): array
{
    $now = $now ?? time();
    $st  = tile_breaker_read($provider);
    $d   = tile_breaker_decide($st, $now);
    if ($d['half_open']) {
        $st['opened_at'] = $now;
        tile_breaker_write($provider, $st);
    }
    return $d;
}

/** Upstream failed at the transport level. Count it; open the breaker at the threshold. */
function tile_breaker_record_failure(string $provider, string $error, ?int $now = null): void
{
    $now = $now ?? time();
    $st  = tile_breaker_read($provider);
    $st['fails']        = $st['fails'] + 1;
    $st['last_error']   = substr(preg_replace('/[^\x20-\x7E]/', '', $error), 0, 180);
    $st['last_fail_at'] = $now;
    // Stamp the window the moment we cross the threshold, not on every failure
    // after it — otherwise concurrent failures keep pushing the retry away.
    if ($st['fails'] >= TILE_BREAKER_THRESHOLD && (int) $st['opened_at'] === 0) {
        $st['opened_at'] = $now;
    }
    tile_breaker_write($provider, $st);
}

/** Upstream answered. Clear the counters so the next outage starts from zero. */
function tile_breaker_record_success(string $provider): void
{
    $st = tile_breaker_read($provider);
    if ($st['fails'] === 0 && (int) $st['opened_at'] === 0) return;   // already clean
    tile_breaker_write($provider, ['fails' => 0, 'opened_at' => 0,
                                   'last_error' => '', 'last_fail_at' => 0]);
}

/** Forget everything about a provider (tests, and the admin "retry now" action). */
function tile_breaker_reset(string $provider): void
{
    @unlink(tile_breaker_path($provider));
}

/**
 * Every provider the breaker currently knows about, for action=status. Only
 * providers that have failed at some point have a file, so a clean install
 * reports an empty list rather than a wall of green.
 */
function tile_breaker_status(?int $now = null): array
{
    $now = $now ?? time();
    $out = [];
    $dir = tile_breaker_dir();
    if (!is_dir($dir)) return $out;
    foreach ((array) @glob($dir . '/*.json') as $f) {
        $provider = basename($f, '.json');
        if (!tile_proxy_valid_provider_id($provider)) continue;
        $st = tile_breaker_read($provider);
        $d  = tile_breaker_decide($st, $now);
        $out[] = [
            'provider'     => $provider,
            'open'         => $d['open'],
            'fails'        => $d['fails'],
            'retry_in'     => $d['retry_in'],
            'last_error'   => $st['last_error'],
            'last_fail_at' => $st['last_fail_at'] > 0 ? date('c', $st['last_fail_at']) : null,
        ];
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────
// UPSTREAM FETCH
// ─────────────────────────────────────────────────────────────────────────

/**
 * Minimal HTTP GET for tiles.
 *
 * Lives here rather than in the endpoint so tests can drive the REAL fetch
 * path instead of a stand-in. (A test that exercises a re-implementation of
 * the thing it is guarding is the failure mode this project keeps hitting.)
 *
 * Separate connect and total timeouts matter: a provider that accepts the
 * connection and then stalls must not hold a PHP worker — and with a session
 * open, a wedged worker is a wedged dispatcher.
 *
 * Redirects are followed (tile CDNs bounce between edges) but capped, and
 * pinned to http/https so a redirect can never walk the fetch onto file://
 * or another scheme.
 *
 * @param string   $url     absolute http(s) URL, already built server-side
 * @param string[] $headers request headers
 * @return array{status:int,body:string,headers:array<string,string>,error:string}
 */
function tile_http_get(string $url, array $headers): array
{
    $out = ['status' => 0, 'body' => '', 'headers' => [], 'error' => ''];

    // Refuse anything that is not a plain http(s) URL, even here. This function
    // is only ever called with a server-built URL today; the check means a
    // future caller cannot turn it into a general fetcher by accident.
    if (tile_proxy_sanitize_template($url) === '') {
        $out['error'] = 'refused non-http(s) url';
        return $out;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_CONNECTTIMEOUT  => TILE_PROXY_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT         => TILE_PROXY_READ_TIMEOUT,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 3,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_HEADERFUNCTION  => function ($ch, $line) use (&$out) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $out['headers'][strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $out['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false) {
            $out['error'] = curl_error($ch);
        } else {
            $out['body'] = (string) $body;
        }
        curl_close($ch);
        return $out;
    }

    // No cURL — stream wrapper fallback (same shape as api/weather-proxy.php).
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => TILE_PROXY_READ_TIMEOUT,
        'ignore_errors' => true,
        'max_redirects' => 3,
        'header'        => implode("\r\n", $headers),
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        $out['error'] = 'request failed';
        return $out;
    }
    $out['body'] = $body;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $out['status'] = (int) $m[1];
            continue;
        }
        $p = strpos($line, ':');
        if ($p !== false) {
            $out['headers'][strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
        }
    }
    return $out;
}
