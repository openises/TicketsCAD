<?php
/**
 * Run Member Columns — Add named columns to legacy member table.
 *
 * Purpose:  The legacy member table uses field1-field65. The NewUI roster
 *           expects named columns (middle_name, member_type_id, title,
 *           phone_home, etc.). This script adds the missing columns without
 *           touching existing data or removing legacy fields.
 * Usage:    php sql/run_member_columns.php
 * Prerequisites: config.php; member table must exist.
 * Safety:   Idempotent. Each ALTER is guarded by an information_schema
 *           column-existence check. Safe to run multiple times.
 * Output:   [OK] per column added, [SKIP] if already exists, [WARN] on error.
 */
require_once __DIR__ . '/../config.php';

echo "Member Table Column Migration\n";
echo "=============================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'member';

/**
 * Add a column if it doesn't already exist.
 */
function addCol($table, $colName, $colDef) {
    try {
        $exists = db_fetch_all(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $colName]
        );
        if (!empty($exists)) {
            echo "  [SKIP] {$colName} already exists\n";
            return;
        }
        db_query("ALTER TABLE `{$table}` ADD COLUMN `{$colName}` {$colDef}");
        echo "  [OK]   {$colName} added\n";
    } catch (Exception $e) {
        echo "  [WARN] {$colName}: " . $e->getMessage() . "\n";
    }
}

// ── Personal / Identity columns ─────────────────────────────
// first_name + last_name were assumed to already exist on the member
// table — true for installs upgraded from a NewUI pre-base_schema era,
// false on TRUE-greenfield installs from the legacy v3.44 DB_FULL.sql
// dump (which only has the generic field1..field65 columns). Add them
// here so the New Member form's INSERT (api/members.php) doesn't crash
// with "Unknown column 'first_name'" on fresh installs.
// Beta tester (a beta tester, 2026-06-25) hit this immediately after
// the CSRF fix landed.
echo "Personal info columns:\n";
addCol($table, 'first_name',        "VARCHAR(64) DEFAULT NULL");
addCol($table, 'last_name',         "VARCHAR(64) DEFAULT NULL");
addCol($table, 'middle_name',       "VARCHAR(64) DEFAULT NULL AFTER `last_name`");
addCol($table, 'title',             "VARCHAR(64) DEFAULT NULL COMMENT 'Position/title'");
addCol($table, 'dob',               "DATE DEFAULT NULL");

// ── Organization columns ────────────────────────────────────
echo "\nOrganization columns:\n";
addCol($table, 'member_type_id',    "INT DEFAULT NULL");
addCol($table, 'member_status_id',  "INT DEFAULT NULL");
addCol($table, 'team_id',           "INT DEFAULT NULL");
addCol($table, 'available',         "ENUM('Yes','No') DEFAULT 'Yes'");
addCol($table, 'join_date',         "DATE DEFAULT NULL");
addCol($table, 'membership_due',    "DATE DEFAULT NULL");

// ── Contact columns ─────────────────────────────────────────
echo "\nContact columns:\n";
addCol($table, 'callsign',          "VARCHAR(32) DEFAULT NULL");
addCol($table, 'email',             "VARCHAR(128) DEFAULT NULL");
addCol($table, 'phone_home',        "VARCHAR(24) DEFAULT NULL");
addCol($table, 'phone_work',        "VARCHAR(24) DEFAULT NULL");
addCol($table, 'phone_cell',        "VARCHAR(24) DEFAULT NULL");

// ── Address columns ─────────────────────────────────────────
echo "\nAddress columns:\n";
addCol($table, 'street',            "VARCHAR(128) DEFAULT NULL");
addCol($table, 'city',              "VARCHAR(64) DEFAULT NULL");
addCol($table, 'state',             "VARCHAR(4) DEFAULT NULL");
addCol($table, 'zip',               "VARCHAR(16) DEFAULT NULL");
addCol($table, 'lat',               "DOUBLE DEFAULT NULL");
addCol($table, 'lng',               "DOUBLE DEFAULT NULL");

// ── Emergency contact ───────────────────────────────────────
echo "\nEmergency contact columns:\n";
addCol($table, 'emergency_contact',  "VARCHAR(128) DEFAULT NULL");
addCol($table, 'emergency_phone',    "VARCHAR(24) DEFAULT NULL");
addCol($table, 'emergency_relation', "VARCHAR(64) DEFAULT NULL");

// ── Medical & Notes ─────────────────────────────────────────
echo "\nMedical & notes columns:\n";
addCol($table, 'medical_info',      "TEXT DEFAULT NULL COMMENT 'Allergies, medications, conditions'");
addCol($table, 'notes',             "TEXT DEFAULT NULL");

