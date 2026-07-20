<?php
/**
 * Migration: `file_uploads` table (QA hardening, 2026-07-07).
 *
 * Historically this table was created LAZILY by api/upload.php on the
 * first upload request. That left virgin installs without the table
 * until someone uploaded a file, which (a) made the schema-audit gate
 * (tools/schema_audit.php) report api/upload.php's queries as targeting
 * a missing table on any fresh install, and (b) meant list/read paths
 * touching file_uploads before the first upload relied on try/catch
 * fallbacks. Provisioning now creates it up front.
 *
 * DDL mirrors api/upload.php's lazy CREATE exactly (that copy remains as
 * a self-heal for pre-migration installs). Idempotent — CREATE TABLE IF
 * NOT EXISTS, safe to re-run.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}file_uploads` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `entity_type` VARCHAR(32)  NOT NULL COMMENT 'incident, member, facility, etc.',
        `entity_id`   INT          NOT NULL,
        `filename`    VARCHAR(255) NOT NULL,
        `orig_name`   VARCHAR(255) NOT NULL,
        `mime_type`   VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
        `file_size`   BIGINT       NOT NULL DEFAULT 0,
        `file_path`   VARCHAR(512) NOT NULL,
        `uploaded_by` INT          NOT NULL DEFAULT 0,
        `uploaded_by_name` VARCHAR(64) NOT NULL DEFAULT '',
        `description` VARCHAR(255) DEFAULT '',
        `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_entity` (`entity_type`, `entity_id`),
        KEY `idx_uploaded_by` (`uploaded_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] file_uploads table ready\n";
} catch (Exception $e) {
    echo "[FAIL] file_uploads: " . $e->getMessage() . "\n";
    exit(1);
}
