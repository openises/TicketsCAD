<?php
/**
 * Phase 105 (a beta tester GH #16) — Conditional / Stateful Unit Statuses.
 *
 * Creates the status-workflow schema:
 *   - status_transitions        allowed (from_status_id -> to_status_id)
 *                               edges with optional conditions_json.
 *                               from_status_id = 0 is the synthetic "ANY"
 *                               source ("reachable from any status").
 *   - status_workflow_layout    persisted designer node positions.
 *
 * Seeds the RBAC permission action.manage_status_workflow, granted to
 * Super Admin + Org Admin.
 *
 * Enforcement mode lives in the settings table row
 * `status_workflow_mode` ('off' | 'warn' | 'enforce'); a missing row
 * means 'off' so this migration deliberately does NOT create it —
 * fully backwards compatible until an admin opts in via the designer.
 *
 * Run: php sql/run_status_workflow.php
 * Safety: idempotent — CREATE TABLE IF NOT EXISTS + INSERT IGNORE.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$hadError = false;

echo "=== Status Workflow Schema Setup (Phase 105) ===\n\n";

// ── status_transitions table ─────────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}status_transitions` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `from_status_id`  INT NOT NULL,
        `to_status_id`    INT NOT NULL,
        `conditions_json` TEXT NULL,
        `created_by`      INT NULL,
        `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_edge` (`from_status_id`, `to_status_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] status_transitions table ready\n";
} catch (Exception $e) {
    echo "[ERR] status_transitions: " . $e->getMessage() . "\n";
    $hadError = true;
}

// ── status_workflow_layout table ─────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}status_workflow_layout` (
        `status_id` INT PRIMARY KEY,
        `pos_x`     INT NOT NULL DEFAULT 0,
        `pos_y`     INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] status_workflow_layout table ready\n";
} catch (Exception $e) {
    echo "[ERR] status_workflow_layout: " . $e->getMessage() . "\n";
    $hadError = true;
}

// ── RBAC permission ──────────────────────────────────────────────
// Same idempotent pattern as sql/run_routing.php: INSERT the permission
// once, then INSERT IGNORE the role grants (Super Admin + Org Admin).
try {
    $exists = db_fetch_one(
        "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?",
        ['action.manage_status_workflow']
    );
    if (!$exists) {
        db_query(
            "INSERT INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
             VALUES (?, ?, ?, ?)",
            [
                'action.manage_status_workflow',
                'Manage Status Workflow',
                'action',
                'Design allowed unit-status transitions and set the enforcement mode',
            ]
        );
        $permId = (int) db_insert_id();
    } else {
        $permId = (int) $exists['id'];
    }

    // Grant to Super Admin (role 1) + Org Admin (role 2). Resolve by
    // role name first (robust across installs), fall back to legacy IDs.
    $roleIds = [];
    foreach (['Super Admin', 'Org Admin'] as $roleName) {
        try {
            $rid = db_fetch_value(
                "SELECT id FROM `{$prefix}roles` WHERE `name` = ? LIMIT 1",
                [$roleName]
            );
            if ($rid) $roleIds[] = (int) $rid;
        } catch (Exception $e) { /* roles table variant */ }
    }
    if (empty($roleIds)) $roleIds = [1, 2];

    foreach ($roleIds as $roleId) {
        try {
            db_query(
                "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)",
                [$roleId, $permId]
            );
        } catch (Exception $e) {
            // Role might not exist in this install — non-fatal
        }
    }
    echo $exists
        ? "[--] RBAC permission action.manage_status_workflow already exists (grants re-asserted)\n"
        : "[OK] RBAC permission action.manage_status_workflow added (granted to Super Admin, Org Admin)\n";
} catch (Exception $e) {
    // Permissions table might not exist yet (RBAC not installed) —
    // that is a soft condition on very old installs, but for a NewUI
    // migration we treat it as a warning, not a hard failure.
    echo "[--] RBAC: " . $e->getMessage() . "\n";
}

if ($hadError) {
    echo "\n[ERR] Migration finished with errors.\n";
    exit(1);
}

echo "\nDone.\n";
