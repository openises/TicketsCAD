<?php
/**
 * Phase 16a (2026-06-11) — Personnel Accountability Report schema.
 *
 * Adds the PAR data model spec'd in
 * specs/phase-16-par-checks-2026-06/spec.md, gated by an off-by-
 * default `par_enabled` setting so existing installs don't see any
 * new UI / cron load until an admin opts in.
 *
 * Tables:
 *   par_cycles        — one row per PAR cycle initiated
 *   par_unit_acks     — one row per (cycle × expected unit)
 *   par_config        — agency default + per-incident-type cadence
 *
 * Settings keys:
 *   par_enabled                       int   0 (off)
 *   par_default_cadence_min           int   20
 *   par_first_window_s                int   60
 *   par_retry_window_s                int   120
 *   par_max_misses                    int   2
 *   par_escalation_chat_channel       str   ''
 *   par_mayday_auto_trigger           int   1 (Phase 16e: default ON)
 *   par_standby_unit_behavior         enum 'recommended' (Phase 16e)
 *
 * Column:
 *   ticket.par_cadence_override_min  INT NULL — per-incident override
 *   ticket.par_last_cycle_at         DATETIME NULL — drives the UI
 *                                    "time since last PAR" + "next PAR
 *                                    due in" timers Eric asked for
 *
 * RBAC permission seeded:
 *   action.manage_par                — initiate / abort / configure PAR
 *
 * Idempotent: information-schema-guarded ALTERs + INSERT IGNORE on
 * settings + permissions.
 *
 * Usage: php sql/run_phase16a_par_schema.php
 */
require_once __DIR__ . '/../config.php';

echo "Phase 16a — PAR schema\n";
echo "======================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _p16_col_exists(string $table, string $col): bool {
    global $prefix;
    try {
        $r = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . $table, $col]
        );
        return !empty($r);
    } catch (Exception $e) { return false; }
}

function _p16_table_exists(string $table): bool {
    global $prefix;
    try {
        $r = db_fetch_one(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . $table]
        );
        return !empty($r);
    } catch (Exception $e) { return false; }
}

