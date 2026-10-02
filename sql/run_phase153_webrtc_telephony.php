<?php
/**
 * Phase 153 (2026-09-08) — Browser-native WebRTC telephony schema.
 *
 * `phone_extensions` — one row per SIP extension a browser can register as
 * (via JsSIP over WSS to the Asterisk PBX). Distinct from Phase 149's
 * `pbx_trunks`: a trunk is an inbound line an EXTERNAL PBX/provider posts
 * webhooks against; an extension here is a REGISTERED endpoint the
 * TicketsCAD browser itself controls directly. `is_general` marks the
 * shared "ring every workstation" number (there is exactly one expected,
 * but not enforced as a hard constraint here -- Asterisk's own dialplan is
 * the actual ring-group; this flag is only how the PHP/JS side decides
 * "broadcast to everyone" vs "target one workstation" when a call comes
 * in). `sip_password` is plaintext by necessity (the browser needs the
 * real value to register) -- same deliberate, documented convention as
 * inc/sip_token.php / inc/dmr_token.php for this exact class of credential.
 *
 * `inbound_calls` gains two columns (idempotent ALTER, guarded):
 *   - `called_extension_id` — FK-by-convention (no enforced FK, matching
 *     this table family's existing plain-INT-reference convention) to
 *     phone_extensions, so the UI can show "Direct: Workstation 2" vs
 *     "General Number" on the ringing banner.
 *   - `constituent_id` — the constituents row this call's caller number
 *     matched or was created against (inc/inbound-calls.php resolves this
 *     at ingest time; see that file for the lookup-or-create logic).
 *
 * Idempotent -- guarded CREATE/ALTER per this project's convention.
 * Auto-discovered by sql/run_migrations.php's normal run_*.php sweep.
 *
 * Spec: specs/phase-153-webrtc-telephony-allstar/{spec.md,plan.md,tasks.md}
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 153 — WebRTC Telephony schema\n";
echo "=====================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _p153_table_exists(string $t): bool {
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

function _p153_column_exists(string $table, string $column): bool {
    global $prefix;
    try {
        $r = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . $table, $column]
        );
        return !empty($r);
    } catch (Exception $e) { return false; }
}

// ── A. phone_extensions ──────────────────────────────────────────────────
if (!_p153_table_exists('phone_extensions')) {
    try {
        db_query(
            "CREATE TABLE `{$prefix}phone_extensions` (
                `id`                 INT AUTO_INCREMENT PRIMARY KEY,
                `extension`          VARCHAR(20) NOT NULL,
                `label`              VARCHAR(100) NOT NULL,
                `sip_username`       VARCHAR(100) NOT NULL,
                `sip_password`       VARCHAR(255) NOT NULL,
                `workstation_token`  VARCHAR(64) NULL DEFAULT NULL,
                `org_id`             INT NULL DEFAULT NULL,
                `is_general`         TINYINT(1) NOT NULL DEFAULT 0,
                `enabled`            TINYINT(1) NOT NULL DEFAULT 1,
                `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_extension` (`extension`),
                KEY `idx_org` (`org_id`),
                KEY `idx_workstation` (`workstation_token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        echo "[OK] phone_extensions created\n";
    } catch (Exception $e) {
        echo "[FAIL] phone_extensions: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP] phone_extensions already exists\n";
}

// ── B. inbound_calls: called_extension_id + constituent_id ─────────────────
if (_p153_table_exists('inbound_calls')) {
    if (!_p153_column_exists('inbound_calls', 'called_extension_id')) {
        try {
            db_query("ALTER TABLE `{$prefix}inbound_calls` ADD COLUMN `called_extension_id` INT NULL DEFAULT NULL AFTER `called_number`");
            echo "[OK] inbound_calls.called_extension_id added\n";
        } catch (Exception $e) {
            echo "[FAIL] inbound_calls.called_extension_id: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[SKIP] inbound_calls.called_extension_id already exists\n";
    }

    if (!_p153_column_exists('inbound_calls', 'constituent_id')) {
        try {
            db_query("ALTER TABLE `{$prefix}inbound_calls` ADD COLUMN `constituent_id` INT NULL DEFAULT NULL AFTER `caller_name`");
            echo "[OK] inbound_calls.constituent_id added\n";
        } catch (Exception $e) {
            echo "[FAIL] inbound_calls.constituent_id: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[SKIP] inbound_calls.constituent_id already exists\n";
    }
} else {
    echo "[SKIP] inbound_calls table does not exist yet (Phase 149 migration not run) -- nothing to alter\n";
}

echo "\nDone.\n";
