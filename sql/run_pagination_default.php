<?php
/**
 * Phase 118 — seed the default list page size.
 *
 * The `page_size` setting drives client-side pagination on list screens
 * (units.php first). get_variable('page_size') falls back to 50 when absent,
 * so this seed is not strictly required for function — but seeding it makes the
 * Settings UI show the current value and follows the project's "seed feature
 * defaults" convention.
 *
 * Idempotent: INSERT IGNORE only writes when the row is missing, so it never
 * overwrites an admin's chosen value. Auto-discovered + tracked by
 * sql/run_migrations.php (glob run_*.php).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 118 — list pagination default\n";
echo "===================================\n";

try {
    $before = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = 'page_size'");
    db_query(
        "INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES ('page_size', '50')"
    );
    $current = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'page_size'");
    if ((int) $before > 0) {
        echo "  [skip] page_size already set to '{$current}' — left unchanged.\n";
    } else {
        echo "  [ok]   page_size seeded to '{$current}'.\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "  [fail] could not seed page_size: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Phase 118 complete. Re-run is idempotent.\n";
exit(0);
