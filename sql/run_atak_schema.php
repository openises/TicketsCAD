<?php
/**
 * Phase 91 Slice 1 — ATAK / TAK interop schema + seed data.
 *
 * Post-consolidation (2026-06-24): the parallel atak_channels and
 * atak_push_log tables are NOT created here anymore. ATAK channel
 * policy lives on mesh_channels (Phase 35) via the atak_* columns
 * added by sql/run_atak_consolidation.php. The CoT audit trail is
 * mesh_packet_log + mesh_outbox.
 *
 * What this migration still does (idempotent):
 *   - atak_unbound_uids table (operator-review surface for orphaned
 *     ATAK uids; no equivalent in mesh schema)
 *   - location_providers += 'atak' row
 *   - comm_modes += 'atak' row
 *   - settings defaults
 *   - RBAC permission action.manage_atak
 *
 * Fresh installs must also run sql/run_atak_consolidation.php to
 * get the ATAK columns onto mesh_channels.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 91 Slice 1 — ATAK schema + seed\n";
echo "=====================================\n";

// ── atak_unbound_uids ───────────────────────────────────────────
// (atak_channels and atak_push_log are no longer created here — see
// consolidation note at the top. Fresh installs run this migration
// AND sql/run_atak_consolidation.php to get the ATAK columns on
// mesh_channels.)
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}atak_unbound_uids` (
        `id`             INT NOT NULL AUTO_INCREMENT,
        `atak_uid`       VARCHAR(120) NOT NULL,
        `callsign_seen`  VARCHAR(64) NULL,
        `transport`      ENUM('meshtastic','tak_server') NOT NULL,
        `channel_ref`    VARCHAR(120) NOT NULL,
        `first_seen`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_seen`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `position_count` INT NOT NULL DEFAULT 1,
        `last_lat`       DECIMAL(10,7) NULL,
        `last_lng`       DECIMAL(10,7) NULL,
        `bound_to`       INT NULL COMMENT 'member.id once operator binds; row stays for audit',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_atak_uid` (`atak_uid`),
        KEY `idx_atak_unbound_last` (`last_seen`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  [ok] atak_unbound_uids table ready\n";
} catch (Exception $e) {
    echo "  [err] atak_unbound_uids: " . $e->getMessage() . "\n";
    throw $e;
}

// ── Seed: location_providers row ────────────────────────────────
try {
    $exists = db_fetch_value(
        "SELECT 1 FROM `{$prefix}location_providers` WHERE `code` = ?",
        ['atak']
    );
    if (!$exists) {
        db_query(
            "INSERT INTO `{$prefix}location_providers`
                (code, name, enabled, priority, config_json, icon, color)
             VALUES ('atak', 'ATAK / TAK', 0, 25,
                     ?, 'bi-geo-alt-fill', '#1A5490')",
            ['{"transport":"multi","note":"Bidirectional CoT via Meshtastic and/or TAK Server"}']
        );
        echo "  [ok] seeded location_providers.atak row (enabled=0)\n";
    } else {
        echo "  [skip] location_providers.atak row already present\n";
    }
} catch (Exception $e) {
    echo "  [warn] location_providers seed: " . $e->getMessage() . "\n";
}

// ── Seed: comm_modes row ────────────────────────────────────────
$atakFields = json_encode([
    [
        'key'         => 'atak_uid',
        'label'       => 'ATAK device UID',
        'type'        => 'text',
        'placeholder' => '9d8c4f3a-1b2c-...',
        'maxlength'   => 120,
        'required'    => true,
        'hint'        => "Paste from ATAK → Settings → Device Preferences → My Callsign and Device Preferences → UID.",
    ],
    [
        'key'         => 'atak_callsign',
        'label'       => 'ATAK callsign',
        'type'        => 'text',
        'placeholder' => 'N0NKI',
        'maxlength'   => 64,
        'required'    => false,
        'hint'        => 'Optional — the visible callsign the ATAK app shows on the map.',
    ],
]);

try {
    $exists = db_fetch_value(
        "SELECT 1 FROM `{$prefix}comm_modes` WHERE `code` = ?",
        ['atak']
    );
    if (!$exists) {
        db_query(
            "INSERT INTO `{$prefix}comm_modes`
                (code, name, icon, color, fields_json, capabilities,
                 lookup_url, enabled, sort_order, notes)
             VALUES ('atak', 'ATAK', 'geo-alt-fill', '#1A5490',
                     ?, 'LM', '', 1, 60,
                     'ATAK device — binding key is the device uid the ATAK app generates per install.')",
            [$atakFields]
        );
        echo "  [ok] seeded comm_modes.atak row\n";
    } else {
        // Refresh fields_json + notes so an existing stub picks up improvements,
        // but DON'T touch enabled / sort_order (operator's choice).
        db_query(
            "UPDATE `{$prefix}comm_modes`
                SET fields_json = ?, notes = ?, icon = ?, color = ?
              WHERE code = 'atak'",
            [$atakFields,
             'ATAK device — binding key is the device uid the ATAK app generates per install.',
             'geo-alt-fill', '#1A5490']
        );
        echo "  [ok] refreshed comm_modes.atak fields_json + notes\n";
    }
} catch (Exception $e) {
    echo "  [warn] comm_modes seed: " . $e->getMessage() . "\n";
}

// ── Seed: settings defaults ─────────────────────────────────────
$settingDefaults = [
    'atak_marker_nearest_radius_meters' => '500',
    'atak_inbound_require_token'         => '0',
];
foreach ($settingDefaults as $k => $v) {
    try {
        $has = db_fetch_value(
            "SELECT 1 FROM `{$prefix}settings` WHERE `name` = ?",
            [$k]
        );
        if (!$has) {
            db_query(
                "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
                [$k, $v]
            );
            echo "  [ok] seeded setting {$k}={$v}\n";
        } else {
            echo "  [skip] setting {$k} already present (operator value preserved)\n";
        }
    } catch (Exception $e) {
        echo "  [warn] settings seed {$k}: " . $e->getMessage() . "\n";
    }
}

// ── RBAC permission ─────────────────────────────────────────────
try {
    $hasRbac = db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'permissions']
    );
    if ($hasRbac) {
        $perm = 'action.manage_atak';
        $exists = db_fetch_value(
            "SELECT 1 FROM `{$prefix}permissions` WHERE `code` = ?",
            [$perm]
        );
        if (!$exists) {
            db_query(
                "INSERT INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
                 VALUES (?, ?, 'action', ?)",
                [$perm, 'Manage ATAK / TAK Integration',
                 'Configure ATAK channels + TAK Server connections; mint and revoke ATAK ingest tokens; bind unbound ATAK uids to personnel']
            );
            echo "  [ok] minted RBAC permission '{$perm}'\n";

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
