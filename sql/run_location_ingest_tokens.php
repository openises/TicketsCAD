<?php
/**
 * Phase 89 — Per-device ingest tokens for Traccar / OpenGTS / generic
 * location ingest. Companion to Phase 88's native receiver.
 *
 * Why a separate table from member_tracking_tokens (Phase 41)?
 *
 *   Phase 41 tokens are *per-member* — an OwnTracks user gets one when
 *   they're set up to share their phone's GPS. The binding is to a
 *   person, and rotation flows through the OwnTracks setConfiguration
 *   outbox.
 *
 *   Phase 89 tokens are *per-device* — an admin mints one to authorize
 *   a fleet GPS modem (a Traccar Server, an IoT tracker, a Garmin
 *   handheld speaking OpenGTS). The binding is to a device-identifier
 *   string (an IMEI, a Traccar uniqueId, anything the device sends as
 *   its ID), not a TicketsCAD user. There's no companion config push.
 *
 * Either auth path is acceptable in api/location.php's Traccar/OpenGTS
 * receiver — a per-device token, OR the legacy shared secret in
 * settings.location_ingest_secret. Per-device is preferred because:
 *
 *   - One leaked token revokes one device, not the entire fleet
 *   - last_used_at / last_used_ip gives operators visibility
 *   - Scoping to a single provider_id prevents an OwnTracks-meant
 *     token from being misused against the Traccar endpoint
 *
 * Idempotent. Safe to re-run on existing installs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 89 — location_ingest_tokens schema + settings defaults\n";
echo "============================================================\n";

// ── 1. The tokens table ─────────────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}location_ingest_tokens` (
        `id`              INT NOT NULL AUTO_INCREMENT,
        `label`           VARCHAR(120) NOT NULL COMMENT 'Admin-friendly name, e.g. Truck-7-Teltonika',
        `secret_hash`     CHAR(64) NOT NULL COMMENT 'sha256 of the raw token value',
        `provider_id`     INT NULL COMMENT 'NULL = accepted on any provider; else scoped to one of location_providers.id',
        `device_unique_id` VARCHAR(120) NULL COMMENT 'Optional binding key; when set the receiver only accepts this token if the report unit_identifier matches',
        `created_by`      INT NULL,
        `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_used_at`    DATETIME NULL,
        `last_used_ip`    VARCHAR(45) NULL,
        `revoked_at`      DATETIME NULL,
        `notes`           VARCHAR(255) NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_secret_hash` (`secret_hash`),
        KEY `idx_lit_provider` (`provider_id`),
        KEY `idx_lit_device`   (`device_unique_id`),
        KEY `idx_lit_revoked`  (`revoked_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  [ok] location_ingest_tokens table ready\n";
} catch (Exception $e) {
    echo "  [err] create table: " . $e->getMessage() . "\n";
    throw $e;
}

// ── 2. Make sure location_reports has auth_token_id ─────────────
// Phase 41 added it for the OwnTracks path; on rare older installs
// without Phase 41 applied first, we need it for the Phase 89 receiver
// to attribute incoming traccar/opengts reports to the matched token.
try {
    $has = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = 'auth_token_id'",
        [$prefix . 'location_reports']
    );
    if (!$has) {
        db_query("ALTER TABLE `{$prefix}location_reports`
                  ADD COLUMN `auth_token_id` INT NULL,
                  ADD KEY `idx_auth_token` (`auth_token_id`)");
        echo "  [ok] added location_reports.auth_token_id\n";
    } else {
        echo "  [skip] location_reports.auth_token_id already present\n";
    }
} catch (Exception $e) {
    echo "  [warn] auth_token_id check: " . $e->getMessage() . "\n";
}

// ── 3. Seed sensible defaults for the ingest flags ──────────────
// These are the settings the Phase 88 receiver already reads. Without
// rows in the settings table, get_variable() returns the empty string
// which the receiver interprets correctly (anonymous + null-island
// guard on), but seeding makes them discoverable in the new UI panel.
$defaults = [
    'location_ingest_require_token'      => '0',
    'location_ingest_secret'             => '',
    'location_ingest_allow_null_island'  => '0',
    'location_ingest_rate_limit_per_min' => '600',
];

foreach ($defaults as $k => $v) {
    try {
        $exists = db_fetch_value(
            "SELECT 1 FROM `{$prefix}settings` WHERE `name` = ?",
            [$k]
        );
        if (!$exists) {
            db_query(
                "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
                [$k, $v]
            );
            echo "  [ok] seeded setting {$k} = '" . ($v === '' ? '<empty>' : $v) . "'\n";
        } else {
            echo "  [skip] setting {$k} already present (operator value preserved)\n";
        }
    } catch (Exception $e) {
        echo "  [warn] {$k}: " . $e->getMessage() . "\n";
    }
}

// ── 4. RBAC permission for managing ingest tokens ───────────────
// Reuses the existing action.manage_config permission for the panel
// itself, but mints a more specific action.manage_ingest_tokens for
// audit trail purposes — token minting is more sensitive than the
// surrounding settings.
try {
    $hasRbac = db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?",
        [$prefix . 'permissions']
    );
    if ($hasRbac) {
        $perm = 'action.manage_ingest_tokens';
        $exists = db_fetch_value(
            "SELECT 1 FROM `{$prefix}permissions` WHERE `code` = ?",
            [$perm]
        );
        if (!$exists) {
            db_query(
                "INSERT INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
                 VALUES (?, ?, 'action', ?)",
                [$perm, 'Manage Location Ingest Tokens',
                 'Mint, list, and revoke per-device location-ingest tokens for Traccar/OpenGTS']
            );
            echo "  [ok] minted RBAC permission '{$perm}'\n";

            // Grant it to Super Admin (and Org Admin if the role exists).
            // Match by role.name — the role IDs vary per install.
            $supers = db_fetch_all(
                "SELECT id FROM `{$prefix}roles` WHERE name IN ('Super Admin','Org Admin')"
            );
            foreach ($supers as $r) {
                $permId = (int) db_fetch_value(
                    "SELECT id FROM `{$prefix}permissions` WHERE code = ?",
                    [$perm]
                );
                db_query(
                    "INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id)
                     VALUES (?, ?)",
                    [(int) $r['id'], $permId]
                );
            }
            echo "  [ok] granted {$perm} to Super Admin / Org Admin\n";
        } else {
            echo "  [skip] permission '{$perm}' already present\n";
        }
    } else {
        echo "  [skip] permissions table not present — pre-RBAC install\n";
    }
} catch (Exception $e) {
    echo "  [warn] RBAC seed: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