// ── par_cycles ────────────────────────────────────────────────────────
if (!_p16_table_exists('par_cycles')) {
    db_query("
        CREATE TABLE `{$prefix}par_cycles` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ticket_id`      BIGINT UNSIGNED NOT NULL,
            `initiated_at`   DATETIME NOT NULL,
            `initiated_by`   INT UNSIGNED NULL,
            `initiated_kind` ENUM('scheduled','manual','mayday','benchmark') NOT NULL,
            `cycle_window_s` INT UNSIGNED NOT NULL DEFAULT 60,
            `status`         ENUM('pending','complete','aborted') NOT NULL DEFAULT 'pending',
            `completed_at`   DATETIME NULL,
            `notes`          VARCHAR(255) NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ticket` (`ticket_id`),
            KEY `idx_status` (`status`),
            KEY `idx_initiated_at` (`initiated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Created par_cycles\n";
} else {
    echo "[OK] par_cycles already exists\n";
}

// ── par_unit_acks ─────────────────────────────────────────────────────
if (!_p16_table_exists('par_unit_acks')) {
    db_query("
        CREATE TABLE `{$prefix}par_unit_acks` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `par_cycle_id`  INT UNSIGNED NOT NULL,
            `responder_id`  INT UNSIGNED NOT NULL,
            `expected`      TINYINT(1) NOT NULL DEFAULT 1,
            `state`         ENUM('pending','acked','missed','aborted') NOT NULL DEFAULT 'pending',
            `acked_at`      DATETIME NULL,
            `acked_by`      INT UNSIGNED NULL,
            `acked_via`     ENUM('mobile','dispatcher_manual','sse','voice_radio') NULL,
            `member_count`  TINYINT UNSIGNED NULL,
            `comments`      VARCHAR(1024) NULL,
            `notes`         VARCHAR(255) NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_cycle_unit` (`par_cycle_id`, `responder_id`),
            KEY `idx_state` (`state`),
            KEY `idx_responder` (`responder_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Created par_unit_acks\n";
} else {
    echo "[OK] par_unit_acks already exists\n";
}

// ── par_config ────────────────────────────────────────────────────────
if (!_p16_table_exists('par_config')) {
    db_query("
        CREATE TABLE `{$prefix}par_config` (
            `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `scope`                  ENUM('agency_default','incident_type') NOT NULL,
            `in_types_id`            INT UNSIGNED NULL,
            `cadence_minutes`        INT UNSIGNED NOT NULL DEFAULT 0,
            `first_cycle_window_s`   INT UNSIGNED NOT NULL DEFAULT 60,
            `retry_cycle_window_s`   INT UNSIGNED NOT NULL DEFAULT 120,
            `escalate_after_misses`  TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `chat_channel`           VARCHAR(64) NULL,
            `audio_alert`            VARCHAR(32) NULL,
            `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_scope` (`scope`, `in_types_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Created par_config\n";
} else {
    echo "[OK] par_config already exists\n";
}

// Seed the agency_default row if not present.
try {
    $agencyRow = db_fetch_value(
        "SELECT id FROM `{$prefix}par_config` WHERE scope = 'agency_default' LIMIT 1"
    );
    if (!$agencyRow) {
        db_query(
            "INSERT INTO `{$prefix}par_config`
                (scope, in_types_id, cadence_minutes, first_cycle_window_s,
                 retry_cycle_window_s, escalate_after_misses)
             VALUES ('agency_default', NULL, 20, 60, 120, 2)"
        );
        echo "[OK] Seeded par_config agency_default row\n";
    } else {
        echo "[OK] par_config agency_default already present\n";
    }
} catch (Exception $e) {
    echo "[WARN] agency_default seed: " . $e->getMessage() . "\n";
}

// ── ticket columns ────────────────────────────────────────────────────
if (!_p16_col_exists('ticket', 'par_cadence_override_min')) {
    db_query(
        "ALTER TABLE `{$prefix}ticket`
         ADD COLUMN `par_cadence_override_min` INT UNSIGNED NULL
         COMMENT 'PAR cadence override in minutes; NULL=use default'"
    );
    echo "[OK] Added ticket.par_cadence_override_min\n";
} else {
    echo "[OK] ticket.par_cadence_override_min already exists\n";
}

if (!_p16_col_exists('ticket', 'par_last_cycle_at')) {
    db_query(
        "ALTER TABLE `{$prefix}ticket`
         ADD COLUMN `par_last_cycle_at` DATETIME NULL
         COMMENT 'Timestamp of most recently initiated PAR cycle for this incident',
         ADD KEY `idx_par_last_cycle` (`par_last_cycle_at`)"
    );
    echo "[OK] Added ticket.par_last_cycle_at + index\n";
} else {
    echo "[OK] ticket.par_last_cycle_at already exists\n";
}

// ── Settings ──────────────────────────────────────────────────────────
$settings = [
    'par_enabled'                  => '0',
    'par_default_cadence_min'      => '20',
    'par_first_window_s'           => '60',
    'par_retry_window_s'           => '120',
    'par_max_misses'               => '2',
    'par_escalation_chat_channel'  => '',
    'par_mayday_auto_trigger'      => '1',
    'par_standby_unit_behavior'    => 'recommended',
];
foreach ($settings as $name => $val) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
            [$name, $val]
        );
    } catch (Exception $e) {
        echo "[WARN] setting {$name}: " . $e->getMessage() . "\n";
    }
}
echo "[OK] Settings seeded (par_enabled=0 — admin must opt in)\n";

// ── RBAC permission ──────────────────────────────────────────────────
// Schema uses tables `permissions` and `role_permissions` (NOT
// rbac_*). Permission identifier column is `code`, not `name`.
try {
    $exists = db_fetch_value(
        "SELECT 1 FROM `{$prefix}permissions` WHERE code = 'action.manage_par' LIMIT 1"
    );
    if (!$exists) {
        db_query(
            "INSERT INTO `{$prefix}permissions`
                (code, name, category, resource, verb, description)
             VALUES ('action.manage_par',
                     'Manage PAR',
                     'action',
                     'par',
                     'manage',
                     'Initiate PAR checks, acknowledge units, configure cadence')"
        );
        echo "[OK] Added action.manage_par permission row\n";

        // Grant to Super Admin / Org Admin / Dispatcher tier.
        // role_permissions join is by permission_id (int), not code.
        try {
            db_query("
                INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id)
                SELECT r.id, (SELECT id FROM `{$prefix}permissions` WHERE code = 'action.manage_par' LIMIT 1)
                  FROM `{$prefix}roles` r
                 WHERE r.legacy_level IN (0, 1, 2)
                    OR r.name IN ('Super Admin', 'Org Admin', 'Dispatcher')
            ");
            echo "[OK] Granted action.manage_par to dispatcher-tier roles\n";
        } catch (Exception $e) {
            echo "[WARN] action.manage_par grant: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[OK] action.manage_par already exists\n";
    }
    // Re-run grant unconditionally; INSERT IGNORE makes it idempotent
    // and handles the case where the permission was added on a prior
    // run but no roles were granted yet.
    try {
        db_query("
            INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id)
            SELECT r.id, (SELECT id FROM `{$prefix}permissions` WHERE code = 'action.manage_par' LIMIT 1)
              FROM `{$prefix}roles` r
             WHERE r.legacy_level IN (0, 1, 2)
                OR r.name IN ('Super Admin', 'Org Admin', 'Dispatcher')
        ");
    } catch (Exception $e) {
        echo "[WARN] grant re-apply: " . $e->getMessage() . "\n";
    }
} catch (Exception $e) {
    echo "[WARN] RBAC permission: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
