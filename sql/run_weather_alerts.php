<?php
/**
 * Migration: Phase 112 — NWS weather alerts (Phase 1: poll + notify)
 *
 * Creates the configurable weather-alert store + coverage-area + routing-rule
 * tables, and seeds the UNIVERSAL, INERT defaults only:
 *   - master switch weather_alerts_enabled = 0 (OFF)
 *   - poll cadence, provider, and TTS read-out sub-setting defaults
 *
 * DELIBERATELY seeds NO areas or rules. The spec's Minnesota examples are
 * Eric-install-specific; this migration runs on every install worldwide and
 * "a fresh non-US install seeds none" (spec §Configurability). Eric's MN rows
 * are loaded from the settings panel's one-click "Minnesota example" button
 * (or applied directly on training/Bloomington), never globally. Result: a
 * fresh install is completely inert — master OFF, zero areas, zero rules, no
 * NWS traffic, no UI noise.
 *
 * Safety: fully idempotent. CREATE TABLE IF NOT EXISTS; settings seeds are
 * select-then-insert so a re-run never clobbers an admin's choices and never
 * duplicates. Touches nothing on a weather-off install beyond four empty
 * tables and a handful of '0'/default settings rows.
 *
 * Usage: php sql/run_weather_alerts.php   (also auto-run by sql/run_migrations.php)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 112 — NWS weather alerts (schema + inert defaults)\n";

/** Run a DDL statement, treating "already exists" as success. */
function _wx_ddl(string $sql, string $label): void {
    try {
        db_query($sql);
        echo "[OK] {$label}\n";
    } catch (Throwable $e) {
        $m = $e->getMessage();
        if (stripos($m, 'already exists') !== false || stripos($m, 'duplicate') !== false) {
            echo "[--] {$label} (already present)\n";
        } else {
            echo "[WARN] {$label}: {$m}\n";
        }
    }
}

// ── Coverage areas (0..N; configurable list) ──────────────────────────────
_wx_ddl(
    "CREATE TABLE IF NOT EXISTS `{$prefix}weather_alert_areas` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `label` VARCHAR(120) NOT NULL,
        `kind` ENUM('state','zones','point_radius') NOT NULL,
        `state_code` CHAR(2) NULL,
        `zones` TEXT NULL,
        `lat` DECIMAL(9,6) NULL,
        `lng` DECIMAL(9,6) NULL,
        `radius_miles` INT NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `sort_order` INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'weather_alert_areas'
);

// ── Per-target routing rules (0..N) ───────────────────────────────────────
_wx_ddl(
    "CREATE TABLE IF NOT EXISTS `{$prefix}weather_alert_rules` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `label` VARCHAR(120) NOT NULL,
        `area_id` INT NOT NULL,
        `target` ENUM('tray','chat','sms','email','zello','dmr') NOT NULL,
        `target_ref` VARCHAR(120) NULL,
        `min_severity` ENUM('Minor','Moderate','Severe','Extreme') NOT NULL DEFAULT 'Severe',
        `min_urgency` ENUM('Past','Future','Expected','Immediate') NOT NULL DEFAULT 'Expected',
        `event_allow` TEXT NULL,
        `event_deny` TEXT NULL,
        `message_types` VARCHAR(60) NOT NULL DEFAULT 'Alert,Update',
        `action_mode` ENUM('notify','auto_fire','operator_approve') NOT NULL DEFAULT 'notify',
        `repeat_on_update` TINYINT(1) NOT NULL DEFAULT 1,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `sort_order` INT NOT NULL DEFAULT 0,
        KEY `idx_area` (`area_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'weather_alert_rules'
);

// ── Seen / active alerts (de-dup + lifecycle) ─────────────────────────────
_wx_ddl(
    "CREATE TABLE IF NOT EXISTS `{$prefix}weather_alerts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `nws_id` VARCHAR(255) NOT NULL,
        `event` VARCHAR(120) NULL,
        `severity` VARCHAR(20) NULL,
        `urgency` VARCHAR(20) NULL,
        `certainty` VARCHAR(20) NULL,
        `message_type` VARCHAR(20) NULL,
        `area_desc` TEXT NULL,
        `headline` TEXT NULL,
        `description` MEDIUMTEXT NULL,
        `instruction` MEDIUMTEXT NULL,
        `onset` DATETIME NULL,
        `expires` DATETIME NULL,
        `ends` DATETIME NULL,
        `geocode_ugc` TEXT NULL,
        `polygon` MEDIUMTEXT NULL,
        `centroid_lat` DECIMAL(9,6) NULL,
        `centroid_lng` DECIMAL(9,6) NULL,
        `status` ENUM('active','cancelled','expired') NOT NULL DEFAULT 'active',
        `first_seen` DATETIME NOT NULL,
        `last_seen` DATETIME NOT NULL,
        UNIQUE KEY `uk_nws` (`nws_id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'weather_alerts'
);

// ── Per-target dispatch ledger (no duplicate notify/read-outs) ────────────
_wx_ddl(
    "CREATE TABLE IF NOT EXISTS `{$prefix}weather_alert_dispatch` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `alert_id` INT NOT NULL,
        `rule_id` INT NOT NULL,
        `nws_message_type` VARCHAR(20) NULL,
        `status` ENUM('sent','queued','skipped','failed') NOT NULL,
        `detail` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL,
        UNIQUE KEY `uk_once` (`alert_id`, `rule_id`, `nws_message_type`),
        KEY `idx_alert` (`alert_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'weather_alert_dispatch'
);

// ── Inert setting defaults (select-then-insert; never clobber an admin) ────
// settings.name is made UNIQUE by run_phase24_settings_unique_name.php, which
// sorts before this file in the migration glob; but we select-then-insert
// anyway so this is safe even standalone / pre-phase24.
$defaults = [
    'weather_alerts_enabled'            => '0',     // MASTER SWITCH — OFF
    'weather_provider'                  => 'nws',
    'weather_poll_seconds'              => '60',
    'weather_ua_contact'                => '',       // required when enabled
    'weather_tts_clear_channel_seconds' => '3.0',
    'weather_tts_callsign'              => '',
    'weather_tts_prefix'                => 'Weather bulletin from the National Weather Service.',
    'weather_tts_voice'                 => '',
    'weather_tts_max_seconds'           => '45',
];
$seeded = 0;
foreach ($defaults as $name => $val) {
    try {
        $exists = db_fetch_one(
            "SELECT `id` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
            [$name]
        );
        if (!$exists) {
            db_query(
                "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
                [$name, $val]
            );
            $seeded++;
        }
    } catch (Throwable $e) {
        echo "[WARN] setting {$name}: " . $e->getMessage() . "\n";
    }
}
echo "[OK] setting defaults ({$seeded} new, master switch OFF)\n";

echo "Done. Feature is inert until an admin turns on weather_alerts_enabled.\n";
