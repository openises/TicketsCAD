<?php
/**
 * Issue #31 (a beta tester + a beta tester, 2026-07-02) — create the `signals`
 * table on installs that don't have it, so the new-incident Signal
 * dropdown has somewhere to store real codes.
 *
 * Historically the `signals` table shipped alongside `hints` in the
 * v3.44 mysqldump but was inconsistent — some installs shipped
 * without it. The api/incident-types.php endpoint had a fallback to
 * read `hints` when `signals` was empty, but that surfaced tooltip
 * help text (Location - type in location...) as signal codes.
 * Removing that fallback makes an empty install show no signals,
 * which is correct — but installs without the table itself would
 * SQL-error. This migration creates the table so the empty-case is
 * clean.
 *
 * No seed data — agencies add their own signal codes via the config
 * UI (settings.php#signals).
 *
 * Idempotent. Safe to re-run.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Issue #31 — signals table\n";
echo "=========================\n\n";

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}signals` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `code`         VARCHAR(16) NOT NULL,
        `description`  VARCHAR(255) NOT NULL DEFAULT '',
        `sort_order`   INT NOT NULL DEFAULT 0,
        `hide`         ENUM('n','y') NOT NULL DEFAULT 'n',
        `_by`          INT NULL,
        `_from`        VARCHAR(45) NULL,
        `_on`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_code` (`code`),
        KEY `idx_sort` (`sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] signals table ready.\n";
} catch (Exception $e) {
    echo "[ERR] " . $e->getMessage() . "\n";
    if (defined('_INCLUDED_FROM_INSTALLER')) return;
    exit(1);
}

echo "\nDone.\n";
