<?php
/**
 * Phase 148 (2026-08-20) — pure timing-math tests for
 * inc/fcc_station_id.php, driving fcc_ts()/fcc_may_transmit_without_id()/
 * fcc_id_zone()/fcc_seconds_since()/fcc_callsign_valid() directly with
 * synthetic timestamps. Legitimate here — these are pure functions with no
 * DB dependency, same convention as tests/test_interval_report_math.php
 * (see that file's own docblock for why synthetic inputs are the right
 * tool for exercising boundary conditions, as opposed to hand-seeding a
 * DATABASE row to fake a real writer's output, which this project's
 * CLAUDE.md explicitly warns against).
 *
 * The worked examples below are taken DIRECTLY from the
 * fcc-amateur-station-id skill (the one that guided this build) so that a
 * future reader can check this file against the skill's own text and see
 * the exact same numbers.
 *
 * The companion tests/test_fcc_station_id_integration.php drives the DB-
 * backed functions (fcc_record_tx/fcc_record_id_event/fcc_monitoring_id/
 * fcc_end_conversation/fcc_status_payload) against real fixture rows.
 *
 * Usage: php tests/test_fcc_station_id_timing.php
 */

require_once __DIR__ . '/../inc/fcc_station_id.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 148 — FCC 97.119 station-ID pure timing math ===\n\n";

// ── fcc_ts() ─────────────────────────────────────────────────────────────
echo "--- fcc_ts() ---\n";
t('null -> null', fcc_ts(null) === null);
t('empty string -> null', fcc_ts('') === null);
t('zero-date sentinel -> null', fcc_ts('0000-00-00 00:00:00') === null);
t('garbage string -> null', fcc_ts('not-a-date') === null);
t('a real datetime parses', fcc_ts('2026-08-20 10:00:00') === strtotime('2026-08-20 10:00:00'));

// ── fcc_callsign_valid() ────────────────────────────────────────────────
echo "\n--- fcc_callsign_valid() ---\n";
t('N0NKI is valid', fcc_callsign_valid('N0NKI'));
t('W1AW is valid', fcc_callsign_valid('W1AW'));
t('AA0E is valid', fcc_callsign_valid('AA0E'));
t('lowercase n0nki is valid (uppercased internally)', fcc_callsign_valid('n0nki'));
t('blank is invalid', !fcc_callsign_valid(''));
t('whitespace-only is invalid', !fcc_callsign_valid('   '));
t('a bare word with no digit is invalid', !fcc_callsign_valid('DISPATCH'));
t('a phone-number-shaped string is invalid', !fcc_callsign_valid('555-1234'));

// ── fcc_seconds_since() ─────────────────────────────────────────────────
echo "\n--- fcc_seconds_since() ---\n";
t('null timestamp -> null', fcc_seconds_since(null) === null);
t('300s ago -> 300',
    fcc_seconds_since('2026-08-20 10:00:00', '2026-08-20 10:05:00') === 300);
t('same instant -> 0',
    fcc_seconds_since('2026-08-20 10:00:00', '2026-08-20 10:00:00') === 0);
t('never negative even if "now" is before the timestamp (clock skew guard)',
    fcc_seconds_since('2026-08-20 10:05:00', '2026-08-20 10:00:00') === 0);

// ── fcc_may_transmit_without_id() — THE regulatory-meaningful function ──
// Per the skill: "The check that actually matters: gates the NEXT TX, not
// silence." Anchored to last_id_at ONLY, never last-TX time.
echo "\n--- fcc_may_transmit_without_id() ---\n";
t('never IDed (null last_id_at) -> false (next TX must contain callsign)',
    fcc_may_transmit_without_id(null, 600) === false);
t('just IDed (0s elapsed) -> true',
    fcc_may_transmit_without_id('2026-08-20 10:00:00', 600, '2026-08-20 10:00:00') === true);
t('599s elapsed, 600s interval -> still true (1s of margin left)',
    fcc_may_transmit_without_id('2026-08-20 10:00:00', 600, '2026-08-20 10:09:59') === true);
t('exactly 600s elapsed -> false (the boundary belongs to "must ID")',
    fcc_may_transmit_without_id('2026-08-20 10:00:00', 600, '2026-08-20 10:10:00') === false);
t('601s elapsed -> false',
    fcc_may_transmit_without_id('2026-08-20 10:00:00', 600, '2026-08-20 10:10:01') === false);
t('a tighter admin-configured interval (300s) is honored',
    fcc_may_transmit_without_id('2026-08-20 10:00:00', 300, '2026-08-20 10:04:59') === true);
t('...and its own boundary too',
    fcc_may_transmit_without_id('2026-08-20 10:00:00', 300, '2026-08-20 10:05:00') === false);
t('an interval of 0 is treated as 1s minimum (never divide-by-zero / always-false)',
    fcc_may_transmit_without_id('2026-08-20 10:00:00', 0, '2026-08-20 10:00:00') === true);

