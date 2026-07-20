<?php
/**
 * Personal-unit schema at provision time (caught by CI's first-ever run,
 * 2026-07-08).
 *
 * responder.personal_for_member_id (+ the guaranteed off-shift status)
 * was only created lazily by pu_ensure_schema() when someone first
 * clocked in — but api/mesh.php and api/zello-inbox.php SELECT the
 * column unconditionally, so a fresh install crashed those paths until
 * the first clock-in happened to run. Provision it up front via the
 * canonical ensure (idempotent).
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/audit.php';
require_once 'inc/personnel-units.php';

echo "Personal-unit schema provisioning\n";
echo "=================================\n\n";

try {
    pu_ensure_schema();
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $has = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = 'personal_for_member_id'",
        [$prefix . 'responder']
    );
    if (!$has) {
        fwrite(STDERR, "ERROR: personal_for_member_id still missing after ensure\n");
        exit(1);
    }
    echo "ok: responder.personal_for_member_id present, off-shift status guaranteed\n";
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone.\n";
