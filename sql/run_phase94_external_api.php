<?php
/**
 * Phase 94 Stage 1 — External API Integration: schema migrations.
 *
 * See specs/phase-94-external-api-integration/ for the full design.
 *
 * What this script lands (per tasks.md Stage 1):
 *
 *   1.2  external_api_tokens table — admin-mintable bearer tokens for
 *        third-party integrators (CAD platforms, mobile apps, IoT
 *        sensors, dashboards). Mirrors the hash-only + last-used +
 *        scopes shape of Phase 89's location_ingest_tokens.
 *
 *   1.3  external_api_rate_limits table — per-token sliding-window
 *        counter (one row per token per minute, GC'd by cron).
 *
 *   1.4  NEW webhook_subscriptions table — replaces the legacy bare
 *        `webhooks` table per spec §7.3 (Decision #3, Eric 2026-06-27).
 *        Named fields from day one (target_url, hmac_secret,
 *        event_filters_json, retry_policy_json, ip_allowlist_json,
 *        plus visibility columns: last_success_at, last_failure_at,
 *        dead_letter_count).
 *
 *   1.5  Data migration from legacy webhooks → webhook_subscriptions.
 *        Idempotent (skip rows already migrated by target_url match).
 *        Legacy table kept in place until Stage 6 verification.
 *
 *   1.6  webhook_deliveries extension: add dead_lettered_at +
 *        replayed_from_id, rename webhook_id → subscription_id, walk
 *        historical rows to set subscription_id by target_url match.
 *        Orphans get subscription_id = 0.
 *
 *   1.7  RBAC permissions: action.manage_external_api_tokens +
 *        action.manage_webhooks. Granted to Super Admin + Org Admin.
 *
 *   1.8  Settings seeds: external_api_require_tls=1, env letter,
 *        default rate limit, per-IP rate limit.
 *
 * Idempotent — every step checks for existing schema before applying.
 * Safe to re-run on existing installs.
 *
 * Run via tools/install_fresh.php (which is updated to wrap this
 * script into the foundational-imports list at the bottom) OR
 * directly: `sudo -u www-data php sql/run_phase94_external_api.php`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 94 Stage 1 — External API Integration schema\n";
echo "===================================================\n";

function _phase94_column_exists(string $prefix, string $table, string $column): bool {
    try {
        $r = db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?
               AND COLUMN_NAME  = ?",
            [$prefix . $table, $column]
        );
        return ((int) $r) > 0;
    } catch (Exception $e) {
        return false;
    }
}

function _phase94_table_exists(string $prefix, string $table): bool {
    try {
        $r = db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?",
            [$prefix . $table]
        );
        return ((int) $r) > 0;
    } catch (Exception $e) {
        return false;
    }
}

// ── 1.2 external_api_tokens ─────────────────────────────────────
echo "  [1.2] external_api_tokens table... ";
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}external_api_tokens` (
        `id`               INT          NOT NULL AUTO_INCREMENT,
        `name`             VARCHAR(120) NOT NULL COMMENT 'Admin-friendly label, e.g. Acme Agency iOS v1.4',
        `description`      VARCHAR(512) NULL     COMMENT 'Operator notes',
        `token_prefix`     VARCHAR(16)  NOT NULL COMMENT 'First 14 visible chars of the raw token (tcad_p_xxxxxxx) — for admin-UI lookup without storing the raw token',
        `token_hash`       CHAR(64)     NOT NULL COMMENT 'sha256 of the raw token (binary-safe)',
        `scopes_json`      TEXT         NOT NULL COMMENT 'JSON array of scope codes, e.g. [\"incidents:read\",\"incidents:write\"]',
        `ip_allowlist_json` TEXT        NULL     COMMENT 'JSON array of CIDR strings; NULL = no IP restriction',
        `user_id`          INT          NOT NULL COMMENT 'Binding user — RBAC checks evaluate against this user (Decision #1, real-user binding)',
        `rate_limit_per_hour` INT       NOT NULL DEFAULT 1000 COMMENT 'Per-token hard ceiling, overridable per token',
        `created_by`       INT          NOT NULL COMMENT 'Admin who minted',
        `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expires_at`       DATETIME     NULL,
        `last_used_at`     DATETIME     NULL,
        `last_used_ip`     VARCHAR(45)  NULL,
        `revoked_at`       DATETIME     NULL,
        `revoked_by`       INT          NULL,
        `revoked_reason`   VARCHAR(255) NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_eat_token_hash` (`token_hash`),
        KEY `idx_eat_user`    (`user_id`),
        KEY `idx_eat_revoked` (`revoked_at`),
        KEY `idx_eat_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// ── 1.3 external_api_rate_limits ────────────────────────────────
echo "  [1.3] external_api_rate_limits table... ";
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}external_api_rate_limits` (
        `token_id`    INT      NOT NULL,
        `bucket_min`  DATETIME NOT NULL COMMENT 'Truncated to minute boundary',
        `count`       INT      NOT NULL DEFAULT 0,
        PRIMARY KEY (`token_id`, `bucket_min`),
        KEY `idx_earl_bucket` (`bucket_min`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// ── 1.4 NEW webhook_subscriptions table ─────────────────────────
echo "  [1.4] webhook_subscriptions table (NEW per Decision #3)... ";
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}webhook_subscriptions` (
        `id`                  INT          NOT NULL AUTO_INCREMENT,
        `name`                VARCHAR(120) NOT NULL COMMENT 'Admin-friendly label',
        `description`         VARCHAR(512) NULL     COMMENT 'Operator notes',
        `target_url`          VARCHAR(512) NOT NULL COMMENT 'Subscriber endpoint URL (HTTPS required in prod per Decision #5)',
        `hmac_secret`         VARCHAR(128) NOT NULL COMMENT 'Per-subscription HMAC-SHA256 secret',
        `event_filters_json`  TEXT         NOT NULL COMMENT 'JSON array of event-type filters; * = all',
        `retry_policy_json`   TEXT         NULL     COMMENT 'JSON: {max_attempts, backoff_seconds[]}; NULL = default policy',
        `active`              TINYINT(1)   NOT NULL DEFAULT 1,
        `ip_allowlist_json`   TEXT         NULL     COMMENT 'Rare; restricts where retries can originate from',
        `created_by`          INT          NULL     COMMENT 'NULL for legacy-migrated rows; shown as Migrated in admin UI',
        `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `last_success_at`     DATETIME     NULL,
        `last_failure_at`     DATETIME     NULL,
        `dead_letter_count`   INT          NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_ws_active`     (`active`),
        KEY `idx_ws_created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// ── 1.5 Data migration: webhooks → webhook_subscriptions ────────
// Only attempted if the legacy table exists. Idempotent — skips rows
// already present in the new table (match by target_url). Legacy
// table is left in place until Stage 6 verification + explicit
// admin "decommission legacy webhooks" action.
echo "  [1.5] migrate legacy webhooks → webhook_subscriptions... ";
if (_phase94_table_exists($prefix, 'webhooks')) {
    try {
        // Use JSON_OBJECT/JSON_ARRAY for retry_policy_json so older
        // MariaDB versions without JSON_OBJECT (pre-10.2) still pass
        // the literal string. MariaDB 10.2+ universally supports
        // these; verify on training before relying on this.
        $result = db_query("
            INSERT INTO `{$prefix}webhook_subscriptions`
                (name, target_url, hmac_secret, event_filters_json, active,
                 retry_policy_json, created_by, created_at)
            SELECT
                COALESCE(NULLIF(TRIM(`name`), ''), CONCAT('Migrated webhook #', `id`)) AS name,
                `url`           AS target_url,
                `secret`        AS hmac_secret,
                `events_json`   AS event_filters_json,
                `active`,
                CONCAT('{\"max_attempts\":', COALESCE(`retry_max`, 5),
                       ',\"backoff_seconds\":[30,60,120,240,480]}') AS retry_policy_json,
                NULL            AS created_by,
                COALESCE(`created_at`, NOW())
            FROM `{$prefix}webhooks`
            WHERE `url` NOT IN (SELECT `target_url` FROM `{$prefix}webhook_subscriptions`)
        ");
        $migratedCount = $result instanceof PDOStatement ? $result->rowCount() : 0;
        echo "OK ({$migratedCount} migrated)\n";
    } catch (Exception $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
    }
} else {
    echo "skipped (legacy webhooks table not present — fresh install)\n";
}

// ── 1.6 webhook_deliveries extension + FK retarget ──────────────
echo "  [1.6] webhook_deliveries extension... ";
if (_phase94_table_exists($prefix, 'webhook_deliveries')) {
    try {
        if (!_phase94_column_exists($prefix, 'webhook_deliveries', 'dead_lettered_at')) {
            db_query("ALTER TABLE `{$prefix}webhook_deliveries`
                ADD COLUMN `dead_lettered_at` DATETIME NULL AFTER `created_at`");
        }
        if (!_phase94_column_exists($prefix, 'webhook_deliveries', 'replayed_from_id')) {
            db_query("ALTER TABLE `{$prefix}webhook_deliveries`
                ADD COLUMN `replayed_from_id` INT NULL AFTER `dead_lettered_at`");
        }
        if (!_phase94_column_exists($prefix, 'webhook_deliveries', 'subscription_id')) {
            // Two-phase rename: add subscription_id, populate from
            // webhook_id via target_url match against the new
            // subscriptions table, then drop webhook_id. Done in
            // separate ALTERs so each is rollback-safe individually.
            db_query("ALTER TABLE `{$prefix}webhook_deliveries`
                ADD COLUMN `subscription_id` INT NOT NULL DEFAULT 0 AFTER `id`");
            // Walk historical rows. Orphans (no matching subscription
            // — could happen if a since-deleted webhook had
            // deliveries) keep subscription_id = 0 and the admin UI
            // handles them with a NULL-friendly query path.
            db_query("UPDATE `{$prefix}webhook_deliveries` d
                LEFT JOIN `{$prefix}webhooks` w ON w.id = d.webhook_id
                LEFT JOIN `{$prefix}webhook_subscriptions` s ON s.target_url = w.url
                SET d.subscription_id = COALESCE(s.id, 0)
                WHERE d.subscription_id = 0");
            // Add the index for the new column
            db_query("ALTER TABLE `{$prefix}webhook_deliveries`
                ADD KEY `idx_wd_subscription` (`subscription_id`)");
        }

        // 2026-06-28 fix — webhook_id was created as NOT NULL with no
        // default for the legacy delivery path. New code (post-Phase
        // 94 Stage 5) only sets subscription_id, so _webhook_log_delivery()
        // INSERTs were SILENTLY FAILING with "Column 'webhook_id'
        // cannot be null" — caught try/catch returned 0, no row got
        // written, no webhook ever fired. test_webhook_delivery.php
        // surfaced this. Make webhook_id NULLable so new INSERTs work
        // without touching it.
        try {
            db_query("ALTER TABLE `{$prefix}webhook_deliveries`
                MODIFY COLUMN `webhook_id` INT NULL DEFAULT NULL");
        } catch (Exception $e) { /* column may not exist on fresh installs */ }

        if (!_phase94_column_exists($prefix, 'webhook_deliveries', 'dead_lettered_at')) {
            // (re-check; happens if the above failed silently somehow)
        } else {
            // Add the dead-letter index if missing
            try {
                db_query("ALTER TABLE `{$prefix}webhook_deliveries`
                    ADD KEY `idx_wd_dead_letter` (`dead_lettered_at`)");
            } catch (Exception $idxErr) { /* index may already exist */ }
        }
        echo "OK\n";
    } catch (Exception $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
    }
} else {
    echo "skipped (webhook_deliveries table not present — fresh install will create it via inc/webhooks.php)\n";
}