// ── Linkage columns ─────────────────────────────────────────
echo "\nLinkage columns:\n";
addCol($table, 'responder_id',      "INT DEFAULT NULL COMMENT 'Link to responder table'");
addCol($table, 'user_id',           "INT DEFAULT NULL COMMENT 'Link to user account'");
addCol($table, 'photo_url',         "VARCHAR(255) DEFAULT NULL");
addCol($table, 'photo_file_id',     "INT DEFAULT NULL COMMENT 'FK to files table (preferred over photo_url)'");

// ── Audit / soft-delete columns ─────────────────────────────
// deleted_at is required by api/members.php list queries:
//   WHERE (m.deleted_at IS NULL) ...
// Without it the SELECT throws and the list silently returns []
// (safe_fetch_all_m catches), so the Roster page shows 0 members
// even when rows exist. Beta tester (a beta tester, 2026-06-25)
// hit this after the callsign/email fix unblocked New Member save.
echo "\nAudit / soft-delete columns:\n";
addCol($table, 'deleted_at',        "DATETIME DEFAULT NULL");
addCol($table, 'deleted_by',        "INT DEFAULT NULL");

echo "\nAudit columns:\n";
addCol($table, 'created_by',        "INT DEFAULT NULL");
addCol($table, 'created_at',        "DATETIME DEFAULT NULL");
addCol($table, 'updated_at',        "DATETIME DEFAULT NULL");

// ── Add indexes ─────────────────────────────────────────────
echo "\nIndexes:\n";
$indexes = [
    'idx_member_type'   => 'member_type_id',
    'idx_member_status' => 'member_status_id',
    'idx_team'          => 'team_id',
    'idx_last_name'     => 'last_name',
    'idx_callsign'      => 'callsign',
    'idx_user_id'       => 'user_id',
    'idx_responder_id'  => 'responder_id',
];

foreach ($indexes as $idxName => $colName) {
    try {
        $existing = db_fetch_all(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$idxName]
        );
        if (!empty($existing)) {
            echo "  [SKIP] Index {$idxName} already exists\n";
            continue;
        }
        // Only add index if column exists
        $colExists = db_fetch_all(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $colName]
        );
        if (empty($colExists)) {
            echo "  [SKIP] Index {$idxName} — column {$colName} not found\n";
            continue;
        }
        db_query("ALTER TABLE `{$table}` ADD KEY `{$idxName}` (`{$colName}`)");
        echo "  [OK]   Index {$idxName} on {$colName}\n";
    } catch (Exception $e) {
        echo "  [WARN] {$idxName}: " . $e->getMessage() . "\n";
    }
}

// ── Fix legacy field* columns that lack defaults ────────────
// The legacy member table has field1-field65 as NOT NULL without DEFAULT.
// This causes INSERT to fail in MySQL strict mode when only named columns
// are specified. Set DEFAULT '' on all legacy field* columns.
echo "\nLegacy field defaults:\n";

try {
    $legacyCols = db_fetch_all(
        "SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND IS_NULLABLE = 'NO'
           AND COLUMN_DEFAULT IS NULL
           AND COLUMN_NAME != 'id'
           AND (COLUMN_NAME REGEXP '^field[0-9]+$'
                OR COLUMN_NAME IN ('_by', '_on', '_from'))
         ORDER BY ORDINAL_POSITION",
        [$table]
    );

    if (empty($legacyCols)) {
        echo "  [OK] All legacy columns already have defaults\n";
    } else {
        $fixed = 0;
        foreach ($legacyCols as $col) {
            // Pick the right default based on data type
            $dtype = strtolower($col['DATA_TYPE']);
            if (in_array($dtype, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'float', 'double'])) {
                $default = '0';
            } elseif (in_array($dtype, ['datetime', 'timestamp'])) {
                // Can't use ALTER COLUMN SET DEFAULT for datetime without CURRENT_TIMESTAMP
                // Instead, change to NULLABLE
                try {
                    db_query("ALTER TABLE `{$table}` MODIFY COLUMN `{$col['COLUMN_NAME']}` {$col['COLUMN_TYPE']} NULL DEFAULT NULL");
                    echo "  [OK] {$col['COLUMN_NAME']} ({$dtype}) → nullable\n";
                    $fixed++;
                } catch (Exception $e) {
                    echo "  [WARN] {$col['COLUMN_NAME']}: " . $e->getMessage() . "\n";
                }
                continue;
            } elseif ($dtype === 'date') {
                try {
                    db_query("ALTER TABLE `{$table}` MODIFY COLUMN `{$col['COLUMN_NAME']}` {$col['COLUMN_TYPE']} NULL DEFAULT NULL");
                    echo "  [OK] {$col['COLUMN_NAME']} ({$dtype}) → nullable\n";
                    $fixed++;
                } catch (Exception $e) {
                    echo "  [WARN] {$col['COLUMN_NAME']}: " . $e->getMessage() . "\n";
                }
                continue;
            } else {
                $default = "''";
            }

            try {
                db_query("ALTER TABLE `{$table}` ALTER COLUMN `{$col['COLUMN_NAME']}` SET DEFAULT {$default}");
                echo "  [OK] {$col['COLUMN_NAME']} ({$dtype}) → DEFAULT {$default}\n";
                $fixed++;
            } catch (Exception $e) {
                echo "  [WARN] {$col['COLUMN_NAME']}: " . $e->getMessage() . "\n";
            }
        }
        echo "  Fixed {$fixed} legacy columns\n";
    }
} catch (Exception $e) {
    echo "  [WARN] Legacy column check: " . $e->getMessage() . "\n";
}

