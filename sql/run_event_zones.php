<?php
/**
 * Run Event Zones — Phase 109 Slice A schema.
 *
 * Purpose:  Creates the `event_zones` table (per-event "posts" for the
 *           Net Control Board), adds the zone-state columns to the
 *           legacy `assigns` table, and seeds the Slice-A RBAC
 *           permissions (screen.net_control, action.update_zone,
 *           action.manage_event_zones).
 *
 * Usage:    php sql/run_event_zones.php
 * Prereq:   config.php with valid database credentials.
 * Safety:   Idempotent. CREATE TABLE IF NOT EXISTS; column-adds are
 *           guarded against information_schema so a re-run is a no-op.
 *           Safe to re-run.
 * Output:   [OK]/[--] per step; [ERR] + exit(1) on hard failure.
 *
 * Design note (Eric decision #1, 2026-07-04): destination-only. There
 * is deliberately NO `zone_phase` (en-route/arrived) column — recording
 * where a unit is headed IS the operational fact.
 */
require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$hadError = false;

echo "=== Event Zones (Phase 109 Slice A) Schema Setup ===\n\n";

/**
 * Return true if a column already exists on a table (schema-agnostic).
 */
function _ez_column_exists(string $table, string $column): bool {
    try {
        $row = db_fetch_one(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        return $row !== null;
    } catch (Exception $e) {
        // If we can't read information_schema, fall back to a probe SELECT.
        try {
            db_query("SELECT `{$column}` FROM `{$table}` LIMIT 0");
            return true;
        } catch (Exception $e2) {
            return false;
        }
    }
}

/**
 * Return true if a named index exists on a table.
 */
function _ez_index_exists(string $table, string $indexName): bool {
    try {
        $row = db_fetch_one(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$table, $indexName]
        );
        return $row !== null;
    } catch (Exception $e) {
        return false;
    }
}

// ── event_zones table ─────────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}event_zones` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `ticket_id`   INT NOT NULL,
        `name`        VARCHAR(64) NOT NULL,
        `code`        VARCHAR(16) NOT NULL,
        `color`       VARCHAR(16) DEFAULT NULL,
        `geo_json`    TEXT DEFAULT NULL,
        `sort_order`  INT DEFAULT 0,
        `hide`        TINYINT(1) DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `ticket_idx` (`ticket_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] event_zones table ready\n";
} catch (Exception $e) {
    echo "[ERR] event_zones: " . $e->getMessage() . "\n";
    $hadError = true;
}

// ── assigns column-adds (guarded, idempotent) ─────────────
// assigns is a legacy table (bigint id). Add zone-state columns only if
// missing so the ALTER never fails on a second run.
$assignsTbl = $prefix . 'assigns';
$assignColumns = [
    'current_zone_id' => "ADD COLUMN `current_zone_id` INT DEFAULT NULL",
    'zone_updated_at' => "ADD COLUMN `zone_updated_at` DATETIME DEFAULT NULL",
    'last_checkin_at' => "ADD COLUMN `last_checkin_at` DATETIME DEFAULT NULL",
];
foreach ($assignColumns as $col => $clause) {
    try {
        if (_ez_column_exists($assignsTbl, $col)) {
            echo "[--] assigns.$col already present\n";
            continue;
        }
        db_query("ALTER TABLE `{$assignsTbl}` {$clause}");
        echo "[OK] assigns.$col added\n";
    } catch (Exception $e) {
        // A parallel run may have added it between the check and the
        // ALTER — treat "duplicate column" as success, everything else
        // as a hard error.
        if (stripos($e->getMessage(), 'duplicate column') !== false
            || stripos($e->getMessage(), 'Duplicate column') !== false) {
            echo "[--] assigns.$col already present (race)\n";
        } else {
            echo "[ERR] assigns.$col: " . $e->getMessage() . "\n";
            $hadError = true;
        }
    }
}

// KEY on current_zone_id
try {
    if (_ez_index_exists($assignsTbl, 'current_zone_idx')) {
        echo "[--] assigns.current_zone_idx already present\n";
    } elseif (_ez_column_exists($assignsTbl, 'current_zone_id')) {
        db_query("ALTER TABLE `{$assignsTbl}` ADD KEY `current_zone_idx` (`current_zone_id`)");
        echo "[OK] assigns.current_zone_idx added\n";
    }
} catch (Exception $e) {
    if (stripos($e->getMessage(), 'duplicate key') !== false
        || stripos($e->getMessage(), 'Duplicate key') !== false) {
        echo "[--] assigns.current_zone_idx already present (race)\n";
    } else {
        // Index add failing is non-fatal (the feature still works
        // without the index) — warn but don't abort.
        echo "[--] assigns.current_zone_idx skipped: " . $e->getMessage() . "\n";
    }
}

// ── RBAC permissions ──────────────────────────────────────
// Copies the idempotent pattern from sql/run_routing.php. The
// permissions table keys on `code` (NOT `name`).
$perms = [
    'screen.net_control' => [
        'Net Control Board', 'screen',
        'View the event Net Control Board',
    ],
    'action.update_zone' => [
        'Update Unit Zone', 'action',
        'Move a unit between event zones on the Net Control Board',
    ],
    'action.manage_event_zones' => [
        'Manage Event Zones', 'action',
        'Create, rename, and delete per-event zones',
    ],
];
foreach ($perms as $code => $meta) {
    try {
        $exists = db_fetch_one(
            "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?",
            [$code]
        );
        if ($exists) {
            echo "[--] RBAC permission {$code} already exists\n";
            continue;
        }
        db_query(
            "INSERT INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
             VALUES (?, ?, ?, ?)",
            [$code, $meta[0], $meta[1], $meta[2]]
        );
        $permId = db_insert_id();

        // Grant to Super Admin (role 1), Org Admin (role 2), Dispatcher (role 3).
        foreach ([1, 2, 3] as $roleId) {
            try {
                db_query(
                    "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)",
                    [$roleId, $permId]
                );
            } catch (Exception $e) {
                // Role might not exist in this install — skip.
            }
        }
        echo "[OK] RBAC permission {$code} added (granted to Super Admin, Org Admin, Dispatcher)\n";
    } catch (Exception $e) {
        // Permissions table might not exist yet (RBAC not installed).
        // That's a soft failure — the board degrades to is_admin() checks.
        echo "[--] RBAC {$code} skipped: " . $e->getMessage() . "\n";
    }
}

echo "\n";
if ($hadError) {
    echo "[ERR] One or more required steps failed. See messages above.\n";
    exit(1);
}
echo "Done.\n";
