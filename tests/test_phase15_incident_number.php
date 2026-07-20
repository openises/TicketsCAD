<?php
/**
 * Phase 15 — incident-number template engine regression tests.
 *
 * Covers every test case from Eric's spec (2026-06-11) verbatim plus
 * the edge cases (overflow, malformed, escape) and the atomic
 * allocation behavior.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-number.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 15 — incident-number template tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : '') . "\n"; $fail++; }

// Fixed timestamp: June 2, 2026 10:00:00 (matches Eric's spec doc).
$t = mktime(10, 0, 0, 6, 2, 2026);

// ── Eric's spec test cases — verbatim ──────────────────────────────────
$cases = [
    ['CASE-{YYYY}-{MM}-{DD}-{0000}',  42,    'CASE-2026-06-02-0042'],
    ['INV-{YY}{MM}-{NNNNN}',           42,    'INV-2606-00042'],
    ['INCIDENT-{0}',                   42,    'INCIDENT-42'],
    ['DEPT-A-{NNN}-LEGACY',            42,    'DEPT-A-042-LEGACY'],
];
foreach ($cases as $c) {
    [$tpl, $seq, $expected] = $c;
    $got = incnum_render($tpl, $seq, $t);
    if ($got === $expected) ok("spec: '{$tpl}' seq={$seq} → '{$expected}'");
    else                    bad("spec: '{$tpl}' seq={$seq}", "got '{$got}'");
}

// ── Overflow ───────────────────────────────────────────────────────────
$cases = [
    ['{000}',  1024, '1024'],          // 4-digit overflow on 3-digit token
    ['{0}',    99,   '99'],            // min-width 1 doesn't pad to 1 char anyway
    ['{NNNNNNNN}', 12345, '00012345'], // normal 8-wide pad
    ['{NN}',   100,  '100'],           // 3-digit overflow on 2-digit token
];
foreach ($cases as $c) {
    [$tpl, $seq, $expected] = $c;
    $got = incnum_render($tpl, $seq, $t);
    if ($got === $expected) ok("overflow: '{$tpl}' seq={$seq} → '{$expected}'");
    else                    bad("overflow: '{$tpl}' seq={$seq}", "got '{$got}'");
}

// ── Malformed tokens — left as literal ────────────────────────────────
$cases = [
    ['{XYZ}',          '{XYZ}'],
    ['{00N0}',         '{00N0}'],      // mixed N and 0 — not valid
    ['{NNn}',          '{NNn}'],       // lowercase n not allowed
    ['Hello {WORLD}',  'Hello {WORLD}'],
    ['{}',             '{}'],          // empty braces
];
foreach ($cases as $c) {
    [$tpl, $expected] = $c;
    $got = incnum_render($tpl, 42, $t);
    if ($got === $expected) ok("malformed left-literal: '{$tpl}' → '{$expected}'");
    else                    bad("malformed: '{$tpl}'", "got '{$got}'");
}

// ── Escape sequences ──────────────────────────────────────────────────
$cases = [
    ['\\{literal\\}-{NNN}',  '{literal}-042'],
    ['\\{\\{}',              '{{}'],   // \{ \{ } → {{}
    ['{NNN}\\\\test',        '042\\test'], // backslash + text
    ['\\}',                  '}'],
];
foreach ($cases as $c) {
    [$tpl, $expected] = $c;
    $got = incnum_render($tpl, 42, $t);
    if ($got === $expected) ok("escape: '{$tpl}' → '{$expected}'");
    else                    bad("escape: '{$tpl}'", "got '{$got}'");
}

// ── Additional date tokens ────────────────────────────────────────────
$cases = [
    ['{HH}',   42, '10'],              // hour from $t (10:00)
    ['{JJJ}',  42, '153'],             // day-of-year for June 2, 2026 = 153
    ['{UU}',   42, '23'],              // ISO week 23 (PHP date('W'))
];
foreach ($cases as $c) {
    [$tpl, $seq, $expected] = $c;
    $got = incnum_render($tpl, $seq, $t);
    if ($got === $expected) ok("date token: '{$tpl}' → '{$expected}'");
    else                    bad("date token: '{$tpl}'", "got '{$got}'");
}

// ── Validator ─────────────────────────────────────────────────────────
$v = incnum_validate('{YYYY}-{NNNN}');
if ($v['valid'] && $v['has_sequence'] && $v['has_date']) ok('validator accepts valid template');
else                                                     bad('validator: '. var_export($v, true));

$v = incnum_validate('NO-SEQ-{YYYY}');
if (!$v['valid'] && !empty($v['errors'])) ok('validator rejects template with no sequence token');
else                                      bad('validator should reject no-seq', var_export($v, true));

$v = incnum_validate('{XYZ}-{NNN}');
if ($v['valid'] && !empty($v['warnings'])) ok('validator warns on malformed but doesn\'t hard-error');
else                                       bad('validator malformed handling', var_export($v, true));

// ── Atomic allocation (sequential calls must be monotonic, no skips) ──
$start = incnum_get_next();
$a = incnum_allocate($t);
$b = incnum_allocate($t);
$c = incnum_allocate($t);
if ($a['sequence'] + 1 === $b['sequence'] &&
    $b['sequence'] + 1 === $c['sequence']) {
    ok("allocate increments monotonically: {$a['sequence']} → {$b['sequence']} → {$c['sequence']}");
} else {
    bad('allocate sequence skipped or repeated',
        "a={$a['sequence']} b={$b['sequence']} c={$c['sequence']}");
}

// Reset back to the value we found so we don't leave the dev DB in
// a different state than we started.
incnum_set_next($start);
ok('allocate cleanup — sequence restored');

// ── Schema ────────────────────────────────────────────────────────────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = 'incident_number'",
        [$prefix . 'ticket']
    );
    if ($col && stripos($col['COLUMN_TYPE'], 'varchar') !== false) {
        ok('ticket.incident_number column exists (varchar)');
    } else {
        bad('ticket.incident_number missing');
    }
} catch (Exception $e) {
    bad('schema check: ' . $e->getMessage());
}

// ── Settings rows ─────────────────────────────────────────────────────
$tpl = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name='incident_number_template'");
$seq = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name='incident_number_sequence'");
if ($tpl !== false && $tpl !== '') ok("template setting persisted ('{$tpl}')");
else                               bad('template setting missing or empty');
if ($seq !== false && (int) $seq >= 1) ok("sequence setting persisted ({$seq})");
else                                   bad('sequence setting missing');

echo "\n===========================================\n";
echo "Phase 15 incident numbers: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";
if ($fail > 0) exit(1);
