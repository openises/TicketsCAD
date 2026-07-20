<?php
/**
 * Phase 114c migration — audio-matrix route table
 *
 * The audio matrix (services/audio-matrix/) patches any channel's audio
 * into any other with per-route gain, priority, and ducking. Those patches
 * live here so they survive a matrix restart and so the console UI can
 * read/write them over the matrix control plane's DB, not just its RAM.
 *
 *   comm_routes — one row per directed patch (src channel -> dst channel).
 *                 Mirrors the Route dataclass in matrix_core.py:
 *                 gain_db, priority, ducking, enabled, allow_cross_class.
 *                 allow_cross_class is the audited operator override that
 *                 lets an amateur<->commercial/pstn patch exist (§97.113);
 *                 the matrix core HARD-BLOCKS it otherwise.
 *
 * src_channel_id / dst_channel_id reference comm_channels.id. We DON'T add
 * a hard FK (comm_channels rows are re-derived by channel_registry_sync and
 * an FK would fight the sync's prune step); instead the matrix service
 * skips routes whose endpoints have vanished, and this migration leaves
 * orphan cleanup to the console API.
 *
 * Idempotent — safe to run repeatedly; picked up by run_migrations.php.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 114c — audio-matrix route table\n";
echo "=====================================\n\n";

$stmts = [
    "CREATE TABLE IF NOT EXISTS `{$prefix}comm_routes` (
        `id`                INT AUTO_INCREMENT PRIMARY KEY,
        `src_channel_id`    INT NOT NULL,
        `dst_channel_id`    INT NOT NULL,
        `gain_db`           DECIMAL(5,1) NOT NULL DEFAULT 0.0,
        `priority`          INT NOT NULL DEFAULT 0,
        `ducking`           TINYINT(1) NOT NULL DEFAULT 1,
        `enabled`           TINYINT(1) NOT NULL DEFAULT 1,
        `allow_cross_class` TINYINT(1) NOT NULL DEFAULT 0,
        `note`              VARCHAR(255) DEFAULT NULL,
        `created_by`        INT DEFAULT NULL,
        `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`        DATETIME DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_src_dst` (`src_channel_id`, `dst_channel_id`),
        KEY `idx_enabled` (`enabled`)
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

// ── RBAC permission: manage the audio matrix routing ─────────────────────
// Follows the run_phase114a pattern — Super Admin (1) + Org Admin (2) only;
// re-patching audio between an amateur channel and anything else is a
// regulated action, so it stays off the Dispatcher role.
$perm = ['action.manage_matrix', 'Manage Audio Matrix Routes', 'action',
         'Create, edit, and remove audio-matrix routes between channels', [1, 2]];

try {
    $permTable = $prefix . 'permissions';
    $rolePermTable = $prefix . 'role_permissions';
    // Only seed if the RBAC tables exist (fresh installs run RBAC first).
    $have = db_fetch_value("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ?", [$permTable]);
    if ($have) {
        [$code, $name, $cat, $desc, $roles] = $perm;
        $existing = db_fetch_value(
            "SELECT id FROM `{$permTable}` WHERE code = ?", [$code]);
        if (!$existing) {
            db_query("INSERT INTO `{$permTable}` (code, name, category, description)
                      VALUES (?, ?, ?, ?)", [$code, $name, $cat, $desc]);
            $pid = db_insert_id();
            echo "seeded permission {$code} (id {$pid})\n";
        } else {
            $pid = $existing;
            echo "permission {$code} already present (id {$pid})\n";
        }
        foreach ($roles as $rid) {
            $has = db_fetch_value("SELECT COUNT(*) FROM `{$rolePermTable}`
                WHERE role_id = ? AND permission_id = ?", [$rid, $pid]);
            if (!$has) {
                db_query("INSERT INTO `{$rolePermTable}` (role_id, permission_id)
                          VALUES (?, ?)", [$rid, $pid]);
            }
        }
        echo "granted {$code} to roles: " . implode(', ', $roles) . "\n";
    } else {
        echo "RBAC tables absent — skipping permission seed (fresh install"
           . " will seed on RBAC migration)\n";
    }
} catch (Exception $e) {
    echo "RBAC seed note: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
