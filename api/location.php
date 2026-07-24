<?php
/**
 * NewUI v4.0 API — Location Tracking Providers
 *
 * Manages location data sources and ingests position reports from
 * APRS, Meshtastic, OwnTracks, OpenGTS, DMR, browser GPS, etc.
 *
 * GET  /api/location.php                 — list all providers
 * GET  /api/location.php?provider_id=X   — single provider detail
 * GET  /api/location.php?unit=X          — latest position for a unit (via bindings)
 * GET  /api/location.php?all_units=1     — latest position for all bound units
 * POST /api/location.php action=report       — ingest a location report
 * POST /api/location.php action=save_provider — update provider config
 * POST /api/location.php action=bind         — bind responder to provider/identifier
 * POST /api/location.php action=unbind       — remove binding
 * POST /api/location.php?provider=owntracks  — OwnTracks-compatible HTTP endpoint
 */

require_once __DIR__ . '/../config.php';

// Issue #32 (a beta tester 2026-07-03): APRS test polling returned an
// empty body ("Unexpected end of JSON input") when the aprs.fi call
// failed. Route through the shared json-safe harness so any fatal
// downstream emits a proper JSON 500 with the real error logged.
require_once __DIR__ . '/../inc/json-safe.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ═══════════════════════════════════════════════════════════════
//  OwnTracks-compatible HTTP ingestion endpoint
//  POST /api/location.php?provider=owntracks
//  No CSRF/auth required — authenticated by shared secret + tid binding
//  Returns [] per OwnTracks protocol spec
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST' && isset($_GET['provider']) && $_GET['provider'] === 'owntracks') {
    // Phase 73x — per-IP rate limit. OwnTracks clients in tactical
    // mode post every 30-60 s; 600 hits/min/IP covers ~10 devices
    // behind the same NAT before kicking in. Above that is flood
    // territory.
    require_once __DIR__ . '/../inc/rate-limit.php';
    $srcIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!rate_limit_ok('ot-ingest:' . $srcIp, 600, 60)) {
        rate_limit_reject(60);
    }

    $rawBody = file_get_contents('php://input');
    $ot = json_decode($rawBody, true);

    // OwnTracks only sends _type=location payloads we care about
    if (!is_array($ot) || !isset($ot['_type']) || $ot['_type'] !== 'location') {
        header('Content-Type: application/json');
        echo '[]';
        exit;
    }

    $lat = isset($ot['lat']) ? (float) $ot['lat'] : null;
    $lon = isset($ot['lon']) ? (float) $ot['lon'] : null;
    $tid = isset($ot['tid']) ? trim($ot['tid']) : '';

    if ($lat === null || $lon === null || $tid === '') {
        header('Content-Type: application/json');
        echo '[]';
        exit;
    }

    // Sanity check coordinates
    if (abs($lat) > 90 || abs($lon) > 180) {
        header('Content-Type: application/json');
        echo '[]';
        exit;
    }

    // Phase 41: per-member token lookup via HTTP Basic auth.
    // OwnTracks setConfiguration push sends Authorization: Basic <user:secret>
    // where user = member.username and secret = the raw mint_token() secret.
    // We resolve member by username, then find the matching unrevoked + unexpired
    // token row by hashing the supplied password. On success we get auth_token_id
    // which is written on location_reports for rotation/revocation accounting.
    $authTokenId = null;
    $authMemberId = null;
    $basicUser = null;
    $basicPass = null;
    if (!empty($_SERVER['PHP_AUTH_USER'])) {
        $basicUser = $_SERVER['PHP_AUTH_USER'];
        $basicPass = $_SERVER['PHP_AUTH_PW'] ?? '';
    } elseif (!empty($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'basic ') === 0) {
        $decoded = base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6), true);
        if ($decoded !== false && strpos($decoded, ':') !== false) {
            list($basicUser, $basicPass) = explode(':', $decoded, 2);
        }
    }
    if ($basicUser !== null && $basicPass !== null && $basicPass !== '') {
        try {
            // member's login lives on the user table (`user.user`). Two linkage
            // patterns exist in the wild — `member.user_id → user.id` (newer)
            // and `user.member → member.id` (legacy). Cover both via OR-join.
            $member = db_fetch_one(
                "SELECT m.id FROM `{$prefix}member` m
                   JOIN `{$prefix}user` u
                     ON (u.id = m.user_id OR u.member = m.id)
                  WHERE u.user = ? LIMIT 1",
                [$basicUser]
            );
            if ($member) {
                $authMemberId = (int) $member['id'];
                $needle = hash('sha256', $basicPass);
                $tok = db_fetch_one(
                    "SELECT id FROM `{$prefix}member_tracking_tokens`
                      WHERE member_id = ?
                        AND secret_hash = ?
                        AND revoked_at IS NULL
                        AND (valid_until IS NULL OR valid_until > NOW())
                      ORDER BY id DESC LIMIT 1",
                    [$authMemberId, $needle]
                );
                if ($tok) {
                    $authTokenId = (int) $tok['id'];
                    db_query(
                        "UPDATE `{$prefix}member_tracking_tokens`
                            SET last_used_at = NOW()
                          WHERE id = ?",
                        [$authTokenId]
                    );
                }
            }
        } catch (Exception $e) {
            // Tables might not exist on older installs — fall through to shared-secret path.
        }
    }

    // Phase 91-followup (2026-06-24): unify OwnTracks auth with the
    // Phase 89 per-device token system. The "Mint Token" admin UI lets
    // operators mint device-scoped tokens for ANY provider — including
    // OwnTracks — but the OwnTracks receiver historically only checked
    // member_tracking_tokens (Phase 41). That made the admin UI's
    // OwnTracks option a silent dead-end.
    //
    // This check accepts the same Phase 89 token via any of three
    // transports OwnTracks devices and operators actually use:
    //   - HTTP Basic Auth password (the OwnTracks app's standard auth
    //     field — paste the minted token here)
    //   - ?token=<value> query param (curl-friendly)
    //   - Authorization: Bearer <value> header (custom HTTP clients)
    //
    // Matched tokens may optionally be bound to a specific TID via
    // location_ingest_tokens.device_unique_id; if set, the device's
    // tid field must match. Phase 41 member_tracking_tokens remains
    // the per-member rotation path; both coexist.
    $phase89TokenId = null;
    if ($authTokenId === null) {
        $candidates = [];
        if (!empty($basicPass)) $candidates[] = $basicPass;
        if (!empty($_GET['token'])) $candidates[] = (string) $_GET['token'];
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $h = (string) $_SERVER['HTTP_AUTHORIZATION'];
            if (stripos($h, 'bearer ') === 0) $candidates[] = trim(substr($h, 7));
        }
        if (!empty($candidates)) {
            try {
                $otProviderId = (int) db_fetch_value(
                    "SELECT id FROM `{$prefix}location_providers` WHERE code = 'owntracks'"
                );
                foreach ($candidates as $candidate) {
                    if ($candidate === '') continue;
                    $hash = hash('sha256', $candidate);
                    $tokRow = db_fetch_one(
                        "SELECT id, device_unique_id FROM `{$prefix}location_ingest_tokens`
                          WHERE secret_hash = ? AND revoked_at IS NULL
                            AND (provider_id IS NULL OR provider_id = ?)
                          LIMIT 1",
                        [$hash, $otProviderId]
                    );
                    if (!$tokRow) continue;
                    // Device-binding check: if the token was minted with
                    // a specific device_unique_id, the OwnTracks TID
                    // must match exactly.
                    if ($tokRow['device_unique_id'] !== null
                        && (string) $tokRow['device_unique_id'] !== (string) $tid) {
                        continue;
                    }
                    $phase89TokenId = (int) $tokRow['id'];
                    try {
                        db_query(
                            "UPDATE `{$prefix}location_ingest_tokens`
                                SET last_used_at = NOW(), last_used_ip = ?
                              WHERE id = ?",
                            [$_SERVER['REMOTE_ADDR'] ?? 'unknown', $phase89TokenId]
                        );
                    } catch (Exception $e) { /* non-fatal */ }
                    break;
                }
            } catch (Exception $e) {
                // location_ingest_tokens missing — fall through to legacy path.
            }
        }
    }

    // Fall back to legacy shared-secret if neither token system matched.
    // Any of the three auth paths is acceptable so a staged rollout works:
    //   - Phase 41 per-member token via Basic Auth
    //   - Phase 89 per-device token (new — covers Basic Auth password,
    //     ?token=, or Bearer header)
    //   - Legacy shared-secret via X-Limit-U header or ?secret=
    //
    // CJIS-grade lockdown: when owntracks_require_token=1 we reject
    // anything that didn't pass EITHER token path, regardless of legacy
    // shared-secret.
    if ($authTokenId === null && $phase89TokenId === null) {
        $requireToken = (int) get_variable('owntracks_require_token');
        if ($requireToken) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: application/json');
            echo '[]';
            exit;
        }
        // Phase 73v — CRITICAL: previously the shared-secret block
        // only ran if a non-empty owntracks_secret existed in
        // settings. With the default install (no secret set, no
        // require_token), the endpoint was wide open — anyone could
        // POST arbitrary coordinates as any tid, triggering geofence
        // alerts and polluting the map.
        //
        // New default: FAIL CLOSED. If neither the per-member token
        // path nor a configured shared-secret authenticated this
        // request, reject. Operators who genuinely want unauthenticated
        // ingest (rare; testing only) can explicitly set
        // `owntracks_allow_anonymous=1` to opt in.
        $otSecret = get_variable('owntracks_secret');
        $hasSecret = ($otSecret !== false && $otSecret !== '');
        if ($hasSecret) {
            $authHeader = isset($_SERVER['HTTP_X_LIMIT_U']) ? $_SERVER['HTTP_X_LIMIT_U'] : '';
            $querySecret = isset($_GET['secret']) ? $_GET['secret'] : '';
            if (!hash_equals((string) $otSecret, (string) $authHeader)
                && !hash_equals((string) $otSecret, (string) $querySecret)) {
                header('HTTP/1.1 403 Forbidden');
                header('Content-Type: application/json');
                echo '[]';
                exit;
            }
        } else {
            // No secret configured AND no token matched. Reject unless
            // operator explicitly opted in to anonymous ingest.
            $allowAnon = (int) get_variable('owntracks_allow_anonymous');
            if (!$allowAnon) {
                header('HTTP/1.1 403 Forbidden');
                header('Content-Type: application/json');
                header('X-OwnTracks-Reason: no-auth');
                echo '[]';
                exit;
            }
        }
    }

    // Look up the OwnTracks provider
    $otProvider = null;
    try {
        $otProvider = db_fetch_one(
            "SELECT `id`, `enabled` FROM `{$prefix}location_providers` WHERE `code` = 'owntracks'"
        );
    } catch (Exception $e) {
        // Provider not found
    }

    if (!$otProvider || !(int) $otProvider['enabled']) {
        header('Content-Type: application/json');
        echo '[]';
        exit;
    }

    // Parse optional OwnTracks fields
    $tst      = isset($ot['tst'])  ? (int) $ot['tst']    : null;
    $vel      = isset($ot['vel'])  ? (float) $ot['vel']  : null;
    $cog      = isset($ot['cog'])  ? (float) $ot['cog']  : null;
    $acc      = isset($ot['acc'])  ? (float) $ot['acc']  : null;
    $batt     = isset($ot['batt']) ? (int) $ot['batt']   : null;
    $alt      = isset($ot['alt'])  ? (float) $ot['alt']  : null;

    $reportedAt = $tst ? date('Y-m-d H:i:s', $tst) : date('Y-m-d H:i:s');

    // Truncate raw data if needed
    $rawData = $rawBody;
    if (strlen($rawData) > 65000) {
        $rawData = substr($rawData, 0, 65000);
    }

    try {
        // Phase 41: also write auth_token_id when basic-auth resolved a token.
        // We probe for the column once per process so older installs without
        // the migration still ingest cleanly.
        static $_hasAuthTokenCol = null;
        if ($_hasAuthTokenCol === null) {
            try {
                $_hasAuthTokenCol = (bool) db_fetch_value(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = ?
                        AND COLUMN_NAME = 'auth_token_id'",
                    [$prefix . 'location_reports']
                );
            } catch (Exception $e) { $_hasAuthTokenCol = false; }
        }
        if ($_hasAuthTokenCol) {
            db_query(
                "INSERT INTO `{$prefix}location_reports`
                 (`provider_id`, `unit_identifier`, `lat`, `lng`, `altitude`,
                  `speed`, `heading`, `accuracy`, `battery`, `raw_data`, `reported_at`, `auth_token_id`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [(int) $otProvider['id'], $tid, $lat, $lon, $alt,
                 $vel, $cog, $acc, $batt, $rawData, $reportedAt, $authTokenId ?? $phase89TokenId]
            );
        } else {
            db_query(
                "INSERT INTO `{$prefix}location_reports`
                 (`provider_id`, `unit_identifier`, `lat`, `lng`, `altitude`,
                  `speed`, `heading`, `accuracy`, `battery`, `raw_data`, `reported_at`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [(int) $otProvider['id'], $tid, $lat, $lon, $alt,
                 $vel, $cog, $acc, $batt, $rawData, $reportedAt]
            );
        }
    } catch (Exception $e) {
        // Silently fail per OwnTracks protocol
    }

    // Check geofences for this OwnTracks position update
    try {
        require_once __DIR__ . '/../inc/geofence.php';
        geofence_check($lat, $lon, $tid);
    } catch (Exception $e) {
        // Non-fatal
    }

    // Phase 41: piggy-back any queued setConfiguration on this position-POST
    // response so rotation pushes get applied without a separate channel.
    // Only fires when we authenticated the member via the token path.
    $messages = [];
    if ($authMemberId !== null) {
        try {
            $pending = db_fetch_one(
                "SELECT id, payload_json FROM `{$prefix}owntracks_outbox`
                  WHERE member_id = ? AND consumed_at IS NULL
                  ORDER BY id ASC LIMIT 1",
                [$authMemberId]
            );
            if ($pending) {
                $payload = json_decode($pending['payload_json'], true);
                if (is_array($payload)) {
                    $messages[] = $payload;
                    db_query(
                        "UPDATE `{$prefix}owntracks_outbox`
                            SET consumed_at = NOW()
                          WHERE id = ?",
                        [(int) $pending['id']]
                    );
                }
            }
        } catch (Exception $e) {
            // Outbox table might not exist yet — ignore.
        }
    }

    header('Content-Type: application/json');
    echo json_encode($messages);
    exit;
}

// ═════════════════════════════════════════════════════════════════
//  Native Traccar / OpenGTS HTTP ingest (Phase 87)
// ═════════════════════════════════════════════════════════════════
//
// Two providers, one shared receive path. Both write to the same
// `location_reports` table the OwnTracks endpoint above uses, so
// downstream consumers (map, breadcrumbs, geofences) treat all
// providers uniformly.
//
//   provider=traccar   → Traccar Server's "JSON" HTTP forwarder.
//                        Body is JSON like:
//                          {"device":{"uniqueId":"123456789012345","name":"Truck 7"},
//                           "position":{"latitude":44.97,"longitude":-93.26,
//                                       "speed":12.5,"course":180,"accuracy":5,
//                                       "altitude":900,"deviceTime":"2026-06-22T22:00:00Z",
//                                       "fixTime":"2026-06-22T22:00:00Z"}}
//                        The TicketsCAD-side device→responder binding key is
//                        device.uniqueId (typically the IMEI). Stored in
//                        location_reports.unit_identifier exactly as received.
//
//   provider=opengts   → Legacy OpenGTS protocol — Traccar Server's "OsmAnd"
//                        forwarder + countless hardware modems emit it.
//                        Query string OR x-www-form-urlencoded body, e.g.:
//                          ?id=861234567890123&lat=44.97&lon=-93.26
//                          &speed=12.5&heading=180&timestamp=1719000000
//                        Field aliases handled: id|deviceid|imei,
//                        lat|latitude, lon|lng|longitude,
//                        speed|spd, heading|bearing|cog,
//                        timestamp|time|tst (epoch or ISO8601),
//                        altitude|alt, accuracy|hdop.
//
// Auth:
//   - settings.location_ingest_require_token (default 0) — when 1,
//     a `?token=<value>` must match a row in `location_provider_tokens`
//     (or the legacy single-secret in settings.location_ingest_secret).
//     When 0, accept anonymous reports (fine on private LANs, behind
//     Cloudflare with IP allowlist, or for evaluation).
//   - Per-IP rate-limit reuses the same throttle the OwnTracks endpoint
//     uses (location_rate_limit_*) so a misconfigured device can't
//     flood the DB.

if ($method === 'POST' && isset($_GET['provider']) &&
    in_array($_GET['provider'], ['traccar', 'opengts'], true)) {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../inc/db.php';
    require_once __DIR__ . '/../inc/functions.php';  // get_variable()
    require_once __DIR__ . '/../inc/rate-limit.php'; // rate_limit_ok / _reject

    $providerCode = $_GET['provider'];
    $prefix       = $GLOBALS['db_prefix'] ?? '';

    // Per-IP rate limit — same shape Phase 73x uses for OwnTracks.
    // Generous default (600 req / 60 sec / IP) leaves headroom for a
    // chatty fleet on a single NAT'd uplink while still bounding the
    // damage a misconfigured device can do.
    $srcIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!rate_limit_ok($providerCode . '-ingest:' . $srcIp, 600, 60)) {
        rate_limit_reject(60);
    }

    // Lookup the provider row + confirm it's enabled.
    try {
        $provider = db_fetch_one(
            "SELECT id, enabled FROM `{$prefix}location_providers` WHERE code = ?",
            [$providerCode]
        );
    } catch (Exception $e) { $provider = null; }

    if (!$provider || !(int) $provider['enabled']) {
        // Same silent-200 pattern OwnTracks uses — devices treat any
        // 4xx/5xx as a retry signal which causes log spam.
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'note' => 'provider disabled']);
        exit;
    }

    // Two-tier auth: per-device tokens (Phase 89) preferred, legacy
    // shared secret (settings.location_ingest_secret) as fallback.
    //
    // The token may arrive via either ?token=... (works for any
    // forwarder including OpenGTS) or `Authorization: Bearer ...`
    // (Traccar Server's preferred form).
    $token = $_GET['token'] ?? '';
    if ($token === '' && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $h = $_SERVER['HTTP_AUTHORIZATION'];
        if (stripos($h, 'bearer ') === 0) {
            $token = trim(substr($h, 7));
        }
    }

    $authTokenId  = null;
    $boundDeviceId = null;
    if ($token !== '') {
        try {
            $hash = hash('sha256', $token);
            $row = db_fetch_one(
                "SELECT id, provider_id, device_unique_id
                   FROM `{$prefix}location_ingest_tokens`
                  WHERE secret_hash = ? AND revoked_at IS NULL
                  LIMIT 1",
                [$hash]
            );
            if ($row) {
                // Optional scoping — if the token was minted for one
                // provider, reject use on a different provider.
                if ($row['provider_id'] !== null
                    && (int) $row['provider_id'] !== (int) $provider['id']) {
                    http_response_code(403);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'token not valid for this provider']);
                    exit;
                }
                $authTokenId = (int) $row['id'];
                // device_unique_id binding is checked *after* we parse
                // the device id from the body (further down).
                $boundDeviceId = $row['device_unique_id'];
                // Update last_used trail. Best-effort — never block the
                // ingest on a stats UPDATE.
                try {
                    db_query(
                        "UPDATE `{$prefix}location_ingest_tokens`
                            SET last_used_at = NOW(), last_used_ip = ?
                          WHERE id = ?",
                        [$srcIp, $authTokenId]
                    );
                } catch (Exception $e) { /* non-fatal */ }
            }
        } catch (Exception $e) {
            // location_ingest_tokens not yet created on this install —
            // fall through to legacy-secret check.
        }
    }

    $requireToken = (int) get_variable('location_ingest_require_token');
    if ($authTokenId === null) {
        if ($requireToken) {
            // Strict mode + no per-device token matched — try legacy
            // shared secret as the last accepted path.
            $expected = (string) get_variable('location_ingest_secret');
            if ($expected === '' || !hash_equals($expected, (string) $token)) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'invalid or missing token']);
                exit;
            }
            // Legacy shared-secret authenticated; $authTokenId stays
            // null so the report attribution shows "shared secret"
            // in the admin UI.
        }
    }

    // ── Parse the device-id + position from whichever wire format ──
    $rawBody = file_get_contents('php://input') ?: '';
    $deviceId = null; $lat = null; $lon = null;
    $vel = null; $cog = null; $acc = null; $alt = null;
    $reportedAt = null;

    if ($providerCode === 'traccar') {
        $j = json_decode($rawBody, true);
        if (!is_array($j)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'expected JSON body for Traccar forwarder']);
            exit;
        }
        // Traccar's "JSON" forwarder shape — verified against
        // Traccar v6+ docs. Older releases used a flat shape; we
        // accept both for resilience.
        $deviceId = $j['device']['uniqueId']
                 ?? $j['uniqueId']
                 ?? $j['deviceId']
                 ?? null;
        $pos = $j['position'] ?? $j;
        $lat = isset($pos['latitude'])  ? (float) $pos['latitude']  : null;
        $lon = isset($pos['longitude']) ? (float) $pos['longitude'] : null;
        $vel = isset($pos['speed'])     ? (float) $pos['speed']     : null;
        $cog = isset($pos['course'])    ? (float) $pos['course']    : null;
        $acc = isset($pos['accuracy'])  ? (float) $pos['accuracy']  : null;
        $alt = isset($pos['altitude'])  ? (float) $pos['altitude']  : null;
        $ts  = $pos['fixTime'] ?? $pos['deviceTime'] ?? null;
        if ($ts) {
            // Traccar emits ISO8601 (e.g. "2026-06-22T22:00:00.000+00:00").
            $reportedAt = date('Y-m-d H:i:s', strtotime((string) $ts) ?: time());
        }
    } else {
        // OpenGTS — params can be in query string OR form body. Build
        // a unified $p array first, then pull the canonical keys.
        $p = $_GET;
        if ($rawBody !== '' && strpos($rawBody, '=') !== false) {
            parse_str($rawBody, $body);
            $p = array_merge($p, $body);
        }
        $deviceId = $p['id'] ?? $p['deviceid'] ?? $p['imei'] ?? null;
        $lat      = isset($p['lat'])       ? (float) $p['lat']       : (isset($p['latitude'])  ? (float) $p['latitude']  : null);
        $lon      = isset($p['lon'])       ? (float) $p['lon']       : (isset($p['lng'])       ? (float) $p['lng']       : (isset($p['longitude']) ? (float) $p['longitude'] : null));
        $vel      = isset($p['speed'])     ? (float) $p['speed']     : (isset($p['spd'])       ? (float) $p['spd']       : null);
        $cog      = isset($p['heading'])   ? (float) $p['heading']   : (isset($p['bearing'])   ? (float) $p['bearing']   : (isset($p['cog'])       ? (float) $p['cog']       : null));
        $acc      = isset($p['accuracy'])  ? (float) $p['accuracy']  : (isset($p['hdop'])      ? (float) $p['hdop'] * 5  : null);
        $alt      = isset($p['altitude'])  ? (float) $p['altitude']  : (isset($p['alt'])       ? (float) $p['alt']       : null);
        $ts       = $p['timestamp'] ?? $p['time'] ?? $p['tst'] ?? null;
        if ($ts !== null && $ts !== '') {
            if (is_numeric($ts)) {
                $reportedAt = date('Y-m-d H:i:s', (int) $ts);
            } else {
                $epoch = strtotime((string) $ts);
                if ($epoch !== false) $reportedAt = date('Y-m-d H:i:s', $epoch);
            }
        }
    }
    if ($reportedAt === null) $reportedAt = date('Y-m-d H:i:s');

    // Validate the minimum fields needed for a useful report.
    if ($deviceId === null || $deviceId === '' || $lat === null || $lon === null) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'missing required fields',
            'need'  => ['device id', 'lat', 'lon'],
            'got'   => ['device' => $deviceId, 'lat' => $lat, 'lon' => $lon],
        ]);
        exit;
    }

    // Per-device token binding check — if the token was minted with a
    // specific device_unique_id, reject reports that don't match it.
    // This prevents a leaked token from being used to report positions
    // for unrelated devices.
    if (!empty($boundDeviceId) && (string) $boundDeviceId !== (string) $deviceId) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'token bound to a different device_unique_id',
        ]);
        exit;
    }

    // Sanity bounds — refuses obvious garbage that some misconfigured
    // modems emit (0,0 "null island" or wildly out-of-range values).
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'lat/lon out of range']);
        exit;
    }
    // Most fleets never operate at exactly 0,0 — drop the report rather
    // than misplace a unit on Null Island. Operators who DO need 0,0
    // can disable this guard via setting location_ingest_allow_null_island=1.
    if (abs($lat) < 0.0001 && abs($lon) < 0.0001) {
        $allowNI = (int) get_variable('location_ingest_allow_null_island');
        if (!$allowNI) {
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'note' => 'null island (0,0) report dropped']);
            exit;
        }
    }

    // Truncate the raw payload audit blob.
    $rawAudit = $rawBody !== '' ? $rawBody : http_build_query($_GET);
    if (strlen($rawAudit) > 65000) $rawAudit = substr($rawAudit, 0, 65000);

    try {
        // Probe for the auth_token_id column once per process — older
        // installs that haven't run Phase 41 OR Phase 89 yet won't
        // have it, and we don't want every ingest hit to error out.
        // (Named differently from the OwnTracks-block static of the
        // same purpose because PHP rejects duplicate static names in
        // the same file scope.)
        static $_traccarHasAuthTokenCol = null;
        if ($_traccarHasAuthTokenCol === null) {
            try {
                $_traccarHasAuthTokenCol = (bool) db_fetch_value(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = ?
                        AND COLUMN_NAME = 'auth_token_id'",
                    [$prefix . 'location_reports']
                );
            } catch (Exception $e) { $_traccarHasAuthTokenCol = false; }
        }
        if ($_traccarHasAuthTokenCol) {
            db_query(
                "INSERT INTO `{$prefix}location_reports`
                    (provider_id, unit_identifier, lat, lng, altitude,
                     speed, heading, accuracy, battery, raw_data, reported_at, auth_token_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)",
                [(int) $provider['id'], (string) $deviceId, $lat, $lon, $alt,
                 $vel, $cog, $acc, $rawAudit, $reportedAt, $authTokenId]
            );
        } else {
            db_query(
                "INSERT INTO `{$prefix}location_reports`
                    (provider_id, unit_identifier, lat, lng, altitude,
                     speed, heading, accuracy, battery, raw_data, reported_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)",
                [(int) $provider['id'], (string) $deviceId, $lat, $lon, $alt,
                 $vel, $cog, $acc, $rawAudit, $reportedAt]
            );
        }
    } catch (Exception $e) {
        // Match OwnTracks' silent-fail behavior so a transient DB hiccup
        // doesn't make Traccar retry forever. The error is in apache logs.
        error_log("[location.php traccar/opengts] insert failed: " . $e->getMessage());
    }

    // Geofence check (same hook OwnTracks uses).
    try {
        require_once __DIR__ . '/../inc/geofence.php';
        if (function_exists('geofence_check')) {
            geofence_check($lat, $lon, (string) $deviceId);
        }
    } catch (Exception $e) { /* non-fatal */ }

    // Traccar / OpenGTS clients ignore the body but check the status code.
    // Return 200 with a tiny JSON ack so curl smoke tests can confirm.
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'provider' => $providerCode]);
    exit;
}

