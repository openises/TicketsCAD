<?php
/**
 * Phase 152 prerequisite #6 — mandatory, renewable route expiry (PHP/DB
 * side; services/audio-matrix/tests/test_matrix_core.py's "9. Route.
 * expires_at read-time expiry" section and test_control_http.py's wire-
 * through section cover the Python/live-service side of the SAME
 * feature).
 *
 * Drives the REAL writer functions (matrix_route_create(),
 * matrix_route_update(), matrix_route_renew()) against throwaway
 * comm_channels/comm_routes/user fixtures — never hand-seeded rows.
 *
 * Usage: php tests/test_phase152_route_expiry.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/functions.php';
require_once 'inc/matrix-routes.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 152 prerequisite #6 -- mandatory, renewable route expiry ===\n\n";

$createdChannelIds = [];
$createdRouteIds = [];
$createdUserIds = [];
register_shutdown_function(function () use (&$createdRouteIds, &$createdChannelIds, &$createdUserIds, $prefix) {
    foreach ($createdRouteIds as $id) { try { db_query("DELETE FROM `{$prefix}comm_routes` WHERE id = ?", [$id]); } catch (Exception $e) {} }
    foreach ($createdChannelIds as $id) { try { db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$id]); } catch (Exception $e) {} }
    foreach ($createdUserIds as $id) { try { db_query("DELETE FROM `{$prefix}user` WHERE id = ?", [$id]); } catch (Exception $e) {} }
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

$s = uniqid();

// ── Fixture operator with a real callsign on file ──────────────────────
$opUserId = 900199071;
db_query(
    "INSERT INTO `{$prefix}user` (id, user, passwd, callsign, must_change_password) VALUES (?, ?, ?, ?, 0)",
    [$opUserId, 'zz152-expiry-op', password_hash('irrelevant', PASSWORD_BCRYPT), 'N0ZZ152']
);
$createdUserIds[] = $opUserId;
t('fixture operator with callsign N0ZZ152 created', true);

$ham = mk_chan($prefix, "zz152ex:ham:$s", 'Test Ham', 'amateur', $createdChannelIds);
$pstn = mk_chan($prefix, "zz152ex:pstn:$s", 'Test PSTN', 'pstn', $createdChannelIds);
$intA = mk_chan($prefix, "zz152ex:intA:$s", 'Test Internal A', 'internal', $createdChannelIds);
$intB = mk_chan($prefix, "zz152ex:intB:$s", 'Test Internal B', 'internal', $createdChannelIds);

// ── 1. expires_at is REQUIRED for a cross-class route ──────────────────
echo "1. expires_at required whenever the route is cross-class\n";
$threw = null;
try { matrix_route_create(['src_channel_id' => $ham, 'dst_channel_id' => $pstn, 'allow_cross_class' => 1]); }
catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('create cross-class WITHOUT expires_at is rejected', $threw !== null && stripos($threw, 'expir') !== false);

$threw = null;
try {
    matrix_route_create(['src_channel_id' => $ham, 'dst_channel_id' => $pstn, 'allow_cross_class' => 1, 'expires_at' => '2020-01-01 00:00:00']);
} catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('create cross-class with a PAST expires_at is rejected', $threw !== null && stripos($threw, 'future') !== false);

$threw = null;
try { matrix_route_create(['src_channel_id' => $ham, 'dst_channel_id' => $pstn, 'allow_cross_class' => 1, 'expires_at' => 'not a date']); }
catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('create cross-class with an UNPARSEABLE expires_at is rejected', $threw !== null);

$future1h = date('Y-m-d H:i:s', time() + 3600);
$idCross = matrix_route_create([
    'src_channel_id' => $ham, 'dst_channel_id' => $pstn,
    'allow_cross_class' => 1, 'expires_at' => $future1h,
], $opUserId);
$createdRouteIds[] = $idCross;
t('create cross-class WITH a valid future expires_at succeeds', $idCross > 0);

$row = matrix_route_get($idCross);
t('expires_at persisted correctly', $row && $row['expires_at'] === $future1h);
t('operator_ack_by snapshotted to the creating user', $row && (int) $row['operator_ack_by'] === $opUserId);
t('operator_callsign snapshotted from user.callsign at creation time', $row && $row['operator_callsign'] === 'N0ZZ152');

// ── 2. expires_at is OPTIONAL for a same-class route ────────────────────
echo "\n2. expires_at optional for a same-class route\n";
$idPlain = matrix_route_create(['src_channel_id' => $intA, 'dst_channel_id' => $intB]);
$createdRouteIds[] = $idPlain;
$rowPlain = matrix_route_get($idPlain);
t('same-class route with NO expires_at succeeds', $idPlain > 0);
t('expires_at is NULL when never requested', $rowPlain && $rowPlain['expires_at'] === null);
t('operator_ack_by/callsign are NULL when no expiry was ever requested (not every route needs an ack)',
    $rowPlain && $rowPlain['operator_ack_by'] === null && $rowPlain['operator_callsign'] === null);

// A same-class route MAY still opt into an expiry -- and gets acknowledged too.
db_query("DELETE FROM `{$prefix}comm_routes` WHERE id = ?", [$idPlain]);
$idPlainExp = matrix_route_create([
    'src_channel_id' => $intA, 'dst_channel_id' => $intB, 'expires_at' => $future1h,
], $opUserId);
$createdRouteIds[] = $idPlainExp;
$rowPlainExp = matrix_route_get($idPlainExp);
t('a same-class route CAN opt into an expiry voluntarily', $rowPlainExp && $rowPlainExp['expires_at'] === $future1h);
t('opting in also snapshots the acknowledging operator', $rowPlainExp && (int) $rowPlainExp['operator_ack_by'] === $opUserId);

// ── 3. Editing an unrelated field leaves expiry/ack/callsign untouched ─
echo "\n3. An unrelated edit does not disturb expiry/ack/callsign\n";
matrix_route_update($idCross, ['gain_db' => -6.0]);
$rowAfterUnrelated = matrix_route_get($idCross);
t('gain_db actually changed', $rowAfterUnrelated && abs((float) $rowAfterUnrelated['gain_db'] - (-6.0)) < 0.01);
t('expires_at is UNCHANGED by an edit that does not mention it', $rowAfterUnrelated && $rowAfterUnrelated['expires_at'] === $future1h);
t('operator_ack_by is UNCHANGED', $rowAfterUnrelated && (int) $rowAfterUnrelated['operator_ack_by'] === $opUserId);
t('operator_callsign is UNCHANGED', $rowAfterUnrelated && $rowAfterUnrelated['operator_callsign'] === 'N0ZZ152');

// ── 4. Renewal via matrix_route_update() with expires_at supplied ─────
echo "\n4. Renewal: supplying expires_at re-snapshots ack/callsign, clears warned_at\n";
db_query("UPDATE `{$prefix}comm_routes` SET warned_at = NOW() WHERE id = ?", [$idCross]);
$rowWarned = matrix_route_get($idCross);
t('(setup) warned_at is set before the renewal', $rowWarned && $rowWarned['warned_at'] !== null);

// A different operator does the renewal this time.
$opUserId2 = 900199072;
db_query(
    "INSERT INTO `{$prefix}user` (id, user, passwd, callsign, must_change_password) VALUES (?, ?, ?, ?, 0)",
    [$opUserId2, 'zz152-expiry-op2', password_hash('irrelevant', PASSWORD_BCRYPT), 'W9ZZ152']
);
$createdUserIds[] = $opUserId2;

$future2h = date('Y-m-d H:i:s', time() + 7200);
matrix_route_update($idCross, ['expires_at' => $future2h], $opUserId2);
$rowRenewed = matrix_route_get($idCross);
t('expires_at was extended to the new value', $rowRenewed && $rowRenewed['expires_at'] === $future2h);
t('operator_ack_by re-snapshotted to the RENEWING user (not the original creator)',
    $rowRenewed && (int) $rowRenewed['operator_ack_by'] === $opUserId2);
t('operator_callsign re-snapshotted to the renewing operator\'s own callsign',
    $rowRenewed && $rowRenewed['operator_callsign'] === 'W9ZZ152');
t('warned_at is cleared by the renewal (re-arms the warning tick for the new deadline)',
    $rowRenewed && $rowRenewed['warned_at'] === null);

// ── 5. matrix_route_renew() convenience wrapper ─────────────────────────
echo "\n5. matrix_route_renew() wrapper\n";
$future3h = date('Y-m-d H:i:s', time() + 10800);
matrix_route_renew($idCross, $future3h, $opUserId);
$rowRenewed2 = matrix_route_get($idCross);
t('matrix_route_renew() extends expires_at', $rowRenewed2 && $rowRenewed2['expires_at'] === $future3h);
t('matrix_route_renew() re-snapshots the acking operator', $rowRenewed2 && (int) $rowRenewed2['operator_ack_by'] === $opUserId);

$threw = null;
try { matrix_route_renew($idCross, '2020-01-01 00:00:00', $opUserId); }
catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('matrix_route_renew() with a PAST date is rejected, same as create', $threw !== null && stripos($threw, 'future') !== false);

// ── 6. Flipping allow_cross_class=1 on an edit needs expiry too ────────
echo "\n6. An edit that newly makes a route cross-class needs expiry too\n";
// Fresh, ISOLATED channels for this section -- $ham/$pstn are already
// transitively tied together via $idCross above (Phase 152 prereq #5's
// own guard), so reusing them here would trip THAT check instead of
// exercising what this section actually tests.
$intC = mk_chan($prefix, "zz152ex:intC:$s", 'Test Internal C', 'internal', $createdChannelIds);
$ham2 = mk_chan($prefix, "zz152ex:ham2:$s", 'Test Ham 2', 'amateur', $createdChannelIds);
$pstn2 = mk_chan($prefix, "zz152ex:pstn2:$s", 'Test PSTN 2', 'pstn', $createdChannelIds);

$idFlip = matrix_route_create(['src_channel_id' => $intC, 'dst_channel_id' => $pstn2]);
$createdRouteIds[] = $idFlip;
// intC<->pstn2 is same-class (internal<->pstn, not blocked) -- fine with no expiry.
$rowFlip = matrix_route_get($idFlip);
t('(setup) intC->pstn2 created fine, no expiry needed (not cross-class)', $rowFlip && $rowFlip['allow_cross_class'] == 0);

// Re-point its SRC to a fresh amateur channel -- this edit makes it
// genuinely cross-class (amateur<->pstn), and supplies allow_cross_class=1
// but NO expires_at, and the row has none on file either.
$threw = null;
try { matrix_route_update($idFlip, ['src_channel_id' => $ham2, 'allow_cross_class' => 1]); }
catch (InvalidArgumentException $e) { $threw = $e->getMessage(); }
t('flipping to cross-class on edit, with NO expires_at anywhere (new or existing), is rejected',
    $threw !== null && stripos($threw, 'expir') !== false);

// The SAME edit, this time supplying expires_at, succeeds.
matrix_route_update($idFlip, ['src_channel_id' => $ham2, 'allow_cross_class' => 1, 'expires_at' => $future1h], $opUserId);
$rowFlipped = matrix_route_get($idFlip);
t('the same edit WITH expires_at succeeds', $rowFlipped && (int) $rowFlipped['src_channel_id'] === $ham2 && (int) $rowFlipped['allow_cross_class'] === 1);
t('expires_at persisted on the newly-cross-class route', $rowFlipped && $rowFlipped['expires_at'] === $future1h);

// ── 7. Nothing in this codebase tears down an expired route's DB row ──
echo "\n7. An expired route's DB row is left exactly alone (Phase 143 non-authoritative-sweep proof)\n";
$intD = mk_chan($prefix, "zz152ex:intD:$s", 'Test Internal D', 'internal', $createdChannelIds);
$idExpiring = matrix_route_create([
    'src_channel_id' => $intA, 'dst_channel_id' => $intD, 'expires_at' => date('Y-m-d H:i:s', time() + 5),
], $opUserId);
$createdRouteIds[] = $idExpiring;
// Force it into the past directly (simulating "time has passed") without
// running ANY sweep/tick script at all.
db_query("UPDATE `{$prefix}comm_routes` SET expires_at = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 3600), $idExpiring]);
$rowExpired = matrix_route_get($idExpiring);
t('the expired route is STILL enabled=1 in the DB -- nothing here disables it',
    $rowExpired && (int) $rowExpired['enabled'] === 1);
t('the expired route STILL exists (not deleted) -- an admin can see and renew it',
    $rowExpired !== null);
// (services/audio-matrix's own tests prove the LIVE service independently
// refuses to relay this same expired state -- see test_matrix_core.py's
// "9. Route.expires_at read-time expiry" section.)

// ── 8. tools/matrix_expiry_warning_tick.php -- real CLI subprocess ─────
echo "\n8. matrix_expiry_warning_tick.php (real CLI subprocess)\n";

$savedLead = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'matrix_expiry_warning_lead_secs'");
if ($savedLead === false || $savedLead === null) {
    db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES ('matrix_expiry_warning_lead_secs', '120')");
} else {
    db_query("UPDATE `{$prefix}settings` SET value = '120' WHERE name = 'matrix_expiry_warning_lead_secs'");
}
register_shutdown_function(function () use ($prefix, $savedLead) {
    if ($savedLead === false || $savedLead === null) {
        try { db_query("DELETE FROM `{$prefix}settings` WHERE name = 'matrix_expiry_warning_lead_secs'"); } catch (Exception $e) {}
    } else {
        try { db_query("UPDATE `{$prefix}settings` SET value = ? WHERE name = 'matrix_expiry_warning_lead_secs'", [$savedLead]); } catch (Exception $e) {}
    }
});

$intE = mk_chan($prefix, "zz152ex:intE:$s", 'Test Internal E (warn-soon)', 'internal', $createdChannelIds);
$intF = mk_chan($prefix, "zz152ex:intF:$s", 'Test Internal F (warn-later)', 'internal', $createdChannelIds);
$intG = mk_chan($prefix, "zz152ex:intG:$s", 'Test Internal G (dst for both)', 'internal', $createdChannelIds);

// Inside the 120s lead window -- should be warned.
$idWarnSoon = matrix_route_create(['src_channel_id' => $intE, 'dst_channel_id' => $intG, 'expires_at' => date('Y-m-d H:i:s', time() + 60)], $opUserId);
$createdRouteIds[] = $idWarnSoon;
// Well OUTSIDE the lead window (1 hour out vs a 120s lead) -- should NOT be warned yet.
$idWarnLater = matrix_route_create(['src_channel_id' => $intF, 'dst_channel_id' => $intG, 'expires_at' => date('Y-m-d H:i:s', time() + 3600)], $opUserId);
$createdRouteIds[] = $idWarnLater;

$eventsBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}sse_events` WHERE event_type = 'comm:route_expiring'");

$bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
$out = [];
$rc = 1;
exec(escapeshellarg($bin) . ' ' . escapeshellarg(NEWUI_ROOT . '/tools/matrix_expiry_warning_tick.php') . ' 2>&1', $out, $rc);
$outStr = implode("\n", $out);
t('the real warning tick script exits 0', $rc === 0);

$rowSoon = matrix_route_get($idWarnSoon);
$rowLater = matrix_route_get($idWarnLater);
t('the route INSIDE the lead window got warned_at stamped', $rowSoon && $rowSoon['warned_at'] !== null);
t('the route OUTSIDE the lead window was NOT touched (warned_at still NULL)', $rowLater && $rowLater['warned_at'] === null);

$eventsAfter = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}sse_events` WHERE event_type = 'comm:route_expiring'");
t('exactly one new comm:route_expiring SSE event was published', $eventsAfter === $eventsBefore + 1);

$evt = db_fetch_one(
    "SELECT payload, visibility_scope FROM `{$prefix}sse_events` WHERE event_type = 'comm:route_expiring' ORDER BY id DESC LIMIT 1"
);
t('the published event is admin-scoped', $evt && $evt['visibility_scope'] === 'admin');
$payload = $evt ? json_decode($evt['payload'], true) : null;
t('the published event payload names the correct route_id', $payload && (int) $payload['route_id'] === $idWarnSoon);
t('the published event payload carries the operator callsign', $payload && $payload['callsign'] === 'N0ZZ152');

// Re-run: the just-warned route must NOT be warned a second time.
$out2 = []; $rc2 = 1;
exec(escapeshellarg($bin) . ' ' . escapeshellarg(NEWUI_ROOT . '/tools/matrix_expiry_warning_tick.php') . ' 2>&1', $out2, $rc2);
t('a second run exits 0 too', $rc2 === 0);
$eventsAfter2 = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}sse_events` WHERE event_type = 'comm:route_expiring'");
t('a second run does NOT re-warn the already-warned route (no duplicate event)', $eventsAfter2 === $eventsAfter);

// A RENEWAL clears warned_at -- prove the tick will warn again for the new deadline.
matrix_route_renew($idWarnSoon, date('Y-m-d H:i:s', time() + 90), $opUserId);
$out3 = []; $rc3 = 1;
exec(escapeshellarg($bin) . ' ' . escapeshellarg(NEWUI_ROOT . '/tools/matrix_expiry_warning_tick.php') . ' 2>&1', $out3, $rc3);
$eventsAfter3 = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}sse_events` WHERE event_type = 'comm:route_expiring'");
t('after a renewal clears warned_at, the tick warns AGAIN for the new deadline', $eventsAfter3 === $eventsAfter2 + 1);

// ── 9. Static wiring: api/matrix.php exposes the `renew` action ────────
echo "\n9. Static wiring guard\n";
$api = (string) @file_get_contents('api/matrix.php');
t('api/matrix.php: renew action exists and calls the real writer',
    strpos($api, "\$action === 'renew'") !== false && strpos($api, 'matrix_route_renew(') !== false);
t('api/matrix.php: renew applies the change live too (not DB-only)',
    strpos($api, 'matrix_control_apply_update(') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
