<?php
/**
 * Phase 18a (2026-06-11) — Security labels foundation.
 *
 * Creates the security_labels table + companion columns on
 * in_types/ticket + the pending_routed_messages send-delay queue,
 * seeds three default labels, registers the RBAC permissions
 * spanning the full Phase 18 feature set.
 *
 * Idempotent. Spec: specs/phase-18-incident-sensitivity-2026-06/spec.md
 */
require_once __DIR__ . '/../config.php';

echo "Phase 18a — Security labels foundation\n";
echo "======================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _p18_table_exists(string $t): bool {
    global $prefix;
    try {
        $r = db_fetch_one(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . $t]
        );
        return !empty($r);
    } catch (Exception $e) { return false; }
}

function _p18_col_exists(string $t, string $c): bool {
    global $prefix;
    try {
        $r = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . $t, $c]
        );
        return !empty($r);
    } catch (Exception $e) { return false; }
}

// ── security_labels ──────────────────────────────────────────────────
if (!_p18_table_exists('security_labels')) {
    db_query("
        CREATE TABLE `{$prefix}security_labels` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code`             VARCHAR(32)  NOT NULL,
            `name`             VARCHAR(64)  NOT NULL,
            `sort_order`       INT UNSIGNED NOT NULL DEFAULT 100,
            `is_default`       TINYINT(1)   NOT NULL DEFAULT 0,
            `badge_bg_color`   VARCHAR(16)  NULL,
            `badge_text_color` VARCHAR(16)  NULL,

            `eoc_show_scope`           TINYINT(1) NOT NULL DEFAULT 1,
            `eoc_show_address`         TINYINT(1) NOT NULL DEFAULT 1,
            `eoc_show_map_marker`      ENUM('full','dim','hide') NOT NULL DEFAULT 'full',
            `eoc_placeholder_text`     VARCHAR(64) NULL,

            `routing_allow_broadcast`  TINYINT(1) NOT NULL DEFAULT 1,
            `routing_allow_direct`     TINYINT(1) NOT NULL DEFAULT 1,
            `routing_send_delay_secs`  INT UNSIGNED NOT NULL DEFAULT 0,
            `routing_recall_window_s`  INT UNSIGNED NOT NULL DEFAULT 0,

            `ics_export_show_full`     TINYINT(1) NOT NULL DEFAULT 1,
            `ics_watermark_text`       VARCHAR(64) NULL,

            `audit_required_reason`    TINYINT(1) NOT NULL DEFAULT 0,

            `created_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_code` (`code`),
            KEY `idx_sort` (`sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Created security_labels\n";
} else {
    echo "[OK] security_labels already exists\n";
}

// ── Seed three default labels (only on fresh table) ─────────────────
try {
    $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}security_labels`");
    if ($count === 0) {
        $seeds = [
            ['standard',     'Standard',     10, 1, '#198754', '#ffffff',
             1, 1, 'full', null,
             1, 1, 0, 0,
             1, null,
             0],
            ['restricted',   'Restricted',   20, 0, '#ffc107', '#000000',
             1, 0, 'dim', '*** Restricted *** see dispatch console',
             0, 1, 30, 0,
             0, 'Restricted',
             1],
            ['confidential', 'Confidential', 30, 0, '#dc3545', '#ffffff',
             0, 0, 'hide', '*** Confidential incident ***',
             0, 1, 60, 0,
             0, 'Confidential',
             1],
        ];
        foreach ($seeds as $s) {
            db_query("
                INSERT INTO `{$prefix}security_labels`
                  (code, name, sort_order, is_default,
                   badge_bg_color, badge_text_color,
                   eoc_show_scope, eoc_show_address, eoc_show_map_marker, eoc_placeholder_text,
                   routing_allow_broadcast, routing_allow_direct,
                   routing_send_delay_secs, routing_recall_window_s,
                   ics_export_show_full, ics_watermark_text,
                   audit_required_reason)
                VALUES (?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?,
                        ?, ?,
                        ?, ?,
                        ?, ?,
                        ?)", $s);
        }
        echo "[OK] Seeded 3 default labels: Standard / Restricted / Confidential\n";
    } else {
        echo "[OK] security_labels already has {$count} row(s); skipping seed\n";
    }
} catch (Exception $e) {
    echo "[WARN] seed: " . $e->getMessage() . "\n";
}

// ── in_types.default_security_label_id ──────────────────────────────
if (!_p18_col_exists('in_types', 'default_security_label_id')) {
    try {
        db_query("ALTER TABLE `{$prefix}in_types`
                  ADD COLUMN `default_security_label_id` INT UNSIGNED NULL
                  COMMENT 'Phase 18 — null = use system default'");
        echo "[OK] Added in_types.default_security_label_id\n";
    } catch (Exception $e) {
        echo "[WARN] in_types alter: " . $e->getMessage() . "\n";
    }
} else {
    echo "[OK] in_types.default_security_label_id already exists\n";
}

// ── ticket.security_* columns ───────────────────────────────────────
$ticketCols = [
    'security_label_override_id' => "INT UNSIGNED NULL COMMENT 'Phase 18 — null = use type/system default'",
    'security_set_by'            => "INT UNSIGNED NULL",
    'security_set_at'            => "DATETIME NULL",
    'security_reason'            => "VARCHAR(255) NULL",
];
foreach ($ticketCols as $col => $def) {
    if (!_p18_col_exists('ticket', $col)) {
        try {
            db_query("ALTER TABLE `{$prefix}ticket` ADD COLUMN `{$col}` {$def}");
            echo "[OK] Added ticket.{$col}\n";
        } catch (Exception $e) {
            echo "[WARN] ticket.{$col}: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[OK] ticket.{$col} already exists\n";
    }
}

// ── pending_routed_messages (the send-delay queue) ──────────────────
if (!_p18_table_exists('pending_routed_messages')) {
    db_query("
        CREATE TABLE `{$prefix}pending_routed_messages` (
            `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ticket_id`         BIGINT UNSIGNED NULL,
            `route_id`          INT UNSIGNED NULL,
            `channel`           VARCHAR(64) NOT NULL,
            `target`            VARCHAR(255) NOT NULL,
            `subject`           VARCHAR(255) NULL,
            `body`              TEXT NOT NULL,
            `priority`          VARCHAR(16) NULL,
            `scheduled_send_at` DATETIME NOT NULL,
            `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by`        INT UNSIGNED NULL,
            `status`            ENUM('pending','sent','killed','failed') NOT NULL DEFAULT 'pending',
            `sent_at`           DATETIME NULL,
            `killed_at`         DATETIME NULL,
            `killed_by`         INT UNSIGNED NULL,
            `killed_reason`     VARCHAR(255) NULL,
            `send_error`        VARCHAR(255) NULL,
            PRIMARY KEY (`id`),
            KEY `idx_scheduled` (`scheduled_send_at`, `status`),
            KEY `idx_ticket`    (`ticket_id`),
            KEY `idx_status`    (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Created pending_routed_messages (send-delay queue)\n";
} else {
    echo "[OK] pending_routed_messages already exists\n";
}

// ── RBAC permissions (Phase 18 full set) ────────────────────────────
$perms = [
    ['action.set_incident_security', 'Override an incident security label',
     'action', 'incident',  'set_security'],
    ['action.kill_pending_message',  'Kill a queued routed message before it sends',
     'action', 'routing',   'kill'],
    ['action.recall_routed_message', 'Best-effort recall a sent routed message within its recall window',
     'action', 'routing',   'recall'],
    ['action.manage_security_labels','CRUD on security_labels table (Super Admin only)',
     'action', 'security_labels', 'manage'],
    ['screen.view_eoc_display',      'Open the EOC Display page',
     'screen', 'eoc_display', 'view'],
];
foreach ($perms as $p) {
    [$code, $desc, $cat, $res, $verb] = $p;
    try {
        $exists = db_fetch_value(
            "SELECT 1 FROM `{$prefix}permissions` WHERE code = ? LIMIT 1", [$code]);
        if ($exists) {
            echo "[OK] {$code} already exists\n";
            continue;
        }
        $name = ucwords(str_replace(['action.', 'screen.', '_', '.'], ['', '', ' ', ' '], $code));
        db_query("
            INSERT INTO `{$prefix}permissions` (code, name, category, resource, verb, description)
            VALUES (?, ?, ?, ?, ?, ?)",
            [$code, $name, $cat, $res, $verb, $desc]
        );
        echo "[OK] Added permission {$code}\n";
    } catch (Exception $e) {
        echo "[WARN] perm {$code}: " . $e->getMessage() . "\n";
    }
}

// Grant defaults — match spec's "default-granted to" column.
$grants = [
    'action.set_incident_security' => "r.legacy_level IN (0,1,2) OR r.name IN ('Super Admin','Org Admin','Dispatcher','Operator')",
    'action.kill_pending_message'  => "r.legacy_level IN (0,1,2) OR r.name IN ('Super Admin','Org Admin','Dispatcher')",
    'action.recall_routed_message' => "r.legacy_level IN (0,1,2) OR r.name IN ('Super Admin','Org Admin','Dispatcher')",
    'action.manage_security_labels'=> "r.legacy_level = 0 OR r.name = 'Super Admin'",
    'screen.view_eoc_display'      => "r.legacy_level <= 4 OR r.is_super = 1 OR r.name NOT IN ('Read Only','Field Unit')",
];
foreach ($grants as $code => $where) {
    try {
        db_query("
            INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id)
            SELECT r.id,
                   (SELECT id FROM `{$prefix}permissions` WHERE code = ? LIMIT 1)
              FROM `{$prefix}roles` r
             WHERE {$where}", [$code]);
    } catch (Exception $e) {
        echo "[WARN] grant {$code}: " . $e->getMessage() . "\n";
    }
}
echo "[OK] Default grants applied for Phase 18 permissions\n";

// ── Cache the default label code in settings for quick lookup ──────
try {
    $defaultCode = (string) db_fetch_value(
        "SELECT code FROM `{$prefix}security_labels` WHERE is_default = 1 LIMIT 1");
    if ($defaultCode === '') {
        $defaultCode = (string) db_fetch_value(
            "SELECT code FROM `{$prefix}security_labels` ORDER BY sort_order LIMIT 1");
    }
    if ($defaultCode !== '') {
        db_query(
            "INSERT INTO `{$prefix}settings` (name, value) VALUES ('incident_default_security_label', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$defaultCode]
        );
        echo "[OK] incident_default_security_label cached as '{$defaultCode}'\n";
    }
} catch (Exception $e) {
    echo "[WARN] default cache: " . $e->getMessage() . "\n";
}

echo "\nPhase 18a done.\n";
