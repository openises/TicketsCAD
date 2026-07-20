<?php
/**
 * Phase 15b — incident-number period-reset tests.
 *
 * Verifies that the sequence counter resets when the configured
 * period (year/month/day) rolls over, and that admin overrides
 * the smart suggester correctly.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-number.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 15b — period-reset tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : '') . "\n"; $fail++; }

// Save current state so we don't leave the dev DB clobbered.
$origTpl  = incnum_get_template();
$origMode = incnum_get_reset_mode();
$origNext = incnum_get_next();
$origPer  = (string) db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name='incident_number_period'");

// ── incnum_suggest_reset_mode ──────────────────────────────────────────
$cases = [
    ['{YY}-{NNNN}',                'yearly'],
    ['{YYYY}-{NNNN}',              'yearly'],
    ['{YY}-{MM}-{NNNN}',           'monthly'],
    ['{YY}-{MM}-{DD}-{NNNN}',      'daily'],
    ['{JJJ}-{NNNN}',               'daily'],
    ['CASE-{NNNNN}',               'never'],     // no date token
    ['INC-{NNNN}-{HH}',            'never'],     // HH alone doesn't imply a reset period
];
foreach ($cases as $c) {
    [$tpl, $expected] = $c;
    $got = incnum_suggest_reset_mode($tpl);
    if ($got === $expected) ok("suggest: '{$tpl}' → {$expected}");
    else                    bad("suggest: '{$tpl}'", "got '{$got}', expected '{$expected}'");
}

// ── incnum_period_key ──────────────────────────────────────────────────
$t = mktime(10, 0, 0, 6, 2, 2026);
$cases = [
    ['never',   '0'],
    ['yearly',  '2026'],
    ['monthly', '2026-06'],
    ['daily',   '2026-06-02'],
];
foreach ($cases as $c) {
    [$mode, $expected] = $c;
    $got = incnum_period_key($mode, $t);
    if ($got === $expected) ok("period key: mode={$mode} → '{$expected}'");
    else                    bad("period key: mode={$mode}", "got '{$got}'");
}

// ── Allocation with no period rollover ─────────────────────────────────
incnum_set_reset_mode('yearly');
incnum_set_next(100);
$a = incnum_allocate();
$b = incnum_allocate();
if ($a['sequence'] === 100 && $b['sequence'] === 101 && !$a['did_reset'] && !$b['did_reset']) {
    ok("no-rollover: same period → sequence increments {$a['sequence']} → {$b['sequence']}");
} else {
    bad('no-rollover increment', "a={$a['sequence']} b={$b['sequence']} reset_a=" . var_export($a['did_reset'], true));
}

// ── Allocation triggers reset when period changes ──────────────────────
// Simulate end-of-year by stamping the stored period to last year.
incnum_set_reset_mode('yearly');
incnum_set_next(4287);
db_query(
    "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_period', ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
    ['2026']  // older than current year
);
// Force current period to be in 2027 by using future timestamp
$jan1_2027 = mktime(0, 1, 0, 1, 1, 2027);
$reset = incnum_allocate($jan1_2027);
if ($reset['sequence'] === 1 && $reset['did_reset'] && $reset['period'] === '2027') {
    ok("yearly rollover: stored=2026, current=2027 → sequence reset to 1");
} else {
    bad('yearly rollover',
        "seq={$reset['sequence']} did_reset=" . var_export($reset['did_reset'], true) .
        " period={$reset['period']}");
}

// Subsequent allocation in the SAME new period should continue from 2
$cont = incnum_allocate($jan1_2027);
if ($cont['sequence'] === 2 && !$cont['did_reset']) {
    ok("yearly rollover: subsequent in same period continues at 2");
} else {
    bad('yearly post-rollover increment', "seq={$cont['sequence']} did_reset=" . var_export($cont['did_reset'], true));
}

// ── Monthly rollover ──────────────────────────────────────────────────
incnum_set_reset_mode('monthly');
incnum_set_next(42);
db_query(
    "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_period', ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
    ['2026-06']
);
$augFirst = mktime(0, 1, 0, 8, 1, 2026);
$r = incnum_allocate($augFirst);
if ($r['sequence'] === 1 && $r['did_reset'] && $r['period'] === '2026-08') {
    ok("monthly rollover: 2026-06 → 2026-08 → reset to 1");
} else {
    bad('monthly rollover', "seq={$r['sequence']} period={$r['period']}");
}

// ── 'never' mode never resets ────────────────────────────────────────
incnum_set_reset_mode('never');
incnum_set_next(999);
db_query(
    "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_period', ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
    ['2020']  // ancient period
);
$farFuture = mktime(0, 0, 0, 1, 1, 2099);
$r = incnum_allocate($farFuture);
if ($r['sequence'] === 999 && !$r['did_reset']) {
    ok("'never' mode: ancient stored period → still continues incrementing");
} else {
    bad("'never' mode reset", "seq={$r['sequence']} did_reset=" . var_export($r['did_reset'], true));
}

// ── incnum_get_next reports the upcoming reset accurately ──────────────
incnum_set_reset_mode('yearly');
incnum_set_next(500);
// Stamp stored period to current year so next allocation continues.
db_query(
    "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_period', ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
    [date('Y')]
);
$n = incnum_get_next();
if ($n === 500) ok("get_next: same period → reports stored+1 (would-be-allocated)");
else            bad('get_next same period', "got {$n}");

// Now stamp period to old year — get_next should report 1 (reset due).
db_query(
    "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_period', ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
    ['1999']
);
$n = incnum_get_next();
if ($n === 1) ok("get_next: period-rollover due → reports 1");
else          bad('get_next pending reset', "got {$n}");

// ── Restore state ─────────────────────────────────────────────────────
incnum_set_reset_mode($origMode);
incnum_set_next($origNext);
db_query(
    "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_template', ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
    [$origTpl]
);
db_query(
    "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_period', ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
    [$origPer]
);
ok("cleanup — original state restored");

echo "\n===========================================\n";
echo "Phase 15b period-reset: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";
if ($fail > 0) exit(1);
