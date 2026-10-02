<?php
/**
 * Phase 152 prerequisite #2 — per-class station-ID policy + the
 * comm_id_log/comm_ptt_state autonomous-transmission gate.
 *
 * Covers: id_policy_by_class()'s four classes (amateur 600s, commercial
 * 900s — a genuinely different number, not the same path reused — pstn
 * and internal both not-applicable), id_policy_effective_interval()'s
 * "never looser than the class ceiling" rule, and comm_fcc_may_transmit()
 * driven against the REAL comm_id_log table (never hand-simulated state),
 * including the not-applicable short-circuit that must never evaluate an
 * interval for pstn/internal classes.
 *
 * Usage: php tests/test_phase152_id_policy.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/id-policy.php';
require_once 'inc/comm_fcc_gate.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 152 prerequisite #2 — per-class ID policy ===\n\n";

// ── id_policy_by_class() ────────────────────────────────────────────────
$amateur = id_policy_by_class('amateur');
t('amateur requires ID, 600s ceiling (Part 97)',
    $amateur['requires_id'] === true && $amateur['interval_ceiling_secs'] === 600);

$commercial = id_policy_by_class('commercial');
t('commercial requires ID, 900s ceiling (Part 90 — a DIFFERENT number from amateur, not reused)',
    $commercial['requires_id'] === true && $commercial['interval_ceiling_secs'] === 900);
t('amateur and commercial ceilings are genuinely different values',
    $amateur['interval_ceiling_secs'] !== $commercial['interval_ceiling_secs']);

$pstn = id_policy_by_class('pstn');
t('pstn: no ID requirement at all (requires_id false, interval null)',
    $pstn['requires_id'] === false && $pstn['interval_ceiling_secs'] === null);

$internal = id_policy_by_class('internal');
t('internal (Zello and friends): no ID requirement at all',
    $internal['requires_id'] === false && $internal['interval_ceiling_secs'] === null);

$unknown = id_policy_by_class('some_future_class_nobody_wrote_yet');
t('unknown class defaults to not-applicable, never to a silent amateur/commercial assumption',
    $unknown['requires_id'] === false);

// ── id_policy_effective_interval() ──────────────────────────────────────
t('a stricter per-channel override (300s) on an amateur channel is honored',
    id_policy_effective_interval('amateur', 300) === 300);
t('a LOOSER per-channel override (900s) on an amateur channel is clamped to the 600s ceiling, never loosened',
    id_policy_effective_interval('amateur', 900) === 600);
t('no per-channel override falls back to the class ceiling',
    id_policy_effective_interval('commercial', null) === 900);
t('pstn/internal return null regardless of any configured interval (never evaluated)',
    id_policy_effective_interval('pstn', 300) === null);

// ── comm_fcc_may_transmit() driven against the real comm_id_log table ───
$testChannelId = 900199051; // zz-fixture range, distinct from other phases'
db_query("DELETE FROM `{$prefix}comm_id_log` WHERE channel_id = ?", [$testChannelId]);
db_query("DELETE FROM `{$prefix}comm_ptt_state` WHERE channel_id = ?", [$testChannelId]);

$r = comm_fcc_may_transmit($testChannelId, 'pstn', null, 'hard', '');
t('pstn class: allowed with reason not_applicable even with NO callsign and hard enforcement (never evaluates ID at all)',
    $r['allowed'] === true && $r['reason'] === 'not_applicable');

$r = comm_fcc_may_transmit($testChannelId, 'internal', null, 'hard', '');
t('internal class (Zello): same — not_applicable, always allowed',
    $r['allowed'] === true && $r['reason'] === 'not_applicable');

$r = comm_fcc_may_transmit($testChannelId, 'amateur', null, 'hard', '');
t('amateur class, no callsign on file, hard enforcement: refused with reason no_callsign',
    $r['allowed'] === false && $r['reason'] === 'no_callsign');

$r = comm_fcc_may_transmit($testChannelId, 'amateur', null, 'hard', 'N0NKI');
t('amateur class, valid callsign, never IDed before, hard enforcement: refused (lapsed_hard) — must ID before relaying',
    $r['allowed'] === false && $r['reason'] === 'lapsed_hard');

$r = comm_fcc_may_transmit($testChannelId, 'amateur', null, 'soft', 'N0NKI');
t('same state, soft enforcement: allowed but flagged (lapsed_soft) — never blocks under soft',
    $r['allowed'] === true && $r['reason'] === 'lapsed_soft');

comm_fcc_record_id_event($testChannelId, 0, 'N0NKI', 'autonomous_relay', 'test fixture');
$r = comm_fcc_may_transmit($testChannelId, 'amateur', null, 'hard', 'N0NKI');
t('after a real recorded ID event, amateur class + hard enforcement now allows (ok)',
    $r['allowed'] === true && $r['reason'] === 'ok');

$lastId = comm_fcc_last_id_at($testChannelId, 0);
t('comm_fcc_last_id_at() reads back the real row just written', $lastId !== null);

// Commercial class ceiling actually differs in the live gate, not just in
// the policy table: back-date the ID event to 700s ago (past amateur's
// 600s ceiling, still within commercial's 900s) and confirm the two
// classes disagree on the SAME timestamp.
db_query("UPDATE `{$prefix}comm_id_log` SET id_at = DATE_SUB(NOW(), INTERVAL 700 SECOND) WHERE channel_id = ? AND user_id = 0", [$testChannelId]);
$rAmateur = comm_fcc_may_transmit($testChannelId, 'amateur', null, 'hard', 'N0NKI');
$rCommercial = comm_fcc_may_transmit($testChannelId, 'commercial', null, 'hard', 'N0NKI');
t('700s since last ID: amateur (600s ceiling) now refuses (lapsed_hard)',
    $rAmateur['allowed'] === false && $rAmateur['reason'] === 'lapsed_hard');
t('the SAME 700s-old ID: commercial (900s ceiling) still allows (ok) — proves the classes are genuinely evaluated differently, not just labeled differently',
    $rCommercial['allowed'] === true && $rCommercial['reason'] === 'ok');

db_query("DELETE FROM `{$prefix}comm_id_log` WHERE channel_id = ?", [$testChannelId]);
db_query("DELETE FROM `{$prefix}comm_ptt_state` WHERE channel_id = ?", [$testChannelId]);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
