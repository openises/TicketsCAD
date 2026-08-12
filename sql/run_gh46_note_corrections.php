<?php
/**
 * GH#46 (cbyrdmo, 2026-08-12) — append-a-correction for unit notes.
 *
 * Eric's call: notes stay append-only, matching how they're used (ICS-214
 * activity records, e.g. "suspect description", "address update") -- a
 * correction is a NEW note that references the one it corrects, never an
 * in-place edit. This adds the one column that relationship needs.
 *
 * `corrects_id` is nullable and self-referencing (no FK constraint --
 * responder_notes has none of its own relations enforced at the DB level
 * either, matching the existing table's own convention). NULL means "not a
 * correction," which is every note that exists before this migration.
 *
 * Idempotent -- checks information_schema before altering.
 *
 * `responder_notes` itself is NOT created by any schema migration -- it has
 * always been lazily created on first use by api/unit-history.php's
 * _uh_ensure_notes_table(). A truly fresh install (CI, or any host that has
 * never posted a note) therefore has no such table when the migration
 * runner reaches this script, and the ALTER below would fail outright. This
 * CREATEs the table first (IF NOT EXISTS, corrects_id already included --
 * matching _uh_ensure_notes_table()'s shape so the two never drift), then
 * the ALTER becomes a no-op on that fresh path and still does its job on an
 * existing table from before this column existed.
 *
 * Usage:
 *   php sql/run_gh46_note_corrections.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$pdo    = db();
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH#46 — responder_notes.corrects_id\n";
echo "=====================================\n\n";

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `{$prefix}responder_notes` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `responder_id` INT NOT NULL,
            `category`     VARCHAR(32) NOT NULL DEFAULT 'general',
            `note`         TEXT NOT NULL,
            `by_user`      INT NOT NULL DEFAULT 0,
            `by_username`  VARCHAR(64) NOT NULL DEFAULT '',
            `corrects_id`  INT NULL,
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted_at`   DATETIME NULL,
            `deleted_by`   INT NULL,
            KEY `idx_responder_time` (`responder_id`, `created_at`),
            KEY `idx_category`       (`category`),
            KEY `idx_corrects`       (`corrects_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "  [ok]   responder_notes table exists\n";
} catch (Exception $e) {
    fwrite(STDERR, "ERROR creating responder_notes: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    $exists = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'corrects_id'"
    );
    $exists->execute(["{$prefix}responder_notes"]);
    if ((int) $exists->fetchColumn() > 0) {
        echo "  [skip] corrects_id already exists\n";
    } else {
        $pdo->exec(
            "ALTER TABLE `{$prefix}responder_notes`
             ADD COLUMN `corrects_id` INT NULL AFTER `by_username`,
             ADD KEY `idx_corrects` (`corrects_id`)"
        );
        echo "  [+] corrects_id column + index added\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone.\n";
