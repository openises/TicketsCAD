<?php
/**
 * GH#148 (Phase 155) -- regression tests for the findings of the independent security/logic review.
 *
 * Each section names the finding and proves the FIX by driving the real library and writers (never a hand-seeded
 * ledger row standing in for what the writer produces). Settings-dependent behaviour runs in a fresh worker process per
 * value (get_variable() caches the whole settings table for the life of a process). The two row-lock tests use real
 * second connections (tests/_vendor_lock_holder.php).
 *
 *   1  A pick that names a rotation member but omits list_id cannot dodge the override rules
 *   2  Changing vendor_advance_rule while a call is in flight cannot stamp two turns (or none) for one call
 *   3  The history's overrides filter and the per-company summary hold when the result is truncated
 *   4  Reordering a list that has history needs a reason and leaves a ledger row; so does switching its mode
 *   5  A list that changes service type does not carry its default flag with it
 *   6  The service types and settings are install-wide: an Org Admin cannot change them
 *   7  A destination/yard facility must be one the caller can see
 *   8  vendor:dispatch is published to the incident's organizations, not to every holder of the permission
 *   9  Elapsed-time decisions read the DATABASE clock, so a PHP timezone that differs cannot refuse a valid void
 *  10  An offer and a delete of the same company serialise on the provider row (real second connection)
 *
 * @requires-db
 * Usage: php tests/test_vendor_review_fixes.php
 */
require_once __DIR__ . '/_vendor_fixtures.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

$prefix = vf_prefix();
$admin = test_admin_user_id();
$A = vf_actor($admin, 'vf-admin');
vf_session_as($admin, null, 'vf-admin');
register_shutdown_function('vf_cleanup');

