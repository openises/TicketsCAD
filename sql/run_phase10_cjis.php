<?php
/**
 * Run Phase 10 — CJIS hardening: schema + settings.
 *
 * Purpose:  Adds the schema backing for:
 *             - configurable minimum password length
 *             - configurable password history retention
 *             - configurable password rotation reminder + snooze
 *
 *           Existing users get password_changed_at backfilled to NOW()
 *           so the migration is non-disruptive — no user is suddenly
 *           told "your password is 5 years old, change it now."
 *
 * Usage:    php sql/run_phase10_cjis.php
 * Prereqs:  config.php with valid DB credentials.
 * Safety:   Idempotent. Guards all ALTER / CREATE with
 *           information_schema lookups. INSERT IGNORE for settings.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 10 — CJIS hardening migration\n";
echo "===================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ─── 1. user.password_changed_at column ───────────────────────────────────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = 'password_changed_at'",
        [$prefix . 'user']
    );
    if (!$col) {
        db_query(
            "ALTER TABLE `{$prefix}user`
             ADD COLUMN `password_changed_at` DATETIME NULL DEFAULT NULL"
        );
        echo "[OK] Added user.password_changed_at column\n";
    } else {
        echo "[OK] user.password_changed_at already exists (skipped)\n";
    }
} catch (Exception $e) {
    echo "[WARN] password_changed_at column: " . $e->getMessage() . "\n";
}

// ─── 2. user.password_reminder_snoozed_until column ───────────────────────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = 'password_reminder_snoozed_until'",
        [$prefix . 'user']
    );
    if (!$col) {
        db_query(
            "ALTER TABLE `{$prefix}user`
             ADD COLUMN `password_reminder_snoozed_until` DATETIME NULL DEFAULT NULL"
        );
        echo "[OK] Added user.password_reminder_snoozed_until column\n";
    } else {
        echo "[OK] user.password_reminder_snoozed_until already exists (skipped)\n";
    }
} catch (Exception $e) {
    echo "[WARN] snoozed_until column: " . $e->getMessage() . "\n";
}

// ─── 3. Charitable backfill of password_changed_at ────────────────────────
// Set NOW() for any NULL row so existing users don't get ambushed by the
// rotation reminder the moment migration runs. The reminder fires only
// after password_rotation_reminder_days elapses from this backfill, so
// existing users get the default 180-day grace period from migration day.
try {
    $backfilled = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user` WHERE password_changed_at IS NULL"
    );
    if ($backfilled > 0) {
        db_query(
            "UPDATE `{$prefix}user` SET password_changed_at = NOW()
             WHERE password_changed_at IS NULL"
        );
        echo "[OK] Backfilled password_changed_at on {$backfilled} existing user(s)\n";
    } else {
        echo "[OK] password_changed_at already populated (no backfill needed)\n";
    }
} catch (Exception $e) {
    echo "[WARN] backfill: " . $e->getMessage() . "\n";
}

// ─── 4. user_password_history table ───────────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}user_password_history` (
        `id`          INT          AUTO_INCREMENT PRIMARY KEY,
        `user_id`     INT          NOT NULL,
        `hash`        VARCHAR(255) NOT NULL,
        `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_user_created` (`user_id`, `created_at` DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] user_password_history table ready\n";
} catch (Exception $e) {
    echo "[WARN] user_password_history: " . $e->getMessage() . "\n";
}

// ─── 5. Settings ─────────────────────────────────────────────────────────
$settings = [
    ['password_min_length',                '8',
        'Minimum password length (CJIS recommended: 8)'],
    ['password_history_count',             '10',
        'Last N password hashes retained per user (CJIS recommended: 10)'],
    ['password_rotation_reminder_days',    '180',
        'Days after which the user sees a "consider rotating" banner (0 = disabled)'],
    ['password_rotation_snooze_days',      '10',
        'How long the snooze button defers the reminder (0 = re-prompt every login)'],
];

foreach ($settings as $s) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
            [$s[0], $s[1]]
        );
        // Report current value (may differ from default if admin already changed it)
        $current = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
            [$s[0]]
        );
        echo "[OK] {$s[0]} = '{$current}' ({$s[2]})\n";
    } catch (Exception $e) {
        echo "[WARN] setting {$s[0]}: " . $e->getMessage() . "\n";
    }
}

// ─── 6. Report final state ────────────────────────────────────────────────
try {
    $totalUsers = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user`");
    $withChanged = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user` WHERE password_changed_at IS NOT NULL"
    );
    $historyRows = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user_password_history`"
    );

    echo "\nFinal state:\n";
    echo "  Users in table:                   {$totalUsers}\n";
    echo "  With password_changed_at set:     {$withChanged}\n";
    echo "  Password history rows recorded:   {$historyRows}\n";
    echo "    (history populates on subsequent password changes)\n";
} catch (Exception $e) {
    echo "[WARN] report: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
