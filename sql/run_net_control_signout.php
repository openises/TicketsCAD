<?php
/**
 * Migration: Net Control Board — sign-out tray (Phase 109 Slice B)
 *
 * Adds `assigns.signed_out_at` so net control can explicitly sign a unit OUT
 * of an event. A signed-out unit drops off the active board into a "signed
 * out" tray (and, in Slice C, its issued equipment is flagged for return).
 * This closes the "vanished without signing out" gap from Eric's real op.
 *
 * Sign-out is independent of the unit's global status and of its zone
 * (decision #3): signing out does not wipe the zone; signing back in restores
 * the unit to the active board with its zone intact.
 *
 * Safety: idempotent. Column-add guarded against information_schema; a re-run
 * is a no-op. Touches nothing on a non-event install (column defaults NULL).
 *
 * Usage: php sql/run_net_control_signout.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

/** Return true if a column already exists on a table. */
function _ncso_col_exists(string $table, string $col): bool {
    try {
        return (bool) db_fetch_one(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $col]
        );
    } catch (Throwable $e) {
        // Fall back to a probe SELECT if information_schema is unreadable.
        try { db_query("SELECT `{$col}` FROM `{$table}` LIMIT 0"); return true; }
        catch (Throwable $e2) { return false; }
    }
}

echo "Net Control — sign-out tray (Phase 109 Slice B)\n";

$table = $prefix . 'assigns';
if (_ncso_col_exists($table, 'signed_out_at')) {
    echo "[--] assigns.signed_out_at already present\n";
} else {
    try {
        db_query("ALTER TABLE `{$table}` ADD COLUMN `signed_out_at` DATETIME DEFAULT NULL");
        echo "[OK] assigns.signed_out_at added\n";
    } catch (Throwable $e) {
        // Concurrent apply race — treat "duplicate column" as success.
        if (stripos($e->getMessage(), 'duplicate') !== false) {
            echo "[--] assigns.signed_out_at already present (race)\n";
        } else {
            echo "[WARN] assigns.signed_out_at: " . $e->getMessage() . "\n";
        }
    }
}

echo "Done.\n";