// ── Also ensure supporting tables exist ─────────────────────
echo "\nSupporting tables:\n";

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}member_types` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(64) NOT NULL,
        `description` varchar(255) DEFAULT NULL,
        `color` varchar(7) DEFAULT '#6c757d',
        `sort_order` int(11) DEFAULT 0,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  [OK] member_types table ready\n";
} catch (Exception $e) {
    echo "  [WARN] member_types: " . $e->getMessage() . "\n";
}
// Defensive: text_color is SELECT'd by api/members.php list queries
// (mt.text_color AS type_text_color). Phase 18a is supposed to add
// it but it's after this script alphabetically — if Phase 18a was
// skipped or didn't run, the SELECT crashes and the list silently
// returns []. Add idempotently here too.
addCol($prefix . 'member_types',  'text_color', "VARCHAR(7) DEFAULT '#000000'");

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}member_status` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(64) NOT NULL,
        `description` varchar(255) DEFAULT NULL,
        `color` varchar(7) DEFAULT '#6c757d',
        `bg_color` varchar(7) DEFAULT '#ffffff',
        `sort_order` int(11) DEFAULT 0,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  [OK] member_status table ready\n";
} catch (Exception $e) {
    echo "  [WARN] member_status: " . $e->getMessage() . "\n";
}
addCol($prefix . 'member_status', 'text_color', "VARCHAR(7) DEFAULT '#000000'");

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}teams` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(128) NOT NULL,
        `description` text DEFAULT NULL,
        `team_type` varchar(64) DEFAULT NULL,
        `leader_id` int(11) DEFAULT NULL,
        `deputy_id` int(11) DEFAULT NULL,
        `active` tinyint(1) DEFAULT 1,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  [OK] teams table ready\n";
} catch (Exception $e) {
    echo "  [WARN] teams: " . $e->getMessage() . "\n";
}
// Legacy-bridge: v3.44 ships teams with `team` (varchar(48) NOT NULL).
// api/members.php list/detail queries use `t.name` — crashes if name
// doesn't exist. Add `name` defensively and backfill from `team` so
// both column names work going forward. Also add the other modern
// columns the New Team form / queries reference, with NULL defaults
// so they don't fight the legacy NOT NULLs.
addCol($prefix . 'teams', 'name',        "VARCHAR(128) DEFAULT NULL");
addCol($prefix . 'teams', 'description', "TEXT DEFAULT NULL");
addCol($prefix . 'teams', 'team_type',   "VARCHAR(64) DEFAULT NULL");
addCol($prefix . 'teams', 'leader_id',   "INT DEFAULT NULL");
addCol($prefix . 'teams', 'deputy_id',   "INT DEFAULT NULL");
addCol($prefix . 'teams', 'active',      "TINYINT(1) DEFAULT 1");
try {
    db_query("UPDATE `{$prefix}teams` SET `name` = `team` WHERE `name` IS NULL OR `name` = ''");
    echo "  [OK] backfilled teams.name from legacy teams.team where empty\n";
} catch (Exception $e) {
    echo "  [WARN] teams backfill: " . $e->getMessage() . "\n";
}

// ── Member callsigns table (multi-callsign support) ─────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}member_callsigns` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `member_id`     INT NOT NULL,
        `callsign`      VARCHAR(16) NOT NULL,
        `license_type`  VARCHAR(32) NOT NULL DEFAULT 'amateur',
        `oper_class`    VARCHAR(16) DEFAULT NULL,
        `frn`           VARCHAR(16) DEFAULT NULL,
        `grant_date`    DATE DEFAULT NULL,
        `expiry_date`   DATE DEFAULT NULL,
        `grid_square`   VARCHAR(8) DEFAULT NULL,
        `is_primary`    TINYINT(1) NOT NULL DEFAULT 0,
        `source`        VARCHAR(32) DEFAULT NULL,
        `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_member` (`member_id`),
        UNIQUE KEY `uq_member_call` (`member_id`, `callsign`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  [OK] member_callsigns table ready\n";
} catch (Exception $e) {
    echo "  [WARN] member_callsigns: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
