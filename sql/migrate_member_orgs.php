<?php
/**
 * Migrate Member Organizations — Link all existing members to org 1.
 *
 * Purpose:  Populates the member_organizations join table by creating an
 *           active membership link for every member to organization #1.
 *           Uses INSERT IGNORE to skip duplicates.
 * Usage:    php sql/migrate_member_orgs.php
 * Prerequisites: config.php; member and member_organizations tables.
 * Safety:   Idempotent. INSERT IGNORE skips existing rows. Safe to re-run.
 * Output:   Total count of member-organization links after migration.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
db()->exec("INSERT IGNORE INTO member_organizations (member_id, org_id, status, created_at)
            SELECT id, 1, 'active', NOW() FROM member WHERE id IS NOT NULL");
$cnt = db()->query('SELECT COUNT(*) FROM member_organizations')->fetchColumn();
echo "$cnt member-org links created\n";
