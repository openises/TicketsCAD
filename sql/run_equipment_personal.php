<?php
/**
 * Run Equipment Personal — Add personal/volunteer equipment columns.
 *
 * Purpose:  Adds ownership, owner_member_id, and available_for_events columns
 *           to the newui_equipment table so volunteers can register their own
 *           equipment and mark it available for organizational use.
 * Usage:    php sql/run_equipment_personal.php
 * Prerequisites: config.php; newui_equipment table (from run_equipment.php).
 * Safety:   Idempotent. Checks information_schema before ALTER. Safe to re-run.
 * Output:   OK if columns added, "nothing to do" if already present.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pdo = db();

// Check if column already exists
$check = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'newui_equipment'
    AND COLUMN_NAME = 'ownership'")->fetchColumn();

if ($check > 0) {
    echo "Columns already exist, nothing to do.\n";
    exit(0);
}

try {
    $pdo->exec("ALTER TABLE `newui_equipment`
        ADD COLUMN `ownership` ENUM('organization','personal') DEFAULT 'organization'
            COMMENT 'organization=agency owned, personal=volunteer owned' AFTER `equipment_type_id`,
        ADD COLUMN `owner_member_id` INT(11) DEFAULT NULL
            COMMENT 'Volunteer who owns this (for personal equipment)' AFTER `ownership`,
        ADD COLUMN `available_for_events` TINYINT(1) DEFAULT 0
            COMMENT '1=owner has marked this available for org use' AFTER `owner_member_id`,
        ADD KEY `owner_member_id` (`owner_member_id`),
        ADD KEY `ownership` (`ownership`)");
    echo "OK: Added ownership, owner_member_id, available_for_events columns\n";
} catch (PDOException $e) {
    echo "ERR: " . $e->getMessage() . "\n";
}

// Verify
$cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newui_equipment'
    ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
echo "Columns: " . implode(', ', $cols) . "\n";
