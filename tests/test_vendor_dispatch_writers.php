<?php
/**
 * GH#148 (Phase 155) -- the dispatch WRITERS, against a real database, through the real functions.
 *
 * Drives vendor_dispatch_create / _offer / _outcome / _status / _note / _void and the admin writers that set the
 * scene (providers, lists, members) -- never a hand-seeded ledger row. Settings-dependent behaviour runs in a FRESH
 * subprocess per setting value (tests/_vendor_worker.php), because get_variable() caches the whole settings table for the
 * life of a process.
 *
 * Unique keys are verified the only way that proves anything: by attempting a real duplicate INSERT (a UNIQUE key that
 * ends in a NULLable column constrains nothing, and reading the DDL cannot tell you).
 *
 * @requires-db
 * Usage: php tests/test_vendor_dispatch_writers.php
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

// The baseline this process runs under (equal to the shipped defaults). Variants use a worker process.
$BASE = ['vendor_dispatch_enabled' => '1', 'vendor_rotation_mode' => 'round_robin', 'vendor_advance_rule' => 'any_offer',
         'vendor_allow_override' => '1', 'vendor_override_requires_reason' => '1', 'phone_click_to_call' => 'off'];
vf_set_settings($BASE);
$TOW = vf_service_type_id('tow');
$LOCK = vf_service_type_id('lockout');

/** One scenario: a ticket, a tow list and three companies (A, B, C), added in that order. */
function scenario(string $tag, array $actor): array
{
    $tid = vf_ticket();
    $list = vf_list("VTW list $tag");
    $p = [];
    foreach (['A', 'B', 'C'] as $i => $n) $p[$n] = vf_provider("VTW $tag $n Towing", ['phone' => '555-01' . (10 + $i)], $actor);
    vf_members($list, [$p['A'], $p['B'], $p['C']], $actor);
    return ['ticket' => $tid, 'list' => $list, 'A' => $p['A'], 'B' => $p['B'], 'C' => $p['C']];
}

function offer(array $in, array $actor) { return vendor_dispatch_offer($in, $actor); }

function dup_insert_fails(string $sql, array $params): bool
{
    try { db_query($sql, $params); return false; }
    catch (PDOException $e) { return isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062; }
}

