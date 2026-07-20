<?php
/**
 * Run Phase 9 — Force password change on first login
 *
 * Purpose:  Adds the schema bits that back the "must change password
 *           on next login" feature requested by Eric 2026-06-08.
 *
 *           Adds:
 *             - user.must_change_password TINYINT(1) NOT NULL DEFAULT 0
 *             - index on that column
 *             - settings row 'force_pw_change_for_new_users' default '1'
 *
 *           Existing user rows get must_change_password = 0 so this
 *           rolls out non-disruptively. Only NEWLY-created accounts
 *           inherit the system default after the migration runs.
 *
 * Usage:    php sql/run_force_pw_change.php
 * Prereqs:  config.php with valid DB credentials.
 * Safety:   Idempotent. Guards every ALTER / INDEX add with
 *           information_schema lookups. INSERT IGNORE for the setting.
 *           Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 9 — Force password change on first login\n";
echo "==============================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ─── 1. Add user.must_change_password column ──────────────────────────────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = 'must_change_password'",
        [$prefix . 'user']
    );
    if (!$col) {
        db_query(
            "ALTER TABLE `{$prefix}user`
             ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0"
        );
        echo "[OK] Added user.must_change_password column (default 0)\n";
    } else {
        echo "[OK] user.must_change_password already exists (skipped)\n";
    }
} catch (Exception $e) {
    echo "[WARN] add column: " . $e->getMessage() . "\n";
}

// ─── 2. Add supporting index ──────────────────────────────────────────────
try {
    $idx = db_fetch_one(
        "SELECT INDEX_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND INDEX_NAME   = 'idx_must_change_password'
         LIMIT 1",
        [$prefix . 'user']
    );
    if (!$idx) {
        db_query(
            "ALTER TABLE `{$prefix}user`
             ADD KEY `idx_must_change_password` (`must_change_password`)"
        );
        echo "[OK] Added idx_must_change_password index\n";
    } else {
        echo "[OK] idx_must_change_password already exists (skipped)\n";
    }
} catch (Exception $e) {
    echo "[WARN] add index: " . $e->getMessage() . "\n";
}

// ─── 3. Seed the system setting (default ON) ──────────────────────────────
try {
    db_query(
        "INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`)
         VALUES ('force_pw_change_for_new_users', '1')"
    );
    echo "[OK] Setting force_pw_change_for_new_users seeded (default: '1')\n";
} catch (Exception $e) {
    echo "[WARN] seed setting: " . $e->getMessage() . "\n";
}

// ─── 4. Report final state ────────────────────────────────────────────────
try {
    $total       = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user`");
    $flaggedRows = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user` WHERE must_change_password = 1"
    );
    $setting = db_fetch_value(
        "SELECT value FROM `{$prefix}settings` WHERE name = 'force_pw_change_for_new_users' LIMIT 1"
    );

    echo "\nFinal state:\n";
    echo "  Users in table:                {$total}\n";
    echo "  Users flagged to change pw:    {$flaggedRows}\n";
    echo "  force_pw_change_for_new_users: " . ($setting ?? 'NULL') . "\n";
} catch (Exception $e) {
    echo "[WARN] report: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
