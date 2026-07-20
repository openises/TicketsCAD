<?php
/**
 * Migration: widen unit_types.name from varchar(16) → varchar(32)
 * (Eric, 2026-07-05, GH #61 follow-up)
 *
 * The legacy unit_types.name column is only 16 chars, which is too short for
 * real unit-type names ("Command Vehicle", "Rescue Squad", etc.). Double it.
 *
 * Safety: idempotent. Reads the current length from information_schema and only
 * ALTERs when it is under 32. Widening never truncates existing data, and the
 * NOT NULL constraint is preserved.
 *
 * Usage: php sql/run_unit_types_name_widen.php  (also auto-run by run_migrations.php)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'unit_types';

echo "Widen unit_types.name → varchar(32)\n";

try {
    $len = db_fetch_value(
        "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'name'",
        [$table]
    );
    if ($len === null || $len === false) {
        echo "[--] unit_types.name not found (table absent on this install) — skipping\n";
    } elseif ((int) $len >= 32) {
        echo "[--] unit_types.name already >= 32 ({$len}) — no change\n";
    } else {
        db_query("ALTER TABLE `{$table}` MODIFY `name` VARCHAR(32) NOT NULL");
        echo "[OK] unit_types.name widened {$len} → 32\n";
    }
} catch (Throwable $e) {
    echo "[WARN] unit_types.name widen: " . $e->getMessage() . "\n";
}

echo "Done.\n";
