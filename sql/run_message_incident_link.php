<?php
/**
 * Run Message→Incident Link — Phase 111 Slice A schema setup.
 *
 * Purpose:  Adds the columns + RBAC permission that let inbound
 *           Meshtastic/Zello/DMR messages auto-log to an "active event"
 *           incident's ICS-214 activity log:
 *
 *             action        + source_channel, source_message_id, author_member_id
 *             message_routes + attach_action, attach_ticket_id
 *             permissions   + action.manage_active_event (Super/Org Admin/Dispatcher)
 *
 *           The `active_event_ticket_id` setting is deliberately LEFT UNSET
 *           so the feature is OFF by default (the router does nothing new
 *           until a dispatcher picks an active event).
 *
 * Usage:    php sql/run_message_incident_link.php
 * Prereq:   config.php with valid DB credentials.
 * Safety:   Idempotent. Every ALTER is guarded by an information_schema.COLUMNS
 *           existence check first, so re-running is a no-op. The `action`
 *           table is legacy MyISAM/latin1 — adds are defensive and never
 *           fail if the column is already present.
 *           message_routes may not exist on an install where routing was
 *           never migrated — that block SKIPS ([--]) rather than failing.
 * Output:   [OK] applied, [--] skipped/already-present, [ERR]+exit(1) on a
 *           real failure.
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Phase 111 Slice A — Message→Incident Link Schema Setup ===\n\n";

$hadError = false;

/**
 * Does a table exist in the current database?
 */
function _mil_table_exists(string $table): bool {
    try {
        return (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$table]
        );
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Does a column exist on a table?
 */
function _mil_column_exists(string $table, string $column): bool {
    try {
        return (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Add a column to a table only if it's absent. Echoes [OK]/[--]/[ERR].
 * Sets the global $hadError on a real failure so the script can exit(1).
 *
 * @param string $table    Prefixed table name (physical)
 * @param string $column   Column name
 * @param string $ddlType  The column definition, e.g. "VARCHAR(32) NULL"
 */
function _mil_add_column(string $table, string $column, string $ddlType): void {
    global $hadError;
    if (!_mil_table_exists($table)) {
        echo "[--] {$table}.{$column} skipped — table {$table} not present on this install\n";
        return;
    }
    if (_mil_column_exists($table, $column)) {
        echo "[OK] {$table}.{$column} already present\n";
        return;
    }
    try {
        // Column/table identifiers are validated by the existence checks
        // above and are internal constants here (never user input), so
        // interpolating them into the ALTER is safe.
        db_query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$ddlType}");
        echo "[OK] {$table}.{$column} added\n";
    } catch (Exception $e) {
        // Defensive: a concurrent run / legacy engine quirk may report the
        // column already exists — treat "duplicate column" as OK, anything
        // else as a real error.
        if (stripos($e->getMessage(), 'duplicate column') !== false
            || stripos($e->getMessage(), 'exists') !== false) {
            echo "[OK] {$table}.{$column} already present (race)\n";
            return;
        }
        echo "[ERR] {$table}.{$column}: " . $e->getMessage() . "\n";
        $hadError = true;
    }
}

// ── action table (legacy MyISAM / latin1 — be defensive) ─────────────
echo "-- action columns --\n";
_mil_add_column($prefix . 'action', 'source_channel',    'VARCHAR(32) NULL');
_mil_add_column($prefix . 'action', 'source_message_id', 'INT NULL');
_mil_add_column($prefix . 'action', 'author_member_id',  'INT NULL');

// ── message_routes table (skip if routing was never migrated) ────────
echo "\n-- message_routes columns --\n";
_mil_add_column($prefix . 'message_routes', 'attach_action',    'VARCHAR(16) NULL');
_mil_add_column($prefix . 'message_routes', 'attach_ticket_id', 'INT NULL');

// ── settings: active_event_ticket_id ─────────────────────────────────
// DELIBERATELY UNSET. The feature is OFF until a dispatcher picks an event.
// We do NOT insert a value here. Document only.
echo "\n-- active-event setting --\n";
try {
    $present = false;
    if (_mil_table_exists($prefix . 'settings')) {
        $present = (bool) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?",
            ['active_event_ticket_id']
        );
    }
    if ($present) {
        echo "[OK] settings.active_event_ticket_id already exists (feature configured)\n";
    } else {
        echo "[--] settings.active_event_ticket_id intentionally UNSET (feature off by default)\n";
    }
} catch (Exception $e) {
    echo "[--] settings check skipped: " . $e->getMessage() . "\n";
}

// ── RBAC permission action.manage_active_event ───────────────────────
echo "\n-- RBAC permission --\n";
try {
    if (!_mil_table_exists($prefix . 'permissions')) {
        echo "[--] permissions table absent — RBAC not installed; skipped\n";
    } else {
        $exists = db_fetch_one(
            "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?",
            ['action.manage_active_event']
        );
        if (!$exists) {
            db_query(
                "INSERT INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
                 VALUES (?, ?, ?, ?)",
                [
                    'action.manage_active_event',
                    'Manage Active Event',
                    'action',
                    'Set / clear the active event incident that inbound messages auto-log to (Phase 111)'
                ]
            );
            $permId = db_insert_id();

            // Grant to Super Admin (1), Org Admin (2), Dispatcher (3).
            foreach ([1, 2, 3] as $roleId) {
                try {
                    db_query(
                        "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)",
                        [$roleId, $permId]
                    );
                } catch (Exception $e) {
                    // Role may not exist on this install — non-fatal.
                }
            }
            echo "[OK] RBAC permission action.manage_active_event added (granted to Super Admin, Org Admin, Dispatcher)\n";
        } else {
            echo "[OK] RBAC permission action.manage_active_event already exists\n";
        }
    }
} catch (Exception $e) {
    echo "[ERR] RBAC: " . $e->getMessage() . "\n";
    $hadError = true;
}

echo "\n" . ($hadError ? "Done WITH ERRORS.\n" : "Done.\n");
exit($hadError ? 1 : 0);
