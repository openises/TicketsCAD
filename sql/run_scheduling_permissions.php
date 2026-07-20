<?php
/**
 * Run scheduling_permissions.sql migration
 *
 * Usage: php sql/run_scheduling_permissions.php
 */

require_once __DIR__ . '/../config.php';

echo "=== Scheduling Permissions Migration ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Create scheduling_permission_profiles ────────────────────
echo "Creating scheduling_permission_profiles table... ";
try {
    db_query("
        CREATE TABLE IF NOT EXISTS `{$prefix}scheduling_permission_profiles` (
            `id`                INT AUTO_INCREMENT PRIMARY KEY,
            `code`              VARCHAR(32) NOT NULL UNIQUE,
            `name`              VARCHAR(64) NOT NULL,
            `description`       VARCHAR(255) DEFAULT NULL,
            `can_view_schedule` TINYINT(1) NOT NULL DEFAULT 1,
            `can_view_own`      TINYINT(1) NOT NULL DEFAULT 1,
            `can_view_others`   TINYINT(1) NOT NULL DEFAULT 0,
            `can_view_available` TINYINT(1) NOT NULL DEFAULT 0,
            `can_self_assign`   TINYINT(1) NOT NULL DEFAULT 0,
            `can_self_remove`   TINYINT(1) NOT NULL DEFAULT 0,
            `can_mark_unavailable` TINYINT(1) NOT NULL DEFAULT 0,
            `can_swap`          TINYINT(1) NOT NULL DEFAULT 0,
            `can_request_cover` TINYINT(1) NOT NULL DEFAULT 0,
            `can_assign_others` TINYINT(1) NOT NULL DEFAULT 0,
            `can_remove_others` TINYINT(1) NOT NULL DEFAULT 0,
            `can_change_status` TINYINT(1) NOT NULL DEFAULT 0,
            `can_manage_slots`  TINYINT(1) NOT NULL DEFAULT 0,
            `sort_order`        INT NOT NULL DEFAULT 50,
            `active`            TINYINT(1) NOT NULL DEFAULT 1,
            `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK\n";
} catch (Exception $e) {
    echo "SKIP (" . $e->getMessage() . ")\n";
}

// ── Create scheduling_permission_assignments ─────────────────
echo "Creating scheduling_permission_assignments table... ";
try {
    db_query("
        CREATE TABLE IF NOT EXISTS `{$prefix}scheduling_permission_assignments` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `profile_id`    INT NOT NULL,
            `scope_type`    ENUM('global','template','event','role') NOT NULL DEFAULT 'global',
            `scope_id`      INT DEFAULT NULL,
            `target_type`   ENUM('all','member','team','member_type') NOT NULL DEFAULT 'all',
            `target_id`     INT DEFAULT NULL,
            `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_scope` (`scope_type`, `scope_id`),
            KEY `idx_target` (`target_type`, `target_id`),
            KEY `idx_profile` (`profile_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK\n";
} catch (Exception $e) {
    echo "SKIP (" . $e->getMessage() . ")\n";
}

// ── Seed default profiles ────────────────────────────────────
echo "Seeding permission profiles... ";
$profiles = [
    ['none',               'No Access',             'Cannot view or interact with scheduling',
     0,0,0,0, 0,0,0,0,0, 0,0,0,0, 10],
    ['view_only',          'View Only',             'Can view the full schedule but cannot make changes',
     1,1,1,1, 0,0,0,0,0, 0,0,0,0, 20],
    ['view_own',           'View Own Only',         'Can only see their own assignments',
     1,1,0,0, 0,0,0,0,0, 0,0,0,0, 30],
    ['view_own_available', 'View Own + Available',  'Can see own assignments and open slots',
     1,1,0,1, 0,0,0,0,0, 0,0,0,0, 40],
    ['self_service',       'Self-Service',          'Can view, sign up, mark unavailable, swap',
     1,1,1,1, 1,1,1,1,1, 0,0,0,0, 50],
    ['team_lead',          'Team Lead',             'Can manage assignments for their team members',
     1,1,1,1, 1,1,1,1,1, 1,1,1,0, 60],
    ['full_control',       'Full Control',          'Complete scheduling management including slot creation',
     1,1,1,1, 1,1,1,1,1, 1,1,1,1, 70],
];

$count = 0;
foreach ($profiles as $p) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}scheduling_permission_profiles`
             (`code`, `name`, `description`,
              `can_view_schedule`, `can_view_own`, `can_view_others`, `can_view_available`,
              `can_self_assign`, `can_self_remove`, `can_mark_unavailable`, `can_swap`, `can_request_cover`,
              `can_assign_others`, `can_remove_others`, `can_change_status`, `can_manage_slots`,
              `sort_order`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            $p
        );
        $count++;
    } catch (Exception $e) {
        // Skip duplicates
    }
}
echo "$count profiles\n";

// ── Set global default: self-service ─────────────────────────
echo "Setting global default permission (self-service)... ";
try {
    $selfService = db_fetch_one(
        "SELECT `id` FROM `{$prefix}scheduling_permission_profiles` WHERE `code` = 'self_service'"
    );
    if ($selfService) {
        $existing = db_fetch_one(
            "SELECT `id` FROM `{$prefix}scheduling_permission_assignments`
             WHERE `scope_type` = 'global' AND `target_type` = 'all'"
        );
        if (!$existing) {
            db_query(
                "INSERT INTO `{$prefix}scheduling_permission_assignments`
                 (`profile_id`, `scope_type`, `scope_id`, `target_type`, `target_id`)
                 VALUES (?, 'global', NULL, 'all', NULL)",
                [(int) $selfService['id']]
            );
            echo "OK\n";
        } else {
            echo "EXISTS\n";
        }
    } else {
        echo "SKIP (profile not found)\n";
    }
} catch (Exception $e) {
    echo "SKIP (" . $e->getMessage() . ")\n";
}

echo "\n=== Migration Complete ===\n";
