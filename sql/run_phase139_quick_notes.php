<?php
/**
 * Phase 139 (2026-08-14) — Quick Notes (/log command).
 *
 * Eric's idea: type /log <text>, capture a timestamped note in one
 * keystroke, review/delete/mark-done later, copy or move it into an
 * incident's activity log, an ICS-214 Activity Log row, or a personal
 * SOP-Wiki page (dragged onto a tree view).
 *
 * Schema:
 *   - New table `quick_notes` — strictly private to its owner
 *     (Eric's explicit answer: notes are never shared/visible to anyone
 *     else). No RBAC permission needed; every authenticated user manages
 *     only their own rows, enforced by WHERE user_id = <session user>
 *     in every query, never by role.
 *   - `sop_pages.owner_user_id` — reuses the existing SOP Wiki tables
 *     (Eric's explicit answer, over a separate personal-wiki table) for
 *     the "personal wiki page" copy target. NULL (the existing default)
 *     means an organization-wide SOP page, unchanged behavior. A non-null
 *     value marks a page as personal to that user — created and edited
 *     only through the quick-notes drag-and-drop flow (api/quick-notes.php),
 *     never through api/sop-save.php's action.manage_sop-gated path, so
 *     a personal page never needs that permission and a personal page
 *     can never silently become (or be mistaken for) an official SOP.
 *
 * Idempotent — safe to run repeatedly.
 *
 * Spec: specs/phase-139-quick-notes/{spec.md,plan.md}
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 139 — Quick Notes\n";
echo "========================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

if (function_exists('_p18_col_exists')) {
    function _p139_col_exists(string $t, string $c): bool { return _p18_col_exists($t, $c); }
} else {
    function _p139_col_exists(string $t, string $c): bool {
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
}

if (function_exists('_p18_table_exists')) {
    function _p139_table_exists(string $t): bool { return _p18_table_exists($t); }
} else {
    function _p139_table_exists(string $t): bool {
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

// ── A. quick_notes table ─────────────────────────────────────────────────
if (!_p139_table_exists('quick_notes')) {
    try {
        db_query(
            "CREATE TABLE `{$prefix}quick_notes` (
                `id`          INT AUTO_INCREMENT PRIMARY KEY,
                `user_id`     INT NOT NULL COMMENT 'Phase 139 - owner; notes are strictly private, never shared',
                `note_text`   MEDIUMTEXT NOT NULL,
                `captured_at` DATETIME NOT NULL COMMENT 'Phase 139 - when the note was originally typed, travels with copies',
                `done`        TINYINT(1) NOT NULL DEFAULT 0,
                `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_qn_user`      (`user_id`),
                KEY `idx_qn_user_done` (`user_id`, `done`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        echo "[OK] Created table quick_notes\n";
    } catch (Exception $e) {
        echo "[WARN] quick_notes: " . $e->getMessage() . "\n";
    }
} else {
    echo "[OK] quick_notes already exists\n";
}

// ── B. sop_pages.owner_user_id ───────────────────────────────────────────
if (_p139_table_exists('sop_pages')) {
    if (!_p139_col_exists('sop_pages', 'owner_user_id')) {
        try {
            db_query(
                "ALTER TABLE `{$prefix}sop_pages` ADD COLUMN `owner_user_id` INT NULL
                 COMMENT 'Phase 139 - NULL = organization-wide SOP page (unchanged). Non-null = a personal page owned by that user, created/edited only via the quick-notes flow, never via action.manage_sop.'
                 AFTER `parent_id`"
            );
            echo "[OK] Added sop_pages.owner_user_id\n";
        } catch (Exception $e) {
            echo "[WARN] sop_pages.owner_user_id: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[OK] sop_pages.owner_user_id already exists\n";
    }
    $hasIdx = false;
    try {
        $hasIdx = (bool) db_fetch_value(
            "SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_sop_owner' LIMIT 1",
            [$prefix . 'sop_pages']
        );
    } catch (Exception $e) { $hasIdx = false; }
    if (!$hasIdx) {
        try {
            db_query("ALTER TABLE `{$prefix}sop_pages` ADD INDEX `idx_sop_owner` (`owner_user_id`)");
            echo "[OK] Added index idx_sop_owner\n";
        } catch (Exception $e) {
            echo "[WARN] idx_sop_owner: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[OK] idx_sop_owner already exists\n";
    }
} else {
    echo "[WARN] sop_pages table not found -- personal-wiki copy target will be unavailable until sql/sop_wiki.sql has been applied\n";
}

echo "\nDone.\n";