// ── 1.7 RBAC permissions ────────────────────────────────────────
echo "  [1.7] RBAC permissions (action.manage_external_api_tokens, action.manage_webhooks)... ";
try {
    $newPerms = [
        ['code' => 'action.manage_external_api_tokens',
         'category' => 'action',
         'name' => 'Manage External API Tokens (mint, list, revoke)'],
        ['code' => 'action.manage_webhooks',
         'category' => 'action',
         'name' => 'Manage Outbound Webhook Subscriptions'],
    ];
    $seeded = 0;
    foreach ($newPerms as $p) {
        try {
            db_query("INSERT IGNORE INTO `{$prefix}permissions`
                (code, category, name) VALUES (?, ?, ?)",
                [$p['code'], $p['category'], $p['name']]);
            if (db_insert_id() > 0) $seeded++;
        } catch (Exception $permErr) {
            // permissions table may have a slightly different shape
            // on very old installs — non-fatal
        }
    }
    // Grant to Super Admin + Org Admin roles (the standard pattern
    // run_location_ingest_tokens.php uses)
    try {
        db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id)
            SELECT r.id, p.id
            FROM `{$prefix}roles` r
            CROSS JOIN `{$prefix}permissions` p
            WHERE r.name IN ('Super Admin', 'Org Admin')
              AND p.code IN ('action.manage_external_api_tokens', 'action.manage_webhooks')");
    } catch (Exception $grantErr) {
        // role_permissions junction table may differ — non-fatal
    }
    echo "OK ({$seeded} new permissions)\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

