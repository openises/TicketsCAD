<?php
/**
 * Phase 16b — PAR scheduler tests.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/par.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 16b — PAR scheduler ===\n\n";
$pass = 0; $fail = 0;
function ok($n) { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $w='') { global $fail; echo "[FAIL] $n" . ($w?" — $w":'') . "\n"; $fail++; }

// Enable PAR for these tests
db_query(
    "INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('par_enabled','1')
     ON DUPLICATE KEY UPDATE value = VALUES(value)"
);

// Disabled case
db_query(
    "INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('par_enabled','0')
     ON DUPLICATE KEY UPDATE value = VALUES(value)"
);
$r = par_run_scheduler();
if (($r['reason'] ?? '') === 'disabled') ok('scheduler no-ops when par_enabled=0');
else                                     bad('disabled-case', var_export($r, true));

// Re-enable + run a sweep on real data (should not throw)
db_query(
    "INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('par_enabled','1')
     ON DUPLICATE KEY UPDATE value = VALUES(value)"
);
$r = par_run_scheduler();
if (is_array($r) && isset($r['cycles_started']) && isset($r['units_missed'])) {
    ok("scheduler sweep returned shape OK (started={$r['cycles_started']}, missed={$r['units_missed']})");
} else {
    bad('sweep shape', var_export($r, true));
}

// Create a pending cycle whose window has already elapsed; verify the
// next sweep marks remaining pending acks as missed.
try {
    // Use the first active ticket if there is one.
    $tid = (int) db_fetch_value("SELECT id FROM `{$prefix}ticket` ORDER BY id ASC LIMIT 1");
    if (!$tid) {
        db_query(
            "INSERT INTO `{$prefix}ticket` (in_types_id, scope, description, status, `date`)
             VALUES (1, 'p16b sentinel', '', 0, NOW())"
        );
        $tid = (int) db_insert_id();
        $isSentinel = true;
    } else {
        $isSentinel = false;
    }

    // Insert a cycle backdated 5 minutes with a 60s window.
    db_query(
        "INSERT INTO `{$prefix}par_cycles`
            (ticket_id, initiated_at, initiated_by, initiated_kind,
             cycle_window_s, status, notes)
         VALUES (?, DATE_SUB(NOW(), INTERVAL 5 MINUTE), NULL, 'manual',
                 60, 'pending', 'p16b stale')",
        [$tid]
    );
    $cycleId = (int) db_insert_id();

    // Need at least one responder
    $rid = (int) db_fetch_value("SELECT id FROM `{$prefix}responder` LIMIT 1");
    if ($rid > 0) {
        db_query(
            "INSERT INTO `{$prefix}par_unit_acks`
                (par_cycle_id, responder_id, expected, state)
             VALUES (?, ?, 1, 'pending')",
            [$cycleId, $rid]
        );

        $r = par_run_scheduler();
        $state = db_fetch_value(
            "SELECT state FROM `{$prefix}par_unit_acks`
             WHERE par_cycle_id = ? AND responder_id = ?",
            [$cycleId, $rid]
        );
        if ($state === 'missed') ok('elapsed-window pending → missed');
        else                     bad('miss transition', "state={$state}");

        // Cycle should now be 'complete' since no pending remain
        $cyStatus = db_fetch_value(
            "SELECT status FROM `{$prefix}par_cycles` WHERE id = ?", [$cycleId]);
        if ($cyStatus === 'complete') ok('cycle marked complete after all elapsed');
        else                          bad('cycle status', "got={$cyStatus}");
    } else {
        ok('(skipped miss test — no responders in this DB)');
        ok('(skipped cycle-complete test — no responders)');
    }

    // Cleanup
    db_query("DELETE FROM `{$prefix}par_unit_acks` WHERE par_cycle_id = ?", [$cycleId]);
    db_query("DELETE FROM `{$prefix}par_cycles` WHERE id = ?", [$cycleId]);
    if ($isSentinel) db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
} catch (Exception $e) {
    bad('miss test scaffold: ' . $e->getMessage());
}

// Restore par_enabled to OFF
db_query(
    "INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('par_enabled','0')
     ON DUPLICATE KEY UPDATE value = VALUES(value)"
);
ok('cleanup — par_enabled restored to 0');

echo "\n===========================================\n";
echo "Phase 16b scheduler: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";
if ($fail > 0) exit(1);
