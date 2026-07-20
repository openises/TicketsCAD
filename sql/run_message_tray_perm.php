<?php
/**
 * Migration: RBAC permissions for the dispatcher message tray (Phase 111 Slice B)
 *
 *   screen.message_tray   — see the unified inbound message tray
 *   action.assign_message — log/assign/copy an inbound message to an incident,
 *                           start a sub-incident from it, set/remember a sender
 *
 * Granted by default to Super Admin (1), Org Admin (2), AND Dispatcher (3) —
 * the tray IS the dispatcher's net-control workflow, so dispatchers get it.
 *
 * IMPORTANT: the base "Super Admin gets EVERYTHING" seed in rbac.sql only ran
 * once at install, so permissions added later are NOT retroactively granted.
 * This migration grants them explicitly and re-asserts every run.
 *
 * Safety: idempotent. INSERT IGNORE on permissions + grants.
 *
 * Usage: php sql/run_message_tray_perm.php  (also auto-run by run_migrations.php)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

$perms = [
    ['screen.message_tray',   'Message Tray',    'screen', 'See the unified inbound message tray (all modes)'],
    ['action.assign_message', 'Assign Messages', 'action', 'Log/assign/copy inbound messages to incidents and set senders'],
];

try {
    foreach ($perms as $p) {
        db_query(
            "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
             VALUES (?, ?, ?, ?)",
            $p
        );
        $permId = (int) db_fetch_value(
            "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?",
            [$p[0]]
        );
        if ($permId > 0) {
            foreach ([1, 2, 3] as $roleId) {
                $roleExists = db_fetch_one("SELECT id FROM `{$prefix}roles` WHERE id = ?", [$roleId]);
                if ($roleExists) {
                    db_query(
                        "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)",
                        [$roleId, $permId]
                    );
                }
            }
            echo "[OK] {$p[0]} ready (Super Admin + Org Admin + Dispatcher)\n";
        } else {
            echo "[WARN] {$p[0]}: could not resolve permission id\n";
        }
    }
} catch (Exception $e) {
    echo "[WARN] message-tray perms: " . $e->getMessage() . "\n";
}
