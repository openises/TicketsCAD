<?php
/**
 * Phase 41 — Email Distribution Lists schema.
 *
 *   email_lists        — named list + slug + description + created_by
 *   email_list_members — polymorphic recipient (member / constituent /
 *                        inline-address / nested-list)
 *
 * Eric's recipient-type design:
 *   - 'member'      → references member.id; email read from member.email
 *                     at send time so changes auto-propagate.
 *   - 'constituent' → references constituents.id.
 *   - 'inline'      → plain RFC 5322 string for external recipients.
 *   - 'list'        → nested sub-list (one level of nesting; cycles
 *                     rejected at send time).
 *
 * Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 41 — email_lists + email_list_members\n";
echo "===========================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}email_lists` (
        `id`          INT NOT NULL AUTO_INCREMENT,
        `name`        VARCHAR(64) NOT NULL,
        `slug`        VARCHAR(64) NOT NULL COMMENT 'URL-safe identifier used as to: alias',
        `description` TEXT NULL,
        `created_by`  INT NULL,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `archived_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_slug` (`slug`),
        KEY `idx_archived` (`archived_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] email_lists ready.\n";
} catch (Exception $e) { echo "[ERR] email_lists: " . $e->getMessage() . "\n"; exit(1); }

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}email_list_members` (
        `id`           INT NOT NULL AUTO_INCREMENT,
        `list_id`      INT NOT NULL,
        `member_type`  ENUM('member','constituent','inline','list') NOT NULL,
        `ref_id`       INT NULL COMMENT 'member.id / constituents.id / sub-list.id when applicable',
        `inline_email` VARCHAR(255) NULL COMMENT 'used when member_type=inline',
        `display_name` VARCHAR(128) NULL COMMENT 'optional override label',
        `added_by`     INT NULL,
        `added_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_list` (`list_id`),
        KEY `idx_type_ref` (`member_type`, `ref_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] email_list_members ready.\n";
} catch (Exception $e) { echo "[ERR] email_list_members: " . $e->getMessage() . "\n"; exit(1); }

echo "\nDone.\n";
