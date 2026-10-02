<?php
/**
 * Phase 152 prerequisite #5 — the transitive cross-class regulatory guard.
 *
 * Reproduces the net-control design review's exact scenario: patching
 * amateur<->Zello and, separately, Zello<->PSTN. Neither individual patch
 * is blocked by the PRE-EXISTING pairwise guard (matrix_classes_blocked())
 * — Zello ('internal' class) is exempt from every pairwise rule — but
 * together they put a phone line and a ham radio on the same live call.
 * This is the single most safety-critical test in this phase: if it ever
 * regresses, an amateur radio operator's audio could reach the PSTN (or a
 * commercial/Part-90 system) through an internal-channel hop with nobody
 * ever having acknowledged it.
 *
 * Drives the REAL functions (matrix_route_would_cross_class(),
 * matrix_route_create()/update() through the full matrix_route_validate()
 * path, and matrix_regulatory_scan_components()) against throwaway
 * comm_channels/comm_routes fixtures — never hand-simulated graph state.
 *
 * Usage: php tests/test_phase152_transitive_cross_class.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/functions.php';
require_once 'inc/matrix-routes.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 152 prerequisite #5 -- transitive cross-class guard ===\n\n";

$createdChannelIds = [];
$createdRouteIds = [];
register_shutdown_function(function () use (&$createdRouteIds, &$createdChannelIds, $prefix) {
    foreach ($createdRouteIds as $id) { try { db_query("DELETE FROM `{$prefix}comm_routes` WHERE id = ?", [$id]); } catch (Exception $e) {} }
    foreach ($createdChannelIds as $id) { try { db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$id]); } catch (Exception $e) {} }
});

function mk_chan($prefix, $key, $label, $class, &$createdChannelIds) {
    db_query(
        "INSERT INTO `{$prefix}comm_channels` (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order)
         VALUES (?, 'test', ?, ?, 1, 0, 999)",
        [$key, $label, $class]
    );
    $id = (int) db_insert_id();
    $createdChannelIds[] = $id;
    return $id;
}
function mk_route($prefix, $src, $dst, $allowCross, &$createdRouteIds) {
    db_query(
        "INSERT INTO `{$prefix}comm_routes` (src_channel_id, dst_channel_id, gain_db, priority, ducking, enabled, allow_cross_class)
         VALUES (?, ?, 0, 0, 1, 1, ?)",
        [$src, $dst, $allowCross ? 1 : 0]
    );
    $id = (int) db_insert_id();
    $createdRouteIds[] = $id;
    return $id;
}

$s = uniqid();

// ── 1. The net-control scenario, via matrix_route_would_cross_class() directly ──
echo "1. matrix_route_would_cross_class() — the exact net-control scenario\n";
$ham1  = mk_chan($prefix, "zz152tx:ham1:$s", 'Test Ham', 'amateur', $createdChannelIds);
$zello1 = mk_chan($prefix, "zz152tx:zello1:$s", 'Test Zello', 'internal', $createdChannelIds);
$pstn1 = mk_chan($prefix, "zz152tx:pstn1:$s", 'Test PSTN', 'pstn', $createdChannelIds);

// amateur<->Zello alone -- not a violation (internal is exempt from every pairwise rule)
$r1 = matrix_route_would_cross_class($ham1, $zello1);
t('amateur<->Zello alone: not crossing (no routes yet, this is the FIRST edge)', $r1['crosses'] === false);
mk_route($prefix, $ham1, $zello1, false, $createdRouteIds);

// Zello<->PSTN, proposed NEXT (Zello already tied to the amateur channel) -- THIS is the violation
$r2 = matrix_route_would_cross_class($zello1, $pstn1);
t('Zello<->PSTN, proposed after amateur<->Zello already exists: CROSSES', $r2['crosses'] === true);
t('the reported amateur channel is the real one', $r2['amateur'] && (int) $r2['amateur']['id'] === $ham1);
t('the reported conflict channel is the real PSTN one, class=pstn', $r2['conflict'] && (int) $r2['conflict']['id'] === $pstn1 && $r2['conflict']['class'] === 'pstn');

// ── 2. Enforced end-to-end through matrix_route_create() ──────────────
echo "\n2. Enforced through the REAL writer (matrix_route_create)\n";
$threw = null;
try { matrix_route_create(['src_channel_id' => $zello1, 'dst_channel_id' => $pstn1]); }
catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('create Zello->PSTN without override is REJECTED end-to-end', $threw !== null && stripos($threw, '97.113') !== false);
t('the error message names the amateur channel by label', $threw !== null && stripos($threw, 'Test Ham') !== false);

// With the override, it's allowed (audited path) -- and cross_class comes back true.
// expires_at is required alongside allow_cross_class as of Phase 152
// prerequisite #6 -- incidental to what THIS test covers (prerequisite #5).
$idBridge = matrix_route_create([
    'src_channel_id' => $zello1, 'dst_channel_id' => $pstn1,
    'allow_cross_class' => 1, 'expires_at' => date('Y-m-d H:i:s', time() + 3600),
]);
$createdRouteIds[] = $idBridge;
t('WITH the override, the bridging route is created', $idBridge > 0);

// ── 3. Two SEPARATE, non-adjacent safe components are never flagged ───
echo "\n3. Two disjoint safe components -- no false positive\n";
$intX = mk_chan($prefix, "zz152tx:intX:$s", 'Test Internal X', 'internal', $createdChannelIds);
$intY = mk_chan($prefix, "zz152tx:intY:$s", 'Test Internal Y', 'internal', $createdChannelIds);
$rDisjoint = matrix_route_would_cross_class($intX, $intY);
t('two plain internal channels, unrelated to the amateur/pstn component: never crosses', $rDisjoint['crosses'] === false);
mk_route($prefix, $intX, $intY, false, $createdRouteIds);

$ham2 = mk_chan($prefix, "zz152tx:ham2:$s", 'Test Ham 2', 'amateur', $createdChannelIds);
$comm2 = mk_chan($prefix, "zz152tx:comm2:$s", 'Test Commercial 2', 'commercial', $createdChannelIds);
// A SEPARATE amateur<->commercial component, disjoint from {intX,intY} above
$threw = null;
try { matrix_route_create(['src_channel_id' => $ham2, 'dst_channel_id' => $comm2]); }
catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('a SEPARATE direct amateur<->commercial pair is still caught by the PAIRWISE guard (sanity: prereq #5 did not weaken it)', $threw !== null);

// ── 4. Retroactive bridge via matrix_route_update() ────────────────────
echo "\n4. matrix_route_update() re-validates transitively too (retroactive bridge)\n";
$ham3 = mk_chan($prefix, "zz152tx:ham3:$s", 'Test Ham 3', 'amateur', $createdChannelIds);
$zello3 = mk_chan($prefix, "zz152tx:zello3:$s", 'Test Zello 3', 'internal', $createdChannelIds);
$pstn3 = mk_chan($prefix, "zz152tx:pstn3:$s", 'Test PSTN 3', 'pstn', $createdChannelIds);
$other3 = mk_chan($prefix, "zz152tx:other3:$s", 'Test Other 3', 'internal', $createdChannelIds);

mk_route($prefix, $ham3, $zello3, false, $createdRouteIds);
// A harmless route, Zello3 -> other3, created BEFORE pstn3 is anywhere in the picture
$idEditable = mk_route($prefix, $zello3, $other3, false, $createdRouteIds);
$rBefore = matrix_route_would_cross_class($zello3, $other3, $idEditable);
t('before any pstn involvement, editing zello3->other3 does not cross', $rBefore['crosses'] === false);

// Now edit that SAME route to re-point its dst at pstn3 -- this is the
// retroactive bridge: the edit itself is what newly connects ham3 to pstn3.
$threw = null;
try { matrix_route_update($idEditable, ['dst_channel_id' => $pstn3]); }
catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('re-pointing an EXISTING route to bridge amateur->pstn is rejected on UPDATE, not just create', $threw !== null && stripos($threw, '97.113') !== false);
// the route must be UNCHANGED (still pointing at other3) since the update was rejected
$row = matrix_route_get($idEditable);
t('the rejected update left the route AT ITS ORIGINAL destination (other3), not half-applied', $row && (int) $row['dst_channel_id'] === $other3);

// ── 5. A persisted override survives an unrelated later edit ──────────
echo "\n5. A route's OWN persisted allow_cross_class survives an edit that doesn't touch it\n";
$idPersist = matrix_route_get($idBridge);
t('idBridge (created WITH override) still has allow_cross_class=1 before any edit', $idPersist && (int) $idPersist['allow_cross_class'] === 1);
// Editing only gain_db, with NO allow_cross_class key in $in at all --
// matrix_route_update() must fall back to the EXISTING row's own value,
// not silently default to false and re-trip the guard on every edit.
$threw = null;
try { matrix_route_update($idBridge, ['gain_db' => -3.0]); }
catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('editing an unrelated field on an already-overridden bridging route does NOT re-trip the guard', $threw === null);
$rowAfter = matrix_route_get($idBridge);
t('gain_db actually changed', $rowAfter && abs((float) $rowAfter['gain_db'] - (-3.0)) < 0.01);
t('allow_cross_class is still 1 after the edit', $rowAfter && (int) $rowAfter['allow_cross_class'] === 1);

// ── 6. matrix_regulatory_scan_components() -- the periodic-scan half ──
echo "\n6. matrix_regulatory_scan_components() -- catches a channel RECLASSIFIED after the fact\n";
$before = matrix_regulatory_scan_components();
$beforeIds = array_map(function ($v) { return $v['amateur']['id']; }, $before);
t('scan does not (yet) flag a fresh internal channel with no amateur/pstn neighbors', !in_array($intX, $beforeIds, true));

// Build a topology that is SAFE at creation time (both ends internal),
// then reclassify one channel directly (simulating api/channels.php's
// admin action, which the route writer never sees) to create a violation
// with NO route-level mutation involved at all.
$reInt = mk_chan($prefix, "zz152tx:reclassify_int:$s", 'Test Reclassify (starts internal)', 'internal', $createdChannelIds);
$rePstn = mk_chan($prefix, "zz152tx:reclassify_pstn:$s", 'Test Reclassify PSTN', 'pstn', $createdChannelIds);
mk_route($prefix, $reInt, $rePstn, false, $createdRouteIds);   // internal<->pstn: fine when created

$scanClean = matrix_regulatory_scan_components();
$foundClean = false;
foreach ($scanClean as $v) { if (in_array($reInt, $v['channel_ids'], true)) { $foundClean = true; } }
t('scan reports NO violation while the channel is still internal-class', !$foundClean);

// Reclassify DIRECTLY via SQL -- exactly what api/channels.php's update
// action does, bypassing comm_routes entirely.
db_query("UPDATE `{$prefix}comm_channels` SET regulatory_class = 'amateur' WHERE id = ?", [$reInt]);

$scanDirty = matrix_regulatory_scan_components();
$found = null;
foreach ($scanDirty as $v) { if (in_array($reInt, $v['channel_ids'], true)) { $found = $v; } }
t('AFTER reclassifying the channel to amateur (no route ever touched), the scan NOW flags the component', $found !== null);
t('the flagged component correctly names the reclassified channel as the amateur side', $found && (int) $found['amateur']['id'] === $reInt);
t('the flagged component correctly names the real pstn channel as the conflict side', $found && (int) $found['conflict']['id'] === $rePstn && $found['conflict']['class'] === 'pstn');

// An override on the SAME component's route silences the scan (an
// admin explicitly acknowledging the mixing) without changing topology.
db_query("UPDATE `{$prefix}comm_routes` SET allow_cross_class = 1 WHERE src_channel_id = ? AND dst_channel_id = ?", [$reInt, $rePstn]);
$scanAcked = matrix_regulatory_scan_components();
$stillFound = false;
foreach ($scanAcked as $v) { if (in_array($reInt, $v['channel_ids'], true)) { $stillFound = true; } }
t('an explicit override on the component silences the scan (acknowledged, not disabled)', !$stillFound);

// ── 7. The CLI tick script wires the scan correctly, end to end ───────
echo "\n7. tools/matrix_regulatory_audit_tick.php wiring (real CLI subprocess)\n";
// Re-introduce a live, unacknowledged violation for the tick script to find.
db_query("UPDATE `{$prefix}comm_routes` SET allow_cross_class = 0 WHERE src_channel_id = ? AND dst_channel_id = ?", [$reInt, $rePstn]);
$bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
$out = [];
$rc = 1;
exec(escapeshellarg($bin) . ' ' . escapeshellarg(NEWUI_ROOT . '/tools/matrix_regulatory_audit_tick.php') . ' 2>&1', $out, $rc);
$outStr = implode("\n", $out);
t('the real tick script exits 0 (a found violation is still a SUCCESSFUL scan run)', $rc === 0);
t('the real tick script reports the violation on stdout', stripos($outStr, 'VIOLATION') !== false);
t('the real tick script names the reclassified channel', stripos($outStr, 'Test Reclassify (starts internal)') !== false);

// Silence it again so this test's own cleanup doesn't leave a real, live
// violation sitting in the shared dev database for anyone else's health
// check to trip over before the fixture channels are deleted below.
db_query("UPDATE `{$prefix}comm_routes` SET allow_cross_class = 1 WHERE src_channel_id = ? AND dst_channel_id = ?", [$reInt, $rePstn]);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
