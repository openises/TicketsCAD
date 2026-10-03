<?php
/**
 * GH#148 (Phase 155) -- the ADMIN writers (inc/vendor-admin-write.php): companies, rotation lists, members, service types,
 * settings, the overview and the history. Real writers against a real database; org scope exercised with real org-scoped
 * users (the session decides what a caller may see and change).
 *
 * The fairness rules live in the writers, not the UI, so they are tested here: a newcomer goes to the BACK of a list
 * that has history, there is no way to jump a company to the front (only "move to end", with a reason), the order of a
 * round-robin list with history is not hand-reorderable, and a company or list in the history can only be retired.
 *
 * @requires-db
 * Usage: php tests/test_vendor_admin_writers.php
 */
require_once __DIR__ . '/_vendor_fixtures.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

$prefix = vf_prefix();
$admin = test_admin_user_id();
$A = vf_actor($admin, 'vf-admin');
register_shutdown_function('vf_cleanup');

if (!vendor_schema_ready()) {
    echo "SKIP: the vendor tables are not installed\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}
vf_set_settings(['vendor_dispatch_enabled' => '1', 'vendor_rotation_mode' => 'round_robin', 'vendor_advance_rule' => 'any_offer',
                 'vendor_allow_override' => '1', 'vendor_override_requires_reason' => '1']);
$TOW = vf_service_type_id('tow');
$LOCK = vf_service_type_id('lockout');

function names(array $queue): array { return array_column($queue['candidates'], 'name'); }

echo "=== GH#148 -- admin writers ===\n\n";

$orgA = vf_org('VTA Agency A');
$orgB = vf_org('VTA Agency B');
$mgrA = vf_user('vta-mgr-a', 2, $orgA);
$mgrB = vf_user('vta-mgr-b', 2, $orgB);
$AA = vf_actor($mgrA, 'vta-mgr-a', $orgA);
$AB = vf_actor($mgrB, 'vta-mgr-b', $orgB);

// ══════════════════════════════════════════════════════════════
// Providers: validation, creation, scope
// ══════════════════════════════════════════════════════════════
echo "--- companies ---\n";
vf_session_as($admin, $orgA, 'vf-admin');
t('a company needs a name', vendor_provider_save(['phone' => '555-0100'], $A)['code'] === 'validation');
t('a company needs a valid phone', vendor_provider_save(['name' => 'X', 'phone' => 'call me'], $A)['code'] === 'validation');
t('an alternate phone, if given, must be valid', vendor_provider_save(['name' => 'X', 'phone' => '555-0100', 'phone_alt' => 'n/a'], $A)['code'] === 'validation');
t('a yard facility that does not exist is refused', vendor_provider_save(['name' => 'X', 'phone' => '555-0100', 'yard_facility_id' => 999999993], $A)['code'] === 'validation');
$fac = vf_facility('VTA Yard Facility');
$r = vendor_provider_save(['name' => 'VTA Alpha Towing', 'phone' => '(555) 010-0001', 'contact_name' => 'Pat', 'service_area' => 'North county', 'hours_note' => '24h',
                           'notes' => 'Heavy wrecker available', 'yard_facility_id' => $fac], $A);
$pAlpha = vf_track('providers', (int) ($r['provider']['id'] ?? 0));
t('a company is created with its fields and yard', $r['ok'] === true && $r['provider']['name'] === 'VTA Alpha Towing' && (int) $r['provider']['yard_facility_id'] === $fac);
t('a company created with no org chosen belongs to the caller\'s active organization', (int) $r['provider']['org_id'] === $orgA);

$r2 = vendor_provider_save(['name' => 'VTA Everyone Towing', 'phone' => '555-0002', 'org_id' => 'all'], $A);
$pEveryone = vf_track('providers', (int) ($r2['provider']['id'] ?? 0));
t('a Super Admin may create an "all agencies" company (org_id NULL)', $r2['ok'] === true && $r2['provider']['org_id'] === null);

vf_session_as($mgrA, $orgA, 'vta-mgr-a');
t('an org-scoped manager cannot create an "all agencies" company', vendor_provider_save(['name' => 'Nope', 'phone' => '555-0003', 'org_id' => 'all'], $AA)['code'] === 'forbidden_scope');
t('an org-scoped manager cannot create a company for ANOTHER agency', vendor_provider_save(['name' => 'Nope', 'phone' => '555-0003', 'org_id' => $orgB], $AA)['code'] === 'forbidden_scope');
$mine = vendor_provider_save(['name' => 'VTA Mine Towing', 'phone' => '555-0004'], $AA);
$pMine = vf_track('providers', (int) ($mine['provider']['id'] ?? 0));
t('an org-scoped manager creates in their own agency by default', $mine['ok'] === true && (int) $mine['provider']['org_id'] === $orgA);
$up = vendor_provider_save(['id' => $pMine, 'name' => 'VTA Mine Towing 2', 'phone' => '555-0004', 'org_id' => $orgB], $AA);
t('the organization is fixed at creation (a later org_id is ignored)', $up['ok'] === true && (int) $up['provider']['org_id'] === $orgA);
t('an "all agencies" company is visible but only a Super Admin can change it', vendor_provider_save(['id' => $pEveryone, 'name' => 'Hacked', 'phone' => '555-0002'], $AA)['code'] === 'forbidden_scope'
    && vendor_provider_get($pEveryone)['name'] === 'VTA Everyone Towing');
vf_session_as($mgrB, $orgB, 'vta-mgr-b');
t('another agency\'s manager cannot even SEE this company (404)', vendor_provider_save(['id' => $pMine, 'name' => 'Hijack', 'phone' => '555-0004'], $AB)['http'] === 404
    && vendor_provider_suspend($pMine, date('Y-m-d H:i', time() + 3600), 'x', $AB)['http'] === 404
    && vendor_provider_retire($pMine, $AB)['http'] === 404 && vendor_provider_delete($pMine, $AB)['http'] === 404);
vf_session_as($admin, $orgA, 'vf-admin');

// suspension
echo "\n--- suspension ---\n";
t('suspending needs a future date', vendor_provider_suspend($pAlpha, date('Y-m-d H:i', time() - 3600), 'x', $A)['code'] === 'validation');
t('suspending needs a reason', vendor_provider_suspend($pAlpha, date('Y-m-d H:i', time() + 3600), '', $A)['code'] === 'reason_required');
t('a suspension cannot run longer than a year', vendor_provider_suspend($pAlpha, date('Y-m-d H:i', time() + 500 * 86400), 'x', $A)['code'] === 'validation');
$sus = vendor_provider_suspend($pAlpha, date('Y-m-d H:i', time() + 7200), 'Late on three calls', $A);
t('a company can be suspended until a time, with a reason', $sus['ok'] === true && $sus['provider']['suspend_reason'] === 'Late on three calls' && strtotime($sus['provider']['suspended_until']) > time());
t('lifting a suspension clears the time and the reason', ($u = vendor_provider_unsuspend($pAlpha, $A)) && $u['ok'] === true && $u['provider']['suspended_until'] === null && $u['provider']['suspend_reason'] === null);
$ret = vendor_provider_retire($pAlpha, $A);
t('retiring marks the company inactive', $ret['ok'] === true && (int) $ret['provider']['is_active'] === 0);
$react = vendor_provider_save(['id' => $pAlpha, 'name' => 'VTA Alpha Towing', 'phone' => '555-0001', 'is_active' => 1], $A);
t('reactivating through a save makes it active again', $react['ok'] === true && (int) $react['provider']['is_active'] === 1);

// ══════════════════════════════════════════════════════════════
// Service types
// ══════════════════════════════════════════════════════════════
echo "\n--- service types ---\n";
$bad = vendor_service_type_save(['label' => ''], $A);
t('a service type needs a label', $bad['code'] === 'validation');
$bad = vendor_service_type_save(['label' => 'Heavy tow', 'code' => 'Heavy Tow!'], $A);
t('a code may only use lowercase letters, digits and underscores', $bad['code'] === 'validation');
$st = vendor_service_type_save(['label' => 'Heavy Tow', 'needs_destination' => 1], $A);
$stId = vf_track('types', (int) ($st['service_type']['id'] ?? 0));
t('a code is derived from the label when none is given', $st['ok'] === true && $st['service_type']['code'] === 'heavy_tow' && (int) $st['service_type']['needs_destination'] === 1);
t('a duplicate code is refused (409)', vendor_service_type_save(['label' => 'Heavy Tow again', 'code' => 'heavy_tow'], $A)['code'] === 'duplicate');
$st2 = vendor_service_type_save(['id' => $stId, 'label' => 'Heavy Duty Tow', 'code' => 'changed_code', 'needs_destination' => 0, 'is_active' => 1, 'sort_order' => 55], $A);
t('the code is fixed at creation; the label and flags change', $st2['ok'] === true && $st2['service_type']['code'] === 'heavy_tow' && $st2['service_type']['label'] === 'Heavy Duty Tow'
    && (int) $st2['service_type']['needs_destination'] === 0 && (int) $st2['service_type']['sort_order'] === 55);
[$dialogTypes] = vendor_dialog_lists();
t('an active type is offered in the dispatch dialog', in_array($stId, array_column($dialogTypes, 'id'), true));
vendor_service_type_save(['id' => $stId, 'label' => 'Heavy Duty Tow', 'is_active' => 0], $A);
[$dialogTypes] = vendor_dialog_lists();
t('a deactivated type disappears from the dialog', !in_array($stId, array_column($dialogTypes, 'id'), true));
$tk = vf_ticket();
t('...and cannot start a new dispatch', vendor_dispatch_create($tk, ['service_type_id' => $stId], $A)['code'] === 'validation');
t('there is no way to delete a service type (retire it): no such writer exists', !function_exists('vendor_service_type_delete'));

// ══════════════════════════════════════════════════════════════
// Lists and defaults
// ══════════════════════════════════════════════════════════════
echo "\n--- rotation lists ---\n";
t('a list needs a name', vendor_list_save(['service_type_id' => $TOW], $A)['code'] === 'validation');
t('a list needs a real service type', vendor_list_save(['name' => 'X', 'service_type_id' => 999999], $A)['code'] === 'validation');
t('an unknown mode is refused', vendor_list_save(['name' => 'X', 'service_type_id' => $TOW, 'mode' => 'chaos'], $A)['code'] === 'validation');
$l1 = vf_list('VTA tow list 1', 'tow', ['org_id' => $orgA], $A);
$l2 = vf_list('VTA tow list 2', 'tow', ['org_id' => $orgA], $A);
$lL = vf_list('VTA lock list', 'lockout', ['org_id' => $orgA], $A);
t('a list is created (not the default until someone says so)', (int) vendor_list_get($l1)['is_default'] === 0 && (int) vendor_list_get($l1)['org_id'] === $orgA);
vendor_list_set_default($l1, true, $A);
vendor_list_set_default($l2, true, $A);
t('"at most one default per (org, service type)": making list 2 the default clears list 1', (int) vendor_list_get($l1)['is_default'] === 0 && (int) vendor_list_get($l2)['is_default'] === 1);
vendor_list_set_default($lL, true, $A);
t('...and a default for a DIFFERENT service type is independent', (int) vendor_list_get($lL)['is_default'] === 1 && (int) vendor_list_get($l2)['is_default'] === 1);
$n1 = vf_list('VTA all-agencies list 1', 'tow', ['org_id' => 'all'], $A);
$n2 = vf_list('VTA all-agencies list 2', 'tow', ['org_id' => 'all'], $A);
vendor_list_set_default($n1, true, $A);
vendor_list_set_default($n2, true, $A);
t('the one-default rule also holds for org_id NULL lists (a NULL-safe comparison, not a unique key)', (int) vendor_list_get($n1)['is_default'] === 0 && (int) vendor_list_get($n2)['is_default'] === 1);
t('the all-agencies default did NOT disturb org A\'s default for the same service', (int) vendor_list_get($l2)['is_default'] === 1);
vendor_list_retire($l1, $A);
t('a retired list cannot be made the default', vendor_list_set_default($l1, true, $A)['code'] === 'list_inactive');
$upd = vendor_list_save(['id' => $l2, 'name' => 'VTA tow list 2 renamed', 'service_type_id' => $TOW, 'mode' => 'strict_order', 'org_id' => $orgB], $A);
t('a list is edited; its organization is fixed', $upd['ok'] === true && $upd['list']['mode'] === 'strict_order' && (int) $upd['list']['org_id'] === $orgA);
vendor_list_save(['id' => $l2, 'name' => 'VTA tow list 2', 'service_type_id' => $TOW, 'mode' => ''], $A);
t('an empty mode means "use the install-wide setting"', vendor_list_get($l2)['mode'] === null);
vf_session_as($mgrB, $orgB, 'vta-mgr-b');
t('another agency cannot edit, retire, default or delete this list (404)', vendor_list_save(['id' => $l2, 'name' => 'x', 'service_type_id' => $TOW], $AB)['http'] === 404
    && vendor_list_retire($l2, $AB)['http'] === 404 && vendor_list_set_default($l2, true, $AB)['http'] === 404 && vendor_list_delete($l2, $AB)['http'] === 404);
vf_session_as($mgrA, $orgA, 'vta-mgr-a');
t('an org-scoped manager cannot change an "all agencies" list', vendor_list_retire($n1, $AA)['code'] === 'forbidden_scope');
vf_session_as($admin, $orgA, 'vf-admin');

// ══════════════════════════════════════════════════════════════
// Members
// ══════════════════════════════════════════════════════════════
echo "\n--- members: add, order, the back of the line ---\n";
$mA = vf_provider('VTA M1', ['phone' => '555-0501', 'org_id' => $orgA], $A);
$mB = vf_provider('VTA M2', ['phone' => '555-0502', 'org_id' => $orgA], $A);
$mC = vf_provider('VTA M3', ['phone' => '555-0503', 'org_id' => $orgA], $A);
$mD = vf_provider('VTA M4', ['phone' => '555-0504', 'org_id' => $orgA], $A);
$mOther = vf_provider('VTA OtherOrg', ['phone' => '555-0505', 'org_id' => $orgB], $A);
$mNull = vf_provider('VTA NullOrg', ['phone' => '555-0506', 'org_id' => 'all'], $A);
$ML = vf_list('VTA members list', 'tow', ['org_id' => $orgA], $A);
t('a company of another organization cannot join this list', vendor_member_add($ML, $mOther, $A)['code'] === 'validation');
t('an "all agencies" company cannot join a single-agency list either? it is not org-NULL-vs-list-org compatible only when ORGS differ: allowed (null org)', vendor_member_add($ML, $mNull, $A)['ok'] === true);
vendor_member_remove($ML, $mNull, $A);
vf_members($ML, [$mA, $mB, $mC], $A);
t('adding the same company twice is refused', vendor_member_add($ML, $mA, $A)['code'] === 'already_member');
t('members are ordered by position as added', names(vendor_rotation_queue($ML)) === ['VTA M1', 'VTA M2', 'VTA M3']);
t('a list with NO history writes no ledger row when a company joins',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ?", [$ML]) === 0);

echo "\n  - the order of a round-robin list with NO history can be arranged by hand\n";
$mv = vendor_member_move($ML, $mC, 'up', $A);
t('move up swaps with the neighbour while there is no history', $mv['ok'] === true && names(vendor_rotation_queue($ML)) === ['VTA M1', 'VTA M3', 'VTA M2']);
vendor_member_move($ML, $mC, 'up', $A);
vendor_member_move($ML, $mC, 'up', $A);
t('moving the first company up is a harmless no-op', names(vendor_rotation_queue($ML))[0] === 'VTA M3');
vendor_member_move($ML, $mC, 'down', $A); vendor_member_move($ML, $mC, 'down', $A);
t('move down puts it back', names(vendor_rotation_queue($ML)) === ['VTA M1', 'VTA M2', 'VTA M3']);
t('a bad direction is refused', vendor_member_move($ML, $mC, 'sideways', $A)['code'] === 'validation');

// give the list history
$tk = vf_ticket();
foreach ([$mA, $mB, $mC] as $pid) {
    $o = vendor_dispatch_offer(['ticket_id' => $tk, 'service_type_id' => $TOW, 'list_id' => $ML, 'provider_id' => $pid, 'client_head_provider_id' => $pid], $A);
    if (!$o['ok']) { echo "    setup offer failed: " . json_encode($o) . "\n"; }
}
t('(setup) each of the three companies has had a turn; M1 is the least recently used', vendor_rotation_queue($ML)['head_provider_id'] === $mA);

echo "\n  - a newcomer to a list WITH history goes to the BACK\n";
vf_members($ML, [$mD], $A);
$q = vendor_rotation_queue($ML);
t('the newcomer is last in the rotation and is NOT the head', names($q) === ['VTA M1', 'VTA M2', 'VTA M3', 'VTA M4'] && $q['head_provider_id'] === $mA);
$jl = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ? AND `event_type` = 'joined_list' AND `provider_id` = ?", [$ML, $mD]);
t('...because a joined_list row consumed a turn for it', $jl && (int) $jl['consumed_turn'] === 1 && (int) $jl['provider_id'] === $mD);

echo "\n  - round robin with history: the order follows the ledger, so it cannot be reordered by hand\n";
$locked = vendor_member_move($ML, $mD, 'up', $A);
t('move up is REFUSED (409 order_follows_ledger)', $locked['ok'] === false && $locked['code'] === 'order_follows_ledger' && $locked['http'] === 409);
t('there is no function that jumps a company to the front', !function_exists('vendor_member_move_to_front') && !function_exists('vendor_member_move_to_top') && !function_exists('vendor_member_set_position'));

echo "\n  - the one reordering that IS allowed: move to the END, with a reason\n";
t('move to end needs a reason', vendor_member_move_to_end($ML, $mA, '', $A)['code'] === 'reason_required');
$me = vendor_member_move_to_end($ML, $mA, 'Missed two callouts', $A);
$q = vendor_rotation_queue($ML);
t('moving the head to the end puts it last in a round robin', $me['ok'] === true && names($q) === ['VTA M2', 'VTA M3', 'VTA M4', 'VTA M1'] && $q['head_provider_id'] === $mB);
$mrow = db_fetch_one("SELECT * FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ? AND `event_type` = 'moved_to_end'", [$ML]);
t('...recorded in the ledger with its reason and consuming a turn', $mrow && $mrow['reason'] === 'Missed two callouts' && (int) $mrow['consumed_turn'] === 1 && (int) $mrow['provider_id'] === $mA);
t('moving a company that is not on the list is a 404', vendor_member_move_to_end($ML, $mOther, 'x', $A)['http'] === 404);

echo "\n  - removal keeps the history; re-adding puts the company at the end\n";
$rm = vendor_member_remove($ML, $mB, $A);
t('a company is removed (soft: removed_at is set)', $rm['ok'] === true && db_fetch_value("SELECT `removed_at` FROM `{$prefix}vendor_rotation_members` WHERE `list_id` = ? AND `provider_id` = ?", [$ML, $mB]) !== null);
t('...it leaves the queue and a removed_from_list row records it (the list has history)', !in_array('VTA M2', names(vendor_rotation_queue($ML)), true)
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ? AND `event_type` = 'removed_from_list'", [$ML]) === 1);
t('removing it again is a 404', vendor_member_remove($ML, $mB, $A)['http'] === 404);
vendor_member_add($ML, $mB, $A);
$q = vendor_rotation_queue($ML);
t('re-adding a removed company revives the SAME membership row and sends it to the back', end($q['candidates'])['name'] === 'VTA M2'
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_rotation_members` WHERE `list_id` = ? AND `provider_id` = ?", [$ML, $mB]) === 1);

echo "\n  - strict order: position IS the order, so up/down is allowed and audited\n";
$SL = vf_list('VTA strict list', 'tow', ['org_id' => $orgA, 'mode' => 'strict_order'], $A);
vf_members($SL, [$mA, $mB, $mC], $A);
$tk2 = vf_ticket();
vendor_dispatch_offer(['ticket_id' => $tk2, 'service_type_id' => $TOW, 'list_id' => $SL, 'provider_id' => $mA, 'client_head_provider_id' => $mA], $A);
$mvNoReason = vendor_member_move($SL, $mC, 'up', $A);
t('on a strict-order list WITH history, a move without a reason is refused (it needs one, and leaves a ledger row)', ($mvNoReason['ok'] ?? true) === false && $mvNoReason['code'] === 'reason_required'
    && names(vendor_rotation_queue($SL)) === ['VTA M1', 'VTA M2', 'VTA M3']);
$mvs = vendor_member_move($SL, $mC, 'up', $A, 'Contract amendment');
t('on a strict-order list, even WITH history, up/down reorders (given a reason)', $mvs['ok'] === true && names(vendor_rotation_queue($SL)) === ['VTA M1', 'VTA M3', 'VTA M2']);
t('...and the move is in the LEDGER, not only the purgeable audit log', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_dispatch_ledger` WHERE `list_id` = ? AND `event_type` = 'order_changed' AND `reason` = 'Contract amendment'", [$SL]) === 1);
t('...and strict order ignores the history entirely: M1 is still the head after being called', vendor_rotation_queue($SL)['head_provider_id'] === $mA);
$aud = db_fetch_one("SELECT * FROM `{$prefix}newui_audit_log` WHERE `category` = 'vendor' AND `activity` = 'member.move' ORDER BY `id` DESC LIMIT 1");
t('the reorder is in the audit trail', $aud !== null);

// ══════════════════════════════════════════════════════════════
// Settings
// ══════════════════════════════════════════════════════════════
echo "\n--- settings ---\n";
t('an out-of-enum value is refused and nothing is written', vendor_settings_save(['vendor_advance_rule' => 'whenever'], $A)['code'] === 'validation'
    && vendor_settings_current()['vendor_advance_rule'] === 'any_offer');
$sv = vendor_settings_save(['vendor_allow_override' => false, 'vendor_override_requires_reason' => '0', 'phone_click_to_call' => 'tel_link'], $A);
$cur = vendor_settings_current();
t('booleans and enums are saved and read back fresh (not through the stale per-process cache)', $sv['ok'] === true
    && $cur['vendor_allow_override'] === '0' && $cur['vendor_override_requires_reason'] === '0' && $cur['phone_click_to_call'] === 'tel_link');
t('only the keys that changed are reported', $sv['changed'] === ['vendor_allow_override', 'vendor_override_requires_reason', 'phone_click_to_call']);
$before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE `category` = 'vendor' AND `activity` = 'settings.update'");
$again = vendor_settings_save(['vendor_allow_override' => '0'], $A);
t('saving an unchanged value is not an audited change', $again['ok'] === true && $again['changed'] === []
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE `category` = 'vendor' AND `activity` = 'settings.update'") === $before);
$au = db_fetch_one("SELECT * FROM `{$prefix}newui_audit_log` WHERE `category` = 'vendor' AND `activity` = 'settings.update' ORDER BY `id` DESC LIMIT 1");
t('a change is audited with before AND after', $au && strpos((string) $au['details'], '"before":"1"') !== false && strpos((string) $au['details'], '"after":"0"') !== false);
t('unknown setting names are ignored (only the six owned keys are writable here)', vendor_settings_save(['not_a_vendor_setting' => 'x', 'org_strict_isolation' => '1'], $A)['changed'] === []
    && db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'org_strict_isolation'") !== '1');
vf_set_settings(['vendor_allow_override' => '1', 'vendor_override_requires_reason' => '1', 'phone_click_to_call' => 'off']);

// ══════════════════════════════════════════════════════════════
// Overview and history
// ══════════════════════════════════════════════════════════════
echo "\n--- overview ---\n";
$ov = vendor_admin_overview();
$lrow = null; foreach ($ov['lists'] as $l) { if ($l['id'] === $ML) $lrow = $l; }
t('the list row shows the computed next-up and the member count', $lrow !== null && $lrow['member_count'] === 4 && $lrow['next_up_name'] === 'VTA M3' && $lrow['effective_mode'] === 'round_robin');
$prow = null; foreach ($ov['providers'] as $p) { if ($p['id'] === $mC) $prow = $p; }
t('a company\'s services are DERIVED from the lists it sits on (no second place to keep in sync)', $prow !== null && $prow['services'] === ['Tow'] && in_array('VTA members list', $prow['lists'], true));
t('a company that was called cannot be deleted; the overview says so', $prow !== null && $prow['can_delete'] === false);
$prowD = null; foreach ($ov['providers'] as $p) { if ($p['id'] === $mNull) $prowD = $p; }
t('a company nothing refers to can be deleted; "all agencies" is labelled', $prowD !== null && $prowD['can_delete'] === true && $prowD['org_name'] === 'All agencies');
t('the overview carries the organization choices and the all-agencies right', is_array($ov['orgs']) && $ov['can_all_agencies'] === true && in_array($orgA, array_column($ov['orgs'], 'id'), true));
t('the overview counts', $ov['counts']['providers'] >= 6 && $ov['counts']['lists'] >= 4);
vf_session_as($mgrB, $orgB, 'vta-mgr-b');
$ovB = vendor_admin_overview();
t('an org B manager\'s overview hides org A\'s lists and companies and the all-agencies right', !in_array($ML, array_column($ovB['lists'], 'id'), true)
    && !in_array($mC, array_column($ovB['providers'], 'id'), true) && $ovB['can_all_agencies'] === false && array_column($ovB['orgs'], 'id') === [$orgB]);
t('...and org B cannot open org A\'s list detail', vendor_admin_list_detail($ML) === null);
vf_session_as($admin, $orgA, 'vf-admin');
$det = vendor_admin_list_detail($ML);
t('the list detail carries the ordered members, the head and the order lock', $det !== null && $det['members'][0]['is_head'] === true && $det['order_locked'] === true && $det['has_history'] === true && count($det['members']) === 4);

echo "\n--- history ---\n";
$tk3 = vf_ticket();
$headNow = vendor_rotation_queue($ML)['head_provider_id'];
t('(setup) M4 is NOT the head, so calling it is an override', $headNow !== $mD);
$o = vendor_dispatch_offer(['ticket_id' => $tk3, 'service_type_id' => $TOW, 'list_id' => $ML, 'provider_id' => $mD, 'client_head_provider_id' => $headNow, 'reason' => 'Closest truck'], $A);
vendor_dispatch_outcome($o['dispatch_id'], $o['event_id'], 'no_answer', null, $A);
$tk4 = vf_ticket();
vendor_dispatch_offer(['ticket_id' => $tk4, 'service_type_id' => $TOW, 'list_id' => $ML, 'provider_id' => $mD, 'owner_request' => true], $A);
$h = vendor_history_query(['list_id' => $ML]);
$sumD = null; foreach ($h['summary'] as $s) { if ($s['provider_id'] === $mD) $sumD = $s; }
t('the per-company summary counts calls, overrides, owner requests and no-answers', $sumD !== null && $sumD['offered'] === 2 && $sumD['overrides'] === 1
    && $sumD['owner_requests'] === 1 && $sumD['no_answer'] === 1);
$answeredOffer = null; $unansweredOffer = null;
foreach ($h['events'] as $e) {
    if ($e['event_type'] !== 'offered' || (int) $e['provider_id'] !== $mD) continue;
    if ($e['selection_method'] === 'override') $answeredOffer = $e;
    if ($e['selection_method'] === 'owner_request') $unansweredOffer = $e;
}
t('an offer that was answered is not flagged "no outcome recorded"', $answeredOffer !== null && $answeredOffer['no_outcome'] === false);
t('an offer nobody recorded an outcome for IS flagged (dispatchers forget; the history says so)', $unansweredOffer !== null && $unansweredOffer['no_outcome'] === true
    && in_array('yes', array_slice(vendor_history_csv_row($unansweredOffer), -3, 1), true));
$ho = vendor_history_query(['list_id' => $ML, 'overrides_only' => true]);
t('"overrides only" shows just the offers that skipped the next-up', count($ho['events']) === 1 && $ho['events'][0]['selection_method'] === 'override' && $ho['events'][0]['reason'] === 'Closest truck');
$hp = vendor_history_query(['provider_id' => $mD]);
t('filtering by company returns only that company rows', count($hp['events']) >= 3 && count(array_filter($hp['events'], function ($e) use ($mD) { return (int) $e['provider_id'] !== $mD; })) === 0);
$future = vendor_history_query(['list_id' => $ML, 'date_from' => date('Y-m-d', time() + 86400 * 2)]);
t('a date filter in the future returns nothing', $future['events'] === []);
$today = vendor_history_query(['list_id' => $ML, 'date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d')]);
t('a date filter for today returns the rows', count($today['events']) > 0);
$voidOne = db_fetch_one("SELECT `id` FROM `{$prefix}vendor_dispatch_ledger` WHERE `dispatch_id` = ? AND `event_type` = 'offered'", [$o['dispatch_id']]);
vendor_dispatch_void((int) $voidOne['id'], 'entered in error', $A, true);
$h2 = vendor_history_query(['list_id' => $ML]);
$struck = array_filter($h2['events'], function ($e) use ($voidOne) { return (int) $e['id'] === (int) $voidOne['id']; });
t('a voided entry stays in the history, flagged', count($struck) === 1 && array_values($struck)[0]['voided'] === true);
$sumD2 = null; foreach ($h2['summary'] as $s) { if ($s['provider_id'] === $mD) $sumD2 = $s; }
t('...and it (with the answer to it) is excluded from the per-company counts', $sumD2 !== null && $sumD2['offered'] === 1 && $sumD2['overrides'] === 0
    && $sumD2['no_answer'] === 0 && $sumD2['owner_requests'] === 1);
$csvRow = vendor_history_csv_row(array_values($struck)[0]);
t('a CSV row has one cell per header column and says it was voided', count($csvRow) === count(vendor_history_csv_header()) && in_array('yes', $csvRow, true));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
