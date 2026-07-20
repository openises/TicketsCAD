<?php
/**
 * Phase 15c — incident-number uniqueness tests.
 *
 * Covers the retry-on-collision allocator, the preview-check
 * helper, and the schema-level UNIQUE constraint.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-number.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 15c — uniqueness defense ===\n\n";
$pass = 0; $fail = 0;
function ok($n) { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $w='') { global $fail; echo "[FAIL] $n" . ($w?" — $w":'') . "\n"; $fail++; }

// Save state so we don't trash the dev DB.
$origTpl  = incnum_get_template();
$origMode = incnum_get_reset_mode();
$origNext = incnum_get_next();
$origPer  = (string) db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name='incident_number_period'");

// Pin to a known state for the test.
incnum_set_reset_mode('yearly');
db_query(
    "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_template', '{YY}-{NNNN}')
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
);
db_query(
    "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('incident_number_period', ?)
     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
    [date('Y')]
);

// ── UNIQUE constraint present? ─────────────────────────────────────────
try {
    $idx = db_fetch_one(
        "SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = 'incident_number'
           AND NON_UNIQUE   = 0
         LIMIT 1",
        [$prefix . 'ticket']
    );
    if ($idx) ok('UNIQUE index on ticket.incident_number is present');
    else      bad('UNIQUE index missing — Phase 15c migration not applied?');
} catch (Exception $e) { bad('schema check: ' . $e->getMessage()); }

// ── Insert a ticket with a known number, then check collision ─────────
// Use a uniqueish test sentinel to avoid clobbering real data.
$yy = date('y');
$testNum = $yy . '-9991';
$existingId = null;
try {
    // Clean up any prior test pollution.
    db_query("DELETE FROM `{$prefix}ticket` WHERE incident_number LIKE ?", [$yy . '-999%']);
    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `scope`, `description`, `status`, `date`, `incident_number`)
         VALUES (1, 'phase15c test', 'sentinel', 0, NOW(), ?)",
        [$testNum]
    );
    $existingId = (int) db_insert_id();
} catch (Exception $e) { bad('test fixture: ' . $e->getMessage()); }

// ── incnum_find_existing ──────────────────────────────────────────────
$f = incnum_find_existing($testNum);
if ($f === $existingId) ok("find_existing('{$testNum}') → id {$existingId}");
else                    bad('find_existing', "got " . var_export($f, true));

$f = incnum_find_existing($yy . '-9990');
if ($f === null) ok("find_existing for unused number → null");
else             bad('find_existing should return null', "got {$f}");

// ── incnum_check_collision ────────────────────────────────────────────
// Candidate seq 9991 collides; next_safe should be 9992.
$c = incnum_check_collision('{YY}-{NNNN}', 9991);
if ($c['collision'] && $c['existing_ticket'] === $existingId && $c['next_safe_seq'] === 9992) {
    ok("check_collision: 9991 collides → next_safe 9992");
} else {
    bad('check_collision basic', "got " . var_export($c, true));
}

// Candidate seq 9990 is free; next_safe should equal candidate.
$c = incnum_check_collision('{YY}-{NNNN}', 9990);
if (!$c['collision'] && $c['next_safe_seq'] === 9990) {
    ok("check_collision: 9990 free → next_safe 9990");
} else {
    bad('check_collision free slot', "got " . var_export($c, true));
}

// Template with NO sequence token → no safe slot exists.
$c = incnum_check_collision($testNum, 9991);  // literal, no sequence token
if ($c['collision'] && $c['next_safe_seq'] === null) {
    ok("check_collision: no-seq-token template → next_safe_seq null");
} else {
    bad('check_collision no-seq', "got " . var_export($c, true));
}

// ── incnum_allocate retries past collision ────────────────────────────
// Set next sequence to 9990 → first allocation hits 9990 (free),
// then bump to 9991 → next should detect collision and skip to 9992.
incnum_set_next(9991);
$a = incnum_allocate();
if ($a['number'] === $yy . '-9992' &&
    $a['sequence'] === 9992 &&
    $a['collision_hops'] === 1 &&
    $a['asked_sequence'] === 9991) {
    ok("allocate retries past colliding number: asked 9991, got 9992 (hop=1)");
} else {
    bad('allocate retry-on-collision', "got " . var_export($a, true));
}

// ── Schema enforces uniqueness (defense-in-depth) ─────────────────────
try {
    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `scope`, `description`, `status`, `date`, `incident_number`)
         VALUES (1, 'phase15c dup test', 'sentinel', 0, NOW(), ?)",
        [$testNum]  // already exists
    );
    bad('UNIQUE constraint did NOT reject duplicate — schema not protecting');
    // Roll back the unwanted row if it landed
    db_query("DELETE FROM `{$prefix}ticket` WHERE scope = 'phase15c dup test'");
} catch (Exception $e) {
    if (stripos($e->getMessage(), 'duplicate') !== false ||
        stripos($e->getMessage(), 'unique')    !== false ||
        stripos($e->getMessage(), '1062')      !== false) {
        ok('UNIQUE constraint rejected duplicate (schema-level defense)');
    } else {
        bad('UNIQUE rejection threw unexpected error', $e->getMessage());
    }
}

// ── Cleanup test fixtures ─────────────────────────────────────────────
try {
    db_query("DELETE FROM `{$prefix}ticket` WHERE incident_number LIKE ?", [$yy . '-999%']);
    ok('cleanup — test ticket rows removed');
} catch (Exception $e) {
    bad('cleanup', $e->getMessage());
}

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
ok('cleanup — settings restored');

echo "\n===========================================\n";
echo "Phase 15c uniqueness: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";
if ($fail > 0) exit(1);
