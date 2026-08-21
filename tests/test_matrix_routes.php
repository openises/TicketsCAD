<?php
/**
 * Phase 114c — audio-matrix patch/route CRUD tests (closes SPEC-STATUS.md §B1)
 *
 * Drives the REAL writer functions in inc/matrix-routes.php (never
 * hand-seeded rows) against throwaway comm_channels fixtures, so every
 * assertion here exercises exactly what api/matrix.php calls. Covers:
 * schema, regulatory-guard parity with the live Python matrix service,
 * validation rules (self-route / unknown channel / duplicate pair /
 * cross-class block+override), create/update/delete, orphan tolerance,
 * RBAC seeding, and static wiring guards on api/matrix.php + matrix-admin.php.
 *
 * Usage: php tests/test_matrix_routes.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/functions.php';
require_once 'inc/channel_registry.php';
require_once 'inc/matrix-routes.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 114c audio-matrix route CRUD ===\n\n";

// ── Schema ───────────────────────────────────────────────────────────────
$ok = false;
try { db_query("SELECT 1 FROM `{$prefix}comm_routes` LIMIT 1"); $ok = true; } catch (Exception $e) {}
t('table comm_routes exists', $ok);

$cols = [];
try {
    foreach (db_fetch_all("SHOW COLUMNS FROM `{$prefix}comm_routes`") as $c) { $cols[] = $c['Field']; }
} catch (Exception $e) {}
t('comm_routes has the exact columns service.py\'s load_routes() SELECTs',
    in_array('src_channel_id', $cols) && in_array('dst_channel_id', $cols)
    && in_array('gain_db', $cols) && in_array('priority', $cols)
    && in_array('ducking', $cols) && in_array('enabled', $cols)
    && in_array('allow_cross_class', $cols));

// ── Regulatory-guard parity with the live Python matrix service ──────────
// tools/matrix-routes.php's docblock promises the two lists stay in sync;
// prove it by parsing matrix_core.py's literal _BLOCKED_PAIRS source rather
// than trusting the promise.
$pyFile = 'services/audio-matrix/matrix_core.py';
$pySrc = @file_get_contents($pyFile);
$pyPairs = [];
if ($pySrc !== false && preg_match('/_BLOCKED_PAIRS\s*=\s*\{(.*?)\n\}/s', $pySrc, $m)) {
    if (preg_match_all('/frozenset\(\{RegClass\.(\w+),\s*RegClass\.(\w+)\}\)/', $m[1], $mm, PREG_SET_ORDER)) {
        foreach ($mm as $one) { $pyPairs[] = [strtolower($one[1]), strtolower($one[2])]; }
    }
}
t('parsed at least one blocked pair out of matrix_core.py (parser sanity)', count($pyPairs) > 0);
$phpPairs = matrix_blocked_class_pairs();
t('inc/matrix-routes.php\'s blocked-pair COUNT matches matrix_core.py\'s _BLOCKED_PAIRS',
    count($pyPairs) === count($phpPairs));
$allMatch = true;
foreach ($pyPairs as $pp) {
    if (!matrix_classes_blocked($pp[0], $pp[1])) { $allMatch = false; }
}
foreach ($phpPairs as $pp) {
    $foundInPy = false;
    foreach ($pyPairs as $ppy) {
        if (($ppy[0] === $pp[0] && $ppy[1] === $pp[1]) || ($ppy[0] === $pp[1] && $ppy[1] === $pp[0])) { $foundInPy = true; }
    }
    if (!$foundInPy) { $allMatch = false; }
}
t('every pair matrix_core.py blocks, inc/matrix-routes.php also blocks (and vice versa)', $allMatch);
t('internal<->amateur is NOT blocked (dispatch monitoring always allowed)',
    !matrix_classes_blocked('internal', 'amateur'));
t('amateur<->pstn IS blocked (matches matrix_core.py)', matrix_classes_blocked('amateur', 'pstn'));
t('amateur<->commercial IS blocked (matches matrix_core.py)', matrix_classes_blocked('amateur', 'commercial'));

// ── Fixtures: throwaway comm_channels, cleaned up on exit ────────────────
$createdChannelIds = [];
$createdRouteIds = [];
register_shutdown_function(function () use (&$createdRouteIds, &$createdChannelIds, $prefix) {
    foreach ($createdRouteIds as $id) {
        try { db_query("DELETE FROM `{$prefix}comm_routes` WHERE id = ?", [$id]); } catch (Exception $e) {}
    }
    foreach ($createdChannelIds as $id) {
        try { db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$id]); } catch (Exception $e) {}
    }
});

function mk_test_channel($prefix, $key, $label, $class, &$createdChannelIds) {
    db_query(
        "INSERT INTO `{$prefix}comm_channels` (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order)
         VALUES (?, 'test', ?, ?, 1, 0, 999)",
        [$key, $label, $class]
    );
    $id = (int) db_insert_id();
    $createdChannelIds[] = $id;
    return $id;
}

$suffix = uniqid();
$intA = mk_test_channel($prefix, "test_matrix:intA:$suffix", 'Test Internal A', 'internal', $createdChannelIds);
$intB = mk_test_channel($prefix, "test_matrix:intB:$suffix", 'Test Internal B', 'internal', $createdChannelIds);
$ama  = mk_test_channel($prefix, "test_matrix:amateur:$suffix", 'Test Amateur', 'amateur', $createdChannelIds);
$pstn = mk_test_channel($prefix, "test_matrix:pstn:$suffix", 'Test PSTN', 'pstn', $createdChannelIds);

// ── matrix_route_validate() ───────────────────────────────────────────────
$threw = null;
try { matrix_route_validate($intA, $intA, false); } catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('validate: self-route rejected', $threw !== null && stripos($threw, 'itself') !== false);

$threw = null;
try { matrix_route_validate($intA, 999999999, false); } catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('validate: unknown destination channel rejected', $threw !== null && stripos($threw, 'not found') !== false);

$threw = null;
try { matrix_route_validate($ama, $pstn, false); } catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('validate: amateur->pstn without override rejected (FCC Part 97.113 guard)',
    $threw !== null && stripos($threw, '97.113') !== false);

$check = null;
try { $check = matrix_route_validate($ama, $pstn, true); } catch (InvalidArgumentException $e) {}
t('validate: amateur->pstn WITH override is accepted and flagged cross_class',
    $check !== null && $check['cross_class'] === true);

$check2 = null;
try { $check2 = matrix_route_validate($intA, $ama, false); } catch (InvalidArgumentException $e) {}
t('validate: internal->amateur needs no override', $check2 !== null && $check2['cross_class'] === false);

// ── matrix_route_create() ──────────────────────────────────────────────────
$id1 = matrix_route_create([
    'src_channel_id' => $intA, 'dst_channel_id' => $intB,
    'gain_db' => -3.5, 'priority' => 5, 'ducking' => 1, 'enabled' => 1,
    'note' => 'test patch',
], 12345);
$createdRouteIds[] = $id1;
$row1 = matrix_route_get($id1);
t('create: row persisted with exact column values',
    $row1 && (float) $row1['gain_db'] === -3.5 && (int) $row1['priority'] === 5
    && (int) $row1['ducking'] === 1 && (int) $row1['enabled'] === 1
    && $row1['note'] === 'test patch' && (int) $row1['created_by'] === 12345
    && (int) $row1['allow_cross_class'] === 0);

$threw = null;
try {
    matrix_route_create(['src_channel_id' => $intA, 'dst_channel_id' => $intB]);
} catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('create: duplicate (src,dst) pair rejected', $threw !== null && stripos($threw, 'already exists') !== false);

$idReverse = matrix_route_create(['src_channel_id' => $intB, 'dst_channel_id' => $intA]);
$createdRouteIds[] = $idReverse;
t('create: the REVERSE direction (B->A) is a separate, legitimate row (full-duplex patch)',
    $idReverse !== $id1 && matrix_route_get($idReverse) !== null);

$threw = null;
try {
    matrix_route_create(['src_channel_id' => $ama, 'dst_channel_id' => $pstn]);
} catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('create: cross-class without override rejected end-to-end', $threw !== null);

$idCross = matrix_route_create(['src_channel_id' => $ama, 'dst_channel_id' => $pstn, 'allow_cross_class' => 1]);
$createdRouteIds[] = $idCross;
$rowCross = matrix_route_get($idCross);
t('create: cross-class WITH override persists allow_cross_class=1',
    $rowCross && (int) $rowCross['allow_cross_class'] === 1);

$threw = null;
try { matrix_route_create(['src_channel_id' => $intA, 'dst_channel_id' => $pstn, 'gain_db' => 999]); }
catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('create: gain out of range rejected', $threw !== null && stripos($threw, 'Gain') !== false);

$idNote = matrix_route_create(['src_channel_id' => $pstn, 'dst_channel_id' => $intB]);
$createdRouteIds[] = $idNote;
$rowNote = matrix_route_get($idNote);
t('create: defaults are gain=0.0, priority=0, ducking=1, enabled=1, note=NULL when omitted',
    $rowNote && (float) $rowNote['gain_db'] === 0.0 && (int) $rowNote['priority'] === 0
    && (int) $rowNote['ducking'] === 1 && (int) $rowNote['enabled'] === 1 && $rowNote['note'] === null);

// ── matrix_route_update() ──────────────────────────────────────────────────
matrix_route_update($id1, ['gain_db' => 2.0, 'enabled' => 0, 'note' => 'updated']);
$row1b = matrix_route_get($id1);
t('update: partial update changes only the given fields',
    (float) $row1b['gain_db'] === 2.0 && (int) $row1b['enabled'] === 0
    && $row1b['note'] === 'updated' && (int) $row1b['priority'] === 5 /* untouched */);

