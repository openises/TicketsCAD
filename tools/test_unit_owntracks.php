<?php
/**
 * Phase 117 (GH #84, a beta tester/SAG) — unit-level OwnTracks device regression test.
 *
 * Provisions a unit device through the REAL writer (_p117_provision_unit_device
 * in inc/unit_owntracks.php — the same function api/owntracks-config.php's
 * unit_link action calls), simulates an OwnTracks report for that TID (what the
 * ingest writes to location_reports), and asserts the REAL resolver
 * (location_resolve_unit) surfaces it — then that revoke drops it.
 *
 * Self-skips when the owntracks provider row is absent (virgin DB).
 * The owntracks provider must be enabled for the resolver (lp.enabled=1); the
 * test flips it on and restores the original state in teardown.
 */
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/unit_owntracks.php';
require_once __DIR__ . '/../inc/location-resolver.php';

$_SESSION = ['user_id' => 1, 'user' => 'admin', 'level' => 0];
$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== GH #84 unit-level OwnTracks device ===\n\n";

$pass = 0; $fail = 0;
function ok($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] $label\n"; }
    else       { $fail++; echo "  [FAIL] $label\n"; }
}

$prov = db_fetch_one("SELECT `id`, `enabled` FROM `{$prefix}location_providers` WHERE `code`='owntracks' LIMIT 1");
if (!$prov) {
    echo "SKIP: no owntracks provider row (virgin DB).\n\n=== Results: 0 passed, 0 failed ===\n";
    exit(0);
}
$pid = (int) $prov['id'];
$origEnabled = (int) $prov['enabled'];

$rid = 0; $tokenId = 0; $tid = null;
try {
    // Resolver requires lp.enabled=1 — enable for the test, restore in teardown.
    db_query("UPDATE `{$prefix}location_providers` SET `enabled`=1 WHERE `id`=?", [$pid]);

    // Fixture unit.
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('p117_sag_unit', 'SAG9', 'phase117', 1, NOW(), NOW())");
    $rid = (int) db_insert_id();

    // Provision via the REAL writer.
    $r = _p117_provision_unit_device($rid, $pid, '', 1);
    $tid = $r['tid']; $tokenId = (int) $r['token_id'];
    ok('provision returns a tid + token', ($tid !== null && $tid !== '' && $tokenId > 0));

    // Device token bound to the TID + owntracks provider, not revoked.
    $tok = db_fetch_one("SELECT `provider_id`, `device_unique_id`, `revoked_at`
                           FROM `{$prefix}location_ingest_tokens` WHERE `id`=?", [$tokenId]);
    ok('device token bound to TID + owntracks provider',
        ($tok && (int) $tok['provider_id'] === $pid
              && (string) $tok['device_unique_id'] === $tid
              && $tok['revoked_at'] === null));

    // Unit binding created, active, priority 40, manual.
    $bind = db_fetch_one("SELECT `active`, `priority`, `source`
                            FROM `{$prefix}unit_location_bindings`
                           WHERE `responder_id`=? AND `provider_id`=? AND `unit_identifier`=?",
                          [$rid, $pid, $tid]);
    ok('unit binding created (active, priority 40, manual)',
        ($bind && (int) $bind['active'] === 1 && (int) $bind['priority'] === 40 && $bind['source'] === 'manual'));

    // No position before any report.
    ok('no position before any report', location_resolve_unit($rid) === null);

    // Simulate the OwnTracks device reporting — exactly what the ingest writes.
    db_query("INSERT INTO `{$prefix}location_reports` (provider_id, unit_identifier, lat, lng, reported_at, received_at)
              VALUES (?, ?, 44.9778, -93.2650, NOW(), NOW())", [$pid, $tid]);

    // The REAL resolver surfaces the unit's own device position.
    $pos = location_resolve_unit($rid);
    ok('unit resolves to its device position',
        ($pos !== null && abs((float) $pos['lat'] - 44.9778) < 0.0001
                       && abs((float) $pos['lng'] + 93.2650) < 0.0001));
    ok('resolved via the owntracks provider', ($pos && ($pos['provider_code'] ?? '') === 'owntracks'));

    // Revoke drops it.
    $rev = _p117_revoke_unit_device($rid, $pid, $tokenId);
    ok('revoke deactivates the binding', ($rev && $rev['binding_deactivated'] === true));
    ok('unit no longer resolves via the revoked device', location_resolve_unit($rid) === null);
} catch (Exception $e) {
    echo "  [FAIL] fixture threw: " . $e->getMessage() . "\n";
    $fail++;
}

// Teardown — restore provider enabled state; delete fixture rows.
try {
    if ($tid !== null) db_query("DELETE FROM `{$prefix}location_reports` WHERE provider_id=? AND unit_identifier=?", [$pid, $tid]);
    if ($tokenId > 0)  db_query("DELETE FROM `{$prefix}location_ingest_tokens` WHERE id=?", [$tokenId]);
    if ($rid > 0) {
        db_query("DELETE FROM `{$prefix}unit_location_bindings` WHERE responder_id=?", [$rid]);
        db_query("DELETE FROM `{$prefix}responder` WHERE id=?", [$rid]);
    }
    db_query("UPDATE `{$prefix}location_providers` SET `enabled`=? WHERE `id`=?", [$origEnabled, $pid]);
    ok('teardown complete', true);
} catch (Exception $e) {
    echo "  Teardown warning: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
