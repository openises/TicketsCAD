<?php
/**
 * Phase 16a — PAR foundation tests.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/par.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 16a — PAR foundation ===\n\n";
$pass = 0; $fail = 0;
function ok($n) { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $w='') { global $fail; echo "[FAIL] $n" . ($w?" — $w":'') . "\n"; $fail++; }

// ── Schema ──────────────────────────────────────────────────────────
foreach (['par_cycles','par_unit_acks','par_config'] as $t) {
    try {
        $r = db_fetch_one(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . $t]
        );
        if ($r) ok("table {$t} exists");
        else    bad("table {$t} missing");
    } catch (Exception $e) { bad("table {$t}: " . $e->getMessage()); }
}

foreach (['par_cadence_override_min','par_last_cycle_at'] as $col) {
    try {
        $r = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . 'ticket', $col]
        );
        if ($r) ok("ticket.{$col} present");
        else    bad("ticket.{$col} missing");
    } catch (Exception $e) { bad($col . ': ' . $e->getMessage()); }
}

// ── par_enabled defaults to false ───────────────────────────────────
db_query(
    "INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('par_enabled','0')
     ON DUPLICATE KEY UPDATE value = VALUES(value)"
);
if (par_enabled() === false) ok('par_enabled() returns false when setting=0');
else                         bad('par_enabled() should be false');

db_query(
    "INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('par_enabled','1')
     ON DUPLICATE KEY UPDATE value = VALUES(value)"
);
if (par_enabled() === true) ok('par_enabled() returns true when setting=1');
else                        bad('par_enabled() should be true');

// ── par_resolve_cadence — layered resolution ────────────────────────
// ALWAYS create our own sentinel ticket. Borrowing "the oldest existing
// ticket" (the pre-2026-07-07 behavior) made this test order-dependent in
// the full suite: whatever state an earlier test's leftover ticket carried
// leaked into the cadence/cycle assertions, and our par_cadence_override /
// assigns writes polluted that foreign ticket in return.
db_query(
    "INSERT INTO `{$prefix}ticket` (in_types_id, scope, description, status, `date`)
     VALUES (1, 'phase16a sentinel', '', 0, NOW())"
);
$ticketId = (int) db_insert_id();
$isSentinel = true;

$cad = par_resolve_cadence($ticketId);
if ($cad['cadence_minutes'] > 0 && isset($cad['source'])) {
    ok("resolve_cadence returns positive cadence ({$cad['cadence_minutes']} min, source={$cad['source']})");
} else {
    bad('resolve_cadence', var_export($cad, true));
}

// Per-incident override should win
db_query(
    "UPDATE `{$prefix}ticket` SET par_cadence_override_min = 5 WHERE id = ?",
    [$ticketId]
);
$cad2 = par_resolve_cadence($ticketId);
if ($cad2['cadence_minutes'] === 5 && $cad2['source'] === 'incident_override') {
    ok('resolve_cadence: per-incident override wins');
} else {
    bad('per-incident override', var_export($cad2, true));
}
// Phase 30A (2026-06-12) — par_due_at requires BOTH explicit cadence
// opt-in AND at least one assigned unit. Re-apply the override AND
// assign a unit before testing par_due_at returns a timestamp.
db_query(
    "UPDATE `{$prefix}ticket` SET par_cadence_override_min = 5 WHERE id = ?",
    [$ticketId]
);
// Pick any responder; a virgin install ships the responder table EMPTY
// (demo responders live in the optional sql/seed_demo_data.sql pack), so
// seed a sentinel responder when none exists — tracked and cleaned below.
$respIdForDueAt = (int) db_fetch_value(
    "SELECT id FROM `{$prefix}responder`
      WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
      ORDER BY id LIMIT 1"
);
$sentinelResponderId = 0;
if ($respIdForDueAt <= 0) {
    // Verified against sql/base_schema.sql: responder has `name` (text NULL)
    // and `description` (text NOT NULL, no default — must be included).
    // There are NO _by/_from/_on audit columns on responder.
    db_query(
        "INSERT INTO `{$prefix}responder` (`name`, `description`)
         VALUES ('phase16a sentinel unit', 'PAR test sentinel')"
    );
    $sentinelResponderId = (int) db_insert_id();
    $respIdForDueAt = $sentinelResponderId;
}
if ($respIdForDueAt > 0) {
    db_query(
        "INSERT INTO `{$prefix}assigns` (ticket_id, responder_id, user_id, dispatched)
         VALUES (?, ?, 1, NOW())",
        [$ticketId, $respIdForDueAt]
    );
}

// ── par_due_at returns a sensible timestamp ─────────────────────────
$due = par_due_at($ticketId);
if (is_int($due) && $due > 0) ok("par_due_at returns timestamp {$due}");
else                          bad('par_due_at', var_export($due, true));

// ── par_initiate_cycle + par_ack_unit ────────────────────────────────
$result = par_initiate_cycle($ticketId, 'manual', null, 'test');
if (isset($result['cycle']) && (int) $result['cycle']['ticket_id'] === $ticketId &&
    $result['cycle']['status'] === 'pending') {
    ok("initiate_cycle: created pending cycle for ticket {$ticketId}");
    $cycleId = (int) $result['cycle']['id'];

    // Confirm par_last_cycle_at was stamped
    $last = db_fetch_value(
        "SELECT par_last_cycle_at FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]);
    if ($last !== null && $last !== '0000-00-00 00:00:00') {
        ok('initiate_cycle stamps ticket.par_last_cycle_at');
    } else {
        bad('par_last_cycle_at not stamped');
    }
} else {
    bad('initiate_cycle', var_export($result, true));
    $cycleId = 0;
}

// abort the cycle (we don't have a real assigned unit so ack is trivial)
if ($cycleId > 0) {
    if (par_abort_cycle($cycleId, null, 'test cleanup')) {
        ok('abort_cycle: marked aborted');
        $row = db_fetch_one("SELECT status FROM `{$prefix}par_cycles` WHERE id = ?", [$cycleId]);
        if ($row && $row['status'] === 'aborted') ok('abort_cycle persists status=aborted');
        else                                     bad('abort status', var_export($row, true));
    } else {
        bad('abort_cycle returned false');
    }
}

// ── Cleanup ──────────────────────────────────────────────────────────
// Remove the assigns row created for par_due_at, any PAR cycles/acks for
// the sentinel, then the sentinel ticket itself.
try {
    db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$ticketId]);
    // Column verified against sql/run_phase16a_par_schema.php: par_cycle_id.
    db_query("DELETE FROM `{$prefix}par_unit_acks` WHERE par_cycle_id IN
              (SELECT id FROM `{$prefix}par_cycles` WHERE ticket_id = ?)", [$ticketId]);
    db_query("DELETE FROM `{$prefix}par_cycles` WHERE ticket_id = ?", [$ticketId]);
    if (!empty($sentinelResponderId)) {
        db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$sentinelResponderId]);
    }
} catch (Exception $e) {
    echo "  (cleanup warning: " . $e->getMessage() . ")\n";
}
if ($isSentinel) {
    db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$ticketId]);
}
// Restore par_enabled to off
db_query(
    "INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('par_enabled','0')
     ON DUPLICATE KEY UPDATE value = VALUES(value)"
);
ok('cleanup');

echo "\n===========================================\n";
echo "Phase 16a PAR: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";
if ($fail > 0) exit(1);