$threw = null;
try { matrix_route_update($id1, ['dst_channel_id' => $intA]); } // would collide with... itself? no, src stays intA, dst->intA = self-route
catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('update: re-validates on every save (self-route via edit rejected)',
    $threw !== null && stripos($threw, 'itself') !== false);

$threw = null;
try { matrix_route_update(99999999, ['gain_db' => 1]); } catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('update: unknown route id rejected', $threw !== null && stripos($threw, 'not found') !== false);

// re-pointing dst to collide with the existing reverse-direction row's own dst/src should be
// rejected as a duplicate (moving id1's dst from intB to intA would collide with idReverse? no —
// idReverse is intB->intA, id1 is intA->intB; changing id1's dst to itself (intA) is the self-route
// case above, already covered).

// ── matrix_routes_all() / matrix_route_full() — orphan tolerance ─────────
$all = matrix_routes_all();
$found = null;
foreach ($all as $r) { if ((int) $r['id'] === (int) $id1) { $found = $r; break; } }
t('matrix_routes_all() joins channel display fields', $found && $found['src_label'] === 'Test Internal A');

// Delete one of the fixture channels out from under an existing route (comm_routes
// has no hard FK by design — see the 114c migration's docblock) and confirm the
// route still lists, orphan-flagged via NULL join fields, rather than vanishing.
db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$pstn]);
$createdChannelIds = array_values(array_diff($createdChannelIds, [$pstn]));
$allAfterOrphan = matrix_routes_all();
$orphanRow = null;
foreach ($allAfterOrphan as $r) { if ((int) $r['id'] === (int) $idNote) { $orphanRow = $r; break; } }
t('matrix_routes_all() is orphan-tolerant: a route whose channel vanished still lists (NULL join, not dropped)',
    $orphanRow !== null && $orphanRow['src_label'] === null);

