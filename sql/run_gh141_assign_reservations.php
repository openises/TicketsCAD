<?php
/**
 * GH#141 (Phase 155) — unit reservations on Scheduled incidents.
 *
 * specs/phase-155-community-backlog/141-scheduled-calls-and-147-status-webhook.md
 *
 * WHAT THIS CREATES:
 *   - `assign_reservations`: a unit committed to a Scheduled incident that has
 *     NOT started. A reservation is deliberately NOT an `assigns` row. About 45
 *     readers and three writers across the app treat "any uncleared assigns
 *     row" as "this unit is busy / has live work" (the unit board, callboard,
 *     statistics, the dispatch gate, responder_set_status_internal(),
 *     incident_clear_stragglers()...). Keeping a reservation OUT of that table
 *     means none of them changes -- by construction -- and `assigns.dispatched`
 *     is still stamped when the unit is REALLY dispatched, so the Intervals
 *     report stays truthful. At the booked time (or `scheduled_assign_lead_
 *     minutes` before) each reservation is promoted by calling the ordinary
 *     assign_create_internal().
 *   - Settings `scheduled_assign_mode` = 'immediate' (today's behaviour, the
 *     default on every install fresh or upgraded) and
 *     `scheduled_assign_lead_minutes` = '0'. Written to the `settings` table --
 *     the store get_variable() reads (NOT the separate `config` table read by
 *     get_setting(); CLAUDE.md "TWO settings stores").
 *
 * `active_key` applies the Phase 129 lesson with a PLAIN column instead of a
 * generated one (Phase 144 declined generated columns for unknown self-hosted
 * MariaDB): it is 1 while a reservation is live (pending or blocked) and NULL
 * once finished. NULLs are distinct in a unique index, so any number of finished
 * rows coexist, while two LIVE rows for one (ticket, unit) collide -- a real
 * constraint. This script PROVES that by asking the database to accept a
 * duplicate (never by reading DDL), and exits non-zero if it does.
 *
 * Idempotent and self-sufficient in any order: it needs nothing another
 * migration creates. Verifies its own outcome and exits non-zero on failure
 * (CLAUDE.md, Phase 128 A9: a migration that catches its own exception and exits
 * 0 is a migration that never ran).
 *
 * Usage: php sql/run_gh141_assign_reservations.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'assign_reservations';
$fail   = [];

echo "GH#141 — unit reservations on Scheduled incidents\n";
echo "=================================================\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 1. The table
// ─────────────────────────────────────────────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$table}` (
        `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `ticket_id`          INT NOT NULL,
        `responder_id`       INT NOT NULL,
        `role`               VARCHAR(64) NOT NULL DEFAULT '',
        `reserved_by`        INT NOT NULL,
        `reserved_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `state`              ENUM('pending','promoted','cancelled','blocked') NOT NULL DEFAULT 'pending',
        `active_key`         TINYINT NULL DEFAULT 1,
        `closed_at`          DATETIME NULL DEFAULT NULL,
        `outcome_note`       VARCHAR(255) NULL DEFAULT NULL,
        `promoted_assign_id` INT NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_active` (`ticket_id`, `responder_id`, `active_key`),
        KEY `idx_ticket_state` (`ticket_id`, `state`),
        KEY `idx_responder_state` (`responder_id`, `state`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] table {$table} present\n";
} catch (Exception $e) {
    $fail[] = "table {$table}: " . $e->getMessage();
    echo "[FAIL] table {$table}: " . $e->getMessage() . "\n";
}

// An install whose table pre-dates a column (none yet) would be healed here;
// listing the expected columns now means a future column is one entry away.
$expectedCols = ['id', 'ticket_id', 'responder_id', 'role', 'reserved_by', 'reserved_at', 'state',
                 'active_key', 'closed_at', 'outcome_note', 'promoted_assign_id'];

// ─────────────────────────────────────────────────────────────────────────
// 2. Default settings -- 'immediate' / '0' (no behaviour change on upgrade)
// ─────────────────────────────────────────────────────────────────────────
foreach (['scheduled_assign_mode' => 'immediate', 'scheduled_assign_lead_minutes' => '0'] as $name => $default) {
    try {
        $exists = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
        if ($exists === 0) {
            db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$name, $default]);
            echo "[OK] setting seeded: {$name} = {$default}\n";
        } else {
            echo "[OK] setting exists: {$name}\n";
        }
    } catch (Exception $e) {
        $fail[] = "setting {$name}: " . $e->getMessage();
        echo "[FAIL] setting {$name}: " . $e->getMessage() . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 3. Verify the OUTCOME
// ─────────────────────────────────────────────────────────────────────────
try {
    $have = array_column(db_fetch_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]), 'COLUMN_NAME');
    foreach ($expectedCols as $col) {
        if (!in_array($col, $have, true)) $fail[] = "verify: {$table}.{$col} does not exist";
    }

    foreach (['scheduled_assign_mode', 'scheduled_assign_lead_minutes'] as $name) {
        if ((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", [$name]) === 0) {
            $fail[] = "verify: setting {$name} does not exist";
        }
    }

    // The unique key must REALLY bind live rows and NOT bind finished ones.
    // Ask the database to accept a duplicate; clean up whatever happens.
    $probeTicket = 2147480001; $probeUnit = 2147480002;
    $cleanup = function () use ($table, $probeTicket, $probeUnit) {
        try { db_query("DELETE FROM `{$table}` WHERE `ticket_id` = ? AND `responder_id` = ?", [$probeTicket, $probeUnit]); }
        catch (Exception $e) { /* best effort */ }
    };
    $cleanup();
    $ins = "INSERT INTO `{$table}` (`ticket_id`, `responder_id`, `role`, `reserved_by`, `state`, `active_key`)
            VALUES (?, ?, '', 0, ?, ?)";
    db_query($ins, [$probeTicket, $probeUnit, 'pending', 1]);
    $dupRejected = false;
    try {
        db_query($ins, [$probeTicket, $probeUnit, 'pending', 1]);
    } catch (Exception $e) {
        $dupRejected = (strpos($e->getMessage(), '1062') !== false || strpos($e->getMessage(), '23000') !== false);
    }
    if (!$dupRejected) {
        $fail[] = 'verify: the database ACCEPTED two live reservations for one (ticket, unit) -- uk_active does not bind';
    } else {
        echo "[OK] uk_active rejects a second live reservation for the same ticket+unit\n";
    }
    $finishedOk = true;
    try {
        db_query($ins, [$probeTicket, $probeUnit, 'cancelled', null]);
        db_query($ins, [$probeTicket, $probeUnit, 'promoted', null]);
    } catch (Exception $e) {
        $finishedOk = false;
        $fail[] = 'verify: finished (active_key NULL) rows collide -- history cannot accumulate: ' . $e->getMessage();
    }
    if ($finishedOk) echo "[OK] finished reservations (active_key NULL) coexist freely\n";
    $cleanup();
} catch (Exception $e) {
    $fail[] = 'verify: ' . $e->getMessage();
}

if ($fail) {
    echo "\nFAILED:\n  - " . implode("\n  - ", $fail) . "\n";
    exit(1);
}

echo "\nDone. Unit reservations (GH#141) installed; scheduled_assign_mode stays 'immediate' until an admin changes it.\n";
exit(0);
