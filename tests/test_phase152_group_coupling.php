<?php
/**
 * Phase 152 (Console rebuild, net-control persona review) — group
 * coupling: matrix_group_create()/matrix_group_break()/matrix_group_routes()
 * in inc/matrix-routes.php, and the dispatcher-intercom leaf rule
 * (matrix_route_validate() refusing ANY route touching an intercom_dd
 * channel, with no override).
 *
 * Drives the REAL writer functions against throwaway comm_channels/
 * comm_routes fixtures — never hand-seeded rows.
 *
 * Usage: php tests/test_phase152_group_coupling.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/functions.php';
require_once 'inc/matrix-routes.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 152 -- group coupling + intercom leaf rule ===\n\n";

$createdChannelIds = [];
$createdRouteIds = [];
register_shutdown_function(function () use (&$createdRouteIds, &$createdChannelIds, $prefix) {
    foreach ($createdRouteIds as $id) { try { db_query("DELETE FROM `{$prefix}comm_routes` WHERE id = ?", [$id]); } catch (Exception $e) {} }
    foreach ($createdChannelIds as $id) { try { db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$id]); } catch (Exception $e) {} }
});

function mk_chan($prefix, $key, $label, $class, &$createdChannelIds, $adapter = 'test') {
    db_query(
        "INSERT INTO `{$prefix}comm_channels` (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order)
         VALUES (?, ?, ?, ?, 1, 0, 999)",
        [$key, $adapter, $label, $class]
    );
    $id = (int) db_insert_id();
    $createdChannelIds[] = $id;
    return $id;
}

$s = uniqid();

// ── 1. A 3-channel net: full directed mesh, one shared group ──────────
echo "1. matrix_group_create() -- full directed mesh, 3 channels\n";
$tg1 = mk_chan($prefix, "zz152grp:tg1:$s", 'Test TG1', 'amateur', $createdChannelIds);
$tg2 = mk_chan($prefix, "zz152grp:tg2:$s", 'Test TG2', 'amateur', $createdChannelIds);
$tg3 = mk_chan($prefix, "zz152grp:tg3:$s", 'Test TG3', 'amateur', $createdChannelIds);

$result = matrix_group_create([$tg1, $tg2, $tg3], false, null, 900199081, 'Tuesday net');
$createdRouteIds = array_merge($createdRouteIds, $result['route_ids']);
t('matrix_group_create() returns a group_id', $result['group_id'] > 0);
t('a 3-channel group creates exactly 6 routes (N*(N-1) full directed mesh)', count($result['route_ids']) === 6);

$rows = matrix_group_routes($result['group_id']);
t('matrix_group_routes() returns all 6 rows for this group', count($rows) === 6);
$pairs = [];
foreach ($rows as $r) { $pairs[] = (int) $r['src_channel_id'] . '->' . (int) $r['dst_channel_id']; }
foreach ([[$tg1,$tg2],[$tg2,$tg1],[$tg1,$tg3],[$tg3,$tg1],[$tg2,$tg3],[$tg3,$tg2]] as $p) {
    t("mesh contains {$p[0]}->{$p[1]}", in_array($p[0] . '->' . $p[1], $pairs, true));
}
$noteOk = true; $groupIdOk = true;
foreach ($rows as $r) {
    if ($r['note'] !== 'Tuesday net') { $noteOk = false; }
    if ((int) $r['group_id'] !== (int) $result['group_id']) { $groupIdOk = false; }
}
t('every leg shares the same note', $noteOk);
t('every leg shares the same group_id', $groupIdOk);

// ── 2. All-or-nothing: one bad leg rejects the WHOLE group ─────────────
echo "\n2. All-or-nothing validation -- one bad leg creates NOTHING\n";
$pstnBad = mk_chan($prefix, "zz152grp:pstnbad:$s", 'Test PSTN Bad', 'pstn', $createdChannelIds);
$intOk = mk_chan($prefix, "zz152grp:intok:$s", 'Test Internal OK', 'internal', $createdChannelIds);
$ham4 = mk_chan($prefix, "zz152grp:ham4:$s", 'Test Ham 4', 'amateur', $createdChannelIds);

$countBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}comm_routes` WHERE group_id IS NOT NULL");
$threw = null;
try {
    // ham4<->intOk fine, ham4<->pstnBad is the regulatory violation --
    // WITHOUT the override, the whole group must be refused.
    matrix_group_create([$ham4, $intOk, $pstnBad], false, null, 900199081);
} catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('a group with one cross-class leg (no override) is REJECTED', $threw !== null && stripos($threw, '97.113') !== false);
$countAfter = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}comm_routes` WHERE group_id IS NOT NULL");
t('NOTHING was created -- not even the two valid legs (all-or-nothing)', $countAfter === $countBefore);

// With the override + a valid expiry, the same group succeeds.
$future = date('Y-m-d H:i:s', time() + 3600);
$result2 = matrix_group_create([$ham4, $intOk, $pstnBad], true, $future, 900199081);
$createdRouteIds = array_merge($createdRouteIds, $result2['route_ids']);
t('the SAME group WITH override + expiry succeeds', count($result2['route_ids']) === 6);
$rows2 = matrix_group_routes($result2['group_id']);
$crossClassCount = 0;
foreach ($rows2 as $r) { if ((int) $r['allow_cross_class'] === 1) { $crossClassCount++; } }
// Only the legs actually touching pstnBad<->ham4 (both directions) are
// cross-class; ham4<->intOk and intOk<->pstnBad are NOT amateur<->pstn.
t('only the genuinely cross-class legs (ham4<->pstnBad, both directions) are flagged allow_cross_class=1',
    $crossClassCount === 2);

// ── 3. matrix_group_break() -- instant, unconditional teardown ────────
echo "\n3. matrix_group_break() -- instant teardown, no partial break\n";
$removed = matrix_group_break($result['group_id']);
t('matrix_group_break() removes exactly the 6 routes from group 1', $removed === 6);
t('the group is genuinely gone from the DB', empty(matrix_group_routes($result['group_id'])));
t('breaking an unknown group_id returns 0, not an error', matrix_group_break(999999999) === 0);
// Prevent the shutdown cleanup from trying to re-delete already-gone rows
// (harmless either way, matrix_route_delete-style idempotency, but keep
// the fixture list accurate).
$createdRouteIds = array_diff($createdRouteIds, $result['route_ids']);

$removed2 = matrix_group_break($result2['group_id']);
t('matrix_group_break() removes the second group\'s 6 routes too', $removed2 === 6);
$createdRouteIds = array_diff($createdRouteIds, $result2['route_ids']);

// ── 4. Rejections: too few channels, invalid ids ───────────────────────
echo "\n4. Input validation\n";
$threw = null;
try { matrix_group_create([$tg1], false, null); } catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('a single channel is rejected (needs at least 2)', $threw !== null && stripos($threw, 'at least 2') !== false);

$threw = null;
try { matrix_group_create([$tg1, $tg1, $tg2], false, null); } catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('duplicate channel ids collapse via array_unique, leaving only 2 distinct -- still valid if 2+ remain', $threw === null);
// clean up whatever this last call created
if ($threw === null) {
    $lastGroup = (int) db_fetch_value("SELECT MAX(group_id) FROM `{$prefix}comm_routes`");
    matrix_group_break($lastGroup);
}

$threw = null;
try { matrix_group_create([0, -5], false, null); } catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('non-positive channel ids are rejected before any DB write', $threw !== null);

// ── 5. The dispatcher-intercom leaf rule ────────────────────────────────
echo "\n5. intercom_dd is structurally never patchable to anything\n";
$dd = mk_chan($prefix, "zz152grp:dd:$s", 'Test Dispatch Intercom', 'internal', $createdChannelIds, 'intercom_dd');
$plain = mk_chan($prefix, "zz152grp:plain:$s", 'Test Plain Internal', 'internal', $createdChannelIds);

$threw = null;
try { matrix_route_create(['src_channel_id' => $dd, 'dst_channel_id' => $plain]); }
catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('intercom_dd -> plain internal channel is REJECTED even though both are internal-class',
    $threw !== null && stripos($threw, 'intercom') !== false);

$threw = null;
try { matrix_route_create(['src_channel_id' => $plain, 'dst_channel_id' => $dd]); }
catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('the reverse direction (plain -> intercom_dd) is ALSO rejected', $threw !== null && stripos($threw, 'intercom') !== false);

$threw = null;
try { matrix_route_create(['src_channel_id' => $dd, 'dst_channel_id' => $plain, 'allow_cross_class' => 1, 'expires_at' => $future]); }
catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('the cross-class override does NOT bypass the intercom rule -- there is no escape hatch',
    $threw !== null && stripos($threw, 'intercom') !== false);

$threw = null;
try { matrix_group_create([$dd, $tg2, $tg3], false, null); } catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('a group coupling that includes intercom_dd is ALSO rejected (same choke point, matrix_route_validate())',
    $threw !== null && stripos($threw, 'intercom') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
