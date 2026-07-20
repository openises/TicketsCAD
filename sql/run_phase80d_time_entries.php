<?php
/**
 * Phase 80d — Volunteer time-entry extensions.
 *
 * Extends the existing member_time_entries table (built in pre-release #21
 * via sql/run_time_tracking.php) with three columns the volunteer workflow
 * needs that the original PAR/clock-in-adjacent schema didn't include:
 *
 *   • org_id           — agency scope for monthly/annual rollup
 *   • category         — free-text bucket (training, drill, event, radio_net,
 *                        meeting, admin, public_education, deployment,
 *                        response, other). Distinct from the existing
 *                        activity_type lookup; category is the volunteer-
 *                        reporting taxonomy and stays free-form so each
 *                        agency can tune wording without a schema change.
 *   • rejection_reason — populated when an approver rejects the entry.
 *
 * Also adds the widget.time_entries permission (defaulted to all roles) so
 * the dashboard widget appears in the toggles toolbar for everyone, gated
 * behind RBAC instead of being unconditional.
 *
 * Idempotent. Safe to re-run.
 *
 * Standalone:
 *   php sql/run_phase80d_time_entries.php
 */
declare(strict_types=1);

if (!function_exists('db_query')) {
    require_once __DIR__ . '/../config.php';
}

$prefix = $GLOBALS['db_prefix'] ?? '';

if (!function_exists('p80d_table_exists')) {
    function p80d_table_exists(string $tbl): bool {
        global $prefix;
        try {
            $row = db_fetch_one(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$prefix . $tbl]
            );
            return !empty($row);
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('p80d_col_exists')) {
    function p80d_col_exists(string $tbl, string $col): bool {
        global $prefix;
        try {
            $row = db_fetch_one(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$prefix . $tbl, $col]
            );
            return !empty($row);
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('p80d_index_exists')) {
    function p80d_index_exists(string $tbl, string $idx): bool {
        global $prefix;
        try {
            $row = db_fetch_one(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
                [$prefix . $tbl, $idx]
            );
            return !empty($row);
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('p80d_perm_exists')) {
    function p80d_perm_exists(string $code): bool {
        global $prefix;
        try {
            return ((int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}permissions` WHERE code = ?",
                [$code]
            )) > 0;
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('p80d_step')) {
    function p80d_step(string $name, callable $check, callable $apply): void {
        try {
            if ($check()) { echo "  [skip] $name\n"; return; }
            $apply();
            echo "  [ok]   $name\n";
        } catch (Throwable $e) {
            echo "  [fail] $name -- " . $e->getMessage() . "\n";
        }
    }
}

echo "Phase 80d -- volunteer time-entry extensions\n";

// Guard against running before the base schema exists.
if (!p80d_table_exists('member_time_entries')) {
    echo "  [warn] member_time_entries missing -- running base migration first\n";
    require __DIR__ . '/run_time_tracking.php';
}

// ── Column additions ─────────────────────────────────────────────────

p80d_step('member_time_entries.org_id column',
    fn() => p80d_col_exists('member_time_entries', 'org_id'),
    fn() => db_query(
        "ALTER TABLE `{$prefix}member_time_entries`
         ADD COLUMN `org_id` INT NULL DEFAULT NULL
         COMMENT 'Agency scope (NULL = unscoped)' AFTER `member_id`"
    ));

p80d_step('member_time_entries.category column',
    fn() => p80d_col_exists('member_time_entries', 'category'),
    fn() => db_query(
        "ALTER TABLE `{$prefix}member_time_entries`
         ADD COLUMN `category` VARCHAR(32) NULL DEFAULT NULL
         COMMENT 'Volunteer reporting bucket -- free text, suggestions:
                  training, drill, event, radio_net, meeting, admin,
                  public_education, deployment, response, other'
         AFTER `activity_type`"
    ));

p80d_step('member_time_entries.rejection_reason column',
    fn() => p80d_col_exists('member_time_entries', 'rejection_reason'),
    fn() => db_query(
        "ALTER TABLE `{$prefix}member_time_entries`
         ADD COLUMN `rejection_reason` VARCHAR(255) NULL DEFAULT NULL
         AFTER `approved_at`"
    ));

p80d_step('idx_org index on member_time_entries(org_id, started_at)',
    fn() => p80d_index_exists('member_time_entries', 'idx_org'),
    fn() => db_query(
        "ALTER TABLE `{$prefix}member_time_entries`
         ADD KEY `idx_org` (`org_id`, `started_at`)"
    ));

p80d_step('idx_category index on member_time_entries(category)',
    fn() => p80d_index_exists('member_time_entries', 'idx_category'),
    fn() => db_query(
        "ALTER TABLE `{$prefix}member_time_entries`
         ADD KEY `idx_category` (`category`)"
    ));

// ── RBAC: widget.time_entries permission ─────────────────────────────

p80d_step('permission: widget.time_entries',
    fn() => p80d_perm_exists('widget.time_entries'),
    function () use ($prefix) {
        $hasVerb = p80d_col_exists('permissions', 'verb');
        if ($hasVerb) {
            db_query(
                "INSERT INTO `{$prefix}permissions`
                 (code, name, category, resource, verb, description)
                 VALUES (?, ?, 'widget', 'widget', 'time_entries', ?)",
                ['widget.time_entries', 'Time Entries Widget',
                 'Dashboard widget showing personal volunteer time totals']
            );
        } else {
            db_query(
                "INSERT INTO `{$prefix}permissions`
                 (code, name, category, description)
                 VALUES (?, ?, 'widget', ?)",
                ['widget.time_entries', 'Time Entries Widget',
                 'Dashboard widget showing personal volunteer time totals']
            );
        }
    });

p80d_step('role grants: widget.time_entries -> all roles',
    function () use ($prefix) {
        try {
            $pid = (int) db_fetch_value(
                "SELECT id FROM `{$prefix}permissions` WHERE code = ?",
                ['widget.time_entries']
            );
            if (!$pid) return false;
            $roleIds = db_fetch_all("SELECT id FROM `{$prefix}roles`");
            foreach ($roleIds as $r) {
                $row = db_fetch_one(
                    "SELECT 1 FROM `{$prefix}role_permissions`
                     WHERE role_id = ? AND permission_id = ?",
                    [(int) $r['id'], $pid]
                );
                if (!$row) return false;
            }
            return true;
        } catch (Throwable $e) { return false; }
    },
    function () use ($prefix) {
        $pid = (int) db_fetch_value(
            "SELECT id FROM `{$prefix}permissions` WHERE code = ?",
            ['widget.time_entries']
        );
        if (!$pid) return;
        $roleIds = db_fetch_all("SELECT id FROM `{$prefix}roles`");
        foreach ($roleIds as $r) {
            db_query(
                "INSERT IGNORE INTO `{$prefix}role_permissions`
                 (role_id, permission_id) VALUES (?, ?)",
                [(int) $r['id'], $pid]
            );
        }
    });

echo "Phase 80d migration complete.\n";
