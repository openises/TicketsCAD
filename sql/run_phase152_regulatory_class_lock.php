<?php
/**
 * Phase 152 (Communications Console v2) — regulatory_class becomes real
 * per-channel admin config, prerequisite #1.
 *
 * comm_channels.regulatory_class has existed since Phase 114a, but
 * channel_registry_sync() (inc/channel_registry.php) treats it as a
 * DERIVED field and silently overwrites it back to the adapter catalog's
 * default on every sync -- confirmed in the tree, not theoretical. An
 * admin who manually reclassifies a channel (e.g. a DVMProject P25 network
 * that is actually Part 90, not amateur; a SIP line that is an internal
 * intercom extension, not PSTN) would have that choice silently reverted
 * the next time anything calls channel_registry_sync().
 *
 * This migration adds the lock column only. The sync-function fix lives in
 * inc/channel_registry.php (skip the regulatory_class refresh when
 * locked=1) and the admin UI/API fix lives in api/channels.php's `update`
 * action (setting the lock automatically on the first manual change) --
 * see specs/phase-152-comms-console-v2/tasks.md, Prerequisite 1.
 *
 * Idempotent -- safe to run repeatedly; picked up by run_migrations.php.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 152 -- regulatory_class_locked column\n";
echo "============================================\n\n";

try {
    $col = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = 'regulatory_class_locked'",
        [$prefix . 'comm_channels']
    );
    if ((int) $col === 0) {
        db_query(
            "ALTER TABLE `{$prefix}comm_channels`
                ADD COLUMN `regulatory_class_locked` TINYINT(1) NOT NULL DEFAULT 0
                AFTER `regulatory_class`"
        );
        echo "[OK] added comm_channels.regulatory_class_locked\n";
    } else {
        echo "[OK] comm_channels.regulatory_class_locked already exists\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "[FAIL] " . $e->getMessage() . "\n");
    exit(1);
}

// Verify (Phase 128 A9b lesson: a migration that doesn't check its own
// outcome is a migration that might not have run at all).
$verify = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = 'regulatory_class_locked'",
    [$prefix . 'comm_channels']
);
if ((int) $verify !== 1) {
    fwrite(STDERR, "[FAIL] verification: regulatory_class_locked column not found after migration\n");
    exit(1);
}

echo "\n[OK] verified.\n";
exit(0);
