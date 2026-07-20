<?php
/**
 * Run Constituents — Add phone_type columns + widen legacy fields.
 *
 * Purpose:  Brings the constituents table on installs created from the
 *           legacy install_schema.inc.php (no *_type cols, narrow varchars)
 *           up to the schema defined in sql/constituents.sql.
 *
 * Triggered by: Eric on your-server.example.com 2026-06-02 hitting
 *           "Failed to save: SQLSTATE[42S22] Unknown column 'phone_type'".
 *           The legacy bootstrap predates the four `*_type` columns and
 *           the widened varchars; the canonical schema in
 *           sql/constituents.sql has them; no runner ever existed to
 *           reconcile.
 *
 * Usage:    php sql/run_constituents.php
 * Safety:   Idempotent. Each ALTER is guarded by an information_schema
 *           column-existence check (for ADD COLUMN) or runs as MODIFY
 *           (which is a no-op when the column is already the target
 *           shape). Safe to re-run.
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'constituents';

function rc_addCol(string $table, string $col, string $def): void {
    $exists = db_fetch_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $col]
    );
    if (!empty($exists)) { echo "  [skip] {$col} (already in place)\n"; return; }
    try {
        db_query("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
        echo "  [ok]   {$col} added\n";
    } catch (Exception $e) {
        echo "  [fail] {$col} — " . $e->getMessage() . "\n";
    }
}

function rc_modifyCol(string $table, string $col, string $def): void {
    try {
        db_query("ALTER TABLE `{$table}` MODIFY `{$col}` {$def}");
        echo "  [ok]   {$col} → {$def}\n";
    } catch (Exception $e) {
        echo "  [fail] {$col} — " . $e->getMessage() . "\n";
    }
}

echo "=== Constituents schema migration ===\n\n";

echo "Add phone_type columns:\n";
rc_addCol($table, 'phone_type',   "VARCHAR(24) DEFAULT NULL COMMENT 'e.g. Mobile, Home, Work, Day, Night' AFTER `phone`");
rc_addCol($table, 'phone_2_type', "VARCHAR(24) DEFAULT NULL AFTER `phone_2`");
rc_addCol($table, 'phone_3_type', "VARCHAR(24) DEFAULT NULL AFTER `phone_3`");
rc_addCol($table, 'phone_4_type', "VARCHAR(24) DEFAULT NULL AFTER `phone_4`");

echo "\nWiden legacy narrow columns to match sql/constituents.sql:\n";
rc_modifyCol($table, 'contact',       "VARCHAR(96) NOT NULL");
rc_modifyCol($table, 'phone',         "VARCHAR(32) NOT NULL");
rc_modifyCol($table, 'phone_2',       "VARCHAR(32) DEFAULT NULL");
rc_modifyCol($table, 'phone_3',       "VARCHAR(32) DEFAULT NULL");
rc_modifyCol($table, 'phone_4',       "VARCHAR(32) DEFAULT NULL");
rc_modifyCol($table, 'miscellaneous', "VARCHAR(255) DEFAULT NULL");
rc_modifyCol($table, 'state',         "VARCHAR(48) DEFAULT NULL");

// `updated` is DATETIME in sql/constituents.sql but VARCHAR(16) in the
// legacy installer — too narrow for the 19-char Y-m-d H:i:s strings
// the API writes, causing SQLSTATE[22001] "Data too long" on every
// save. Widening to VARCHAR(32) keeps existing legacy rows readable
// AND fits any string the app emits. (The canonical schema's DATETIME
// would be more correct but risks coercion errors on pre-existing
// malformed rows; widen the varchar instead and let a future cleanup
// phase convert.)
rc_modifyCol($table, 'updated',       "VARCHAR(32) DEFAULT NULL");

echo "\n=== done ===\n";
