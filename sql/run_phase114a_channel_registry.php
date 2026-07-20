<?php
/**
 * Phase 114a migration — unified communications channel registry
 *
 * The audio-matrix / console foundation (specs/phase-114-audio-matrix/
 * channel-catalog.md §5). Two tables:
 *
 *   comm_channels      — one row per communications channel: a named,
 *                        configured instance of an adapter (zello, dmr_bm,
 *                        mesh, broker text channels, nws/eventbus virtual
 *                        sources, and later allstar/sip/intercom/ptt1).
 *                        Existing config (settings-based Zello channels,
 *                        dmr_channels, mesh_channels, broker registrations)
 *                        is WRAPPED via channel_registry_sync(), not
 *                        migrated — managed=1 rows are created/pruned by
 *                        sync; user overrides (label/color/enabled/sort)
 *                        are preserved across syncs.
 *
 *   comm_channel_state — health written by adapters/probes, read by the
 *                        console and System Health (state, last RX/TX,
 *                        last error).
 *
 * Also seeds the Phase 114 RBAC permissions (console screen, personal
 * view customization, shared-view design, console TX, intercom unlock).
 *
 * Idempotent — safe to run repeatedly; picked up by run_migrations.php.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 114a — communications channel registry\n";
echo "============================================\n\n";

$stmts = [
    "CREATE TABLE IF NOT EXISTS `{$prefix}comm_channels` (
        `id`                INT AUTO_INCREMENT PRIMARY KEY,
        `channel_key`       VARCHAR(160) NOT NULL,
        `adapter`           VARCHAR(32) NOT NULL,
        `label`             VARCHAR(120) NOT NULL,
        `short_label`       VARCHAR(24) DEFAULT NULL,
        `color`             VARCHAR(16) DEFAULT NULL,
        `config_json`       TEXT DEFAULT NULL,
        `capabilities_json` TEXT DEFAULT NULL,
        `regulatory_class`  ENUM('amateur','commercial','pstn','internal')
                            NOT NULL DEFAULT 'internal',
        `tts_app`           VARCHAR(64) DEFAULT NULL,
        `enabled`           TINYINT(1) NOT NULL DEFAULT 0,
        `managed`           TINYINT(1) NOT NULL DEFAULT 0,
        `sort_order`        INT NOT NULL DEFAULT 100,
        `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`        DATETIME DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_channel_key` (`channel_key`),
        KEY `idx_adapter` (`adapter`),
        KEY `idx_enabled_sort` (`enabled`, `sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `{$prefix}comm_channel_state` (
        `channel_id`  INT NOT NULL PRIMARY KEY,
        `state`       ENUM('connected','degraded','down','unknown')
                      NOT NULL DEFAULT 'unknown',
        `last_rx_at`  DATETIME DEFAULT NULL,
        `last_tx_at`  DATETIME DEFAULT NULL,
        `last_caller` VARCHAR(120) DEFAULT NULL,
        `last_error`  TEXT DEFAULT NULL,
        `updated_at`  DATETIME DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($stmts as $i => $sql) {
    echo "[" . ($i + 1) . "/" . count($stmts) . "] ";
    try {
        db_query($sql);
        $first = trim(strtok($sql, "\n"));
        echo "OK: " . substr($first, 0, 60) . "...\n";
    } catch (Exception $e) {
        echo "ERR: " . $e->getMessage() . "\n";
    }
}

// ── RBAC permissions (console-designer.md §5) ────────────────────────────
// Grants follow the run_routing.php pattern: Super Admin (1), Org Admin (2),
// Dispatcher (3) for operational perms; design + actuator perms stay admin.
$perms = [
    // code, name, category, description, role grants
    ['screen.console',          'Communications Console', 'screen',
     'Open the multi-channel communications console (console.php)', [1, 2, 3]],
    ['console.customize',       'Customize Console Views', 'action',
     'Clone a shared console view into a personal view and adjust it', [1, 2, 3]],
    ['console.design',          'Design Shared Console Views', 'action',
     'Author/publish shared console views in the console designer', [1, 2]],
    ['action.console_tx',       'Console Transmit', 'action',
     'Key any voice channel (PTT) from the communications console', [1, 2, 3]],
    ['action.intercom_unlock',  'Intercom Door Unlock', 'action',
     'Fire an intercom station door-unlock actuator (audited)', [1, 2]],
];

echo "\n-- RBAC permissions\n";
foreach ($perms as [$code, $name, $cat, $desc, $roles]) {
    try {
        $exists = db_fetch_value(
            "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?", [$code]
        );
        if ($exists) {
            echo "skip (exists): $code\n";
            $permId = (int) $exists;
        } else {
            db_query(
                "INSERT INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
                 VALUES (?, ?, ?, ?)",
                [$code, $name, $cat, $desc]
            );
            $permId = db_insert_id();
            echo "added: $code (id $permId)\n";
        }
        foreach ($roles as $roleId) {
            try {
                db_query(
                    "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)",
                    [$roleId, $permId]
                );
            } catch (Exception $e) {
                // Role may not exist in this install — fine.
            }
        }
    } catch (Exception $e) {
        echo "ERR ($code): " . $e->getMessage() . "\n";
    }
}

// ── Heal the rbac.sql re-import over-grant (2026-07-07) ──────────────────
// tools/install_fresh.php re-imports sql/rbac.sql on upgrades, and its
// broad Dispatcher mapping ("everything NOT IN <exclusions>") predated the
// Phase 114 permissions — so any install upgraded via install_fresh after
// Phase 114 landed had console.design + action.intercom_unlock silently
// granted to Dispatcher (role 3), violating console-designer.md §5
// (design + actuator perms are roles 1-2 only). rbac.sql's exclusion list
// now carries both codes; this revoke heals installs that already took the
// over-grant. Only the seeded Dispatcher role is touched — custom roles
// keep whatever an admin granted deliberately.
echo "\n-- Heal: admin-only console perms revoked from Dispatcher\n";
foreach (['console.design', 'action.intercom_unlock'] as $adminOnlyCode) {
    try {
        $pid = db_fetch_value(
            "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?", [$adminOnlyCode]
        );
        if ($pid) {
            $stmt = db_query(
                "DELETE FROM `{$prefix}role_permissions` WHERE `role_id` = 3 AND `permission_id` = ?",
                [(int) $pid]
            );
            $n = $stmt ? $stmt->rowCount() : 0;
            echo $n > 0
                ? "revoked: $adminOnlyCode from Dispatcher (role 3)\n"
                : "ok: $adminOnlyCode not granted to Dispatcher\n";
        }
    } catch (Exception $e) {
        echo "heal ERR ($adminOnlyCode): " . $e->getMessage() . "\n";
    }
}

// ── Initial sync: wrap existing config as channel rows ───────────────────
echo "\n-- Registry sync\n";
try {
    require_once 'inc/channel_registry.php';
    $r = channel_registry_sync();
    echo "sync: {$r['created']} created, {$r['updated']} updated, {$r['pruned']} pruned, "
       . count($r['channels']) . " total managed sources\n";
} catch (Exception $e) {
    echo "sync ERR: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