// ── Friendly GET on an INGEST url ───────────────────────────────
// The three ingest paths above (owntracks / traccar / opengts) are POST-only.
// When someone opens the forwarder/device URL in a browser to test it, the GET
// falls through to the auth-gated admin handler below and returns a scary
// {"error":"Not authenticated"} — which has NOTHING to do with the ingest and
// sends people chasing a phantom auth problem (Traccar beta report, 2026-07-24:
// "going to that web page I get Not authenticated"). Answer with a plain,
// helpful note instead so a browser sanity-check reads as "right URL, wrong
// method" rather than "auth is broken".
if ($method === 'GET' && isset($_GET['provider'])
    && in_array($_GET['provider'], ['owntracks', 'traccar', 'opengts'], true)) {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json');
    echo json_encode([
        'ok'       => false,
        'endpoint' => $_GET['provider'] . ' position-ingest',
        'method'   => 'POST',
        'note'     => 'This is the ' . $_GET['provider'] . ' position-ingest endpoint. '
                    . 'It accepts POST only — configure your device or Traccar '
                    . 'forward.url to POST here. Seeing this in a browser means the '
                    . 'URL is correct; it is NOT an authentication failure.',
    ]);
    exit;
}

// ── Standard auth + CSRF for all other endpoints ────────────────
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

