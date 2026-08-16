<?php
/**
 * api/geocode.php — server-side geocoding endpoint.
 *
 * The reader that `geocoding_provider` never had. See inc/geocode.php's header
 * for why this exists; this file is only the HTTP skin over it.
 *
 *   GET  ?action=search&q=...[&limit=1][&viewbox=w,n,e,s][&countrycodes=us]
 *   GET  ?action=reverse&lat=..&lon=..
 *   GET  ?action=status                          (action.manage_config)
 *   POST ?action=test                            (action.manage_config, CSRF)
 *   POST ?action=clear_cache                     (action.manage_config, CSRF)
 *
 * SSRF: the client never sends a URL. It sends an address string or a
 * coordinate pair; inc/geocode.php builds the upstream URL from a hardcoded
 * template or the admin's validated `geocoding_url`. Read geocode_build_url().
 *
 * Read actions need only a logged-in user — every dispatcher geocodes. The
 * admin actions are gated on action.manage_config, matching api/places.php and
 * the rest of the Settings surface.
 */

// PHP warnings printed before the JSON corrupt the response. Suppress first.
$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/geocode.php';
// audit_log() (used by the test/clear_cache actions below) lives in
// inc/audit.php, which nothing above pulls in on this entry path -- unlike
// most api/*.php files, which get it transitively through a different
// include chain. Confirmed fatal without this: "Call to undefined function
// audit_log()" on every address-lookup test and cache clear (kmk1971,
// openises/tickets#10, reported against a self-hosted Photon provider).
require_once __DIR__ . '/../inc/audit.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?: []) : $_POST;
    if ($action === '' && !empty($input['action'])) {
        $action = (string) $input['action'];
    }
}

$adminActions = ['status', 'test', 'clear_cache'];
if (in_array($action, $adminActions, true) && !rbac_can('action.manage_config')) {
    json_error('Forbidden — requires action.manage_config', 403);
}

try {
    switch ($action) {

        // ── Lookups (any logged-in user) ─────────────────────────────────
        case 'search':
            _geocode_respond('search', [
                'q'            => (string) ($_GET['q'] ?? ''),
                'limit'        => (int) ($_GET['limit'] ?? 1),
                'viewbox'      => (string) ($_GET['viewbox'] ?? ''),
                'countrycodes' => (string) ($_GET['countrycodes'] ?? ''),
            ]);
            break;

        case 'reverse':
            _geocode_respond('reverse', [
                'lat' => $_GET['lat'] ?? null,
                'lon' => $_GET['lon'] ?? ($_GET['lng'] ?? null),
            ]);
            break;

        // ── Admin ────────────────────────────────────────────────────────
        case 'status': {
            $settings = geocode_settings();
            $cfg      = geocode_client_config($settings);
            $policy   = geocode_policy();
            $providers = [];
            foreach ($policy as $id => $p) {
                $providers[$id] = [
                    'label'            => $p['label'],
                    'kind'             => $p['kind'],
                    'needs_key'        => (bool) $p['needs_key'],
                    'needs_url'        => (bool) $p['needs_url'],
                    'direct_supported' => (bool) $p['direct_supported'],
                    'direct_reason'    => $p['direct_reason'],
                    'throttle_ms'      => (int) $p['throttle_ms'],
                    'policy'           => $p['policy'],
                    'source'           => $p['source'],
                    'caveat'           => $p['caveat'],
                    'verified'         => $p['verified'],
                    'unsupported'      => $p['unsupported'],
                ];
            }
            $breaker = geocode_breaker_read($settings['provider']);
            $decided = geocode_breaker_decide($breaker, time());
            json_response([
                'ok'        => true,
                'config'    => $cfg,
                'providers' => $providers,
                'cache'     => geocode_cache_usage(),
                'cache_ttl_hours' => (int) round($settings['cache_ttl'] / 3600),
                'throttle_ms'     => (int) $settings['throttle_ms'],
                'user_agent'      => geocode_user_agent(
                    (string) $settings['user_agent'], (string) ($_SERVER['HTTP_HOST'] ?? '')),
                'breaker'   => [
                    'open'         => $decided['open'],
                    'fails'        => $decided['fails'],
                    'retry_in'     => $decided['retry_in'],
                    'last_error'   => $breaker['last_error'],
                    'last_fail_at' => $breaker['last_fail_at'] > 0
                        ? date('c', $breaker['last_fail_at']) : null,
                ],
            ]);
            break;
        }

        case 'test': {
            _geocode_require_csrf($input);
            // Reset the breaker first: an admin pressing Test is explicitly
            // asking "is it working NOW", and answering from a cool-off window
            // would report a stale verdict as a live one.
            $settings = geocode_settings();
            geocode_breaker_reset((string) $settings['provider']);

            $q = geocode_clean_query((string) ($input['q'] ?? ''));
            if ($q === '') {
                $q = '1600 Pennsylvania Ave NW, Washington, DC';
            }
            $started = microtime(true);
            $res = geocode_lookup('search', ['q' => $q, 'limit' => 1], $settings);
            $ms  = (int) round((microtime(true) - $started) * 1000);

            audit_log('config', 'geocode_test', 'settings', 0,
                'Tested geocoding provider ' . $settings['provider']
                . ' — ' . ($res['ok'] ? 'ok' : $res['error']) . ' in ' . $ms . 'ms');

            json_response([
                'ok'       => (bool) $res['ok'],
                'provider' => $res['provider'],
                'source'   => $res['source'],
                'error'    => $res['error'],
                'message'  => $res['message'],
                'ms'       => $ms,
                'count'    => count($res['results']),
                'sample'   => $res['results'][0] ?? null,
                'query'    => $q,
            ]);
            break;
        }

        case 'clear_cache': {
            _geocode_require_csrf($input);
            $n = geocode_cache_clear();
            audit_log('config', 'geocode_cache_clear', 'settings', 0,
                'Cleared the geocoding result cache (' . $n . ' entries)');
            json_response(['ok' => true, 'removed' => $n]);
            break;
        }

        default:
            json_error('Unknown action. Use search, reverse, status, test or clear_cache.', 400);
    }
} catch (Throwable $e) {
    // Never leak the raw exception. json_error_safe logs it server-side.
    if (function_exists('json_error_safe')) {
        json_error_safe('Address lookup failed. Check server logs.', $e, 'geocode');
    }
    json_error('Address lookup failed', 500);
} finally {
    ini_set('display_errors', $prevDisplay);
}

/**
 * Run a lookup and answer in the shape assets/js/geocode.js expects.
 *
 * A failed lookup is a 200 with ok:false, not an HTTP error status. That is
 * deliberate: the browser needs the human-readable `message` to put in front of
 * the dispatcher, and a non-2xx makes several existing fetch chains fall into
 * a `.catch` that has nothing but the word "failed". "The address lookup
 * service did not answer — you can still place the pin on the map" is a
 * sentence a dispatcher can act on; a 502 is not.
 */
function _geocode_respond(string $action, array $params): void
{
    $res = geocode_lookup($action, $params);
    json_response([
        'ok'          => (bool) $res['ok'],
        'results'     => $res['results'],
        'provider'    => $res['provider'],
        'source'      => $res['source'],
        'error'       => $res['error'],
        'message'     => $res['message'],
        'retry_in'    => (int) $res['retry_in'],
        'unsupported' => $res['unsupported'],
    ]);
}

/** CSRF gate for the state-changing admin actions. */
function _geocode_require_csrf(array $input): void
{
    $token = (string) ($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($token === '' || !csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }
}
