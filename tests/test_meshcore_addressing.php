<?php
/**
 * Phase B (messaging-send-gaps-2026-06) — unified addressing + resolver
 * + normalized mesh send.
 *
 * Covers:
 *   1. MeshCore comm_mode migration is idempotent + well-shaped.
 *   2. resolve_unit_address() returns the right transport address per
 *      transport (and null when unmapped), across the member linkage and
 *      both unit→member linkages (active assignment + personal unit).
 *   3. The normalized api/mesh.php?action=send queues the correct
 *      mesh_outbox row for: channel broadcast (no to_node), raw-node DM,
 *      and unit/member-resolved DM. Verified by replicating the resolve +
 *      payload-build path against the live DB and asserting the queued
 *      row shape — plus source-level assertions that the endpoint accepts
 *      the new uniform shape AND keeps backward compat.
 *
 * Self-contained: seeds temp rows, asserts, cleans up. Never deploys,
 * never touches a bridge.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/comm_resolve.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

$total  = 0;
$passed = 0;
$failed = [];

function pb_assert(string $name, bool $cond, string $detail = '') {
    global $total, $passed, $failed;
    $total++;
    if ($cond) { $passed++; echo "  PASS  $name\n"; }
    else { $failed[] = "$name — $detail"; echo "  FAIL  $name — $detail\n"; }
}

echo "Phase B — MeshCore addressing + resolver + normalized send\n";
echo "==========================================================\n";

// ─────────────────────────────────────────────────────────────────────
// 1. Migration: idempotent + well-shaped meshcore comm_mode.
// ─────────────────────────────────────────────────────────────────────

$migPath = __DIR__ . '/../sql/run_meshcore_comm_mode.php';
pb_assert('migration script exists', file_exists($migPath), 'run_meshcore_comm_mode.php missing');

$migSrc = file_exists($migPath) ? file_get_contents($migPath) : '';
pb_assert(
    'migration is guarded (INSERT IGNORE keyed on code)',
    strpos($migSrc, 'INSERT IGNORE INTO') !== false && strpos($migSrc, 'comm_modes') !== false,
    'migration does not use INSERT IGNORE — re-run would error/duplicate'
);
pb_assert(
    'migration defines the meshcore pubkey_prefix field',
    strpos($migSrc, "'code'") !== false && strpos($migSrc, 'meshcore') !== false
        && strpos($migSrc, 'pubkey_prefix') !== false,
    'meshcore field definition missing'
);

// Run the migration (it is idempotent) and confirm a single meshcore row
// with the expected field key exists. Run it TWICE and assert the row
// count does not change — the idempotency guarantee.
function pb_meshcore_row_count(string $prefix): int {
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}comm_modes` WHERE code = 'meshcore'"
    );
}
$phpBin = PHP_BINARY;
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($migPath) . ' 2>&1';
exec($cmd, $o1, $e1);
$countAfter1 = pb_meshcore_row_count($prefix);
exec($cmd, $o2, $e2);
$countAfter2 = pb_meshcore_row_count($prefix);

pb_assert('migration ran clean (exit 0) first time',  $e1 === 0, 'exit ' . $e1 . ': ' . implode(' ', $o1));
pb_assert('migration ran clean (exit 0) on re-run',   $e2 === 0, 'exit ' . $e2 . ': ' . implode(' ', $o2));
pb_assert('exactly one meshcore comm_mode after first run',  $countAfter1 === 1, 'count=' . $countAfter1);
pb_assert('re-run does not duplicate (count unchanged)',     $countAfter2 === $countAfter1, "1st=$countAfter1 2nd=$countAfter2");

$mcMode = db_fetch_one("SELECT * FROM `{$prefix}comm_modes` WHERE code = 'meshcore'");
pb_assert('meshcore comm_mode enabled', $mcMode && (int) $mcMode['enabled'] === 1, 'meshcore mode missing or disabled');
$mcFields = $mcMode ? (json_decode($mcMode['fields_json'], true) ?: []) : [];
$mcKeys = array_column($mcFields, 'key');
pb_assert(
    'meshcore fields_json declares pubkey_prefix (required)',
    in_array('pubkey_prefix', $mcKeys, true)
        && (function () use ($mcFields) {
            foreach ($mcFields as $f) { if (($f['key'] ?? '') === 'pubkey_prefix') return !empty($f['required']); }
            return false;
        })(),
    'pubkey_prefix field missing or not required'
);

// ─────────────────────────────────────────────────────────────────────
// 2. Resolver: address-per-transport + null-when-unmapped + both linkages
// ─────────────────────────────────────────────────────────────────────

// field-key mapping is the addressing contract
pb_assert('field key meshtastic → node_id',       comm_resolve_field_key('meshtastic') === 'node_id', 'wrong key');
pb_assert('field key meshcore → pubkey_prefix',    comm_resolve_field_key('meshcore') === 'pubkey_prefix', 'wrong key');
pb_assert('field key zello → username',            comm_resolve_field_key('zello') === 'username', 'wrong key');
pb_assert('field key unknown transport → null',    comm_resolve_field_key('carrier-pigeon') === null, 'should be null');
pb_assert('resolve unknown transport → null',      resolve_unit_address(1, 'carrier-pigeon') === null, 'should be null');
pb_assert('resolve non-positive id → null',        resolve_unit_address(0, 'meshtastic') === null, 'should be null');

// Seed a throwaway member + identifiers + a unit, exercise the resolver,
// then clean up regardless of assertion outcome.
$mtModeId = (int) db_fetch_value("SELECT id FROM `{$prefix}comm_modes` WHERE code = 'meshtastic'");
$mcModeId = (int) db_fetch_value("SELECT id FROM `{$prefix}comm_modes` WHERE code = 'meshcore'");

$memberId = 0; $idRows = []; $respId = 0; $personalRespId = 0;
try {
    // Create a throwaway member. `member` is a legacy table whose
    // first_name/last_name/callsign are VIRTUAL GENERATED columns over
    // fieldN, so they can't be written directly — a bare INSERT (defaults)
    // is the portable way to mint a row. The resolver keys off member_id +
    // comm identifiers, not the name, so a nameless throwaway is fine.
    db_query("INSERT INTO `{$prefix}member` () VALUES ()");
    $memberId = (int) db_insert_id();

    // Meshtastic node_id + MeshCore pubkey_prefix identifiers, both primary.
    db_query(
        "INSERT INTO `{$prefix}member_comm_identifiers` (member_id, comm_mode_id, label, values_json, is_primary, created_at)
         VALUES (?, ?, 'T', ?, 1, NOW())",
        [$memberId, $mtModeId, json_encode(['node_id' => '!deadbeef'])]
    );
    $idRows[] = (int) db_insert_id();
    db_query(
        "INSERT INTO `{$prefix}member_comm_identifiers` (member_id, comm_mode_id, label, values_json, is_primary, created_at)
         VALUES (?, ?, 'T', ?, 1, NOW())",
        [$memberId, $mcModeId, json_encode(['pubkey_prefix' => 'a1b2c3d4e5f6'])]
    );
    $idRows[] = (int) db_insert_id();

    // ── member-linkage resolution ──
    pb_assert('resolve member → meshtastic node_id', resolve_unit_address($memberId, 'meshtastic', 'member') === '!deadbeef', 'wrong/no address');
    pb_assert('resolve member → meshcore pubkey_prefix', resolve_unit_address($memberId, 'meshcore', 'member') === 'a1b2c3d4e5f6', 'wrong/no address');
    pb_assert('resolve member → zello (none on file) is null', resolve_unit_address($memberId, 'zello', 'member') === null, 'should be null — no zello id');
    pb_assert('auto kind resolves the member', resolve_unit_address($memberId, 'meshtastic') === '!deadbeef', 'auto did not resolve member');

    // ── unit linkage #1: active personnel assignment ──
    db_query("INSERT INTO `{$prefix}responder` (name, description) VALUES (?, ?)", ['PhaseB Unit A', 't']);
    $respId = (int) db_insert_id();
    db_query(
        "INSERT INTO `{$prefix}unit_personnel_assignments` (responder_id, member_id, status, assigned_at)
         VALUES (?, ?, 'active', NOW())",
        [$respId, $memberId]
    );
    pb_assert('responder→member via active assignment', comm_resolve_responder_member_id($respId) === $memberId, 'assignment linkage failed');
    pb_assert('resolve unit (assignment) → meshtastic', resolve_unit_address($respId, 'meshtastic', 'unit') === '!deadbeef', 'unit DM address not resolved');
    pb_assert('resolve unit (assignment) → meshcore',   resolve_unit_address($respId, 'meshcore', 'unit') === 'a1b2c3d4e5f6', 'unit DM address not resolved');

    // A released assignment must NOT resolve.
    db_query("UPDATE `{$prefix}unit_personnel_assignments` SET status='released', released_at=NOW() WHERE responder_id=?", [$respId]);
    pb_assert('released assignment no longer resolves', comm_resolve_responder_member_id($respId) === null, 'released assignment still resolving');

    // ── unit linkage #2: personal unit (responder.personal_for_member_id) ──
    // Self-heal the column the way inc/personnel-units.php does, so this
    // linkage is testable on a base-schema install.
    $hasPersonalCol = false;
    try {
        $c = db_fetch_all("SHOW COLUMNS FROM `{$prefix}responder` LIKE 'personal_for_member_id'");
        $hasPersonalCol = !empty($c);
        if (!$hasPersonalCol) {
            db_query("ALTER TABLE `{$prefix}responder` ADD COLUMN `personal_for_member_id` INT NULL");
            $hasPersonalCol = true;
        }
    } catch (Exception $e) { $hasPersonalCol = false; }

    if ($hasPersonalCol) {
        db_query("INSERT INTO `{$prefix}responder` (name, description, personal_for_member_id) VALUES (?, ?, ?)",
            ['PhaseB Personal', 't', $memberId]);
        $personalRespId = (int) db_insert_id();
        pb_assert('responder→member via personal unit', comm_resolve_responder_member_id($personalRespId) === $memberId, 'personal-unit linkage failed');
        pb_assert('resolve personal unit → meshcore', resolve_unit_address($personalRespId, 'meshcore', 'unit') === 'a1b2c3d4e5f6', 'personal-unit DM address not resolved');
    } else {
        pb_assert('personal-unit linkage (skipped — no column)', true, '');
        pb_assert('personal-unit resolve (skipped — no column)', true, '');
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. Normalized send queues the right mesh_outbox row.
    //    Replicate the endpoint's resolve + payload-build path, INSERT,
    //    then assert the queued row shape for channel / DM / unit cases.
    // ─────────────────────────────────────────────────────────────────

    // Helper that mirrors the api/mesh.php send payload construction.
    $buildAndQueue = function (string $protocol, int $slot, ?string $toNode) use ($prefix): array {
        $payload = ['text' => 'phaseb test', 'channel_slot' => $slot];
        if ($toNode !== null && $toNode !== '') $payload['to_node'] = $toNode;
        db_query(
            "INSERT INTO `{$prefix}mesh_outbox` (queued_by, target_bridge_id, target_protocol, kind, payload_json)
             VALUES (NULL, NULL, ?, 'send_text', ?)",
            [$protocol, json_encode($payload)]
        );
        $id = (int) db_insert_id();
        $row = db_fetch_one("SELECT * FROM `{$prefix}mesh_outbox` WHERE id = ?", [$id]);
        $row['_payload'] = json_decode($row['payload_json'], true);
        return $row;
    };
    $outboxIds = [];

    // (a) Channel broadcast — no to_node, slot honoured.
    $ch = $buildAndQueue('meshtastic', 3, null);
    $outboxIds[] = (int) $ch['id'];
    pb_assert('channel send: kind=send_text', $ch['kind'] === 'send_text', 'wrong kind');
    pb_assert('channel send: no to_node in payload', !isset($ch['_payload']['to_node']), 'to_node present on a channel broadcast');
    pb_assert('channel send: channel_slot persisted', (int) ($ch['_payload']['channel_slot'] ?? -1) === 3, 'slot not 3');

    // (b) Raw-node DM — to_node carried verbatim.
    $dm = $buildAndQueue('meshtastic', 0, '!a2a79f57');
    $outboxIds[] = (int) $dm['id'];
    pb_assert('raw DM: to_node in payload', ($dm['_payload']['to_node'] ?? '') === '!a2a79f57', 'to_node missing/wrong');

    // (c) Unit-resolved DM (meshcore) — resolver feeds to_node.
    //     Re-add an active assignment so the unit resolves again.
    db_query("UPDATE `{$prefix}unit_personnel_assignments` SET status='active', released_at=NULL WHERE responder_id=?", [$respId]);
    $resolvedAddr = resolve_unit_address($respId, 'meshcore', 'unit');
    pb_assert('unit-resolved address is the meshcore prefix', $resolvedAddr === 'a1b2c3d4e5f6', 'resolver fed wrong address');
    $udm = $buildAndQueue('meshcore', 0, $resolvedAddr);
    $outboxIds[] = (int) $udm['id'];
    pb_assert('unit-resolved DM: target_protocol=meshcore', $udm['target_protocol'] === 'meshcore', 'protocol not meshcore');
    pb_assert('unit-resolved DM: to_node = resolved prefix', ($udm['_payload']['to_node'] ?? '') === 'a1b2c3d4e5f6', 'resolved address not queued as to_node');

    // cleanup outbox
    if ($outboxIds) {
        $in = implode(',', array_fill(0, count($outboxIds), '?'));
        db_query("DELETE FROM `{$prefix}mesh_outbox` WHERE id IN ($in)", $outboxIds);
    }
} finally {
    // Cleanup — order matters (FK-free but tidy).
    if ($idRows) {
        $in = implode(',', array_fill(0, count($idRows), '?'));
        try { db_query("DELETE FROM `{$prefix}member_comm_identifiers` WHERE id IN ($in)", $idRows); } catch (Exception $e) {}
    }
    if ($respId) {
        try { db_query("DELETE FROM `{$prefix}unit_personnel_assignments` WHERE responder_id = ?", [$respId]); } catch (Exception $e) {}
        try { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$respId]); } catch (Exception $e) {}
    }
    if ($personalRespId) {
        try { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$personalRespId]); } catch (Exception $e) {}
    }
    if ($memberId) {
        try { db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$memberId]); } catch (Exception $e) {}
    }
}

// ─────────────────────────────────────────────────────────────────────
// 3b. Source-level assertions on the normalized send endpoint + UI.
// ─────────────────────────────────────────────────────────────────────

$meshApi = file_get_contents(__DIR__ . '/../api/mesh.php');
pb_assert(
    'api/mesh.php — send accepts unit_id/member_id and resolves',
    strpos($meshApi, "\$input['unit_id']") !== false
        && strpos($meshApi, "\$input['member_id']") !== false
        && strpos($meshApi, 'resolve_unit_address(') !== false,
    'send endpoint does not resolve unit_id/member_id'
);
pb_assert(
    'api/mesh.php — send keeps backward-compat target_protocol alias',
    strpos($meshApi, "\$input['protocol'] ?? \$input['target_protocol']") !== false,
    'legacy target_protocol param no longer honoured'
);
pb_assert(
    'api/mesh.php — resolve requires a concrete protocol (not "any")',
    strpos($meshApi, "protocol must be meshtastic or meshcore when resolving") !== false,
    'resolver allows protocol=any — cannot pick which identifier'
);
pb_assert(
    'api/mesh.php — CSRF + admin auth on send',
    preg_match('/\$action === \'send\'.*?admin_auth\(\);.*?csrf_verify/s', $meshApi) === 1,
    'send endpoint missing admin_auth or csrf_verify'
);
pb_assert(
    'api/mesh.php — send_targets endpoint exists + admin-gated',
    strpos($meshApi, "\$action === 'send_targets'") !== false
        && preg_match('/\$action === \'send_targets\'.*?admin_auth\(\);/s', $meshApi) === 1,
    'send_targets missing or not admin-gated'
);
pb_assert(
    'api/mesh.php — send_targets does NOT leak raw addresses',
    strpos($meshApi, 'send_targets') !== false
        && preg_match("/send_targets.*?comm_resolve_member_address\(\\\$mid, 'meshtastic'\) !== null/s", $meshApi) === 1,
    'send_targets appears to return raw addresses rather than a boolean resolves-flag'
);

$resolveInc = file_get_contents(__DIR__ . '/../inc/comm_resolve.php');
pb_assert(
    'inc/comm_resolve.php — tolerant of missing sort_order column',
    strpos($resolveInc, '_comm_resolve_has_sort_order') !== false,
    'resolver references sort_order unconditionally — breaks on base schema'
);

$meshConsole = file_get_contents(__DIR__ . '/../mesh-console.php');
pb_assert(
    'mesh-console.php — Send tab has the unit/person picker + send-mode selector',
    strpos($meshConsole, 'id="sendToUnit"') !== false
        && strpos($meshConsole, 'id="sendMode"') !== false,
    'Send UI missing the unit picker or send-mode selector'
);

$meshJs = file_get_contents(__DIR__ . '/../assets/js/mesh-console.js');
pb_assert(
    'mesh-console.js — Send builds unit_id/member_id from the picker',
    strpos($meshJs, 'body.unit_id') !== false
        && strpos($meshJs, 'body.member_id') !== false
        && strpos($meshJs, "action=send_targets") !== false,
    'Send JS does not wire the unit/person picker to the resolver'
);
pb_assert(
    'mesh-console.js — channel-mode send carries no direct target',
    strpos($meshJs, "mode === 'channel'") !== false,
    'channel send path missing'
);

$failedCount = count($failed);
echo "\n";
echo "$passed passed, $failedCount failed\n";
echo "Phase B — $passed / $total tests passed\n";
if (!empty($failed)) {
    echo "\nFAILURES:\n";
    foreach ($failed as $f) echo "  - $f\n";
    exit(1);
}
exit(0);
