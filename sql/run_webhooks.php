<?php
/**
 * Run Webhooks — Create webhook delivery tracking table.
 *
 * Purpose:  Creates webhook_deliveries for outbound event notification
 *           delivery with retry tracking.
 * Usage:    php sql/run_webhooks.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Idempotent. Uses CREATE TABLE IF NOT EXISTS. Safe to re-run.
 * Output:   [OK]/[WARN] per table creation.
 *
 * B20 (SPEC-STATUS.md, 2026-08-21): this script used to ALSO create a bare
 * `webhooks` table (name/url/secret/events_json/retry_max). Every live code
 * path uses `webhook_subscriptions` instead (inc/webhooks.php says so
 * explicitly) — the legacy table had zero readers or writers anywhere in
 * the app; api/webhooks.php never inserted into it, only into
 * webhook_subscriptions. It was absent from sql/schema_manifest.json. The
 * CREATE TABLE for it is removed here so it stops appearing on fresh
 * installs; sql/run_facility_capacity_legacy_table_drop.php's B20 sibling,
 * sql/run_webhooks_legacy_table_drop.php, drops it (empty-check-or-refuse)
 * on installs that already have it. Do not re-add the CREATE TABLE below
 * without re-reading the B20 writeup in specs/SPEC-STATUS.md first.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Webhooks Schema Setup ===\n\n";

// ── webhook_deliveries table ────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}webhook_deliveries` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `webhook_id`   INT          NOT NULL,
        `event_type`   VARCHAR(64)  NOT NULL DEFAULT '',
        `payload`      TEXT         NOT NULL,
        `http_status`  INT          DEFAULT NULL,
        `response_body` TEXT        DEFAULT NULL,
        `duration_ms`  INT          DEFAULT NULL,
        `attempt`      TINYINT     NOT NULL DEFAULT 1,
        `status`       VARCHAR(16) NOT NULL DEFAULT 'pending',
        `error`        VARCHAR(512) DEFAULT NULL,
        `delivery_uid` VARCHAR(36)  DEFAULT NULL,
        `created_at`   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_wd_webhook_id` (`webhook_id`),
        KEY `idx_wd_event_type` (`event_type`),
        KEY `idx_wd_status` (`status`),
        KEY `idx_wd_delivery_uid` (`delivery_uid`),
        KEY `idx_wd_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] webhook_deliveries table\n";
} catch (Exception $e) { echo "[WARN] " . $e->getMessage() . "\n"; }

echo "\nDone.\n";
