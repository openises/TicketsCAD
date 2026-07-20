<?php
/**
 * Phase 99a-followup (2026-06-28) — personnel picker for the
 * messaging Compose recipient field.
 *
 *   GET /api/messaging-personnel.php?channel=smtp[&q=jus]
 *
 *     channel: 'smtp' | 'sms' (others added when those broker
 *              handlers land)
 *     q:       optional search string — matches first_name,
 *              last_name, email, or phone (LIKE %q%)
 *
 *   Response:
 *     { members: [
 *         { id, name, address, secondary },
 *         ...
 *       ],
 *       count: N }
 *
 *   - `name`      = full display name (first + last, fallback to
 *                   middle/legacy `who`)
 *   - `address`   = channel-appropriate contact: email for smtp,
 *                   phone_cell || phone || phone_home for sms
 *   - `secondary` = small subtitle text (the email/phone, for
 *                   visual confirmation in the picker)
 *
 * Returns max 25 results. The Compose autocomplete shows the
 * top 10 + a "refine search" hint.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Gate to the same RBAC permission that lets you send messages —
// no point listing recipients to someone who can't send.
if (!rbac_can('action.send_chat') && !rbac_can('action.send_message')) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

$channel = strtolower(trim((string) ($_GET['channel'] ?? 'smtp')));
$q       = trim((string) ($_GET['q'] ?? ''));
$prefix  = $GLOBALS['db_prefix'] ?? '';

if (!in_array($channel, ['smtp', 'sms', 'meshtastic', 'meshcore'], true)) {
    http_response_code(400);
    echo json_encode(['error' => "Channel '{$channel}' is not yet supported by the personnel picker"]);
    exit;
}

// ── Mesh-node picker (Phase 99a #11 follow-on, 2026-06-28) ──────
// Mesh channels search mesh_nodes by long_name / short_name /
// node_id. Eric: "no human is using the node id that starts with
// a ! followed by ~8 digits in hex. We instead use Long or short
// names. The send form in messages needs to search those long
// and short names and propose matches."
//
// Order by last_seen_at DESC so recently-active nodes float to
// the top. Return shape matches the existing personnel picker
// (id / name / address / secondary) so the JS reuses the same
// dropdown render path.
if ($channel === 'meshtastic' || $channel === 'meshcore') {
    $where  = ["`protocol` = ?"];
    $params = [$channel];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(`long_name` LIKE ? OR `short_name` LIKE ? OR `node_id` LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $whereSql = implode(' AND ', $where);
    try {
        $rows = db_fetch_all(
            "SELECT `node_id`, `long_name`, `short_name`, `last_seen_at`
             FROM `{$prefix}mesh_nodes`
             WHERE {$whereSql}
             ORDER BY `last_seen_at` DESC
             LIMIT 25",
            $params
        );
        $members = [];
        $now = time();
        foreach ($rows as $r) {
            $long  = trim((string) ($r['long_name']  ?? ''));
            $short = trim((string) ($r['short_name'] ?? ''));
            $nid   = (string) ($r['node_id'] ?? '');
            // Display name: prefer long, fall back to short, then node_id.
            $name = $long !== '' ? $long : ($short !== '' ? $short : $nid);
            // Secondary line: short-name in []s + freshness ('5m', '2h', '3d').
            $sec  = ($short !== '' && $short !== $name) ? '[' . $short . '] ' : '';
            $sec .= $nid;
            if (!empty($r['last_seen_at'])) {
                $ts = strtotime((string) $r['last_seen_at']);
                if ($ts) {
                    $age = $now - $ts;
                    if      ($age <  120)  $sec .= ' · just now';
                    elseif  ($age < 3600)  $sec .= ' · ' . (int) ($age / 60)  . 'm ago';
                    elseif  ($age < 86400) $sec .= ' · ' . (int) ($age / 3600). 'h ago';
                    else                   $sec .= ' · ' . (int) ($age / 86400) . 'd ago';
                }
            }
            $members[] = [
                'id'        => $nid,
                'name'      => $name,
                'address'   => $nid,
                'secondary' => $sec,
            ];
        }
        echo json_encode([
            'channel' => $channel,
            'members' => $members,
            'count'   => count($members),
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Mesh node lookup failed: ' . $e->getMessage()]);
    }
    exit;
}

// Per-channel column selection. SMTP → email. SMS prefers
// phone_cell, then phone (the generated 'best phone' column),
// then phone_home as a last resort.
//
// Members with no usable address for the channel are excluded
// — otherwise the picker would show names you can't send to.
if ($channel === 'smtp') {
    $addrExpr = "`m`.`email`";
    $addrCond = "`m`.`email` IS NOT NULL AND `m`.`email` <> ''";
} else {
    // COALESCE finds the first non-empty / non-null in order.
    $addrExpr = "COALESCE(NULLIF(`m`.`phone_cell`, ''), NULLIF(`m`.`phone`, ''), NULLIF(`m`.`phone_home`, ''))";
    $addrCond = "(NULLIF(`m`.`phone_cell`, '') IS NOT NULL "
              . " OR NULLIF(`m`.`phone`, '') IS NOT NULL "
              . " OR NULLIF(`m`.`phone_home`, '') IS NOT NULL)";
}

$where  = ["`m`.`deleted_at` IS NULL", $addrCond];
$params = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = "(`m`.`first_name` LIKE ? OR `m`.`last_name` LIKE ? OR {$addrExpr} LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
$whereSql = implode(' AND ', $where);

try {
    // Pull a reasonable batch. Order: alphabetical by last name.
    // 25 cap so the autocomplete doesn't drown the dropdown; the
    // JS shows the first 10 + a "refine" hint when count > 10.
    $rows = db_fetch_all(
        "SELECT `m`.`id`,
                `m`.`first_name`,
                `m`.`last_name`,
                {$addrExpr} AS address
         FROM `{$prefix}member` `m`
         WHERE {$whereSql}
         ORDER BY `m`.`last_name` ASC, `m`.`first_name` ASC
         LIMIT 25",
        $params
    );

    $members = [];
    foreach ($rows as $r) {
        $first = trim((string) ($r['first_name'] ?? ''));
        $last  = trim((string) ($r['last_name']  ?? ''));
        $name = trim($first . ' ' . $last);
        if ($name === '') $name = '#' . $r['id'];

        $members[] = [
            'id'        => (int) $r['id'],
            'name'      => $name,
            'address'   => (string) $r['address'],
            'secondary' => (string) $r['address'],
        ];
    }

    echo json_encode([
        'channel' => $channel,
        'members' => $members,
        'count'   => count($members),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Lookup failed: ' . $e->getMessage()]);
}
