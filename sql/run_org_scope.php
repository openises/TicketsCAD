<?php
/**
 * Run Org Scope — Add organization-scope columns to personnel tables.
 *
 * Purpose:  Adds org_id columns to member_types, member_status, teams,
 *           and certifications tables to support multi-organization scoping.
 * Usage:    php sql/run_org_scope.php
 * Prerequisites: config.php; target tables from the membership schema.
 * Safety:   Idempotent. Each ALTER checks information_schema first.
 *           Safe to run multiple times.
 * Output:   [OK]/[SKIP]/[WARN] per column migration.
 */
require_once __DIR__ . '/../config.php';

echo "Phase C: Org-Scope Schema Migrations\n";
echo "=====================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// 1. Add org_id to member_types
try {
    $cols = db_fetch_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$prefix}member_types' AND COLUMN_NAME = 'org_id'"
    );
    if (empty($cols)) {
        db_query("ALTER TABLE `{$prefix}member_types` ADD COLUMN `org_id` INT DEFAULT NULL");
        db_query("ALTER TABLE `{$prefix}member_types` ADD INDEX `idx_org_id` (`org_id`)");
        echo "[OK] Added org_id column to member_types\n";
    } else {
        echo "[SKIP] member_types.org_id already exists\n";
    }
} catch (Exception $e) {
    echo "[WARN] member_types: " . $e->getMessage() . "\n";
}

// 2. Add org_id to ticket
try {
    $cols = db_fetch_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$prefix}ticket' AND COLUMN_NAME = 'org_id'"
    );
    if (empty($cols)) {
        db_query("ALTER TABLE `{$prefix}ticket` ADD COLUMN `org_id` INT DEFAULT NULL");
        db_query("ALTER TABLE `{$prefix}ticket` ADD INDEX `idx_ticket_org` (`org_id`)");
        echo "[OK] Added org_id column to ticket\n";
    } else {
        echo "[SKIP] ticket.org_id already exists\n";
    }
} catch (Exception $e) {
    echo "[WARN] ticket: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