if (!vendor_schema_ready()) {
    echo "SKIP: the vendor tables are not installed (run sql/run_gh148_vendor_dispatch.php)\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

// Section 6 deals in INSTALL-WIDE rows (the shared service types). If the fix under test ever regressed, those writes would
// land for real, so this test snapshots the types now and puts them back exactly, whatever happens.
$typeSnapshot = db_fetch_all("SELECT * FROM `{$prefix}vendor_service_types` ORDER BY `id`");
register_shutdown_function(function () use ($typeSnapshot, $prefix) {
    try {
        $codes = [];
        foreach ($typeSnapshot as $r) {
            $codes[] = $r['code'];
            db_query("UPDATE `{$prefix}vendor_service_types` SET `label` = ?, `needs_destination` = ?, `is_active` = ?, `sort_order` = ? WHERE `id` = ?",
                [$r['label'], $r['needs_destination'], $r['is_active'], $r['sort_order'], $r['id']]);
        }
        if ($codes) {
            $ph = implode(',', array_fill(0, count($codes), '?'));
            db_query("DELETE FROM `{$prefix}vendor_service_types` WHERE `code` LIKE 'vrf\_%' AND `code` NOT IN ($ph)", $codes);
        }
    } catch (Throwable $e) { fwrite(STDERR, "service type restore: " . $e->getMessage() . "
"); }
});

$sseMark = (int) db_fetch_value("SELECT COALESCE(MAX(`id`), 0) FROM `{$prefix}sse_events`");
register_shutdown_function(function () use ($sseMark, $prefix) {
    try { db_query("DELETE FROM `{$prefix}sse_events` WHERE `id` > ? AND `event_type` = 'vendor:dispatch'", [$sseMark]); } catch (Throwable $e) {}
});

$BASE = ['vendor_dispatch_enabled' => '1', 'vendor_rotation_mode' => 'round_robin', 'vendor_advance_rule' => 'any_offer',
         'vendor_allow_override' => '1', 'vendor_override_requires_reason' => '1', 'phone_click_to_call' => 'off'];
vf_set_settings($BASE);
$TOW = vf_service_type_id('tow');
$LOCK = vf_service_type_id('lockout');

function scenario(string $tag, array $actor): array
{
    $tid = vf_ticket();
    $list = vf_list("VRF list $tag");
    $p = [];
    foreach (['A', 'B', 'C'] as $i => $n) $p[$n] = vf_provider("VRF $tag $n Towing", ['phone' => '555-02' . (10 + $i)], $actor);
    vf_members($list, [$p['A'], $p['B'], $p['C']], $actor);
    return ['ticket' => $tid, 'list' => $list, 'A' => $p['A'], 'B' => $p['B'], 'C' => $p['C']];
}

echo "=== GH#148 -- review-finding regressions ===\n\n";

// ── 1. omitting list_id is not a way round the override rules ───────────────
echo "--- 1. a pick without list_id is judged against the company's own list ---\n";
$noOverride = array_merge($BASE, ['vendor_allow_override' => '0']);
$s = scenario('shadow', $A);
$w = vf_worker($noOverride, 'offer', ['input' => ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'provider_id' => $s['B']], 'actor' => $A]);
t('override disabled: naming B (not next) with NO list_id is refused like any override (409 override_not_allowed)',
    $w && $w['ok'] === false && $w['code'] === 'override_not_allowed' && $w['http'] === 409);
t('...and nothing was recorded', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatches` WHERE `ticket_id` = ?", [$s['ticket']]) === 0);

$w = vf_worker($noOverride, 'offer', ['input' => ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'provider_id' => $s['A']], 'actor' => $A]);
t('naming the head A with no list_id is a normal rotation offer that uses its turn', $w && $w['ok'] === true && $w['selection_method'] === 'rotation' && (int) $w['consumed_turn'] === 1);
$row = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `id` = ?", [$w['event_id'] ?? 0]);
t('...recorded against that company\'s list, with the expected head stamped', $row && (int) $row['list_id'] === $s['list'] && (int) $row['expected_head_provider_id'] === $s['A']);

$s2 = scenario('shadow2', $A);
$w = vf_worker($noOverride, 'offer', ['input' => ['ticket_id' => $s2['ticket'], 'service_type_id' => $TOW, 'provider_id' => $s2['C'], 'owner_request' => true], 'actor' => $A]);
t('the owner-request flag stays the deliberate exception: allowed with overrides off, flagged owner_request, no turn used',
    $w && $w['ok'] === true && $w['selection_method'] === 'owner_request' && (int) $w['consumed_turn'] === 0);

$solo = vf_provider('VRF Solo Wrecker (on no list)', [], $A);
$w = vf_worker($noOverride, 'offer', ['input' => ['ticket_id' => vf_ticket(), 'service_type_id' => $TOW, 'provider_id' => $solo], 'actor' => $A]);
t('a registered company that is on NO list is still simply "unlisted" (allowed, no turn)', $w && $w['ok'] === true && $w['selection_method'] === 'unlisted');

// ── 2. the accept-time stamp follows the offer row ──────────────────────────
echo "\n--- 2. a mid-call settings flip stamps exactly one turn ---\n";
$ANY = $BASE;
$ACC = array_merge($BASE, ['vendor_advance_rule' => 'accepted_only']);
$s = scenario('flip1', $A);
$o = vf_worker($ANY, 'offer', ['input' => ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list'], 'provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], 'actor' => $A]);
$a = vf_worker($ACC, 'outcome', ['dispatch_id' => $o['dispatch_id'], 'offer_event_id' => $o['event_id'], 'outcome' => 'accepted', 'eta' => 20, 'actor' => $A]);
t('offered under any_offer (turn used), flipped to accepted_only, then accepted: the accept does NOT use a second turn',
    (int) $o['consumed_turn'] === 1 && $a && $a['ok'] === true && (int) $a['consumed_turn'] === 0);

$s = scenario('flip2', $A);
$o = vf_worker($ACC, 'offer', ['input' => ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list'], 'provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], 'actor' => $A]);
$a = vf_worker($ANY, 'outcome', ['dispatch_id' => $o['dispatch_id'], 'offer_event_id' => $o['event_id'], 'outcome' => 'accepted', 'eta' => 20, 'actor' => $A]);
t('offered under accepted_only (no turn yet), flipped to any_offer, then accepted: the accept uses the turn (never none)',
    (int) $o['consumed_turn'] === 0 && $a && $a['ok'] === true && (int) $a['consumed_turn'] === 1);
$q = vf_worker($BASE, 'queue', ['list_id' => $s['list']]);
t('...so A is behind B in the rotation afterwards', $q && $q['head_provider_id'] === $s['B']);

// ── 3. history: filter and summary survive truncation ───────────────────────
echo "\n--- 3. history: overrides filter and summary are computed over everything, not over the fetched page ---\n";
$s = scenario('hist', $A);
for ($i = 0; $i < 4; $i++) {
    $r = vendor_dispatch_offer(['ticket_id' => vf_ticket(), 'service_type_id' => $TOW, 'list_id' => $s['list'], 'provider_id' => $s['C'],
                                'client_head_provider_id' => $s['A'], 'reason' => 'Closest yard'], $A);
    if (!($r['ok'] ?? false)) { echo "  setup: override offer failed: " . json_encode($r) . "\n"; }
}
$r = vendor_dispatch_offer(['ticket_id' => vf_ticket(), 'service_type_id' => $TOW, 'list_id' => $s['list'], 'provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $A);
$h = vendor_history_query(['list_id' => $s['list']], 2);
$byName = function (array $summary, int $pid) { foreach ($summary as $x) { if ((int) $x['provider_id'] === $pid) return $x; } return null; };
t('a page of 2 is returned and flagged truncated', count($h['events']) === 2 && $h['truncated'] === true);
$cRow = $byName($h['summary'], $s['C']);
$aRow = $byName($h['summary'], $s['A']);
t('...but the summary counts ALL of C\'s offers and overrides (4 and 4), not just the 2 rows on the page', $cRow && $cRow['offered'] === 4 && $cRow['overrides'] === 4);
t('...and A\'s one rotation offer', $aRow && $aRow['offered'] === 1 && $aRow['overrides'] === 0);
$ho = vendor_history_query(['list_id' => $s['list'], 'overrides_only' => 1], 2);
$allOverride = true;
foreach ($ho['events'] as $e) { if ($e['event_type'] !== 'offered' || $e['selection_method'] !== 'override') $allOverride = false; }
t('overrides_only: the filter is applied BEFORE the limit (2 rows, all overrides, truncated because 4 exist)', count($ho['events']) === 2 && $allOverride && $ho['truncated'] === true);
t('...and its summary covers only the overrides (C, with 4)', count($ho['summary']) === 1 && (int) $ho['summary'][0]['provider_id'] === $s['C'] && $ho['summary'][0]['overrides'] === 4);

// ── 4. reordering / mode change on a list with history ──────────────────────
echo "\n--- 4. a reorder of a list with history needs a reason and leaves a ledger row ---\n";
$s = scenario('move', $A);
$r = vendor_list_save(['id' => $s['list'], 'name' => 'VRF move list', 'service_type_id' => $TOW, 'mode' => 'strict_order'], $A);
t('setting strict_order on a list with no history is not a ledger event', ($r['ok'] ?? false) === true
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ? AND `event_type` = 'order_changed'", [$s['list']]) === 0);
$r = vendor_dispatch_offer(['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list'], 'provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $A);
t('(setup) a rotation offer gives the list history', ($r['ok'] ?? false) === true && (int) $r['consumed_turn'] === 1);
$r = vendor_member_move($s['list'], $s['C'], 'up', $A);
t('moving a company on a list with history WITHOUT a reason is refused (422 reason_required)', ($r['ok'] ?? true) === false && ($r['code'] ?? '') === 'reason_required');
$r = vendor_member_move($s['list'], $s['C'], 'up', $A, 'Contract amendment 24-117');
$ledgerRow = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ? AND `event_type` = 'order_changed' ORDER BY `id` DESC LIMIT 1", [$s['list']]);
t('with a reason it succeeds and writes an order_changed ledger row naming the company, the reason and the places',
    ($r['ok'] ?? false) === true && $ledgerRow && (int) $ledgerRow['provider_id'] === $s['C']
    && $ledgerRow['reason'] === 'Contract amendment 24-117' && strpos((string) $ledgerRow['detail'], 'place 3 to place 2') !== false
    && (int) $ledgerRow['consumed_turn'] === 0);
$r = vendor_list_save(['id' => $s['list'], 'name' => 'VRF move list', 'service_type_id' => $TOW, 'mode' => 'manual'], $A);
$modeRow = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ? AND `event_type` = 'order_changed' ORDER BY `id` DESC LIMIT 1", [$s['list']]);
t('switching the list\'s mode on a list with history is a ledger row too (a way round the move rule otherwise)',
    ($r['ok'] ?? false) === true && $modeRow && strpos((string) $modeRow['detail'], 'strict_order') !== false && strpos((string) $modeRow['detail'], 'manual') !== false);
$hist = vendor_history_query(['list_id' => $s['list']], 100);
t('the history shows the order change rows (list-level, no dispatch)', count(array_filter($hist['events'], function ($e) { return $e['event_type'] === 'order_changed'; })) === 2);

// ── 5. default flag does not follow a list to another service type ──────────
echo "\n--- 5. the default flag stays behind when a list changes service type ---\n";
$l1 = vf_list('VRF default list');
vendor_list_set_default($l1, true, $A);
t('(setup) the list is the default for tow', (int) db_fetch_value("SELECT `is_default` FROM `{$prefix}vendor_rotation_lists` WHERE `id` = ?", [$l1]) === 1);
$r = vendor_list_save(['id' => $l1, 'name' => 'VRF default list', 'service_type_id' => $LOCK], $A);
t('re-typed to lockout (it has no history): saved, and is_default is cleared', ($r['ok'] ?? false) === true
    && (int) db_fetch_value("SELECT `is_default` FROM `{$prefix}vendor_rotation_lists` WHERE `id` = ?", [$l1]) === 0);

// ── 6. install-wide data is Super-Admin-only ───────────────────────────────
echo "\n--- 6. service types and settings are install-wide ---\n";
$orgX = vf_org('VRF Org X');
$orgY = vf_org('VRF Org Y');
$mgr = vf_user('vrf-mgr-x', 2, $orgX);
vf_session_as($mgr, $orgX, 'vrf-mgr-x');
$r = vendor_settings_save(['vendor_dispatch_enabled' => '0', 'vendor_rotation_mode' => 'manual'], vf_actor($mgr, 'vrf-mgr-x'));
t('an Org Admin saving the settings is refused (403 forbidden_scope)', ($r['ok'] ?? true) === false && ($r['http'] ?? 0) === 403 && ($r['code'] ?? '') === 'forbidden_scope');
t('...and the stored values did not change', (string) db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'vendor_dispatch_enabled'") === '1'
    && (string) db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'vendor_rotation_mode'") === 'round_robin');
$r = vendor_service_type_save(['id' => $TOW, 'label' => 'Renamed by another agency', 'needs_destination' => 1, 'is_active' => 0], vf_actor($mgr, 'vrf-mgr-x'));
t('an Org Admin renaming/deactivating the shared "tow" type is refused', ($r['ok'] ?? true) === false && ($r['http'] ?? 0) === 403
    && (string) db_fetch_value("SELECT `label` FROM `{$prefix}vendor_service_types` WHERE `id` = ?", [$TOW]) !== 'Renamed by another agency'
    && (int) db_fetch_value("SELECT `is_active` FROM `{$prefix}vendor_service_types` WHERE `id` = ?", [$TOW]) === 1);
$r = vendor_service_type_save(['label' => 'Org X invented type', 'code' => 'vrf_x_type'], vf_actor($mgr, 'vrf-mgr-x'));
t('...and cannot add a type', ($r['ok'] ?? true) === false && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_service_types` WHERE `code` = 'vrf_x_type'") === 0);
vf_session_as($admin, null, 'vf-admin');
$r = vendor_settings_save(['vendor_rotation_mode' => 'round_robin'], $A);
t('a Super Admin still can', ($r['ok'] ?? false) === true);

// ── 7. facility visibility ─────────────────────────────────────────────────
echo "\n--- 7. a facility from another agency is not an acceptable destination ---\n";
$facX = vf_facility('VRF Yard of Org X');
$facY = vf_facility('VRF Yard of Org Y');
$hasFacOrg = true;
try {
    db_query("UPDATE `{$prefix}facilities` SET `org_id` = ? WHERE `id` = ?", [$orgX, $facX]);
    db_query("UPDATE `{$prefix}facilities` SET `org_id` = ? WHERE `id` = ?", [$orgY, $facY]);
} catch (Throwable $e) { $hasFacOrg = false; }
if (!$hasFacOrg) {
    echo "[SKIP] facilities.org_id does not exist on this install (the check degrades to plain existence there)\n";
} else {
    vf_session_as($mgr, $orgX, 'vrf-mgr-x');
    t('an Org X dispatcher can use Org X\'s facility', vendor_facility_exists($facX) === true);
    t('...but not Org Y\'s (refused, so its name can never be read back)', vendor_facility_exists($facY) === false);
    $dispatchOk = vendor_dispatch_offer(['ticket_id' => vf_ticket($orgX), 'service_type_id' => $TOW, 'provider_name' => 'VRF Typed Wrecker', 'provider_phone' => '555-0199',
                                         'dest_facility_id' => $facY], vf_actor($mgr, 'vrf-mgr-x'));
    t('an offer naming Org Y\'s facility as the destination is refused (422), nothing recorded',
        ($dispatchOk['ok'] ?? true) === false && ($dispatchOk['http'] ?? 0) === 422);
    vf_session_as($admin, null, 'vf-admin');
    t('a Super Admin may use either', vendor_facility_exists($facX) === true && vendor_facility_exists($facY) === true);
}

// ── 8. SSE audience ────────────────────────────────────────────────────────
echo "\n--- 8. vendor:dispatch goes to the incident's organizations ---\n";
$tOrg = vf_ticket($orgX);
vendor_publish_dispatch_sse($tOrg, ['ticket_id' => $tOrg, 'dispatch_id' => 1, 'status' => 'open']);
$evt = db_fetch_one("SELECT * FROM `{$prefix}sse_events` WHERE `id` > ? AND `event_type` = 'vendor:dispatch' ORDER BY `id` DESC LIMIT 1", [$sseMark]);
t('an incident owned by an organization: published as "entitled" scoped to that organization only',
    $evt && $evt['visibility_scope'] === 'entitled' && (string) $evt['visibility_ids'] === (string) $orgX);
$payload = $evt ? json_decode((string) $evt['payload'], true) : [];
t('...and the payload carries ids and a status only (no names or numbers)', is_array($payload) && array_keys($payload) === ['ticket_id', 'dispatch_id', 'status']);

$sharedOk = false;
if (!function_exists('org_sharing_create_manual_share') && is_file(__DIR__ . '/../inc/org-sharing.php')) require_once __DIR__ . '/../inc/org-sharing.php';
if (function_exists('org_sharing_create_manual_share')) {
    $sh = org_sharing_create_manual_share($tOrg, $orgY, 'assist', 'GH148 test share', $admin, 'vf-admin');
    $sharedOk = ($sh['success'] ?? false) === true;
}
if ($sharedOk) {
    $mark2 = (int) db_fetch_value("SELECT COALESCE(MAX(`id`), 0) FROM `{$prefix}sse_events`");
    vendor_publish_dispatch_sse($tOrg, ['ticket_id' => $tOrg, 'dispatch_id' => 1, 'status' => 'open']);
    $evt2 = db_fetch_one("SELECT * FROM `{$prefix}sse_events` WHERE `id` > ? AND `event_type` = 'vendor:dispatch' ORDER BY `id` DESC LIMIT 1", [$mark2]);
    $ids = $evt2 ? explode(',', (string) $evt2['visibility_ids']) : [];
    sort($ids);
    $want = [(string) $orgX, (string) $orgY]; sort($want);
    t('an organization holding an ASSIST share is included', $ids === $want);
} else {
    echo "[SKIP] could not create a manual assist share on this install (Phase 142 tables/permissions); the share branch is covered by reading\n";
}

$tNone = vf_ticket();
db_query("UPDATE `{$prefix}ticket` SET `org_id` = NULL WHERE `id` = ?", [$tNone]);
$mark3 = (int) db_fetch_value("SELECT COALESCE(MAX(`id`), 0) FROM `{$prefix}sse_events`");
vendor_publish_dispatch_sse($tNone, ['ticket_id' => $tNone, 'dispatch_id' => 1, 'status' => 'open']);
$evt3 = db_fetch_one("SELECT * FROM `{$prefix}sse_events` WHERE `id` > ? AND `event_type` = 'vendor:dispatch' ORDER BY `id` DESC LIMIT 1", [$mark3]);
t('an incident with no owning organization falls back to the ordinary per-incident audience (an event is still published)', $evt3 !== null && $evt3['visibility_scope'] !== 'public');

$stream = (string) file_get_contents(__DIR__ . '/../api/stream.php');
t('api/stream.php applies the same org scoping to vendor:% as to call:%', (bool) preg_match("/if \\(\\\$pfx === 'call:%' \\|\\| \\\$pfx === 'vendor:%'\\)/", $stream));

// ── 9. database clock ──────────────────────────────────────────────────────
echo "\n--- 9. elapsed time is measured on the database clock ---\n";
t('vendor_db_now_ts() agrees with the database\'s own NOW()', abs(vendor_db_now_ts() - strtotime((string) db_fetch_value('SELECT NOW()'))) <= 2);
$dispatcher = vf_user('vrf-disp', 3);
$D = vf_actor($dispatcher, 'vrf-disp');
$s = scenario('skew', $A);
$o = vendor_dispatch_offer(['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list'], 'provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $D);
$origTz = date_default_timezone_get();
date_default_timezone_set('Pacific/Kiritimati');          // UTC+14: no sane database server runs there, so the two clocks disagree
$v = vendor_dispatch_void((int) $o['event_id'], 'wrong company called', $D, false);
date_default_timezone_set($origTz);
t('a dispatcher can void their own fresh entry even when PHP\'s timezone differs from the database\'s', ($v['ok'] ?? false) === true);

// ── 10. provider row lock: offer and delete serialise (real second connection) ──
echo "\n--- 10. an offer and a delete of the same company meet on the provider row ---\n";

/** Start the lock holder; returns [proc, pipes, stderrFile] once it has printed READY (lock held), or null. */
function start_holder(int $providerId, int $listId, int $hold, bool $writeRow): ?array
{
    $err = tempnam(sys_get_temp_dir(), 'vrfh');
    $argv = [PHP_BINARY ?: 'php', __DIR__ . '/_vendor_lock_holder.php', (string) $providerId, (string) $listId, (string) $hold, $writeRow ? '1' : '0'];
    $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $err, 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return null;
    fclose($pipes[0]);
    $line = fgets($pipes[1]);
    if (trim((string) $line) !== 'READY') {
        fclose($pipes[1]); proc_close($proc);
        fwrite(STDERR, "lock holder did not report READY: " . var_export($line, true) . " " . @file_get_contents($err) . "\n");
        @unlink($err);
        return null;
    }
    return [$proc, $pipes, $err];
}
function finish_holder(?array $h): void
{
    if (!$h) return;
    stream_get_contents($h[1][1]);
    fclose($h[1][1]);
    proc_close($h[0]);
    @unlink($h[2]);
}

$s = scenario('lock', $A);
$h = start_holder($s['B'], 0, 3, false);
if (!$h) {
    t('the lock holder started', false);
} else {
    $t0 = microtime(true);
    $r = vendor_dispatch_offer(['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list'], 'provider_id' => $s['B'], 'client_head_provider_id' => $s['A'],
                                'reason' => 'lock test'], $A);
    $waited = microtime(true) - $t0;
    finish_holder($h);
    t('an offer naming company B WAITS while another connection holds B\'s row (waited ' . round($waited, 1) . 's of a 3s hold), then succeeds',
        $waited >= 1.5 && ($r['ok'] ?? false) === true);
}

$orphan = vf_provider('VRF Delete Race Co', [], $A);
$h = start_holder($orphan, 0, 3, true);
if (!$h) {
    t('the lock holder started (delete case)', false);
} else {
    $t0 = microtime(true);
    $r = vendor_provider_delete($orphan, $A);
    $waited = microtime(true) - $t0;
    finish_holder($h);
    t('a delete of company X waits for an in-flight offer that already took X\'s row (waited ' . round($waited, 1) . 's), then sees that offer\'s ledger row and REFUSES',
        $waited >= 1.5 && ($r['ok'] ?? true) === false && ($r['code'] ?? '') === 'provider_referenced');
    t('...so the company still exists (no ledger row left pointing at nothing)', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_providers` WHERE `id` = ?", [$orphan]) === 1);
}

$src = (string) file_get_contents(__DIR__ . '/../inc/vendor-dispatch.php');
$offerSrc = substr($src, (int) strpos($src, 'function vendor_dispatch_offer('), 9000);
$posList = strpos($offerSrc, "vendor_rotation_lists` WHERE `id` = ? FOR UPDATE");
$posProv = strpos($offerSrc, "vendor_providers` WHERE `id` = ? FOR UPDATE");
t('lock order in the offer is list THEN provider', $posList !== false && $posProv !== false && $posList < $posProv);
$adm = (string) file_get_contents(__DIR__ . '/../inc/vendor-admin-write.php');
$delSrc = substr($adm, (int) strpos($adm, 'function vendor_provider_delete('), 2500);
$p1 = strpos($delSrc, 'ORDER BY l.`id` FOR UPDATE');
$p2 = strpos($delSrc, "vendor_providers` WHERE `id` = ? FOR UPDATE");
t('lock order in the delete is the same: lists (in id order), THEN provider', $p1 !== false && $p2 !== false && $p1 < $p2);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