// ── CSRF on writes ──────────────────────────────────────────────
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = $input['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }
}

// ═══════════════════════════════════════════════════════════════
//  GET — Read operations
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {

    // GET ?provider_id=X — single provider detail
    if (isset($_GET['provider_id'])) {
        $id = (int) $_GET['provider_id'];
        try {
            $row = db_fetch_one(
                "SELECT `id`, `code`, `name`, `enabled`, `priority`,
                        `config_json`, `icon`, `color`, `created_at`
                 FROM `{$prefix}location_providers`
                 WHERE `id` = ?",
                [$id]
            );
        } catch (Exception $e) {
            $row = null;
        }
        if (!$row) {
            json_error('Provider not found', 404);
        }
        // Decode config for the response
        $row['config'] = json_decode($row['config_json'], true);
        json_response(['provider' => $row]);
    }

    // GET ?unit=X — latest position for a unit identifier
    // Resolves via bindings: finds the highest-priority provider's latest FRESH report.
    // A report is considered fresh if it is within the provider's max_age_seconds threshold.
    // If the highest-priority provider's data is stale, falls through to the next provider.
    if (isset($_GET['unit'])) {
        $unit = trim($_GET['unit']);
        if ($unit === '') {
            json_error('Unit identifier required');
        }

        try {
            // Get latest report per provider, ordered by binding priority then provider priority.
            // The staleness filter is applied: only reports within max_age_seconds are "fresh".
            // First try fresh reports, then fall back to stale if nothing fresh.
            $row = db_fetch_one(
                "SELECT lr.`id`, lr.`provider_id`, lr.`unit_identifier`,
                        lr.`lat`, lr.`lng`, lr.`altitude`, lr.`speed`,
                        lr.`heading`, lr.`accuracy`, lr.`battery`,
                        lr.`reported_at`, lr.`received_at`,
                        lp.`code` AS `provider_code`, lp.`name` AS `provider_name`,
                        lp.`icon`, lp.`color`, lp.`priority`,
                        lp.`max_age_seconds`,
                        TIMESTAMPDIFF(SECOND, lr.`received_at`, NOW()) AS `age_seconds`,
                        CASE
                            WHEN TIMESTAMPDIFF(SECOND, lr.`received_at`, NOW()) <= lp.`max_age_seconds`
                            THEN 1 ELSE 0
                        END AS `is_fresh`
                 FROM `{$prefix}location_reports` lr
                 JOIN `{$prefix}location_providers` lp ON lr.`provider_id` = lp.`id`
                 WHERE lr.`unit_identifier` = ?
                   AND lp.`enabled` = 1
                 ORDER BY `is_fresh` DESC, lp.`priority` ASC, lr.`received_at` DESC
                 LIMIT 1",
                [$unit]
            );
        } catch (Exception $e) {
            $row = null;
        }
        json_response(['position' => $row]);
    }

    // GET ?all_units=1 — latest position for every bound unit (for map display)
    // Uses staleness-aware resolution: fresh reports (within max_age_seconds) are preferred.
    // Falls through to lower-priority providers if higher-priority data is stale.
    if (isset($_GET['all_units'])) {
        try {
            // For each active binding, get the latest report from the bound provider.
            // Sort by freshness first, then by binding+provider priority.
            $rows = db_fetch_all(
                "SELECT b.`responder_id`, b.`unit_identifier`, b.`priority` AS `binding_priority`,
                        lr.`lat`, lr.`lng`, lr.`altitude`, lr.`speed`,
                        lr.`heading`, lr.`accuracy`, lr.`battery`,
                        lr.`reported_at`, lr.`received_at`,
                        lp.`code` AS `provider_code`, lp.`name` AS `provider_name`,
                        lp.`icon`, lp.`color`, lp.`priority` AS `provider_priority`,
                        lp.`max_age_seconds`,
                        TIMESTAMPDIFF(SECOND, lr.`received_at`, NOW()) AS `age_seconds`,
                        CASE
                            WHEN TIMESTAMPDIFF(SECOND, lr.`received_at`, NOW()) <= lp.`max_age_seconds`
                            THEN 1 ELSE 0
                        END AS `is_fresh`
                 FROM `{$prefix}unit_location_bindings` b
                 JOIN `{$prefix}location_providers` lp ON b.`provider_id` = lp.`id`
                 JOIN `{$prefix}location_reports` lr
                   ON lr.`unit_identifier` = b.`unit_identifier`
                  AND lr.`provider_id` = b.`provider_id`
                 WHERE b.`active` = 1
                   AND lp.`enabled` = 1
                   AND lr.`received_at` = (
                       SELECT MAX(lr2.`received_at`)
                       FROM `{$prefix}location_reports` lr2
                       WHERE lr2.`unit_identifier` = b.`unit_identifier`
                         AND lr2.`provider_id` = b.`provider_id`
                   )
                 ORDER BY `is_fresh` DESC, b.`priority` ASC, lp.`priority` ASC"
            );
        } catch (Exception $e) {
            $rows = [];
        }

        // De-duplicate: keep only the best entry per responder_id
        // (fresh reports from highest-priority binding come first due to ORDER BY)
        $seen = [];
        $units = [];
        foreach ($rows as $row) {
            $rid = (int) $row['responder_id'];
            if (!isset($seen[$rid])) {
                $seen[$rid] = true;
                $units[] = $row;
            }
        }

        json_response([
            'units' => $units,
            'count' => count($units),
        ]);
    }

    // GET ?responder_id=X — latest position for a specific responder (via bindings)
    // Returns the best (freshest, highest-priority) position report.
    if (isset($_GET['responder_id'])) {
        $rid = (int) $_GET['responder_id'];
        if (!$rid) {
            json_error('responder_id is required');
        }

        try {
            $row = db_fetch_one(
                "SELECT b.`responder_id`, b.`unit_identifier`, b.`priority` AS `binding_priority`,
                        lr.`lat`, lr.`lng`, lr.`altitude`, lr.`speed`,
                        lr.`heading`, lr.`accuracy`, lr.`battery`,
                        lr.`reported_at`, lr.`received_at`,
                        lp.`code` AS `provider_code`, lp.`name` AS `provider_name`,
                        lp.`icon`, lp.`color`, lp.`priority` AS `provider_priority`,
                        lp.`max_age_seconds`,
                        TIMESTAMPDIFF(SECOND, lr.`received_at`, NOW()) AS `age_seconds`,
                        CASE
                            WHEN TIMESTAMPDIFF(SECOND, lr.`received_at`, NOW()) <= lp.`max_age_seconds`
                            THEN 1 ELSE 0
                        END AS `is_fresh`
                 FROM `{$prefix}unit_location_bindings` b
                 JOIN `{$prefix}location_providers` lp ON b.`provider_id` = lp.`id`
                 JOIN `{$prefix}location_reports` lr
                   ON lr.`unit_identifier` = b.`unit_identifier`
                  AND lr.`provider_id` = b.`provider_id`
                 WHERE b.`responder_id` = ?
                   AND b.`active` = 1
                   AND lp.`enabled` = 1
                   AND lr.`received_at` = (
                       SELECT MAX(lr2.`received_at`)
                       FROM `{$prefix}location_reports` lr2
                       WHERE lr2.`unit_identifier` = b.`unit_identifier`
                         AND lr2.`provider_id` = b.`provider_id`
                   )
                 ORDER BY `is_fresh` DESC, b.`priority` ASC, lp.`priority` ASC
                 LIMIT 1",
                [$rid]
            );
        } catch (Exception $e) {
            $row = null;
        }

        // Also get personnel currently assigned to this unit
        $personnel = [];
        try {
            $personnel = db_fetch_all(
                "SELECT upa.`id` AS `assignment_id`, upa.`member_id`, upa.`role`, upa.`status`,
                        CONCAT(m.`first_name`, ' ', m.`last_name`) AS `member_name`,
                        m.`callsign` AS `member_callsign`
                 FROM `{$prefix}unit_personnel_assignments` upa
                 LEFT JOIN `{$prefix}member` m ON upa.`member_id` = m.`id`
                 WHERE upa.`responder_id` = ? AND upa.`status` != 'released'
                 ORDER BY upa.`assigned_at` ASC",
                [$rid]
            );
        } catch (Exception $e) {}

        json_response([
            'position'  => $row,
            'personnel' => $personnel,
        ]);
    }

    // GET (no params) — list all providers
    try {
        $rows = db_fetch_all(
            "SELECT `id`, `code`, `name`, `enabled`, `priority`,
                    `config_json`, `icon`, `color`, `max_age_seconds`, `created_at`
             FROM `{$prefix}location_providers`
             ORDER BY `priority` ASC"
        );
    } catch (Exception $e) {
        $rows = [];
    }

    // Decode config for each provider
    foreach ($rows as &$row) {
        $row['config'] = json_decode($row['config_json'], true);
    }
    unset($row);

    json_response([
        'providers' => $rows,
        'count'     => count($rows),
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — Write operations
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $action = $input['action'] ?? '';

    // ── test_aprs — test a single APRS callsign lookup ─────────
    if ($action === 'test_aprs') {
        if (!is_admin()) {
            json_error('Admin access required', 403);
        }

        $callsign = trim($input['callsign'] ?? '');
        $apiKey   = trim($input['api_key'] ?? '');

        if ($callsign === '' || $apiKey === '') {
            json_error('callsign and api_key are required');
        }

        // Phase 73x — callsign validation. Without this, an admin
        // (or a session-fixated admin) could craft a value like
        // `FOO&apikey=BAD&url=...#` so that the urlencode + format-string
        // composition added &apikey=BAD to the request URL, leaking
        // the operator's real apikey to whatever the attacker logged.
        // Whitelist to the FCC / ITU callsign character set + APRS
        // SSID separator. 1–10 chars covers everything from ham (W1AW)
        // to APRS-SSID (W1AW-9).
        if (!preg_match('/^[A-Z0-9\-]{1,10}$/i', $callsign)) {
            json_error('Invalid callsign — must be 1-10 alphanumeric/dash chars', 400);
        }
        // Strip anything dubious from api_key too (alphanumeric +
        // dot per aprs.fi's own format).
        if (!preg_match('/^[A-Za-z0-9.\-]{8,40}$/', $apiKey)) {
            json_error('Invalid api_key format', 400);
        }

        $url = 'https://api.aprs.fi/api/get?'
             . 'name=' . urlencode($callsign)
             . '&what=loc'
             . '&apikey=' . urlencode($apiKey)
             . '&format=json';

        $response = null;
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'TicketsCAD-NewUI/4.0');
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($body === false || $httpCode !== 200) {
                // Issue #32 followup (a beta tester 2026-07-03): the raw curl
                // error "SSL certificate problem: certificate has
                // expired" almost never means aprs.fi's cert actually
                // expired — it means the local server's CA-bundle is
                // stale (Let's Encrypt root cross-sign rollover, or
                // just an old Debian install that hasn't apt-updated
                // ca-certificates in a year). Translate the raw error
                // into actionable guidance so the operator knows to
                // update the trust store instead of assuming aprs.fi
                // is down.
                if ($curlErr !== '' && stripos($curlErr, 'certificate') !== false
                    && (stripos($curlErr, 'expired') !== false
                        || stripos($curlErr, 'unable to get local issuer') !== false)) {
                    json_error(
                        "aprs.fi TLS check failed on your server ({$curlErr}). "
                      . "aprs.fi's live cert is valid — your server's CA bundle "
                      . "is out of date. On Debian/Ubuntu run: "
                      . "sudo apt update && sudo apt install --reinstall ca-certificates. "
                      . "On RHEL/CentOS: sudo yum update ca-certificates. "
                      . "Then retry this test.",
                        502
                    );
                }
                json_error('aprs.fi request failed (HTTP ' . $httpCode . '): ' . $curlErr, 502);
            }
            $response = $body;
        } else {
            $ctx = stream_context_create([
                'http' => ['timeout' => 15, 'user_agent' => 'TicketsCAD-NewUI/4.0'],
            ]);
            $response = @file_get_contents($url, false, $ctx);
            if ($response === false) {
                json_error('aprs.fi request failed', 502);
            }
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            json_error('Invalid JSON from aprs.fi', 502);
        }

        if (!isset($data['result']) || strtoupper($data['result']) !== 'OK') {
            $desc = isset($data['description']) ? $data['description'] : 'unknown error';
            json_error('aprs.fi error: ' . $desc);
        }

        $position = null;
        if (isset($data['entries']) && !empty($data['entries'])) {
            $entry = $data['entries'][0];
            $position = [
                'name'     => isset($entry['name']) ? $entry['name'] : $callsign,
                'lat'      => isset($entry['lat']) ? (float) $entry['lat'] : null,
                'lng'      => isset($entry['lng']) ? (float) $entry['lng'] : null,
                'altitude' => isset($entry['altitude']) ? (float) $entry['altitude'] : null,
                'speed'    => isset($entry['speed']) ? (float) $entry['speed'] : null,
                'course'   => isset($entry['course']) ? (float) $entry['course'] : null,
                'lasttime' => isset($entry['lasttime']) ? (int) $entry['lasttime'] : null,
            ];
        }

        json_response(['position' => $position]);
    }

    // ── report — ingest a location report ─────────────────────
    if ($action === 'report') {
        // Phase 73v — CRITICAL: previously this endpoint accepted
        // ANY logged-in session (including read-only roles) POSTing
        // an arbitrary unit_identifier + provider_code + lat/lng, so
        // a low-priv account could move SWAT-7 onto the wrong block
        // and fire geofence alerts. Gate with RBAC: only the dispatcher,
        // unit-status-changer, or admin may post a location report on
        // behalf of a unit. (The bridge daemons + OwnTracks clients
        // never use action=report — they hit their own dedicated
        // ingest paths.)
        require_once __DIR__ . '/../inc/rbac.php';
        if (!is_admin()
            && !rbac_can('action.change_unit_status')
            && !rbac_can('action.dispatch_unit')
            && !rbac_can('action.report_location')) {
            json_error('Forbidden — reporting locations requires dispatcher or admin role', 403);
        }

        $providerCode   = trim($input['provider_code'] ?? '');
        $unitIdentifier = trim($input['unit_identifier'] ?? '');
        $lat            = isset($input['lat']) ? (float) $input['lat'] : null;
        $lng            = isset($input['lng']) ? (float) $input['lng'] : null;

        if ($providerCode === '' || $unitIdentifier === '') {
            json_error('provider_code and unit_identifier are required');
        }
        if ($lat === null || $lng === null) {
            json_error('lat and lng are required');
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            json_error('lat must be -90..90, lng must be -180..180');
        }

        // Resolve provider by code
        try {
            $provider = db_fetch_one(
                "SELECT `id`, `enabled` FROM `{$prefix}location_providers` WHERE `code` = ?",
                [$providerCode]
            );
        } catch (Exception $e) {
            json_error('Failed to look up provider: ' . $e->getMessage(), 500);
        }
        if (!$provider) {
            json_error('Unknown provider code: ' . $providerCode, 404);
        }
        if (!(int) $provider['enabled']) {
            json_error('Provider is disabled: ' . $providerCode, 403);
        }

        $altitude   = isset($input['altitude']) ? (float) $input['altitude'] : null;
        $speed      = isset($input['speed'])    ? (float) $input['speed']    : null;
        $heading    = isset($input['heading'])  ? (float) $input['heading']  : null;
        $accuracy   = isset($input['accuracy']) ? (float) $input['accuracy'] : null;
        $battery    = isset($input['battery'])  ? (int) $input['battery']    : null;
        $rawData    = isset($input['raw_data']) ? $input['raw_data']         : null;
        $reportedAt = isset($input['reported_at']) ? $input['reported_at']   : date('Y-m-d H:i:s');

        if (is_string($rawData) && strlen($rawData) > 65000) {
            $rawData = substr($rawData, 0, 65000);
        }

        try {
            db_query(
                "INSERT INTO `{$prefix}location_reports`
                 (`provider_id`, `unit_identifier`, `lat`, `lng`, `altitude`,
                  `speed`, `heading`, `accuracy`, `battery`, `raw_data`, `reported_at`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [(int) $provider['id'], $unitIdentifier, $lat, $lng, $altitude,
                 $speed, $heading, $accuracy, $battery, $rawData, $reportedAt]
            );
            $reportId = (int) db_insert_id();

            // Check geofences for this location update
            $gfEvents = [];
            try {
                require_once __DIR__ . '/../inc/geofence.php';
                $gfEvents = geofence_check($lat, $lng, $unitIdentifier);
            } catch (Exception $gfErr) {
                // Non-fatal — geofence tables may not exist yet
            }

            json_response(['saved' => true, 'id' => $reportId, 'geofence_events' => $gfEvents]);
        } catch (Exception $e) {
            json_error('Failed to save report: ' . $e->getMessage(), 500);
        }
    }

    // ── save_provider — update provider config ────────────────
    if ($action === 'save_provider') {
        // Require admin
        if (!is_admin()) {
            json_error('Admin access required', 403);
        }

        $id = isset($input['id']) ? (int) $input['id'] : 0;
        if (!$id) {
            json_error('Provider id is required');
        }

        $sets = [];
        $params = [];

        if (isset($input['enabled'])) {
            $sets[] = '`enabled` = ?';
            $params[] = (int) $input['enabled'];
        }
        if (isset($input['priority'])) {
            $sets[] = '`priority` = ?';
            $params[] = (int) $input['priority'];
        }
        if (isset($input['config_json'])) {
            // Validate it's valid JSON
            $decoded = json_decode($input['config_json'], true);
            if ($decoded === null && $input['config_json'] !== 'null') {
                json_error('config_json must be valid JSON');
            }
            $sets[] = '`config_json` = ?';
            $params[] = $input['config_json'];
        }
        if (isset($input['max_age_seconds'])) {
            $maxAge = (int) $input['max_age_seconds'];
            if ($maxAge < 10 || $maxAge > 86400) {
                json_error('max_age_seconds must be between 10 and 86400 (10s to 24h)');
            }
            $sets[] = '`max_age_seconds` = ?';
            $params[] = $maxAge;
        }

        if (empty($sets)) {
            json_error('Nothing to update');
        }

        $params[] = $id;
        try {
            db_query(
                "UPDATE `{$prefix}location_providers` SET " . implode(', ', $sets) . " WHERE `id` = ?",
                $params
            );
            audit_log('config', 'update', 'location_provider', $id,
                "Updated location provider #{$id}",
                array_intersect_key($input, array_flip(['enabled', 'priority'])));
            json_response(['saved' => true, 'id' => $id]);
        } catch (Exception $e) {
            json_error('Save failed: ' . $e->getMessage(), 500);
        }
    }

    // ── bind — bind a responder to a provider/identifier ──────
    if ($action === 'bind') {
        // Require admin
        if (!is_admin()) {
            json_error('Admin access required', 403);
        }

        $responderId    = isset($input['responder_id']) ? (int) $input['responder_id'] : 0;
        $providerId     = isset($input['provider_id']) ? (int) $input['provider_id'] : 0;
        $unitIdentifier = trim($input['unit_identifier'] ?? '');
        $priority       = isset($input['priority']) ? (int) $input['priority'] : 50;

        if (!$responderId || !$providerId || $unitIdentifier === '') {
            json_error('responder_id, provider_id, and unit_identifier are required');
        }

        try {
            // Check for existing binding (same responder + provider + identifier)
            $existing = db_fetch_one(
                "SELECT `id` FROM `{$prefix}unit_location_bindings`
                 WHERE `responder_id` = ? AND `provider_id` = ? AND `unit_identifier` = ?",
                [$responderId, $providerId, $unitIdentifier]
            );

            if ($existing) {
                // Re-activate if it existed
                db_query(
                    "UPDATE `{$prefix}unit_location_bindings`
                     SET `active` = 1, `priority` = ?
                     WHERE `id` = ?",
                    [$priority, (int) $existing['id']]
                );
                $bindId = (int) $existing['id'];
            } else {
                db_query(
                    "INSERT INTO `{$prefix}unit_location_bindings`
                     (`responder_id`, `provider_id`, `unit_identifier`, `priority`, `active`)
                     VALUES (?, ?, ?, ?, 1)",
                    [$responderId, $providerId, $unitIdentifier, $priority]
                );
                $bindId = (int) db_insert_id();
            }

            audit_log('config', 'create', 'location_binding', $bindId,
                "Bound responder #{$responderId} to provider #{$providerId} as '{$unitIdentifier}'");
            json_response(['saved' => true, 'id' => $bindId]);
        } catch (Exception $e) {
            json_error('Bind failed: ' . $e->getMessage(), 500);
        }
    }

    // ── unbind — deactivate a binding ─────────────────────────
    if ($action === 'unbind') {
        // Require admin
        if (!is_admin()) {
            json_error('Admin access required', 403);
        }

        $bindId = isset($input['id']) ? (int) $input['id'] : 0;
        if (!$bindId) {
            json_error('Binding id is required');
        }

        try {
            db_query(
                "UPDATE `{$prefix}unit_location_bindings` SET `active` = 0 WHERE `id` = ?",
                [$bindId]
            );
            audit_log('config', 'update', 'location_binding', $bindId,
                "Deactivated location binding #{$bindId}");
            json_response(['unbound' => true, 'id' => $bindId]);
        } catch (Exception $e) {
            json_error('Unbind failed: ' . $e->getMessage(), 500);
        }
    }

    json_error('Unknown action: ' . $action, 400);
}

json_error('Method not allowed', 405);
