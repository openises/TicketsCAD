<?php
/**
 * GH#148 (Phase 155) -- the PURE rotation logic: ordering, effective ledger events, derived dispatch status.
 *
 * No database: these are the functions the writers and the queue are built from (inc/vendor-dispatch.php section 2),
 * so a wrong answer here is wrong everywhere. The DB-backed behaviour (the real queue over a real ledger, void-aware
 * last-turn) is proven in tests/test_vendor_dispatch_writers.php.
 *
 * Usage: php tests/test_vendor_rotation_order.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/vendor-dispatch.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

function m($id, $pos, $eligible = true) { return ['provider_id' => $id, 'position' => $pos, 'eligible' => $eligible, 'name' => 'P' . $id]; }
function ids($res) { return array_column($res['candidates'], 'provider_id'); }

echo "=== GH#148 -- rotation order (pure) ===\n\n";

// ── round robin ─────────────────────────────────────────────
echo "--- round_robin ---\n";
$members = [m(1, 1), m(2, 2), m(3, 3)];
$r = vendor_rotation_order($members, [1 => 50, 2 => 0, 3 => 30], 'round_robin');
t('a company that never had a turn goes first, then least recently used', ids($r) === [2, 3, 1]);
t('head is the first eligible company', $r['head_provider_id'] === 2);
t('candidates carry rank 1..n and is_head on exactly the head', array_column($r['candidates'], 'rank') === [1, 2, 3]
    && array_sum(array_map(function ($c) { return $c['is_head'] ? 1 : 0; }, $r['candidates'])) === 1);

$r = vendor_rotation_order([m(7, 2), m(5, 2), m(9, 1)], [], 'round_robin');
t('with no history at all, order is by position, ties by provider id (deterministic)', ids($r) === [9, 5, 7]);

$r1 = vendor_rotation_order($members, [1 => 50, 2 => 40, 3 => 30], 'round_robin');
$r2 = vendor_rotation_order(array_reverse($members), [1 => 50, 2 => 40, 3 => 30], 'round_robin');
t('the same inputs in any input order give the same ranking', ids($r1) === ids($r2) && ids($r1) === [3, 2, 1]);

// ── strict order ────────────────────────────────────────────
echo "\n--- strict_order ---\n";
$r = vendor_rotation_order($members, [1 => 999, 2 => 1, 3 => 0], 'strict_order');
t('strict order ignores history entirely: position only', ids($r) === [1, 2, 3]);
t('strict order head is the first eligible by position', $r['head_provider_id'] === 1);

// ── manual ──────────────────────────────────────────────────
echo "\n--- manual ---\n";
$r = vendor_rotation_order($members, [1 => 999, 2 => 1, 3 => 0], 'manual');
t('manual displays by position', ids($r) === [1, 2, 3]);
t('manual designates NO head', $r['head_provider_id'] === null
    && array_sum(array_map(function ($c) { return $c['is_head'] ? 1 : 0; }, $r['candidates'])) === 0);

// ── eligibility ─────────────────────────────────────────────
echo "\n--- eligibility ---\n";
$r = vendor_rotation_order([m(1, 1, false), m(2, 2), m(3, 3)], [1 => 0, 2 => 9, 3 => 8], 'round_robin');
t('an ineligible company (retired / suspended) is listed LAST, never the head, and flagged', ids($r) === [3, 2, 1]
    && $r['head_provider_id'] === 3 && $r['candidates'][2]['eligible'] === false);
$r = vendor_rotation_order([m(1, 1, false), m(2, 2, false)], [], 'round_robin');
t('no eligible company means no head', $r['head_provider_id'] === null);

// ── per-dispatch states ─────────────────────────────────────
echo "\n--- per-dispatch states ---\n";
$r = vendor_rotation_order($members, [1 => 50, 2 => 0, 3 => 30], 'round_robin', [2 => 'declined']);
t('a company that declined THIS dispatch is skipped for the head but still listed', $r['head_provider_id'] === 3 && in_array(2, ids($r), true));
$r = vendor_rotation_order($members, [1 => 50, 2 => 0, 3 => 30], 'round_robin', [2 => 'calling']);
t('a company being called right now is not the head either', $r['head_provider_id'] === 3);
$r = vendor_rotation_order($members, [1 => 50, 2 => 0, 3 => 30], 'round_robin', [2 => 'no_answer', 3 => 'unavailable', 1 => 'declined']);
t('when every company has been tried on this dispatch there is no head', $r['head_provider_id'] === null);
$r = vendor_rotation_order($members, [1 => 50, 2 => 0, 3 => 30], 'round_robin', [2 => 'declined']);
$fresh = vendor_rotation_order($members, [1 => 50, 2 => 0, 3 => 30], 'round_robin');
t('the skip applies to that dispatch only (a fresh dispatch still starts at the same company)', $fresh['head_provider_id'] === 2);

// ── a newcomer goes to the back ─────────────────────────────
echo "\n--- joined_list ---\n";
$r = vendor_rotation_order([m(1, 1), m(2, 2), m(4, 3)], [1 => 10, 2 => 20, 4 => 21], 'round_robin');
t('a newcomer whose joined_list turn is the newest ledger row waits behind everyone', ids($r) === [1, 2, 4] && $r['head_provider_id'] === 1);

// ── effective events / void handling ────────────────────────
echo "\n--- effective events ---\n";
function ev($id, $type, $pid = null, $ref = null, $extra = []) {
    return array_merge(['id' => $id, 'event_type' => $type, 'provider_id' => $pid, 'provider_name' => $pid ? 'P' . $pid : '',
        'provider_phone' => null, 'ref_event_id' => $ref, 'eta_minutes' => null, 'event_at' => '2026-01-01 10:00:0' . ($id % 10), 'selection_method' => 'rotation'], $extra);
}
$rows = [ev(1, 'offered', 1), ev(2, 'accepted', 1, 1, ['eta_minutes' => 20]), ev(3, 'voided', 1, 1)];
$eff = vendor_ledger_effective_events($rows);
t('voiding an offer removes the void row, the offer AND the outcome that answered it', count($eff) === 0);
$rows = [ev(1, 'offered', 1), ev(2, 'declined', 1, 1), ev(3, 'voided', 1, 2)];
$eff = vendor_ledger_effective_events($rows);
t('voiding only the outcome leaves the offer standing', count($eff) === 1 && $eff[0]['id'] === 1);
t('provider states follow the effective events: an un-answered offer is calling',
    vendor_dispatch_provider_states($rows) === [1 => 'calling']);
t('provider states: declined', vendor_dispatch_provider_states([ev(1, 'offered', 1), ev(2, 'declined', 1, 1)]) === [1 => 'declined']);
t('provider states: a re-offer after a failure reads as calling again',
    vendor_dispatch_provider_states([ev(1, 'offered', 1), ev(2, 'no_answer', 1, 1), ev(3, 'offered', 1)]) === [1 => 'calling']);
t('provider states ignore a company with no provider_id (an unlisted typed company has no seat)',
    vendor_dispatch_provider_states([ev(1, 'offered', null)]) === []);

// ── derived status battery ──────────────────────────────────
echo "\n--- derive_status ---\n";
function st(array $rows) { return vendor_dispatch_derive_status($rows); }
t('no events: open', st([])['status'] === 'open');
t('an offer alone does not change the status', st([ev(1, 'offered', 1)])['status'] === 'open');
$s = st([ev(1, 'offered', 1), ev(2, 'accepted', 1, 1, ['eta_minutes' => 20])]);
t('accepted: assigned, provider and ETA copied', $s['status'] === 'assigned' && $s['provider_id'] === 1 && $s['provider_name'] === 'P1' && $s['eta_minutes'] === 20 && $s['assigned_at'] !== null);
$s = st([ev(1, 'offered', 1), ev(2, 'accepted', 1, 1, ['eta_minutes' => 20]), ev(3, 'eta_update', 1, null, ['eta_minutes' => 35])]);
t('eta_update changes the ETA', $s['eta_minutes'] === 35 && $s['status'] === 'assigned');
$s = st([ev(1, 'offered', 1), ev(2, 'accepted', 1, 1), ev(3, 'on_scene', 1)]);
t('on_scene', $s['status'] === 'on_scene');
$s = st([ev(1, 'offered', 1), ev(2, 'accepted', 1, 1), ev(3, 'on_scene', 1), ev(4, 'completed', 1)]);
t('completed sets closed_at', $s['status'] === 'completed' && $s['closed_at'] !== null);
$s = st([ev(1, 'offered', 1), ev(2, 'accepted', 1, 1), ev(3, 'withdrew', 1)]);
t('withdrew returns to open and clears the provider', $s['status'] === 'open' && $s['provider_id'] === null && $s['eta_minutes'] === null && $s['assigned_at'] === null);
$s = st([ev(1, 'offered', 1), ev(2, 'accepted', 1, 1), ev(3, 'withdrew', 1), ev(4, 'offered', 2), ev(5, 'accepted', 2, 4, ['eta_minutes' => 10])]);
t('a second company can be assigned after the first withdrew', $s['status'] === 'assigned' && $s['provider_id'] === 2);
$s = st([ev(1, 'cancelled')]);
t('cancelled from open', $s['status'] === 'cancelled' && $s['closed_at'] !== null);
$s = st([ev(1, 'offered', 1), ev(2, 'accepted', 1, 1), ev(3, 'goa', 1)]);
t('gone on arrival', $s['status'] === 'goa');
$s = st([ev(1, 'offered', 1), ev(2, 'accepted', 1, 1), ev(3, 'completed', 1), ev(4, 'voided', 1, 3)]);
t('voiding the completion reopens the dispatch to assigned', $s['status'] === 'assigned' && $s['closed_at'] === null);
$s = st([ev(1, 'offered', 1), ev(2, 'accepted', 1, 1), ev(3, 'voided', 1, 2)]);
t('voiding the acceptance returns the dispatch to open', $s['status'] === 'open' && $s['provider_id'] === null);
$s = st([ev(1, 'offered', 1), ev(2, 'accepted', 1, 1), ev(3, 'offered', 2), ev(4, 'accepted', 2, 3)]);
t('a second acceptance while assigned is ignored (one accepted company at a time)', $s['provider_id'] === 1);
$s = st([ev(1, 'completed')]);
t('completed from open is not a transition', $s['status'] === 'open');

// ── status transition matrix ────────────────────────────────
echo "\n--- status events allowed ---\n";
t('on_scene only from assigned', vendor_status_event_allowed('assigned', 'on_scene') && !vendor_status_event_allowed('open', 'on_scene') && !vendor_status_event_allowed('on_scene', 'on_scene'));
t('completed from assigned or on_scene only', vendor_status_event_allowed('assigned', 'completed') && vendor_status_event_allowed('on_scene', 'completed') && !vendor_status_event_allowed('open', 'completed') && !vendor_status_event_allowed('completed', 'completed'));
t('cancelled / goa from any non-terminal state', vendor_status_event_allowed('open', 'cancelled') && vendor_status_event_allowed('on_scene', 'goa') && !vendor_status_event_allowed('cancelled', 'goa'));
t('withdrew only after acceptance', vendor_status_event_allowed('assigned', 'withdrew') && !vendor_status_event_allowed('open', 'withdrew'));
t('eta_update in any non-terminal state', vendor_status_event_allowed('open', 'eta_update') && !vendor_status_event_allowed('completed', 'eta_update'));
t('an unknown event is never allowed', !vendor_status_event_allowed('open', 'teleported'));

// ── small pure helpers ──────────────────────────────────────
echo "\n--- helpers ---\n";
t('csv guard prefixes = + - @', vendor_csv_cell('=1+1') === "'=1+1" && vendor_csv_cell('+x') === "'+x" && vendor_csv_cell('-x') === "'-x" && vendor_csv_cell('@x') === "'@x");
t('csv guard leaves ordinary text alone', vendor_csv_cell("Joe's Towing") === "Joe's Towing" && vendor_csv_cell('') === '' && vendor_csv_cell(0) === '0');
t('phone cleaner keeps dialable characters and needs 3 digits', vendor_clean_phone('(555) 010-0100 x2') === '(555) 010-0100 x2' && vendor_clean_phone('ab') === null && vendor_clean_phone("555-0100<script>") === '555-0100');
t('string cleaner strips control characters and caps length', vendor_clean_str("a\x00b\x07c", 10) === 'abc' && vendor_clean_str(str_repeat('x', 300), 120) === str_repeat('x', 120) && vendor_clean_str('   ', 5) === null);
t('reference label', vendor_ref_label('26-0123', 1) === '26-0123-T1' && vendor_ref_label('#123', 2) === '#123-T2');

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
