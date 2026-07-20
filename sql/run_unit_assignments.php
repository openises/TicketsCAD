<?php
/**
 * Run unit_assignments.sql migration
 *
 * Usage: php sql/run_unit_assignments.php
 */

require_once __DIR__ . '/../config.php';

echo "=== Unit Assignments Migration ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Create unit_personnel_assignments table ─────────────────
echo "Creating unit_personnel_assignments table... ";
try {
    db_query("
        CREATE TABLE IF NOT EXISTS `{$prefix}unit_personnel_assignments` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `responder_id`  INT NOT NULL COMMENT 'FK to responder (the unit)',
            `member_id`     INT NOT NULL COMMENT 'FK to member (the person)',
            `role`          VARCHAR(32) NOT NULL DEFAULT 'operator' COMMENT 'operator, driver, observer, commander, medic',
            `status`        ENUM('active','standby','released') NOT NULL DEFAULT 'active',
            `assigned_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `released_at`   DATETIME DEFAULT NULL COMMENT 'NULL = still assigned',
            `assigned_by`   INT DEFAULT NULL COMMENT 'User ID who made the assignment',
            `notes`         VARCHAR(255) DEFAULT NULL,
            KEY `idx_upa_responder` (`responder_id`),
            KEY `idx_upa_member`    (`member_id`),
            KEY `idx_upa_status`    (`status`),
            KEY `idx_upa_active`    (`responder_id`, `status`, `released_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK\n";
} catch (Exception $e) {
    echo "SKIP (" . $e->getMessage() . ")\n";
}

// ── Create unit_assignment_roles table ──────────────────────
echo "Creating unit_assignment_roles table... ";
try {
    db_query("
        CREATE TABLE IF NOT EXISTS `{$prefix}unit_assignment_roles` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `code`        VARCHAR(32) NOT NULL UNIQUE,
            `name`        VARCHAR(64) NOT NULL,
            `description` VARCHAR(255) DEFAULT NULL,
            `sort_order`  INT NOT NULL DEFAULT 50,
            `active`      TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK\n";
} catch (Exception $e) {
    echo "SKIP (" . $e->getMessage() . ")\n";
}

// ── Seed default roles ──────────────────────────────────────
echo "Seeding unit assignment roles... ";
$roles = [
    ['commander',  'Commander/Officer', 'Unit commander or officer in charge',  10],
    ['operator',   'Operator',          'Primary unit operator',                20],
    ['driver',     'Driver',            'Vehicle driver',                       30],
    ['medic',      'Medic/EMT',         'Medical personnel',                   40],
    ['observer',   'Observer',          'Spotter or observer role',             50],
    ['trainee',    'Trainee',           'Personnel in training',               60],
    ['support',    'Support',           'General support role',                 70],
];
$count = 0;
foreach ($roles as $r) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}unit_assignment_roles` (`code`, `name`, `description`, `sort_order`) VALUES (?, ?, ?, ?)",
            $r
        );
        $count++;
    } catch (Exception $e) {
        // skip duplicates
    }
}
echo "$count roles\n";

// ── Add max_age_seconds to location_providers ───────────────
echo "Adding staleness threshold column to location_providers... ";
try {
    // Check if column exists
    $cols = db_fetch_all(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = '{$prefix}location_providers'
           AND COLUMN_NAME = 'max_age_seconds'"
    );
    if (empty($cols)) {
        db_query("
            ALTER TABLE `{$prefix}location_providers`
            ADD COLUMN `max_age_seconds` INT NOT NULL DEFAULT 300
            COMMENT 'Reports older than this are considered stale; system falls through to next provider'
        ");
        echo "ADDED\n";

        // Set per-provider defaults
        echo "Setting per-provider staleness defaults... ";
        $defaults = [
            'aprs'       => 600,
            'meshtastic' => 300,
            'owntracks'  => 120,
            'opengts'    => 600,
            'dmr'        => 900,
            'internal'   => 60,
            'google_lat' => 3600,
        ];
        foreach ($defaults as $code => $maxAge) {
            db_query(
                "UPDATE `{$prefix}location_providers` SET `max_age_seconds` = ? WHERE `code` = ?",
                [$maxAge, $code]
            );
        }
        echo "OK\n";
    } else {
        echo "EXISTS\n";
    }
} catch (Exception $e) {
    echo "SKIP (" . $e->getMessage() . ")\n";
}

echo "\n=== Migration Complete ===\n";
