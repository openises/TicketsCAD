<?php
/**
 * Phase 149 (2026-08-22) — Inbound SIP/PBX Call Integration, schema.
 *
 * Three new tables per specs/phase-149-inbound-sip-calls/plan.md §3:
 *
 *   - `pbx_trunks` — one row per configured SIP trunk/line. `org_id` NULL
 *     means "visible install-wide" (the common single-agency case).
 *     `reassign_grace_seconds` backs FR-18a's quick-reassignment window
 *     (plan.md §4a) -- distinct from `wrapup_seconds`, which governs the
 *     post-call paperwork window (plan.md §4, "Wrap-up" section).
 *   - `inbound_calls` — one mutable row per PBX call, transitioning through
 *     `state`. `UNIQUE KEY uk_trunk_call (trunk_id, provider_call_id)` IS
 *     the idempotency mechanism (plan.md §2) -- there is deliberately no
 *     separate dedupe table (unlike the broker's inbound_message_dedupe):
 *     a call is one row transitioning through states, not an append-only
 *     message log. `org_id` is present from day one (denormalized from
 *     pbx_trunks.org_id at ingest time) -- CLAUDE.md documents this exact
 *     class of table (facilities/responder/teams/newui_equipment/
 *     newui_vehicles) shipping without org_id and silently breaking
 *     multi-tenant isolation, five separate times. Not repeating that here.
 *     `stale_since` is a separate column from `state`, not a sixth ENUM
 *     value -- staleness is a liveness signal layered on an otherwise-
 *     unchanged claim, not a distinct lifecycle state (plan.md §4).
 *   - `inbound_call_events` — append-only audit trail. `actor_name` is
 *     denormalized per this project's standing audit-trail convention (a
 *     log must survive the acting user's later deletion).
 *
 * Idempotent -- guarded CREATE TABLE IF NOT EXISTS per this project's
 * convention (tools/schema_audit.php / tools/gen_schema_manifest.php
 * pick up the writers in inc/inbound-calls.php + api/inbound-calls.php,
 * not this migration file itself). Auto-discovered by
 * sql/run_migrations.php's normal run_*.php sweep.
 *
 * On a zero-trunks-configured install these three tables sit empty and are
 * read by nothing outside this feature's own files -- matches this
 * project's "fully built, off by default" ship discipline (spec.md FR-29).
 *
 * Spec: specs/phase-149-inbound-sip-calls/{spec.md,plan.md,tasks.md}
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 149 — Inbound SIP/PBX Call Integration (schema)\n";
echo "=======================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

if (!function_exists('_p149_table_exists')) {
    // Guarded so this script can be `include`d twice in one process (this
    // project's schema tests drive the real migration file directly to
    // prove idempotency, rather than re-deriving its logic).
    function _p149_table_exists(string $t): bool {
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
}

// ── A. pbx_trunks — per-trunk admin configuration ───────────────────────
if (!_p149_table_exists('pbx_trunks')) {
    try {
        db_query(
            "CREATE TABLE `{$prefix}pbx_trunks` (
                `id`                     INT AUTO_INCREMENT PRIMARY KEY,
                `label`                  VARCHAR(100) NOT NULL,
                `org_id`                 INT NULL DEFAULT NULL,
                `bearer_token`           VARCHAR(255) NOT NULL,
                `mute_bypass_enabled`    TINYINT(1) NOT NULL DEFAULT 1,
                `wrapup_seconds`         INT NOT NULL DEFAULT 90,
                `reassign_grace_seconds` INT NOT NULL DEFAULT 20,
                `enabled`                TINYINT(1) NOT NULL DEFAULT 1,
                `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_org` (`org_id`),
                KEY `idx_enabled` (`enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        echo "[OK] pbx_trunks created\n";
    } catch (Exception $e) {
        echo "[FAIL] pbx_trunks: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP] pbx_trunks already exists\n";
}

// ── B. inbound_calls — one mutable row per PBX call ─────────────────────
if (!_p149_table_exists('inbound_calls')) {
    try {
        db_query(
            "CREATE TABLE `{$prefix}inbound_calls` (
                `id`                INT AUTO_INCREMENT PRIMARY KEY,
                `trunk_id`          INT NOT NULL,
                `org_id`            INT NULL DEFAULT NULL,
                `provider_call_id`  VARCHAR(191) NOT NULL,
                `caller_number`     VARCHAR(40) NULL DEFAULT NULL,
                `caller_name`       VARCHAR(120) NULL DEFAULT NULL,
                `called_number`     VARCHAR(40) NULL DEFAULT NULL,
                `state`             ENUM('ringing','claimed','wrapup','ended','abandoned')
                                        NOT NULL DEFAULT 'ringing',
                `claimed_by`        INT NULL DEFAULT NULL,
                `claimed_by_name`   VARCHAR(120) NULL DEFAULT NULL,
                `claimed_at`        DATETIME NULL DEFAULT NULL,
                `claim_heartbeat_at` DATETIME NULL DEFAULT NULL,
                `stale_since`       DATETIME NULL DEFAULT NULL,
                `released_at`       DATETIME NULL DEFAULT NULL,
                `reassigned_from`   INT NULL DEFAULT NULL,
                `ended_at`          DATETIME NULL DEFAULT NULL,
                `ticket_id`         INT NULL DEFAULT NULL,
                `reviewed_at`       DATETIME NULL DEFAULT NULL,
                `reviewed_by`       INT NULL DEFAULT NULL,
                `ringing_at`        DATETIME NOT NULL,
                `last_event_at`     DATETIME NOT NULL,
                `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_trunk_call` (`trunk_id`, `provider_call_id`),
                KEY `idx_state` (`state`),
                KEY `idx_org` (`org_id`),
                KEY `idx_ticket` (`ticket_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        echo "[OK] inbound_calls created\n";
    } catch (Exception $e) {
        echo "[FAIL] inbound_calls: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP] inbound_calls already exists\n";
}

// ── C. inbound_call_events — append-only audit trail ────────────────────
if (!_p149_table_exists('inbound_call_events')) {
    try {
        db_query(
            "CREATE TABLE `{$prefix}inbound_call_events` (
                `id`             INT AUTO_INCREMENT PRIMARY KEY,
                `call_id`        INT NOT NULL,
                `event_type`     ENUM(
                                     'rang','claimed','released','reassigned',
                                     'force_reclaimed_stale','force_reclaimed_active',
                                     'stale_detected','wrapup_started','ended','abandoned',
                                     'linked_to_ticket','reviewed',
                                     -- Added beyond plan.md §3's literal list: 'claimed_externally'
                                     -- is the informational-only audit note for plan.md §2's
                                     -- claimed_externally webhook event (a physical extension
                                     -- answered with no TicketsCAD claim) -- plan.md's own text
                                     -- says this is 'recorded as an audit note' but the schema
                                     -- section omitted the enum value; added here rather than
                                     -- overloading an unrelated existing value.
                                     'claimed_externally'
                                 ) NOT NULL,
                `actor_user_id`  INT NULL DEFAULT NULL,
                `actor_name`     VARCHAR(120) NULL DEFAULT NULL,
                `reason`         VARCHAR(255) NULL DEFAULT NULL,
                `detail_json`    TEXT NULL DEFAULT NULL,
                `at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_call` (`call_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        echo "[OK] inbound_call_events created\n";
    } catch (Exception $e) {
        echo "[FAIL] inbound_call_events: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP] inbound_call_events already exists\n";
}

echo "\nDone.\n";
