<?php
/**
 * NewUI v4.0 API — DMR ID → name/callsign lookup
 *
 * GET /api/dmr-lookup.php?dmr_id=NNNN
 *
 * Phase 84w — radio widget enriches call cards with sender names.
 *
 * Resolves a numeric DMR Radio ID against the local personnel roster
 * (`member_comm_identifiers` with `comm_modes.code='dmr'`), and on miss
 * falls back to a small `radioid_users` cache table (which an admin
 * task may populate from radioid.net periodically — for now the cache
 * is opportunistic: a populated row wins, an absent row simply returns
 * null and the widget displays just the bare DMR ID).
 *
 * Response shapes:
 *   200 — { "dmr_id": 3127202, "source": "personnel",
 *           "member_id": 132, "name": "Eric Osterberg",
 *           "callsign": "N0NKI" }
 *   200 — { "dmr_id": 3127202, "source": "radioid_cache",
 *           "name": "Eric Osterberg", "callsign": "N0NKI",
 *           "country": "United States" }
 *   200 — { "dmr_id": 3127202, "source": "unknown" }
 *
 * RBAC: action.dmr_receive (or admin). Same gate as /api/dmr-stream.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
ini_set('display_errors', '0');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET required']);
    exit;
}

$rbacOk = function_exists('rbac_can') && (
    rbac_can('action.dmr_receive') || rbac_can('action.play_dmr_audio')
);
if (!is_admin() && !$rbacOk) {
    http_response_code(403);
    echo json_encode(['error' => 'Missing required permission: action.dmr_receive']);
    exit;
}

$dmrId = (int) ($_GET['dmr_id'] ?? 0);
if ($dmrId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'dmr_id required']);
    exit;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$result = ['dmr_id' => $dmrId, 'source' => 'unknown'];

// 1. Personnel roster (preferred). member_comm_identifiers stores the
//    radio_id under values_json — JSON_EXTRACT works on MariaDB 10.2+.
$row = null;
try {
    $row = db_fetch_one(
        "SELECT m.id AS member_id, m.first_name, m.last_name, mci.values_json,
                (SELECT callsign FROM `{$prefix}member_callsigns`
                 WHERE member_id = m.id AND is_primary = 1 LIMIT 1) AS callsign
           FROM `{$prefix}member_comm_identifiers` mci
           JOIN `{$prefix}comm_modes` cm ON cm.id = mci.comm_mode_id
           JOIN `{$prefix}member` m       ON m.id  = mci.member_id
          WHERE cm.code = 'dmr'
            AND JSON_UNQUOTE(JSON_EXTRACT(mci.values_json, '\$.radio_id')) = ?
          LIMIT 1",
        [(string) $dmrId]
    );
} catch (Exception $e) {
    // JSON_EXTRACT may fail on older MariaDB. Fall through to substring.
}
if (!$row) {
    try {
        $row = db_fetch_one(
            "SELECT m.id AS member_id, m.first_name, m.last_name,
                    (SELECT callsign FROM `{$prefix}member_callsigns`
                     WHERE member_id = m.id AND is_primary = 1 LIMIT 1) AS callsign
               FROM `{$prefix}member_comm_identifiers` mci
               JOIN `{$prefix}comm_modes` cm ON cm.id = mci.comm_mode_id
               JOIN `{$prefix}member` m       ON m.id  = mci.member_id
              WHERE cm.code = 'dmr'
                AND mci.values_json LIKE ?
              LIMIT 1",
            ['%"radio_id":"' . $dmrId . '"%']
        );
    } catch (Exception $e) {
        // Table missing on a fresh install — silently degrade to 'unknown'.
    }
}
if ($row) {
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    echo json_encode([
        'dmr_id'    => $dmrId,
        'source'    => 'personnel',
        'member_id' => (int) $row['member_id'],
        'name'      => $name !== '' ? $name : null,
        'callsign'  => $row['callsign'] ?: null,
    ]);
    exit;
}

// 2. radioid_users cache (created by Phase 84x).
$haveCacheTable = true;
try {
    $cache = db_fetch_one(
        "SELECT dmr_id, callsign, fname, surname, country
           FROM `{$prefix}radioid_users` WHERE dmr_id = ? LIMIT 1",
        [$dmrId]
    );
    if ($cache) {
        $name = trim(($cache['fname'] ?? '') . ' ' . ($cache['surname'] ?? ''));
        echo json_encode([
            'dmr_id'   => $dmrId,
            'source'   => 'radioid_cache',
            'name'     => $name !== '' ? $name : null,
            'callsign' => $cache['callsign'] ?: null,
            'country'  => $cache['country'] ?: null,
        ]);
        exit;
    }
} catch (Exception $e) {
    // Cache table doesn't exist — Phase 84x migration hasn't run yet.
    $haveCacheTable = false;
}

// 3. Live radioid.net lookup. Polite caching: every hit gets upserted
//    into the cache table so the next call is local. radioid.net asks
//    for aggressive caching in their TOS; we honour that.
//    The API returns either:
//      success — {"count":1,"results":[{"id":3127202,"callsign":"N0NKI",
//                  "fname":"Eric","surname":"Osterberg","country":"United States",
//                  "state":"Minnesota","city":"..."}]}
//      not-found — {"count":0,"results":[]}
$liveUrl = 'https://database.radioid.net/api/dmr/user/?id=' . $dmrId;
$ch = curl_init($liveUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_USERAGENT      => 'TicketsCAD/4.0 (DMR radio widget)',
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    // Default SSL verification — radioid.net has a valid LE cert.
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body !== false && $code === 200) {
    $j = json_decode($body, true);
    if (is_array($j) && !empty($j['results'][0]['id'])) {
        $r = $j['results'][0];
        $row = [
            'dmr_id'   => (int) $r['id'],
            'callsign' => substr((string) ($r['callsign'] ?? ''), 0, 16),
            'fname'    => substr((string) ($r['fname']    ?? ''), 0, 64),
            'surname'  => substr((string) ($r['surname']  ?? ''), 0, 64),
            'country'  => substr((string) ($r['country']  ?? ''), 0, 64),
            'state'    => substr((string) ($r['state']    ?? ''), 0, 64),
            'city'     => substr((string) ($r['city']     ?? ''), 0, 64),
        ];
        if ($haveCacheTable) {
            try {
                db_query(
                    "INSERT INTO `{$prefix}radioid_users`
                        (dmr_id, callsign, fname, surname, country, state, city, fetched_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                       callsign=VALUES(callsign), fname=VALUES(fname),
                       surname=VALUES(surname), country=VALUES(country),
                       state=VALUES(state), city=VALUES(city),
                       fetched_at=NOW()",
                    [
                        $row['dmr_id'], $row['callsign'], $row['fname'],
                        $row['surname'], $row['country'], $row['state'], $row['city'],
                    ]
                );
            } catch (Exception $e) {
                // Cache write failed — return the answer anyway, don't lose the lookup.
            }
        }
        $name = trim($row['fname'] . ' ' . $row['surname']);
        echo json_encode([
            'dmr_id'   => $row['dmr_id'],
            'source'   => 'radioid_live',
            'name'     => $name !== '' ? $name : null,
            'callsign' => $row['callsign'] ?: null,
            'country'  => $row['country'] ?: null,
            'state'    => $row['state']   ?: null,
            'city'     => $row['city']    ?: null,
        ]);
        exit;
    }
}

// 4. Unknown — return source=unknown so the widget can render bare ID.
echo json_encode($result);
