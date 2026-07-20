<?php
/**
 * Phase 90 — beta_tester_applications table.
 *
 * Stores incoming applications from the public registration form at
 * https://your-server.example.com/beta-tester. Each row is one
 * agency / individual expressing interest in the beta program.
 *
 * Lifecycle:
 *   - PUBLIC form POSTs an application -> status='new'
 *   - Eric reviews and either approves (-> status='approved',
 *     GitHub invite sent) or declines (-> status='declined')
 *   - reviewed_at + reviewed_by + notes captured for audit
 *
 * No PII concerns beyond what the applicant volunteered — they're
 * giving us their contact info to ask for repo access; standard
 * data-handling rules apply (don't share outside the project).
 *
 * Idempotent. Safe to re-run.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 90 — beta_tester_applications schema\n";
echo "==========================================\n";

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}beta_tester_applications` (
        `id`                  INT NOT NULL AUTO_INCREMENT,
        `submitted_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `submitted_ip`        VARCHAR(45) NULL,
        `submitted_ua`        VARCHAR(255) NULL,
        `full_name`           VARCHAR(120) NOT NULL,
        `email`               VARCHAR(180) NOT NULL,
        `phone`               VARCHAR(40) NULL,
        `github_user`         VARCHAR(120) NULL,
        `agency_name`         VARCHAR(200) NOT NULL,
        `agency_type`         VARCHAR(60) NOT NULL COMMENT 'volunteer_fire|ems|ares|races|cert|sar|campus_security|municipal_police|other',
        `agency_type_other`   VARCHAR(120) NULL COMMENT 'free-text when agency_type=other',
        `expected_user_count` INT NULL,
        `city`                VARCHAR(120) NULL,
        `state_or_region`     VARCHAR(120) NULL,
        `country`             VARCHAR(80) NULL,
        `timezone`            VARCHAR(64) NULL COMMENT 'IANA zone, e.g. America/Chicago',
        `referral_source`     VARCHAR(255) NULL,
        `planned_scenarios`   TEXT NULL,
        `feature_interests`   TEXT NULL,
        `agreed_v`            VARCHAR(20) NOT NULL COMMENT 'Agreement version applicant agreed to, e.g. 1.0',
        `signed_name`         VARCHAR(120) NOT NULL COMMENT 'Typed full name as electronic signature',
        `status`              ENUM('new','reviewing','approved','declined','withdrawn') NOT NULL DEFAULT 'new',
        `reviewed_at`         DATETIME NULL,
        `reviewed_by`         INT NULL,
        `review_notes`        TEXT NULL,
        `github_invited_at`   DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_bta_email`     (`email`),
        KEY `idx_bta_status`    (`status`),
        KEY `idx_bta_submitted` (`submitted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  [ok] beta_tester_applications table ready\n";
} catch (Exception $e) {
    echo "  [err] create table: " . $e->getMessage() . "\n";
    throw $e;
}

// Idempotent ADD COLUMN for installs that ran an earlier version of
// this migration before city/timezone were added (Phase 90 v1 → v2).
foreach (['city' => "VARCHAR(120) NULL AFTER `expected_user_count`",
          'timezone' => "VARCHAR(64) NULL COMMENT 'IANA zone, e.g. America/Chicago' AFTER `country`"] as $col => $def) {
    try {
        $has = db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?",
            [$prefix . 'beta_tester_applications', $col]
        );
        if (!$has) {
            db_query("ALTER TABLE `{$prefix}beta_tester_applications` ADD COLUMN `{$col}` {$def}");
            echo "  [ok] added column {$col}\n";
        } else {
            echo "  [skip] column {$col} already present\n";
        }
    } catch (Exception $e) {
        echo "  [warn] add column {$col}: " . $e->getMessage() . "\n";
    }
}

// Settings: who gets the notification email when a new application arrives.
// Defaults to ejosterberg@gmail.com per the agreement doc but the operator
// can rebind via the standard Settings UI later.
try {
    $exists = db_fetch_value(
        "SELECT 1 FROM `{$prefix}settings` WHERE `name` = ?",
        ['beta_application_notify_to']
    );
    if (!$exists) {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
            ['beta_application_notify_to', 'ejosterberg@gmail.com']
        );
        echo "  [ok] seeded beta_application_notify_to = ejosterberg@gmail.com\n";
    } else {
        echo "  [skip] beta_application_notify_to already set (operator value preserved)\n";
    }
} catch (Exception $e) {
    echo "  [warn] settings seed: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
