<?php
/**
 * Phase 115 — facility_notes: append-only history for the facilities widget
 * quick-action bar (status changes, free-text notes, bed-count updates).
 *
 * The facility analog of the incident `action` log. Denormalizes the acting
 * username so a row survives user deletion (audit-trail standing rule).
 *
 * Idempotent — CREATE TABLE IF NOT EXISTS; safe to run repeatedly.
 */

if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('CLI or migration-runner only');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 115 — facility_notes\n";

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}facility_notes` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `facility_id`  INT NOT NULL,
        `category`     ENUM('note','status','beds') NOT NULL DEFAULT 'note',
        `note`         VARCHAR(1000) NOT NULL DEFAULT '',
        `detail`       VARCHAR(255) DEFAULT NULL COMMENT 'structured summary, e.g. status label or bed delta',
        `user_id`      INT NOT NULL DEFAULT 0,
        `username`     VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'denormalized so it survives user deletion',
        `source_ip`    VARCHAR(45) DEFAULT NULL,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_facility` (`facility_id`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] facility_notes table ready\n";
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

echo "Done.\n";