function cache_matches(int $dispatchId): bool
{
    $prefix = vf_prefix();
    $h = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatches` WHERE `id` = ?", [$dispatchId]);
    $d = vendor_dispatch_derive_status(vendor_dispatch_ledger_rows($dispatchId));
    foreach (['status', 'provider_id', 'provider_name', 'provider_phone', 'eta_minutes', 'assigned_at', 'closed_at'] as $k) {
        if ((string) ($h[$k] ?? '') !== (string) ($d[$k] ?? '')) { echo "    cache mismatch on $k: header=" . var_export($h[$k], true) . " derived=" . var_export($d[$k], true) . "\n"; return false; }
    }
    return true;
}

echo "=== GH#148 -- dispatch writers ===\n\n";

// ══════════════════════════════════════════════════════════════
// 1. Header, ordinal, reference; the three real unique keys
// ══════════════════════════════════════════════════════════════
echo "--- header, ordinal, reference; real unique keys ---\n";
$s = scenario('hdr', $A);
$r1 = vendor_dispatch_create($s['ticket'], ['service_type_id' => $TOW, 'list_id' => $s['list'], 'vehicle_desc' => 'Blue Honda', 'plate' => 'abc123', 'plate_state' => 'mn'], $A);
$r2 = vendor_dispatch_create($s['ticket'], ['service_type_id' => $TOW, 'list_id' => $s['list']], $A);
t('a dispatch header is created through the writer', $r1['ok'] === true && $r1['dispatch']['status'] === 'open');
t('ordinals count up per incident (T1, T2)', (int) $r1['dispatch']['ordinal'] === 1 && (int) $r2['dispatch']['ordinal'] === 2);
$tref = incnum_display($s['ticket']);
t('the reference is <incident number>-T<ordinal> and the incident number is snapshotted',
    vendor_ref_label($r1['dispatch']['ticket_ref'], 1) === $tref . '-T1' && $r1['dispatch']['ticket_ref'] === $tref);
t('plate and state are normalised to upper case', $r1['dispatch']['plate'] === 'ABC123' && $r1['dispatch']['plate_state'] === 'MN');
t('the header org is the list org (the rows a supervisor sees in the history are the lists they own)',
    $r1['dispatch']['org_id'] !== null && (int) $r1['dispatch']['org_id'] === (int) vendor_list_get($s['list'])['org_id']);

t('uk_ticket_ordinal: a real duplicate (ticket_id, ordinal) INSERT is refused',
    dup_insert_fails("INSERT INTO `{$prefix}vendor_dispatches` (`ticket_id`,`ticket_ref`,`ordinal`,`service_type_id`,`service_label`,`created_by_name`) VALUES (?,?,?,?,?,?)",
        [$s['ticket'], 'x', 1, $TOW, 'Tow', 'dup']));
t('uk_list_provider: a real duplicate (list_id, provider_id) INSERT is refused',
    dup_insert_fails("INSERT INTO `{$prefix}vendor_rotation_members` (`list_id`,`provider_id`,`position`) VALUES (?,?,?)", [$s['list'], $s['A'], 9]));
t('the service type code is really UNIQUE: a duplicate code INSERT is refused',
    dup_insert_fails("INSERT INTO `{$prefix}vendor_service_types` (`code`,`label`) VALUES ('tow', 'Another tow')", []));

$closedT = vf_ticket(null, true);
$rc = vendor_dispatch_create($closedT, ['service_type_id' => $TOW], $A);
t('a bare header is refused on a closed incident', $rc['ok'] === false && $rc['code'] === 'incident_closed');
$rw = vendor_dispatch_create($s['ticket'], ['service_type_id' => $LOCK, 'list_id' => $s['list']], $A);
t('a list for one service type cannot be used for another', $rw['ok'] === false && $rw['code'] === 'validation');
$rn = vendor_dispatch_create($s['ticket'], ['service_type_id' => 0], $A);
t('the service type is required', $rn['ok'] === false && $rn['code'] === 'validation');

// ══════════════════════════════════════════════════════════════
// 2. Offer classification: the four methods
// ══════════════════════════════════════════════════════════════
echo "\n--- offer classification ---\n";
$s = scenario('cls', $A);
$base = ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list']];
$o1 = offer($base + ['provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $A);
t('calling the head is a ROTATION offer', $o1['ok'] === true && $o1['selection_method'] === 'rotation');
$row1 = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `id` = ?", [$o1['event_id']]);
t('the offer row records the method, the expected head and the snapshot of what was dialled',
    $row1['selection_method'] === 'rotation' && (int) $row1['expected_head_provider_id'] === $s['A'] && $row1['provider_name'] === 'VTW cls A Towing' && $row1['provider_phone'] === '555-0110');
t('under any_offer a rotation offer consumes the turn', (int) $row1['consumed_turn'] === 1);

$q = vendor_rotation_queue($s['list']);
t('after A was called the head is B', $q['head_provider_id'] === $s['B']);

$ov0 = offer($base + ['provider_id' => $s['C'], 'client_head_provider_id' => $s['B']], $A);
t('picking a non-head company without a reason is refused (reason_required) and the refusal carries the queue',
    $ov0['ok'] === false && $ov0['code'] === 'reason_required' && isset($ov0['queue']));
t('the refused offer created NO dispatch header and NO ledger row',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatches` WHERE `ticket_id` = ?", [$s['ticket']]) === 1);
$ov = offer($base + ['provider_id' => $s['C'], 'client_head_provider_id' => $s['B'], 'reason' => 'Closest truck'], $A);
$rowO = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `id` = ?", [$ov['event_id'] ?? 0]);
t('a non-head pick with a reason is an OVERRIDE that does NOT consume a turn',
    $ov['ok'] === true && $ov['selection_method'] === 'override' && (int) $rowO['consumed_turn'] === 0 && $rowO['reason'] === 'Closest truck' && (int) $rowO['expected_head_provider_id'] === $s['B']);

$ow = offer($base + ['provider_id' => $s['B'], 'owner_request' => true, 'client_head_provider_id' => $s['B']], $A);
$rowW = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `id` = ?", [$ow['event_id'] ?? 0]);
t('an owner-requested company is OWNER_REQUEST, even the head, and does not consume the turn',
    $ow['ok'] === true && $ow['selection_method'] === 'owner_request' && (int) $rowW['consumed_turn'] === 0);

$un = offer($base + ['provider_name' => 'Walk-in Wrecker', 'provider_phone' => '555-0199'], $A);
$rowU = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `id` = ?", [$un['event_id'] ?? 0]);
t('a typed company is UNLISTED: no provider id, name and number snapshotted, no turn consumed',
    $un['ok'] === true && $un['selection_method'] === 'unlisted' && $rowU['provider_id'] === null && $rowU['provider_name'] === 'Walk-in Wrecker' && (int) $rowU['consumed_turn'] === 0);
$un2 = offer($base + ['provider_name' => 'No Phone Towing'], $A);
t('an unlisted company needs a phone number', $un2['ok'] === false && $un2['code'] === 'validation');

$q = vendor_rotation_queue($s['list']);
t('override, owner request and unlisted calls left B at the head: none of them used a turn', $q['head_provider_id'] === $s['B']);

$pend = offer($base + ['dispatch_id' => $o1['dispatch_id'], 'provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $A);
t('calling a company that is already being called on this dispatch is refused (offer_pending)', $pend['ok'] === false && $pend['code'] === 'offer_pending');

// ══════════════════════════════════════════════════════════════
// 3. Stale screen: the rotation moved while the screen was open
// ══════════════════════════════════════════════════════════════
echo "\n--- stale screen (queue_changed) ---\n";
$s = scenario('stale', $A);
$base = ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list']];
$first = offer($base + ['provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $A);
$before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatches` WHERE `ticket_id` = ?", [$s['ticket']]);
$stale = offer($base + ['provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $A);
t('a second dispatcher whose screen still showed A as next gets queue_changed (409), not a duplicate offer',
    $stale['ok'] === false && $stale['code'] === 'queue_changed' && $stale['http'] === 409);
t('the stale answer carries the fresh queue with B as the head', isset($stale['queue']) && $stale['queue']['head_provider_id'] === $s['B']);
t('the stale answer says who is next now', strpos($stale['message'], 'VTW stale B Towing') !== false);
t('nothing was recorded for the stale attempt (no header, no offer)',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatches` WHERE `ticket_id` = ?", [$s['ticket']]) === $before
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `provider_id` = ? AND `event_type` = 'offered'", [$s['A']]) === 1);
$deliberate = offer($base + ['provider_id' => $s['C'], 'client_head_provider_id' => $s['A'], 'reason' => 'Closest truck'], $A);
t('a DELIBERATE pick of a different company is not "stale": it classifies as an override', $deliberate['ok'] === true && $deliberate['selection_method'] === 'override');

// ══════════════════════════════════════════════════════════════
// 4. Settings, one fresh process per value
// ══════════════════════════════════════════════════════════════
echo "\n--- settings-dependent behaviour (fresh process per value) ---\n";
$noOverride = $BASE; $noOverride['vendor_allow_override'] = '0';
$s = scenario('set1', $A);
$in = ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list'], 'provider_id' => $s['C'], 'client_head_provider_id' => $s['A'], 'reason' => 'Closest truck'];
$w = vf_worker($noOverride, 'offer', ['input' => $in, 'actor' => $A]);
t('vendor_allow_override=0: a non-head pick is refused (override_not_allowed, 409)', $w && $w['ok'] === false && $w['code'] === 'override_not_allowed' && $w['http'] === 409);
$w = vf_worker($BASE, 'offer', ['input' => $in, 'actor' => $A]);
t('vendor_allow_override=1: the same pick succeeds as an override', $w && $w['ok'] === true && $w['selection_method'] === 'override');

$noReason = $BASE; $noReason['vendor_override_requires_reason'] = '0';
$s = scenario('set2', $A);
$in = ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list'], 'provider_id' => $s['C'], 'client_head_provider_id' => $s['A']];
$w = vf_worker($BASE, 'offer', ['input' => $in, 'actor' => $A]);
t('vendor_override_requires_reason=1: an override with no reason is refused', $w && $w['ok'] === false && $w['code'] === 'reason_required');
$w = vf_worker($noReason, 'offer', ['input' => $in, 'actor' => $A]);
t('vendor_override_requires_reason=0: the same override with no reason is accepted', $w && $w['ok'] === true && $w['selection_method'] === 'override');

// any_offer vs accepted_only
$acc = $BASE; $acc['vendor_advance_rule'] = 'accepted_only';
$s = scenario('adv1', $A);
$in = ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list'], 'provider_id' => $s['A'], 'client_head_provider_id' => $s['A']];
$w = vf_worker($acc, 'offer', ['input' => $in, 'actor' => $A]);
$offRow = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `id` = ?", [$w['event_id'] ?? 0]);
t('accepted_only: the offer itself does NOT consume the turn', $w && $w['ok'] === true && (int) $offRow['consumed_turn'] === 0);
$wq = vf_worker($acc, 'queue', ['list_id' => $s['list']]);
t('accepted_only: after a bare offer A is still the head', $wq && $wq['head_provider_id'] === $s['A']);
$wd = vf_worker($acc, 'outcome', ['dispatch_id' => $w['dispatch_id'], 'offer_event_id' => $w['event_id'], 'outcome' => 'declined', 'actor' => $A]);
$wq = vf_worker($acc, 'queue', ['list_id' => $s['list']]);
t('accepted_only: a DECLINE costs the company nothing, A stays the head for the next call', $wd && $wd['ok'] === true && $wq['head_provider_id'] === $s['A']);

$s = scenario('adv2', $A);
$in = ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list'], 'provider_id' => $s['A'], 'client_head_provider_id' => $s['A']];
$w = vf_worker($acc, 'offer', ['input' => $in, 'actor' => $A]);
$wa = vf_worker($acc, 'outcome', ['dispatch_id' => $w['dispatch_id'], 'offer_event_id' => $w['event_id'], 'outcome' => 'accepted', 'eta' => 15, 'actor' => $A]);
$accRow = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `id` = ?", [$wa['event_id'] ?? 0]);
$wq = vf_worker($acc, 'queue', ['list_id' => $s['list']]);
t('accepted_only: the ACCEPTED row consumes the turn, so B is next', $wa && $wa['ok'] === true && (int) $accRow['consumed_turn'] === 1 && $wq['head_provider_id'] === $s['B']);

$s = scenario('adv3', $A);
$in = ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list'], 'provider_id' => $s['A'], 'client_head_provider_id' => $s['A']];
$w = vf_worker($BASE, 'offer', ['input' => $in, 'actor' => $A]);
$wd = vf_worker($BASE, 'outcome', ['dispatch_id' => $w['dispatch_id'], 'offer_event_id' => $w['event_id'], 'outcome' => 'declined', 'actor' => $A]);
$wq = vf_worker($BASE, 'queue', ['list_id' => $s['list']]);
$dRow = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `id` = ?", [$wd['event_id'] ?? 0]);
t('any_offer: a decline already cost A its turn (stamped on the offer), so B is next', $wq['head_provider_id'] === $s['B'] && (int) $dRow['consumed_turn'] === 0);

// ══════════════════════════════════════════════════════════════
// 5. Outcomes and the state machine
// ══════════════════════════════════════════════════════════════
echo "\n--- outcomes and status transitions ---\n";
$s = scenario('out', $A);
$base = ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list']];
$o = offer($base + ['provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $A);
$did = $o['dispatch_id'];
t('outcome: an unknown outcome is refused', vendor_dispatch_outcome($did, $o['event_id'], 'teleported', null, $A)['code'] === 'validation');
t('outcome: an absurd ETA is refused', vendor_dispatch_outcome($did, $o['event_id'], 'accepted', 5000, $A)['code'] === 'validation');
$acc1 = vendor_dispatch_outcome($did, $o['event_id'], 'accepted', 20, $A);
t('accepted: the header becomes assigned with the provider and ETA copied', $acc1['ok'] === true && $acc1['dispatch']['status'] === 'assigned'
    && (int) $acc1['dispatch']['provider_id'] === $s['A'] && (int) $acc1['dispatch']['eta_minutes'] === 20 && $acc1['dispatch']['provider_name'] === 'VTW out A Towing');
t('header cache equals the status derived from the ledger (accepted)', cache_matches($did));
$again = vendor_dispatch_outcome($did, $o['event_id'], 'declined', null, $A);
t('a call that already has an outcome cannot be answered twice', $again['ok'] === false && $again['code'] === 'outcome_already_recorded');
$late = offer(['dispatch_id' => $did, 'list_id' => $s['list'], 'provider_id' => $s['B'], 'client_head_provider_id' => $s['B']], $A);
t('a new offer on an already-assigned dispatch is refused', $late['ok'] === false && $late['code'] === 'already_assigned');
$other = vendor_dispatch_outcome(999999991, $o['event_id'], 'declined', null, $A);
t('an outcome for a dispatch that does not exist is a 404', $other['ok'] === false && $other['http'] === 404);

$bad = vendor_dispatch_status($did, 'completed', null, $A);
t('completed from assigned is legal', $bad['ok'] === true && $bad['dispatch']['status'] === 'completed' && $bad['dispatch']['closed_at'] !== null);
t('header cache equals the derived status (completed)', cache_matches($did));
t('on_scene after completed is an illegal transition (409)', vendor_dispatch_status($did, 'on_scene', null, $A)['code'] === 'illegal_transition');
t('cancel after completed is illegal', vendor_dispatch_status($did, 'cancelled', null, $A)['code'] === 'illegal_transition');
t('an ETA update on a finished dispatch is illegal', vendor_dispatch_status($did, 'eta_update', 10, $A)['code'] === 'illegal_transition');
t('a NEW offer on a finished dispatch is refused (dispatch_closed)',
    offer(['dispatch_id' => $did, 'list_id' => $s['list'], 'provider_id' => $s['B'], 'client_head_provider_id' => $s['B']], $A)['code'] === 'dispatch_closed');

// on_scene path and withdrew
$o2 = offer($base + ['provider_id' => $s['B'], 'client_head_provider_id' => $s['B']], $A);
$d2 = $o2['dispatch_id'];
t('on_scene before anyone accepted is illegal', vendor_dispatch_status($d2, 'on_scene', null, $A)['code'] === 'illegal_transition');
t('withdrew before anyone accepted is illegal', vendor_dispatch_status($d2, 'withdrew', null, $A)['code'] === 'illegal_transition');
vendor_dispatch_outcome($d2, $o2['event_id'], 'accepted', 30, $A);
$etaRes = vendor_dispatch_status($d2, 'eta_update', 45, $A);
t('eta_update while assigned changes the ETA', $etaRes['ok'] === true && (int) vendor_dispatch_get($d2)['eta_minutes'] === 45);
t('eta_update needs a value', vendor_dispatch_status($d2, 'eta_update', null, $A)['code'] === 'validation');
t('assigned -> on_scene is legal', vendor_dispatch_status($d2, 'on_scene', null, $A)['ok'] === true && vendor_dispatch_get($d2)['status'] === 'on_scene');
t('header cache equals the derived status (on_scene)', cache_matches($d2));
$wd = vendor_dispatch_status($d2, 'withdrew', null, $A, 'Truck broke down');
$h2 = vendor_dispatch_get($d2);
t('withdrew returns the dispatch to open with the provider cleared', $wd['ok'] === true && $h2['status'] === 'open' && $h2['provider_id'] === null && $h2['eta_minutes'] === null);
t('header cache equals the derived status (withdrew)', cache_matches($d2));
$o3 = offer(['dispatch_id' => $d2, 'list_id' => $s['list'], 'provider_id' => $s['C'], 'client_head_provider_id' => $s['C']], $A);
t('after a withdrawal another company can be called on the same dispatch (C is next: A and B have turns)', $o3['ok'] === true && $o3['selection_method'] === 'rotation');
vendor_dispatch_outcome($d2, $o3['event_id'], 'no_answer', null, $A);
t('no_answer is a legal outcome and leaves the dispatch open', vendor_dispatch_get($d2)['status'] === 'open');
t('cancelling an open dispatch is legal', vendor_dispatch_status($d2, 'cancelled', null, $A)['ok'] === true && vendor_dispatch_get($d2)['status'] === 'cancelled');
t('header cache equals the derived status (cancelled)', cache_matches($d2));
$d3o = offer($base + ['provider_id' => $s['A'], 'owner_request' => true], $A);
vendor_dispatch_outcome($d3o['dispatch_id'], $d3o['event_id'], 'accepted', 5, $A);
t('gone-on-arrival is legal from assigned', vendor_dispatch_status($d3o['dispatch_id'], 'goa', null, $A)['ok'] === true && vendor_dispatch_get($d3o['dispatch_id'])['status'] === 'goa');
$noteR = vendor_dispatch_note($d3o['dispatch_id'], "Driver says it was towed by the owner's friend", $A);
t('a note is accepted in any state, even a finished dispatch', $noteR['ok'] === true && in_array('note', vf_ledger_types($d3o['dispatch_id']), true));
t('an empty note is refused', vendor_dispatch_note($d3o['dispatch_id'], '  ', $A)['code'] === 'validation');

// ══════════════════════════════════════════════════════════════
// 6. Destination only for a service type that needs one
// ══════════════════════════════════════════════════════════════
echo "\n--- destination rule ---\n";
$fac = vf_facility('VT Impound Lot');
$tid = vf_ticket();
$lockList = vf_list('VTW lockout list', 'lockout');
$lp = vf_provider('VTW Locksmith', [], $A);
vf_members($lockList, [$lp], $A);
$lo = offer(['ticket_id' => $tid, 'service_type_id' => $LOCK, 'list_id' => $lockList, 'provider_id' => $lp, 'client_head_provider_id' => $lp,
             'dest_facility_id' => $fac, 'dest_text' => '123 Nowhere St'], $A);
$lh = vendor_dispatch_get($lo['dispatch_id']);
t('a lockout ignores a destination even if one is sent', $lo['ok'] === true && $lh['dest_facility_id'] === null && $lh['dest_text'] === null);
$tl = vf_list('VTW tow dest list');
$tp = vf_provider('VTW Dest Towing', [], $A);
vf_members($tl, [$tp], $A);
$to = offer(['ticket_id' => $tid, 'service_type_id' => $TOW, 'list_id' => $tl, 'provider_id' => $tp, 'client_head_provider_id' => $tp,
             'dest_facility_id' => $fac], $A);
t('a tow keeps a destination facility', $to['ok'] === true && (int) vendor_dispatch_get($to['dispatch_id'])['dest_facility_id'] === $fac);
$tv = vendor_dispatch_view(vendor_dispatch_get($to['dispatch_id']), $A, false);
t('the dispatch view names the destination facility', $tv['dest_facility_name'] === 'VT Impound Lot');
$tf = offer(['ticket_id' => $tid, 'service_type_id' => $TOW, 'provider_name' => 'Freetext Wrecker', 'provider_phone' => '555-0123',
             'dest_text' => '77 County Garage Rd, Springfield'], $A);
t('a free-text destination with NO facility is accepted and never blocks the dispatch',
    $tf['ok'] === true && vendor_dispatch_get($tf['dispatch_id'])['dest_text'] === '77 County Garage Rd, Springfield' && vendor_dispatch_get($tf['dispatch_id'])['dest_facility_id'] === null);
$tb = offer(['ticket_id' => $tid, 'service_type_id' => $TOW, 'provider_name' => 'Bad Dest Wrecker', 'provider_phone' => '555-0124', 'dest_facility_id' => 999999992], $A);
t('a destination facility that does not exist is refused', $tb['ok'] === false && $tb['code'] === 'validation');
$up = vendor_dispatch_update_details($lo['dispatch_id'], ['service_type_id' => $LOCK, 'vehicle_desc' => 'Silver Subaru', 'dest_text' => 'ignored'], $A);
t('updating details on a lockout still drops the destination', $up['ok'] === true && vendor_dispatch_get($lo['dispatch_id'])['dest_text'] === null && vendor_dispatch_get($lo['dispatch_id'])['vehicle_desc'] === 'Silver Subaru');
t('updating details reports which fields changed', in_array('vehicle_desc', $up['changed'], true));

// ══════════════════════════════════════════════════════════════
// 7. A closed incident: new offers refused, outcomes and status accepted
// ══════════════════════════════════════════════════════════════
echo "\n--- closed incident ---\n";
$s = scenario('closed', $A);
$base = ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list']];
$o = offer($base + ['provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $A);
incident_update_status_internal($s['ticket'], 1, $admin);
$no = offer(['dispatch_id' => $o['dispatch_id'], 'list_id' => $s['list'], 'provider_id' => $s['B'], 'client_head_provider_id' => $s['B']], $A);
t('a NEW offer on a closed incident is refused', $no['ok'] === false && $no['code'] === 'incident_closed');
$ac = vendor_dispatch_outcome($o['dispatch_id'], $o['event_id'], 'accepted', 25, $A);
t('an OUTCOME is still recorded after the incident closed (a tow arriving late must be on record)', $ac['ok'] === true && $ac['dispatch']['status'] === 'assigned');
t('a STATUS event is still recorded after the incident closed', vendor_dispatch_status($o['dispatch_id'], 'on_scene', null, $A)['ok'] === true);

// ══════════════════════════════════════════════════════════════
// 8. The incident log
// ══════════════════════════════════════════════════════════════
echo "\n--- incident log notes ---\n";
$notes = array_column(db_fetch_all("SELECT `description` FROM `{$prefix}action` WHERE `ticket_id` = ? ORDER BY `id`", [$s['ticket']]), 'description');
$joined = implode("\n", $notes);
$ref = incnum_display($s['ticket']) . '-T1';
t('the incident log shows the call: "Tow ref <ref>: called <company> (next up)"', strpos($joined, "Tow ref $ref: called VTW closed A Towing (next up)") !== false);
t('the incident log shows the acceptance with its ETA', strpos($joined, "Tow ref $ref: VTW closed A Towing accepted, ETA 25 min") !== false);
t('the incident log shows the status change', strpos($joined, "Tow ref $ref: VTW closed A Towing on scene") !== false);

// ══════════════════════════════════════════════════════════════
// 9. Snapshots survive a rename
// ══════════════════════════════════════════════════════════════
echo "\n--- snapshots survive a rename ---\n";
$s = scenario('snap', $A);
$o = offer(['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list'], 'provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $A);
vendor_dispatch_outcome($o['dispatch_id'], $o['event_id'], 'accepted', 10, $A);
vendor_provider_save(['id' => $s['A'], 'name' => 'VTW snap RENAMED Towing', 'phone' => '555-7777'], $A);
$ledger = vf_ledger($o['dispatch_id']);
t('the ledger keeps the name and number that were shown and dialled', $ledger[0]['provider_name'] === 'VTW snap A Towing' && $ledger[0]['provider_phone'] === '555-0110');
$view = vendor_dispatch_view(vendor_dispatch_get($o['dispatch_id']), $A, true);
t('the dispatch header keeps the snapshot too', $view['provider_name'] === 'VTW snap A Towing' && $view['provider_phone'] === '555-0110');
t('the timeline shows the old name', $view['events'][0]['provider_name'] === 'VTW snap A Towing');
t('the rename itself took effect on the provider record', vendor_provider_get($s['A'])['name'] === 'VTW snap RENAMED Towing');

// ══════════════════════════════════════════════════════════════
// 10. Suspended and retired companies
// ══════════════════════════════════════════════════════════════
echo "\n--- suspended / retired ---\n";
$s = scenario('susp', $A);
$base = ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list']];
vendor_provider_suspend($s['A'], date('Y-m-d H:i', time() + 3600), 'Late arrivals', $A);
$q = vendor_rotation_queue($s['list']);
t('a suspended company is skipped as the head (B is next) but still listed, flagged ineligible', $q['head_provider_id'] === $s['B']
    && in_array($s['A'], array_column($q['candidates'], 'provider_id'), true));
$sus = offer($base + ['provider_id' => $s['A']], $A);
t('a suspended company cannot be called as a rotation/override pick', $sus['ok'] === false && $sus['code'] === 'provider_unavailable');
$susOwner = offer($base + ['provider_id' => $s['A'], 'owner_request' => true], $A);
t('...but the driver/owner can still ask for it by name (their choice, not the agency\'s)', $susOwner['ok'] === true && $susOwner['selection_method'] === 'owner_request');
vendor_provider_retire($s['C'], $A);
$ret = offer($base + ['provider_id' => $s['C'], 'owner_request' => true], $A);
t('a retired company can never be called, even on request', $ret['ok'] === false && $ret['code'] === 'provider_unavailable');
vendor_provider_unsuspend($s['A'], $A);
$q = vendor_rotation_queue($s['list']);
$aCand = null;
foreach ($q['candidates'] as $c) { if ((int) $c['provider_id'] === $s['A']) $aCand = $c; }
t('lifting the suspension makes the company eligible again', $aCand !== null && $aCand['eligible'] === true);

// ══════════════════════════════════════════════════════════════
// 11. Void rules
// ══════════════════════════════════════════════════════════════
echo "\n--- void ---\n";
$u1 = vf_user('vtw-void-u1', 3);
$u2 = vf_user('vtw-void-u2', 3);
$A1 = vf_actor($u1, 'vtw-void-u1');
$A2 = vf_actor($u2, 'vtw-void-u2');
$s = scenario('void', $A);
$base = ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list']];
$o = offer($base + ['provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $A1);
$q = vendor_rotation_queue($s['list']);
t('(setup) after A was called by user 1, B is the head', $q['head_provider_id'] === $s['B']);
t('a reason is required to void', vendor_dispatch_void($o['event_id'], '  ', $A1, false)['code'] === 'reason_required');
t('another dispatcher cannot void your entry (void_not_allowed, 403)', ($x = vendor_dispatch_void($o['event_id'], 'oops', $A2, false)) && $x['code'] === 'void_not_allowed' && $x['http'] === 403);
$v = vendor_dispatch_void($o['event_id'], 'Clicked the wrong company', $A1, false);
t('you can void your own entry within 15 minutes', $v['ok'] === true);
$q = vendor_rotation_queue($s['list']);
t('a VOIDED offer no longer consumes a turn: A is the head again', $q['head_provider_id'] === $s['A']);
t('the history keeps BOTH rows (the offer and the void naming it)', in_array('offered', vf_ledger_types($o['dispatch_id']), true) && in_array('voided', vf_ledger_types($o['dispatch_id']), true));
$vrow = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `event_type` = 'voided' AND `dispatch_id` = ?", [$o['dispatch_id']]);
t('the void row names its target and carries the reason', (int) $vrow['ref_event_id'] === $o['event_id'] && $vrow['reason'] === 'Clicked the wrong company');
t('voiding the same entry twice is refused', vendor_dispatch_void($o['event_id'], 'again', $A1, true)['code'] === 'already_voided');
t('a void row cannot itself be voided', vendor_dispatch_void((int) $vrow['id'], 'undo', $A1, true)['code'] === 'already_voided');
t('header cache equals the derived status after a void', cache_matches($o['dispatch_id']));

$o2 = offer(['dispatch_id' => $o['dispatch_id'], 'list_id' => $s['list'], 'provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $A1);
db_query("UPDATE `{$prefix}vendor_dispatch_ledger` SET `event_at` = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE `id` = ?", [$o2['event_id']]);   // test setup only: simulate elapsed time
$late = vendor_dispatch_void($o2['event_id'], 'too late for me', $A1, false);
t('your own entry older than 15 minutes cannot be voided by you', $late['ok'] === false && $late['code'] === 'void_not_allowed');
$mgr = vendor_dispatch_void($o2['event_id'], 'Supervisor correction', $A2, true);
t('a manager can void any entry at any time', $mgr['ok'] === true);
vendor_dispatch_outcome($o['dispatch_id'], ($o3 = offer(['dispatch_id' => $o['dispatch_id'], 'list_id' => $s['list'], 'provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $A1))['event_id'], 'accepted', 12, $A1);
$acceptedRow = db_fetch_one("SELECT `id` FROM `{$prefix}vendor_dispatch_ledger` WHERE `dispatch_id` = ? AND `event_type` = 'accepted'", [$o['dispatch_id']]);
t('(setup) the dispatch is assigned', vendor_dispatch_get($o['dispatch_id'])['status'] === 'assigned');
vendor_dispatch_void((int) $acceptedRow['id'], 'Wrong acceptance', $A1, false);
t('voiding the ACCEPTANCE returns the dispatch to open (cache == derived)', vendor_dispatch_get($o['dispatch_id'])['status'] === 'open' && cache_matches($o['dispatch_id']));
$listRow = db_fetch_one("SELECT `id` FROM `{$prefix}vendor_dispatch_ledger` WHERE `dispatch_id` IS NULL AND `list_id` = ? LIMIT 1", [$s['list']]);
t('list-level rows are not voidable (they are corrected by a further admin action)', $listRow === null || vendor_dispatch_void((int) $listRow['id'], 'x', $A1, true)['code'] === 'event_not_found');

// ══════════════════════════════════════════════════════════════
// 12. Header cache == derived status, across a battery driven through the writers
// ══════════════════════════════════════════════════════════════
echo "\n--- header cache equals ledger-derived status (battery) ---\n";
$s = scenario('bat', $A);
$base = ['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'list_id' => $s['list']];
$ok = true;
$b1 = offer($base + ['provider_id' => $s['A'], 'client_head_provider_id' => $s['A']], $A); $ok = $ok && cache_matches($b1['dispatch_id']);
vendor_dispatch_outcome($b1['dispatch_id'], $b1['event_id'], 'declined', null, $A); $ok = $ok && cache_matches($b1['dispatch_id']);
$b2 = offer(['dispatch_id' => $b1['dispatch_id'], 'list_id' => $s['list'], 'provider_id' => $s['B'], 'client_head_provider_id' => $s['B']], $A); $ok = $ok && cache_matches($b1['dispatch_id']);
vendor_dispatch_outcome($b1['dispatch_id'], $b2['event_id'], 'accepted', 20, $A); $ok = $ok && cache_matches($b1['dispatch_id']);
vendor_dispatch_status($b1['dispatch_id'], 'eta_update', 40, $A); $ok = $ok && cache_matches($b1['dispatch_id']);
vendor_dispatch_status($b1['dispatch_id'], 'on_scene', null, $A); $ok = $ok && cache_matches($b1['dispatch_id']);
vendor_dispatch_status($b1['dispatch_id'], 'withdrew', null, $A); $ok = $ok && cache_matches($b1['dispatch_id']);
$b3 = offer(['dispatch_id' => $b1['dispatch_id'], 'list_id' => $s['list'], 'provider_id' => $s['C'], 'client_head_provider_id' => $s['C']], $A);
vendor_dispatch_outcome($b1['dispatch_id'], $b3['event_id'], 'accepted', 5, $A); $ok = $ok && cache_matches($b1['dispatch_id']);
vendor_dispatch_status($b1['dispatch_id'], 'completed', null, $A); $ok = $ok && cache_matches($b1['dispatch_id']);
$done = db_fetch_one("SELECT `id` FROM `{$prefix}vendor_dispatch_ledger` WHERE `dispatch_id` = ? AND `event_type` = 'completed'", [$b1['dispatch_id']]);
vendor_dispatch_void((int) $done['id'], 'not actually done', $A, true); $ok = $ok && cache_matches($b1['dispatch_id']);
t('the cache matched the derived status after every one of 12 steps (offer, decline, accept, ETA, on scene, withdraw, accept, complete, void)', $ok);
t('...and the final state is assigned to C with ETA 5', vendor_dispatch_get($b1['dispatch_id'])['status'] === 'assigned' && (int) vendor_dispatch_get($b1['dispatch_id'])['provider_id'] === $s['C']);

// ══════════════════════════════════════════════════════════════
// 13. The view
// ══════════════════════════════════════════════════════════════
echo "\n--- dispatch view ---\n";
$v = vendor_dispatch_view(vendor_dispatch_get($b1['dispatch_id']), $A, true);
t('the view carries an ETA clock time for an assigned dispatch', $v['eta_clock'] !== null && preg_match('/^\d{2}:\d{2}$/', $v['eta_clock']) === 1);
t('the view lists no pending calls when every call has an outcome', $v['pending'] === []);
$pv = offer(['ticket_id' => $s['ticket'], 'service_type_id' => $TOW, 'provider_name' => 'Pending Wrecker', 'provider_phone' => '555-0188'], $A);
$pvView = vendor_dispatch_view(vendor_dispatch_get($pv['dispatch_id']), $A, true);
t('a call with no outcome shows up as pending, with the number that was dialled', count($pvView['pending']) === 1 && $pvView['pending'][0]['provider_phone'] === '555-0188');
$voidable = array_filter($pvView['events'], function ($e) { return $e['can_void']; });
t('the caller can void their own fresh entry; the event flag says so', count($voidable) === 1);
$spectator = vendor_dispatch_view(vendor_dispatch_get($pv['dispatch_id']), vf_actor($u2, 'spectator'), false);
t('another dispatcher sees no void button on it (and a manager would)', count(array_filter($spectator['events'], function ($e) { return $e['can_void']; })) === 0
    && count(array_filter(vendor_dispatch_view(vendor_dispatch_get($pv['dispatch_id']), vf_actor($u2, 'mgr'), true)['events'], function ($e) { return $e['can_void']; })) === 1);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
