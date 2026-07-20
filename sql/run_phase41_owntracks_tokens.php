<?php
/**
 * Phase 41 — OwnTracks tracking tokens with rotation support.
 *
 * Schema:
 *   member_tracking_tokens(
 *     id, member_id, token_label, token_hash, secret_hash,
 *     created_by, created_at, valid_from, valid_until,
 *     last_used_at, revoked_at
 *   )
 *
 * The "secret_hash" column is what the OwnTracks client sends as its
 * basic-auth password; we hash the secret at rest so a DB dump can't
 * be replayed. The "token_hash" is for opaque API tokens used in any
 * future OwnTracks-companion endpoints.
 *
 * Rotation semantics:
 *   - valid_from + valid_until let us run two tokens for the same
 *     member in parallel during a rotation window
 *   - revoked_at hard-kills a token regardless of validity window
 *   - last_used_at lets the nightly stale-token report find clients
 *     still on the old token after a rotation cutover
 *
 * Also augments location_reports with auth_token_id so reports can be
 * attributed to a specific token, which is what makes the stale-token
 * report possible.
 *
 * Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 41 — OwnTracks tracking tokens + rotation\n";
echo "===============================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// 1. The tokens table.
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}member_tracking_tokens` (
        `id`             INT NOT NULL AUTO_INCREMENT,
        `member_id`      INT NOT NULL,
        `token_label`    VARCHAR(64) NULL COMMENT 'Admin-friendly label, e.g. iPhone-2026Q2',
        `token_hash`     CHAR(64)   NULL COMMENT 'sha256 of opaque API token; nullable for OwnTracks-only entries',
        `secret_hash`    CHAR(64)   NOT NULL COMMENT 'sha256 of the basic-auth password OwnTracks sends',
        `created_by`     INT NULL,
        `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `valid_from`     DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
        `valid_until`    DATETIME NULL COMMENT 'NULL = open-ended; set during rotation to expire old tokens',
        `last_used_at`   DATETIME NULL,
        `revoked_at`     DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_member`        (`member_id`),
        KEY `idx_token_hash`    (`token_hash`),
        KEY `idx_secret_hash`   (`secret_hash`),
        KEY `idx_last_used`     (`last_used_at`),
        KEY `idx_validity`      (`valid_from`, `valid_until`, `revoked_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] member_tracking_tokens ready.\n";
} catch (Exception $e) { echo "[ERR] member_tracking_tokens: " . $e->getMessage() . "\n"; exit(1); }

// 2. Add auth_token_id to location_reports so we can audit which
//    token produced each report (needed for the stale-token report).
$has = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
    [$prefix . 'location_reports', 'auth_token_id']
);
if (!$has) {
    try {
        db_query("ALTER TABLE `{$prefix}location_reports`
                  ADD COLUMN `auth_token_id` INT NULL,
                  ADD KEY `idx_auth_token` (`auth_token_id`)");
        echo "[OK] Added location_reports.auth_token_id column.\n";
    } catch (Exception $e) { echo "[WARN] location_reports.auth_token_id: " . $e->getMessage() . "\n"; }
} else {
    echo "[skip] location_reports.auth_token_id already exists.\n";
}

// 3. Settings used by the rotation flow.
$defaults = [
    'owntracks_token_dual_window_days' => '7',
    'owntracks_push_on_position'        => '1',
    'owntracks_email_link_template'    => 'Hi {name},\n\nTap the link below on your phone to join {org} location sharing:\n\n{owntracks_url}\n\nThe link is single-use — once OwnTracks loads it, the link no longer works.',
];
foreach ($defaults as $k => $v) {
    try {
        $exists = db_fetch_value("SELECT name FROM `{$prefix}settings` WHERE name = ?", [$k]);
        if (!$exists) {
            db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)", [$k, $v]);
            echo "[OK] seeded setting {$k}\n";
        }
    } catch (Exception $e) { echo "[WARN] seed {$k}: " . $e->getMessage() . "\n"; }
}

echo "\nDone.\n";