// ── matrix_route_delete() ──────────────────────────────────────────────────
t('delete: existing route removed, returns true', matrix_route_delete($id1) === true);
t('delete: row actually gone from the DB', matrix_route_get($id1) === null);
t('delete: unknown route id returns false (not an error)', matrix_route_delete(99999999) === false);
// id1 already removed by the assertions above — drop it from cleanup list
$createdRouteIds = array_values(array_diff($createdRouteIds, [$id1]));

// ── RBAC seeding (sql/run_phase114c_comm_routes.php) ──────────────────────
$permId = null;
try {
    $permId = db_fetch_value("SELECT id FROM `{$prefix}permissions` WHERE code = ?", ['action.manage_matrix']);
} catch (Exception $e) {}
t('permission action.manage_matrix is seeded', (bool) $permId);

if ($permId) {
    $roleIds = db_fetch_all("SELECT role_id FROM `{$prefix}role_permissions` WHERE permission_id = ?", [$permId]);
    $roleIds = array_map(function ($r) { return (int) $r['role_id']; }, $roleIds);
    sort($roleIds);
    t('action.manage_matrix is granted to Super Admin (1) and Org Admin (2)',
        in_array(1, $roleIds, true) && in_array(2, $roleIds, true));
    t('action.manage_matrix is NOT granted to Dispatcher (3) — regulated action, off by default',
        !in_array(3, $roleIds, true));
}

// ── Static wiring guards ────────────────────────────────────────────────
$api = (string) @file_get_contents('api/matrix.php');
t('api/matrix.php: auth + RBAC + CSRF + safe errors + display_errors suppressed',
    strpos($api, "require_once __DIR__ . '/auth.php'") !== false
    && strpos($api, "rbac_can('action.manage_matrix')") !== false
    && strpos($api, 'csrf_verify(') !== false
    && strpos($api, 'json_error_safe(') !== false
    && strpos($api, "ini_set('display_errors', '0')") !== false);
t('api/matrix.php: uses the shared writer functions, not inline SQL',
    strpos($api, 'matrix_route_create(') !== false
    && strpos($api, 'matrix_route_update(') !== false
    && strpos($api, 'matrix_route_delete(') !== false);
t('api/matrix.php: every mutation is audited', substr_count($api, 'audit_log(') >= 3);
t('api/matrix.php: cross-class routes are audited at AUDIT_HIGH severity',
    strpos($api, 'AUDIT_HIGH') !== false);

$page = (string) @file_get_contents('matrix-admin.php');
t('matrix-admin.php: session gate, RBAC gate, CSRF meta, cache-busted assets',
    strpos($page, "empty(\$_SESSION['user_id'])") !== false
    && strpos($page, "rbac_can('action.manage_matrix')") !== false
    && strpos($page, 'csrf-token') !== false
    && strpos($page, "asset_v('assets/js/matrix-admin.js')") !== false);

$nav = (string) @file_get_contents('console.php');
t('console.php links to matrix-admin.php, gated on action.manage_matrix',
    strpos($nav, "rbac_can('action.manage_matrix')") !== false
    && strpos($nav, 'matrix-admin.php') !== false);

$sidebar = (string) @file_get_contents('inc/config-sidebar.php');
t('config-sidebar.php links to matrix-admin.php, gated on action.manage_matrix',
    strpos($sidebar, "rbac_can('action.manage_matrix')") !== false
    && strpos($sidebar, 'matrix-admin.php') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
