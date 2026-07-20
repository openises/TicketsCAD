<?php
/**
 * QA-sweep #1 — add the member_organizations.role column
 *
 * The Roster org-membership Edit modal writes a string role
 * (admin/manager/member/viewer) and reads it back for the badge + the edit
 * form (roster.js reads mo.role at lines 1329/1450, posts role at 1486;
 * api/organizations.php update_member_org writes `role = ?`). But the table
 * only ever had `role_id INT` ("Future RBAC role reference", never wired), so
 * every save that included a role threw "Unknown column 'role'" — and because
 * member_type_id, status, join_date and notes share that one UPDATE, the
 * WHOLE modal save was lost, showing a confusing "Failed to update".
 *
 * Adding the string `role` column the read+write paths already expect makes
 * the feature work end-to-end (write, read-back, badge) and stops the data
 * loss. Idempotent — safe to re-run; picked up by run_migrations.php.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "QA #1 — member_organizations.role column\n";
echo "=======================================\n\n";

$have = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?", [$prefix . 'member_organizations']);
if (!$have) {
    echo "member_organizations table absent — nothing to do (fresh org schema\n";
    echo "will create it; add the column there if this predates that).\n";
    return;
}

$hasCol = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'role'",
    [$prefix . 'member_organizations']);

if ($hasCol) {
    echo "role column already present — nothing to do.\n";
} else {
    try {
        db_query("ALTER TABLE `{$prefix}member_organizations`
                  ADD COLUMN `role` VARCHAR(32) NULL AFTER `role_id`");
        echo "Added member_organizations.role VARCHAR(32).\n";
    } catch (Exception $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