// Worked example 2 from the skill — "silence after conversation, two
// outcomes". A: IDs at T=0:00. Silence follows. The obligation is NEVER a
// background alarm — it only matters at the moment of the NEXT transmit.
echo "\n--- Skill worked example 2: silence after conversation ---\n";
$lastId = '2026-08-20 00:00:00';
t('T=7:00 since last ID: a TX now would NOT need to re-ID',
    fcc_may_transmit_without_id($lastId, 600, '2026-08-20 00:07:00') === true);
t('T=10:00 since last ID: a TX now WOULD need to re-ID',
    fcc_may_transmit_without_id($lastId, 600, '2026-08-20 00:10:00') === false);
t('T=12:00, still silent: the function reports the SAME "next TX needs ID" '
    . 'fact -- it is not an alarm, it is a precondition for a TX that never happened',
    fcc_may_transmit_without_id($lastId, 600, '2026-08-20 00:12:00') === false);
// The critical point the skill makes: Outcome 1 (A never transmits again)
// is legal, and this function's "false" result at T=12:00 does NOT mean a
// violation occurred -- it only means IF A transmits now, the callsign
// must be included. The caller (fccGateBeforeTx() in radio-widget.js) is
// the only thing that acts on this, and it acts ONLY at PTT key-down.

// Worked example: time-since-last-TX is the WRONG model (the skill's own
// "why this is a different mistake" section). A transmits with ID at
// T=0:00, then a quick TX at T=6:00 (no ID, fine — 6 min < 10 min since
// last ID), then nothing until T=8:00. A TX at T=8:00 still doesn't need
// an ID (8 min since last ID). A TX at T=11:00 WOULD need one, even though
// it's only 3 min since the T=8:00 TX -- because the anchor is last_id_at
// (T=0:00), never last-TX time.
echo "\n--- Skill's \"why time-since-last-TX is wrong\" example ---\n";
t('T=8:00 since last ID (T=0:00): TX may omit callsign',
    fcc_may_transmit_without_id('2026-08-20 00:00:00', 600, '2026-08-20 00:08:00') === true);
t('T=11:00 since last ID (T=0:00): TX must include callsign, '
    . 'even though only 3 min have passed since the T=8:00 TX',
    fcc_may_transmit_without_id('2026-08-20 00:00:00', 600, '2026-08-20 00:11:00') === false);

// ── fcc_id_zone() — informational only ───────────────────────────────────
echo "\n--- fcc_id_zone() ---\n";
t('never IDed -> "none"', fcc_id_zone(null, 600) === 'none');
t('0s elapsed -> green',
    fcc_id_zone('2026-08-20 10:00:00', 600, '2026-08-20 10:00:00') === 'green');
t('479s elapsed (just under 80% of 600) -> green',
    fcc_id_zone('2026-08-20 10:00:00', 600, '2026-08-20 10:07:59') === 'green');
t('480s elapsed (exactly 80% of 600, matches the skill\'s 8:00 mark) -> yellow',
    fcc_id_zone('2026-08-20 10:00:00', 600, '2026-08-20 10:08:00') === 'yellow');
t('599s elapsed -> still yellow (not yet the 10:00 boundary)',
    fcc_id_zone('2026-08-20 10:00:00', 600, '2026-08-20 10:09:59') === 'yellow');
t('600s elapsed (matches the skill\'s 10:00 mark) -> red',
    fcc_id_zone('2026-08-20 10:00:00', 600, '2026-08-20 10:10:00') === 'red');
t('well past the interval -> still red (no separate "violation" state)',
    fcc_id_zone('2026-08-20 10:00:00', 600, '2026-08-20 11:00:00') === 'red');
t('a 300s interval\'s own 80% boundary (240s) lands on yellow',
    fcc_id_zone('2026-08-20 10:00:00', 300, '2026-08-20 10:04:00') === 'yellow');

// ── Cross-check: fcc_id_zone() and fcc_may_transmit_without_id() never
// disagree about the red/false boundary (both anchored to the same
// last_id_at + interval arithmetic; a regression that changes one without
// the other is exactly the kind of drift a paired assertion catches).
echo "\n--- Zone/compliance-check agreement across a sweep ---\n";
$agree = true;
for ($elapsed = 0; $elapsed <= 700; $elapsed += 37) {
    $now = date('Y-m-d H:i:s', strtotime('2026-08-20 10:00:00') + $elapsed);
    $zone = fcc_id_zone('2026-08-20 10:00:00', 600, $now);
    $may  = fcc_may_transmit_without_id('2026-08-20 10:00:00', 600, $now);
    // zone=='red' must be exactly the complement of may==true, at every
    // sampled instant -- they're two views of the identical elapsed>=600
    // comparison and must never diverge.
    if (($zone === 'red') !== ($may === false)) { $agree = false; break; }
}
t('across a 0..700s sweep at 37s steps, zone=="red" iff may_transmit_without_id()==false', $agree);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
