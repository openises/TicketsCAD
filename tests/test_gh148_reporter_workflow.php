<?php
/**
 * GH#148 -- regression test for the ORIGINAL ASK (rjonesbsink, openises/TicketsCAD#148), through the real writers.
 *
 * "A unit on scene (disabled vehicle, traffic stop, accident) needs a towing company ... a managed rotation list ...
 *  Company defaults to whoever the rotation logic says is next among companies offering that service type, dispatcher can
 *  override ... Tow Destination only when Service Type = Tow ... a combo field: pick a Facility or type a free-text address ...
 *  Law enforcement also needs this independently of a disabled-vehicle assist -- e.g. an impound tow after a traffic stop."
 *
 * The scenario, step by step, against three incident types (a disabled-vehicle assist, a traffic stop, and a standalone
 * incident of the reporter's own TOW type): Tow from the rotation suggests company A; A declines; B accepts with an ETA; the
 * NEXT tow dispatch starts at C (not A, not B); once everyone has had a turn the rotation wraps to A; a lockout uses its OWN list
 * and is not disturbed; the destination exists only for a Tow, and a free-text destination with no registered facility never
 * blocks; every step is on the incident log; and with the feature off none of it is reachable.
 *
 * @requires-db
 * Usage: php tests/test_gh148_reporter_workflow.php
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
    echo "SKIP: the vendor tables are not installed\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$BASE = ['vendor_dispatch_enabled' => '1', 'vendor_rotation_mode' => 'round_robin', 'vendor_advance_rule' => 'any_offer',
         'vendor_allow_override' => '1', 'vendor_override_requires_reason' => '1', 'phone_click_to_call' => 'off'];
vf_set_settings($BASE);
$TOW = vf_service_type_id('tow');
$LOCK = vf_service_type_id('lockout');

echo "=== GH#148 -- the reporter's workflow ===\n\n";

// ── the agency's setup: one tow rotation (A, B, C) and a SEPARATE lockout list (L1, L2) ──
$typeDisabled = vf_incident_type('GH148 Disabled Vehicle', 'LAW');
$typeStop     = vf_incident_type('GH148 Traffic Stop', 'LAW');
$typeTow      = vf_incident_type('GH148 TOW', 'LAW');           // the reporter's own single TOW incident type
$tAssist = vf_ticket(null, false, '1200 County Rd 5', $typeDisabled);
$tStop   = vf_ticket(null, false, 'Hwy 61 at Mile 12', $typeStop);
$tImpound = vf_ticket(null, false, '5 Garage Lane', $typeTow);

$towList = vf_list('GH148 County tow rotation', 'tow');
$pA = vf_provider('Anderson Towing', ['phone' => '555-0401', 'contact_name' => 'Pat', 'hours_note' => '24h'], $A);
$pB = vf_provider('Bergstrom Wrecker', ['phone' => '555-0402'], $A);
$pC = vf_provider('Carlson Recovery', ['phone' => '555-0403'], $A);
vf_members($towList, [$pA, $pB, $pC], $A);
$lockList = vf_list('GH148 Lockout list', 'lockout');
$pL1 = vf_provider('Lakeside Locksmith', ['phone' => '555-0411'], $A);
$pL2 = vf_provider('Metro Lock and Key', ['phone' => '555-0412'], $A);
vf_members($lockList, [$pL1, $pL2], $A);
vendor_list_set_default($towList, true, $A);

$lockHeadBefore = vendor_rotation_queue($lockList)['head_provider_id'];

// ── 1. the disabled-vehicle assist ──
echo "--- a disabled-vehicle assist: A declines, B accepts ---\n";
$q = vendor_rotation_queue($towList);
t('Tow from the rotation SUGGESTS company A (next up)', $q['head_provider_id'] === $pA && $q['candidates'][0]['name'] === 'Anderson Towing');

$o1 = vendor_dispatch_offer(['ticket_id' => $tAssist, 'service_type_id' => $TOW, 'list_id' => $towList, 'provider_id' => $pA, 'client_head_provider_id' => $pA,
    'vehicle_desc' => '2014 Silver Honda Civic', 'plate' => 'ABC123', 'plate_state' => 'MN', 'tow_reason' => 'Disabled vehicle',
    'dest_text' => 'Anderson yard, 400 Industrial Dr'], $A);
t('the dispatcher calls A (a rotation offer)', $o1['ok'] === true && $o1['selection_method'] === 'rotation');
$did1 = $o1['dispatch_id'];
t('the dispatch has its own reference on the incident (T1)', $o1['dispatch']['ordinal'] === '1' || (int) $o1['dispatch']['ordinal'] === 1);

$qd = vendor_rotation_queue($towList, $did1);
t('while A is being called the screen already shows B as next for THIS dispatch', $qd['head_provider_id'] === $pB);
$dec = vendor_dispatch_outcome($did1, $o1['event_id'], 'declined', null, $A);
t('A declines (recorded)', $dec['ok'] === true && $dec['dispatch']['status'] === 'open');
$o2 = vendor_dispatch_offer(['dispatch_id' => $did1, 'list_id' => $towList, 'provider_id' => $pB, 'client_head_provider_id' => $pB], $A);
t('the dispatcher calls B, the new next-up, on the same dispatch', $o2['ok'] === true && $o2['selection_method'] === 'rotation');
$acc = vendor_dispatch_outcome($did1, $o2['event_id'], 'accepted', 20, $A);
t('B accepts with an ETA of 20 minutes: the dispatch is assigned to B', $acc['ok'] === true && $acc['dispatch']['status'] === 'assigned'
    && $acc['dispatch']['provider_name'] === 'Bergstrom Wrecker' && (int) $acc['dispatch']['eta_minutes'] === 20);

// ── 2. the next tow dispatch ──
echo "\n--- the next tow starts at C, not A or B ---\n";
$q = vendor_rotation_queue($towList);
t('the NEXT tow dispatch (any incident) starts at C: A and B have both had a turn', $q['head_provider_id'] === $pC);

// ── 3. a traffic stop (a different incident type) ──
echo "\n--- a traffic stop: the same flow on another incident type ---\n";
$o3 = vendor_dispatch_offer(['ticket_id' => $tStop, 'service_type_id' => $TOW, 'list_id' => $towList, 'provider_id' => $pC, 'client_head_provider_id' => $pC,
    'tow_reason' => 'Impound after traffic stop', 'vehicle_desc' => 'Black Ford F-150', 'plate' => 'IMP0UND', 'dest_text' => 'County impound lot'], $A);
t('an impound tow on a traffic stop works exactly the same: C is called', $o3['ok'] === true && $o3['selection_method'] === 'rotation');
t('...with its own T1 on THAT incident (ordinals count per incident)', (int) $o3['dispatch']['ordinal'] === 1);
vendor_dispatch_outcome($o3['dispatch_id'], $o3['event_id'], 'accepted', 35, $A);
vendor_dispatch_status($o3['dispatch_id'], 'on_scene', null, $A);
vendor_dispatch_status($o3['dispatch_id'], 'completed', null, $A);
t('the stop\'s tow runs to completion', vendor_dispatch_get($o3['dispatch_id'])['status'] === 'completed');

// ── 4. a standalone incident of the reporter's own TOW type: the rotation wraps ──
echo "\n--- a standalone TOW incident: the rotation has wrapped to A ---\n";
$q = vendor_rotation_queue($towList);
t('everyone has had a turn, so the rotation WRAPS and A is next again', $q['head_provider_id'] === $pA);
$o4 = vendor_dispatch_offer(['ticket_id' => $tImpound, 'service_type_id' => $TOW, 'list_id' => $towList, 'provider_id' => $pA, 'client_head_provider_id' => $pA,
    'tow_reason' => 'Impound, no prior call', 'dest_text' => 'City garage, 12 Main St'], $A);
t('an impound tow with NO prior disabled-vehicle call attaches to a standalone incident of the TOW type', $o4['ok'] === true && $o4['selection_method'] === 'rotation');

// ── 5. a lockout uses its own list and is not disturbed ──
echo "\n--- a lockout is independent of the tow rotation ---\n";
t('the lockout rotation was never disturbed by any of the tow calls', vendor_rotation_queue($lockList)['head_provider_id'] === $lockHeadBefore && $lockHeadBefore === $pL1);
$towHeadBeforeLockout = vendor_rotation_queue($towList)['head_provider_id'];
$lo = vendor_dispatch_offer(['ticket_id' => $tAssist, 'service_type_id' => $LOCK, 'list_id' => $lockList, 'provider_id' => $pL1, 'client_head_provider_id' => $pL1,
    'vehicle_desc' => 'Locked keys in a white van', 'dest_text' => 'should be ignored'], $A);
t('a lockout is called from ITS list (the locksmith, not a tow company)', $lo['ok'] === true && $lo['dispatch']['provider_id'] === null);
$lockRow = vendor_dispatch_get($lo['dispatch_id']);
t('...it is T2 on the disabled-vehicle incident (a tow was T1)', (int) $lockRow['ordinal'] === 2 && $lockRow['service_label'] === 'Lockout');
t('...and a lockout has NO destination, even though one was typed', $lockRow['dest_text'] === null && $lockRow['dest_facility_id'] === null);
t('the tow rotation was not touched by the lockout call, and the lockout list moved on to the second locksmith',
    vendor_rotation_queue($towList)['head_provider_id'] === $towHeadBeforeLockout && vendor_rotation_queue($lockList)['head_provider_id'] === $pL2);

// ── 6. destination: only for a Tow; free text never blocks ──
echo "\n--- destination ---\n";
$tow1 = vendor_dispatch_get($did1);
t('a Tow keeps a FREE-TEXT destination with no registered facility', $tow1['dest_text'] === 'Anderson yard, 400 Industrial Dr' && $tow1['dest_facility_id'] === null);
$fac = vf_facility('GH148 Impound Lot');
$up = vendor_dispatch_update_details($did1, ['service_type_id' => $TOW, 'dest_facility_id' => $fac], $A);
t('...and can instead name a registered facility (impound lot / repair shop)', $up['ok'] === true && (int) vendor_dispatch_get($did1)['dest_facility_id'] === $fac);

// ── 7. the incident log ──
echo "\n--- the incident log shows each step ---\n";
$notes = implode("\n", array_column(db_fetch_all("SELECT `description` FROM `{$prefix}action` WHERE `ticket_id` = ? ORDER BY `id`", [$tAssist]), 'description'));
$ref1 = incnum_display($tAssist) . '-T1';
t('log: called Anderson (next up)', strpos($notes, "Tow ref $ref1: called Anderson Towing (next up)") !== false);
t('log: Anderson declined', strpos($notes, "Tow ref $ref1: Anderson Towing declined") !== false);
t('log: called Bergstrom (next up)', strpos($notes, "Tow ref $ref1: called Bergstrom Wrecker (next up)") !== false);
t('log: Bergstrom accepted, ETA 20 min', strpos($notes, "Tow ref $ref1: Bergstrom Wrecker accepted, ETA 20 min") !== false);
t('log: the lockout is recorded under its own reference', strpos($notes, 'Lockout ref ' . incnum_display($tAssist) . '-T2: called Lakeside Locksmith (next up)') !== false);
$stopNotes = implode("\n", array_column(db_fetch_all("SELECT `description` FROM `{$prefix}action` WHERE `ticket_id` = ? ORDER BY `id`", [$tStop]), 'description'));
t('log (traffic stop): called, accepted, on scene, completed', strpos($stopNotes, 'called Carlson Recovery (next up)') !== false
    && strpos($stopNotes, 'accepted, ETA 35 min') !== false && strpos($stopNotes, 'on scene') !== false && strpos($stopNotes, 'completed') !== false);

// ── 8. an override with a reason does not cost the company its place ──
echo "\n--- an override keeps the order intact ---\n";
$tOv = vf_ticket();
$headNow = vendor_rotation_queue($towList)['head_provider_id'];
$other = ($headNow === $pB) ? $pC : $pB;
$ov = vendor_dispatch_offer(['ticket_id' => $tOv, 'service_type_id' => $TOW, 'list_id' => $towList, 'provider_id' => $other, 'client_head_provider_id' => $headNow,
    'reason' => 'Closest truck'], $A);
t('a dispatcher picking a different company with a reason is an override', $ov['ok'] === true && $ov['selection_method'] === 'override');
t('the company that was skipped is STILL next (the override used nobody\'s turn)', vendor_rotation_queue($towList)['head_provider_id'] === $headNow);

// ── 9. feature off: none of it is reachable ──
echo "\n--- with the feature off, none of it is reachable ---\n";
vf_set_settings(array_merge($BASE, ['vendor_dispatch_enabled' => '0']));
$probe = function (string $method, array $payload) use ($admin) {
    $argv = [PHP_BINARY ?: 'php', __DIR__ . '/_vendor_endpoint_probe.php', 'api/vendor-dispatch.php', $method, (string) $admin, '0', 'good',
             $method === 'GET' ? http_build_query($payload) : json_encode($payload)];
    $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $http = 0; if (preg_match('/\n__HTTP__(\d+)\s*$/', $out, $m)) { $http = (int) $m[1]; $out = substr($out, 0, -strlen($m[0])); }
    return [json_decode(trim($out), true), $http];
};
[$j, $h] = $probe('GET', ['action' => 'config', 'ticket_id' => $tAssist]);
t('config answers enabled:false', $h === 200 && $j === ['enabled' => false]);
[$j, $h] = $probe('POST', ['action' => 'offer', 'ticket_id' => $tAssist, 'service_type_id' => $TOW, 'provider_name' => 'X', 'provider_phone' => '555-0000']);
t('an offer is refused outright', $h === 409 && ($j['code'] ?? '') === 'vendor_disabled');
vf_set_settings($BASE);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
