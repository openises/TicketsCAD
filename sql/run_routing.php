<?php
/**
 * Run Routing — Create cross-protocol message routing tables and RBAC permission.
 *
 * Purpose:  Creates message_routes and routing_log tables for the
 *           cross-protocol message routing engine, and adds the
 *           action.manage_routing RBAC permission.
 * Usage:    php sql/run_routing.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Idempotent. Uses CREATE TABLE IF NOT EXISTS and INSERT IGNORE.
 *           Safe to re-run.
 * Output:   [OK]/[WARN] per step.
 */
require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Cross-Protocol Message Routing Schema Setup ===\n\n";

// ── message_routes table ──────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}message_routes` (
        `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`             VARCHAR(100) NOT NULL,
        `description`      VARCHAR(255) NOT NULL DEFAULT '',
        `enabled`          TINYINT NOT NULL DEFAULT 1,
        `priority`         INT NOT NULL DEFAULT 100,
        `source_channel`   VARCHAR(64) NOT NULL,
        `dest_channel`     VARCHAR(64) NOT NULL,
        `direction`        ENUM('inbound','outbound','both') NOT NULL DEFAULT 'both',
        `filters_json`     TEXT DEFAULT NULL,
        `transform_json`   TEXT DEFAULT NULL,
        `created_by`       INT UNSIGNED DEFAULT NULL,
        `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_enabled`  (`enabled`),
        KEY `idx_source`   (`source_channel`),
        KEY `idx_priority` (`priority`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] message_routes table ready\n";
} catch (Exception $e) {
    echo "[WARN] message_routes: " . $e->getMessage() . "\n";
}

// ── routing_log table ─────────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}routing_log` (
        `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `route_id`          INT UNSIGNED NOT NULL,
        `source_channel`    VARCHAR(64) NOT NULL,
        `dest_channel`      VARCHAR(64) NOT NULL,
        `source_message_id` INT UNSIGNED DEFAULT NULL,
        `dest_message_id`   INT UNSIGNED DEFAULT NULL,
        `status`            ENUM('forwarded','failed','skipped','loop_blocked') NOT NULL DEFAULT 'forwarded',
        `error`             TEXT DEFAULT NULL,
        `payload_summary`   VARCHAR(500) DEFAULT '',
        `routed_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_route`     (`route_id`),
        KEY `idx_routed`    (`routed_at`),
        KEY `idx_source_msg` (`source_message_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] routing_log table ready\n";
} catch (Exception $e) {
    echo "[WARN] routing_log: " . $e->getMessage() . "\n";
}

// ── RBAC permission ───────────────────────────────────────
try {
    $exists = db_fetch_one(
        "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?",
        ['action.manage_routing']
    );
    if (!$exists) {
        db_query(
            "INSERT INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
             VALUES (?, ?, ?, ?)",
            ['action.manage_routing', 'Manage Routing', 'action', 'Create/edit/delete cross-protocol message routing rules']
        );
        $permId = db_insert_id();

        // Grant to Super Admin (role 1), Org Admin (role 2), and Dispatcher (role 3)
        foreach ([1, 2, 3] as $roleId) {
            try {
                db_query(
                    "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)",
                    [$roleId, $permId]
                );
            } catch (Exception $e) {
                // Role might not exist in this install
            }
        }
        echo "[OK] RBAC permission action.manage_routing added (granted to Super Admin, Org Admin, Dispatcher)\n";
    } else {
        echo "[OK] RBAC permission action.manage_routing already exists\n";
    }
} catch (Exception $e) {
    // Permissions table might not exist yet (RBAC not installed)
    echo "[WARN] RBAC: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
