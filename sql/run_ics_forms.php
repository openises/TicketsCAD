<?php
/**
 * Run ICS Forms — Create the ics_forms table for ICS form storage.
 *
 * Purpose:  Creates the ics_forms table for storing ICS-213, ICS-214,
 *           ICS-202, ICS-205, ICS-205A, and ICS-213RR form data.
 * Usage:    php sql/run_ics_forms.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Idempotent. Uses CREATE TABLE IF NOT EXISTS. Safe to re-run.
 * Output:   [OK]/[WARN] per operation.
 */
require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== ICS Forms Schema Setup ===\n\n";

// ── ics_forms table ──────────────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}ics_forms` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `form_type`       VARCHAR(10)   NOT NULL DEFAULT '213' COMMENT '213, 214, 202, 205, 205a, 213rr',
        `incident_id`     INT           DEFAULT NULL COMMENT 'FK to ticket.id (nullable for standalone forms)',
        `title`           VARCHAR(255)  NOT NULL DEFAULT '',
        `form_data_json`  MEDIUMTEXT    NOT NULL COMMENT 'JSON blob storing all form field values',
        `created_by`      INT           NOT NULL DEFAULT 0 COMMENT 'FK to user.id',
        `created_by_name` VARCHAR(128)  NOT NULL DEFAULT '' COMMENT 'Denormalized user display name',
        `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `status`          VARCHAR(16)   NOT NULL DEFAULT 'draft' COMMENT 'draft, final, sent',
        KEY `idx_ics_form_type`    (`form_type`),
        KEY `idx_ics_incident_id`  (`incident_id`),
        KEY `idx_ics_created_at`   (`created_at`),
        KEY `idx_ics_status`       (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] ics_forms table\n";
} catch (Exception $e) {
    echo "[WARN] " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
