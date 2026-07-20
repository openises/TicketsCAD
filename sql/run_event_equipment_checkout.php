<?php
/**
 * Migration: Phase 109 Slice C — equipment cache checkout.
 *
 * Adds `equipment_assignments` — a checkout LEDGER (issue → return, with
 * history) so a piece of cache gear can be handed to a PERSON (gear follows the
 * person even if they change teams), returned in one tap, and prompted for
 * return at sign-out. The existing newui_equipment.assigned_member_id is only a
 * "current holder" pointer; this ledger is the audit-grade history the net-
 * control board + per-person ICS-214 need.
 *
 * Also seeds RBAC `action.issue_equipment` (Super Admin + Org Admin +
 * Dispatcher — net control issues gear from the cache).
 *
 * Safety: idempotent. CREATE TABLE IF NOT EXISTS; INSERT IGNORE on the perm +
 * grants, re-asserted every run.
 *
 * Usage: php sql/run_event_equipment_checkout.php  (auto-run by run_migrations.php)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 109 Slice C — equipment checkout ledger\n";

try {
    db_query(
        "CREATE TABLE IF NOT EXISTS `{$prefix}equipment_assignments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `equipment_id` INT NOT NULL,
            `member_id` INT NOT NULL,
            `issued_by` INT NULL,
            `issued_at` DATETIME NOT NULL,
            `returned_at` DATETIME NULL,
            `returned_by` INT NULL,
            KEY `member_idx` (`member_id`),
            KEY `equipment_idx` (`equipment_id`),
            KEY `open_idx` (`returned_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "[OK] equipment_assignments table ready\n";
} catch (Throwable $e) {
    if (stripos($e->getMessage(), 'already exists') !== false) {
        echo "[--] equipment_assignments already present\n";
    } else {
        echo "[WARN] equipment_assignments: " . $e->getMessage() . "\n";
    }
}

// ── RBAC: action.issue_equipment (Super Admin + Org Admin + Dispatcher) ──
try {
    db_query(
        "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
         VALUES (?, ?, ?, ?)",
        ['action.issue_equipment', 'Issue Equipment', 'action',
         'Check cache equipment in/out to personnel (net-control board)']
    );
    $permId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?", ['action.issue_equipment']
    );
    if ($permId > 0) {
        foreach ([1, 2, 3] as $roleId) {
            if (db_fetch_one("SELECT id FROM `{$prefix}roles` WHERE id = ?", [$roleId])) {
                db_query(
                    "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)",
                    [$roleId, $permId]
                );
            }
        }
        echo "[OK] action.issue_equipment ready (Super Admin + Org Admin + Dispatcher)\n";
    } else {
        echo "[WARN] action.issue_equipment: could not resolve permission id\n";
    }
} catch (Exception $e) {
    echo "[WARN] action.issue_equipment: " . $e->getMessage() . "\n";
}

echo "Done.\n";