// ── 1.8 Settings defaults ───────────────────────────────────────
echo "  [1.8] settings defaults... ";
try {
    $settingsRows = [
        ['name' => 'external_api_require_tls',
         'value' => '1',
         'note' => 'Refuse non-TLS connections to /api/external/v1/* (Decision #5)'],
        ['name' => 'external_api_env_letter',
         'value' => 'p',
         'note' => 'Visible env discriminator in minted tokens: tcad_<env>_<random>'],
        ['name' => 'external_api_default_rate_limit',
         'value' => '1000',
         'note' => 'Default per-token rate limit (requests per hour)'],
        ['name' => 'external_api_per_ip_rate_limit',
         'value' => '5000',
         'note' => 'Per-IP rate limit (requests per hour) — acts as a circuit breaker against compromised tokens'],
        // Phase 94 Stage 4h — attachment upload size ceiling. 10 MB
        // default matches the legacy api/file-upload.php internal cap.
        // file-write.php hard-caps at 100 MB even if this setting is
        // mis-edited upward; Apache's upload_max_filesize / post_max_size
        // still apply on top (training defaults to 2 MB and needs
        // raising in /etc/php/X.Y/apache2/php.ini for the larger end of
        // this range).
        ['name' => 'external_api_max_upload_bytes',
         'value' => '10485760',
         'note' => 'External API attachment upload limit, in bytes (default 10 MB)'],
    ];
    $seedCount = 0;
    foreach ($settingsRows as $row) {
        try {
            db_query("INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
                [$row['name'], $row['value']]);
            if (db_insert_id() > 0) $seedCount++;
        } catch (Exception $setErr) { /* settings table may differ; non-fatal */ }
    }
    echo "OK ({$seedCount} new settings seeded; existing values preserved)\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "\nPhase 94 Stage 1 complete.\n";
echo "Re-run is idempotent. Next: Stage 2 (auth middleware in inc/external-auth.php).\n";
