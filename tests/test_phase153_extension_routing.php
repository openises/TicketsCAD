<?php
/**
 * Phase 153 (2026-09-08) — extension routing + caller-to-constituent tests.
 *
 * Eric: "I want the caller ID from that workstation to be in the
 * constituents... a call from one workstation to a 'general number'
 * should ring all workstations... a direct-to-station number should ring
 * only that one."
 *
 * Drives the REAL functions in inc/inbound-calls.php (_p153_resolve_
 * extension, _p153_resolve_constituent) and the real end-to-end ingest
 * path (inbound_calls_ingest_event -> sse_events), against throwaway
 * fixtures, never hand-seeded ideal state. Covers:
 *   - general number -> broadcast (target_user_id=null)
 *   - direct number with an active console session bound to its
 *     workstation -> targets exactly that operator (scope='user')
 *   - direct number with NOBODY logged in at that workstation -> falls
 *     back to broadcast rather than the call vanishing silently
 *   - unknown called_number -> no extension resolved, no crash
 *   - constituent lookup-or-create: existing match found (never a
 *     duplicate), no match creates a bare record, too-short input is a
 *     no-op (matches new-incident.js's own minimum-digits gate so the two
 *     paths never disagree)
 *
 * Usage: php tests/test_phase153_extension_routing.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/functions.php';
require_once 'inc/inbound-calls.php';
require_once 'inc/sse.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 153 -- extension routing + caller-to-constituent ===\n\n";

// ── Fixtures, cleaned up by reference (this project's own documented
// lesson: a shutdown closure capturing `use ($ids)` BY VALUE at
// registration time, before ids are appended, deletes nothing) ──────────
$createdExtIds = [];
$createdSessionIds = [];
$createdConstituentIds = [];
$createdCallIds = [];
$createdTrunkIds = [];
register_shutdown_function(function () use (&$createdExtIds, &$createdSessionIds, &$createdConstituentIds, &$createdCallIds, &$createdTrunkIds, $prefix) {
    foreach ($createdCallIds as $id) {
        try { db_query("DELETE FROM `{$prefix}inbound_call_events` WHERE call_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}inbound_calls` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdTrunkIds as $id) {
        try { db_query("DELETE FROM `{$prefix}pbx_trunks` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdSessionIds as $id) {
        try { db_query("DELETE FROM `{$prefix}console_sessions` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdExtIds as $id) {
        try { db_query("DELETE FROM `{$prefix}phone_extensions` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdConstituentIds as $id) {
        try { db_query("DELETE FROM `{$prefix}constituents` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
});

function uniq($base) { return $base . '_' . substr(md5(uniqid('', true)), 0, 8); }

function mk_extension($prefix, $extension, $label, $isGeneral, $workstationToken, &$createdExtIds) {
    db_query(
        "INSERT INTO `{$prefix}phone_extensions` (extension, label, sip_username, sip_password, workstation_token, is_general, enabled)
         VALUES (?, ?, ?, ?, ?, ?, 1)",
        [$extension, $label, $extension, 'testpw', $workstationToken, $isGeneral ? 1 : 0]
    );
    $id = (int) db_insert_id();
    $createdExtIds[] = $id;
    return $id;
}

function mk_session($prefix, $workstationToken, $userId, &$createdSessionIds) {
    db_query(
        "INSERT INTO `{$prefix}console_sessions` (session_token, user_id, username, workstation_token, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))",
        [bin2hex(random_bytes(16)), $userId, 'phase153-test-user', $workstationToken]
    );
    $id = (int) db_insert_id();
    $createdSessionIds[] = $id;
    return $id;
}

// A real user id to target -- reuse whichever account already exists
// (Super Admin, lowest id) rather than inventing a fake one FK-lessly.
$realUserId = (int) db_fetch_value("SELECT id FROM `{$prefix}user` ORDER BY id LIMIT 1");
t('found a real user id to target in tests', $realUserId > 0);

// ══ 1. _p153_resolve_extension() ═══════════════════════════════════════

// General number -> broadcast (target_user_id null) regardless of any session.
$genExt = uniq('100');
mk_extension($prefix, $genExt, 'General Number', true, null, $createdExtIds);
$r = _p153_resolve_extension($genExt);
t('general number resolves is_general=true', $r['is_general'] === true);
t('general number resolves target_user_id=null (broadcast, by design)', $r['target_user_id'] === null);
t('general number resolves a real extension_id', $r['extension_id'] > 0);

// Direct number WITH an active session bound to its workstation -> targets that user.
$wsToken = uniq('ws-token');
$directExt = uniq('101');
mk_extension($prefix, $directExt, 'Workstation 1', false, $wsToken, $createdExtIds);
mk_session($prefix, $wsToken, $realUserId, $createdSessionIds);
$r2 = _p153_resolve_extension($directExt);
t('direct number with an active session resolves is_general=false', $r2['is_general'] === false);
t('direct number with an active session targets exactly that operator', $r2['target_user_id'] === $realUserId);

// Direct number with NO session at that workstation -> falls back to broadcast.
$lonelyWsToken = uniq('ws-lonely');
$lonelyExt = uniq('102');
mk_extension($prefix, $lonelyExt, 'Workstation 2 (nobody logged in)', false, $lonelyWsToken, $createdExtIds);
$r3 = _p153_resolve_extension($lonelyExt);
t('direct number with nobody logged in falls back to broadcast, not a silent no-op',
    $r3['is_general'] === false && $r3['target_user_id'] === null && $r3['extension_id'] > 0);

// Unknown called_number -> no extension resolved, no crash.
$r4 = _p153_resolve_extension('unknown_number_' . uniq(''));
t('unknown called_number resolves extension_id=null, no crash', $r4['extension_id'] === null);
$r5 = _p153_resolve_extension(null);
t('null called_number resolves extension_id=null, no crash', $r5['extension_id'] === null);

// An EXPIRED session must not be targeted (only currently-active sessions count).
$expiredWsToken = uniq('ws-expired');
$expiredExt = uniq('103');
mk_extension($prefix, $expiredExt, 'Workstation 3 (expired session)', false, $expiredWsToken, $createdExtIds);
db_query(
    "INSERT INTO `{$prefix}console_sessions` (session_token, user_id, username, workstation_token, expires_at)
     VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL 1 HOUR))",
    [bin2hex(random_bytes(16)), $realUserId, 'phase153-test-user-expired', $expiredWsToken]
);
$createdSessionIds[] = (int) db_insert_id();
$r6 = _p153_resolve_extension($expiredExt);
t('an EXPIRED session is never targeted -- falls back to broadcast, not a stale user',
    $r6['target_user_id'] === null);

// ══ 2. _p153_resolve_constituent() ══════════════════════════════════════

$freshPhone = '952' . rand(1000000, 9999999); // 10 digits, not in the DB yet
$cid1 = _p153_resolve_constituent($freshPhone);
if ($cid1) $createdConstituentIds[] = $cid1;
t('an unknown phone number creates a new bare constituent record', $cid1 > 0);

$check = db_fetch_one("SELECT contact, phone FROM `{$prefix}constituents` WHERE id = ?", [$cid1]);
t('the created constituent uses the phone number as contact (never a fabricated name)',
    $check && $check['contact'] === $freshPhone && $check['phone'] === $freshPhone);

// Calling it again with the SAME number must find the existing row, not duplicate it.
$cid2 = _p153_resolve_constituent($freshPhone);
t('the SAME number resolves to the SAME constituent id (no duplicate created)', $cid2 === $cid1);

// A number differing only by formatting (dashes/spaces/parens) must still match --
// mirrors api/constituents.php's own digit-normalization exactly.
$formatted = '(' . substr($freshPhone, 0, 3) . ') ' . substr($freshPhone, 3, 3) . '-' . substr($freshPhone, 6);
$cid3 = _p153_resolve_constituent($formatted);
t('a differently-formatted version of the same number still matches the existing constituent',
    $cid3 === $cid1);

// Too-short input (matches new-incident.js's own <4-digit gate) is a no-op.
$cidShort = _p153_resolve_constituent('123');
t('a too-short number is a no-op (matches new-incident.js\'s own minimum-digits gate)', $cidShort === null);
$cidNull = _p153_resolve_constituent(null);
t('a null caller_number is a no-op, not a crash', $cidNull === null);

// ══ 3. End-to-end: a real ringing webhook through inbound_calls_ingest_event() ══

$trunkLabel = uniq('phase153-trunk');
db_query(
    "INSERT INTO `{$prefix}pbx_trunks` (label, bearer_token, enabled) VALUES (?, ?, 1)",
    [$trunkLabel, bin2hex(random_bytes(16))]
);
$trunkId = (int) db_insert_id();
$createdTrunkIds[] = $trunkId;
$trunk = db_fetch_one("SELECT * FROM `{$prefix}pbx_trunks` WHERE id = ?", [$trunkId]);

$e2ePhone = '651' . rand(1000000, 9999999);
$callId = uniq('call');
$before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}sse_events` WHERE event_type = 'call:ringing'");
$result = inbound_calls_ingest_event($trunk, [
    'event' => 'ringing',
    'call_id' => $callId,
    'caller_number' => $e2ePhone,
    'called_number' => $directExt, // the direct extension WITH an active session, from part 1
    'event_ts' => time(),
]);
t('the ringing event is accepted', $result['ok'] === true && $result['applied'] === true);
$createdCallIds[] = (int) $result['call_id'];

$row = db_fetch_one("SELECT * FROM `{$prefix}inbound_calls` WHERE id = ?", [$result['call_id']]);
t('the row records the resolved called_extension_id', (int) $row['called_extension_id'] === $r2['extension_id']);
t('the row records a resolved constituent_id (the caller is now a real Constituent)', (int) $row['constituent_id'] > 0);
if ($row && $row['constituent_id']) { $createdConstituentIds[] = (int) $row['constituent_id']; }
$constRow = db_fetch_one("SELECT phone FROM `{$prefix}constituents` WHERE id = ?", [$row['constituent_id']]);
t('the created constituent\'s phone matches the caller_number', $constRow && $constRow['phone'] === $e2ePhone);

$after = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}sse_events` WHERE event_type = 'call:ringing'");
t('a new sse_events row was actually published', $after === $before + 1);

$sseRow = db_fetch_one(
    "SELECT visibility_scope, visibility_ids FROM `{$prefix}sse_events`
      WHERE event_type = 'call:ringing' ORDER BY id DESC LIMIT 1"
);
t('the direct-number ring published scope=user (targeted), not a broadcast',
    $sseRow && $sseRow['visibility_scope'] === 'user');
t('the direct-number ring targeted exactly the one operator logged in at that workstation',
    $sseRow && (string) $sseRow['visibility_ids'] === (string) $realUserId);

// A SECOND call, to the GENERAL number, must publish a broadcast (entitled), not scope=user.
$callId2 = uniq('call');
$result2 = inbound_calls_ingest_event($trunk, [
    'event' => 'ringing',
    'call_id' => $callId2,
    'caller_number' => '763' . rand(1000000, 9999999),
    'called_number' => $genExt,
    'event_ts' => time(),
]);
$createdCallIds[] = (int) $result2['call_id'];
$sseRow2 = db_fetch_one(
    "SELECT visibility_scope FROM `{$prefix}sse_events`
      WHERE event_type = 'call:ringing' ORDER BY id DESC LIMIT 1"
);
t('the general-number ring published scope=entitled (broadcast to every dispatcher), as designed',
    $sseRow2 && $sseRow2['visibility_scope'] === 'entitled');

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
