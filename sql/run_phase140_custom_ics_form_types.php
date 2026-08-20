<?php
/**
 * Phase 140 (2026-08-16) — Custom (data-driven) ICS form types.
 *
 * GitHub issue #69 (rjonesbsink): "would a data-driven ICS form definition
 * be something you'd consider, so agencies can add their own form types?"
 * Eric's decision, delegated to a multi-agent design workflow
 * (wf_0465c810-a04): build it, as a system that sits alongside the nine
 * existing hardcoded ICS forms (213/214/202/205/205a/213rr/206/214a/221)
 * and never touches their code paths.
 *
 * Schema:
 *   - New table `ics_form_types` — agency-authored type definitions
 *     (label, icon, color, field list, org-or-install-wide scope, an
 *     optional extra RBAC gate). `org_key` is a STORED generated column
 *     (`COALESCE(org_id, -1)`) so the uniqueness constraint on
 *     (org_key, slug) actually binds for NULL (install-wide) rows too —
 *     MySQL/MariaDB treat every NULL in a UNIQUE index as distinct, so a
 *     naive (org_id, slug) key would silently allow duplicate install-wide
 *     slugs. Same technique as Phase 129's uk_user_role_scope fix.
 *   - `ics_forms.custom_type_id` — nullable, reference-only. Never
 *     dereferenced to render an EXISTING submission after its first save;
 *     every submission freezes its own field-definition snapshot into
 *     `form_data_json._meta` at save time (see inc/ics-form-types.php),
 *     so editing or even archiving a type later can never break how an
 *     old submission renders. This is also why there is no hard-delete
 *     action anywhere in this feature — archiving is always safe.
 *
 * RBAC: two permissions split by blast radius, same pattern as Phase 138's
 * public-board split (action.manage_public_board /
 * action.manage_public_board_org): action.manage_ics_form_types is
 * install-wide (Super Admin only — added to the Org Admin exclusion list
 * in both this file and sql/rbac.sql); action.manage_ics_form_types_org is
 * org-scoped self-service (Super Admin + Org Admin, via the broad Org
 * Admin grant — deliberately NOT added to that exclusion list). Neither is
 * named in this file's Dispatcher/Operator/Read-Only allow-lists, so both
 * are correctly withheld there with no edit needed on that side; the
 * Dispatcher mapping in sql/rbac.sql IS a broad exclusion list, so both
 * codes are named there explicitly.
 *
 * Idempotent — safe to run repeatedly.
 *
 * Spec: specs/phase-140-custom-ics-form-types/{spec.md,plan.md,tasks.md}
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 140 — Custom ICS Form Types\n";
echo "===================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _p140_table_exists(string $t): bool {
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

function _p140_col_exists(string $t, string $c): bool {
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

function _p140_index_exists(string $t, string $idx): bool {
    global $prefix;
    try {
        $r = db_fetch_value(
            "SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
            [$prefix . $t, $idx]
        );
        return (bool) $r;
    } catch (Exception $e) { return false; }
}

// ── A. ics_form_types table ──────────────────────────────────────────────
if (!_p140_table_exists('ics_form_types')) {
    try {
        db_query(
            "CREATE TABLE `{$prefix}ics_form_types` (
                `id`                     INT AUTO_INCREMENT PRIMARY KEY,
                `slug`                   VARCHAR(60)   NOT NULL COMMENT 'IMMUTABLE after creation; ^[a-z][a-z0-9_-]{2,59}$',
                `form_number`            VARCHAR(40)   NOT NULL DEFAULT '' COMMENT 'agency-chosen short code; cannot match /^ICS-?\\d/i',
                `form_title`             VARCHAR(255)  NOT NULL DEFAULT '',
                `description`            VARCHAR(500)  NOT NULL DEFAULT '',
                `fields_json`            MEDIUMTEXT    NOT NULL COMMENT 'field definitions -- see inc/ics-form-types.php field-type palette',
                `badge_color`            VARCHAR(20)   NOT NULL DEFAULT 'secondary' COMMENT 'enum: primary|secondary|success|danger|warning|info|dark',
                `icon`                   VARCHAR(40)   NOT NULL DEFAULT 'bi-file-earmark-text' COMMENT 'validated ^bi-[a-z0-9-]+\$ (format check, not a fixed icon list)',
                `org_id`                 INT           DEFAULT NULL COMMENT 'NULL = install-wide; else member_organizations.id, forced server-side on write',
                `org_key`                INT AS (COALESCE(`org_id`, -1)) STORED COMMENT 'NULLable-unique-key fix, same technique as Phase 129 uk_user_role_scope',
                `status`                 VARCHAR(16)   NOT NULL DEFAULT 'active' COMMENT 'active|archived -- NO hard delete in v1',
                `restrict_to_permission` VARCHAR(64)   DEFAULT NULL COMMENT 'optional extra rbac_can() code; validated against permissions table on save',
                `created_by`             INT           NOT NULL DEFAULT 0,
                `created_by_name`        VARCHAR(128)  NOT NULL DEFAULT '',
                `created_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_ics_form_type_slug_org` (`org_key`, `slug`),
                KEY `idx_ics_form_type_org`    (`org_id`),
                KEY `idx_ics_form_type_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        echo "[OK] Created table ics_form_types\n";
    } catch (Exception $e) {
        echo "[WARN] ics_form_types: " . $e->getMessage() . "\n";
    }
} else {
    echo "[OK] ics_form_types already exists\n";
}

// ── B. ics_forms.custom_type_id ──────────────────────────────────────────
if (_p140_table_exists('ics_forms')) {
    if (!_p140_col_exists('ics_forms', 'custom_type_id')) {
        try {
            db_query(
                "ALTER TABLE `{$prefix}ics_forms` ADD COLUMN `custom_type_id` INT DEFAULT NULL
                 COMMENT 'Phase 140 -- set only when form_type=\'custom\'; reference only, never dereferenced for rendering after first save (see form_data_json._meta)'"
            );
            echo "[OK] Added ics_forms.custom_type_id\n";
        } catch (Exception $e) {
            echo "[WARN] ics_forms.custom_type_id: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[OK] ics_forms.custom_type_id already exists\n";
    }
    if (!_p140_index_exists('ics_forms', 'idx_ics_custom_type_id')) {
        try {
            db_query("ALTER TABLE `{$prefix}ics_forms` ADD INDEX `idx_ics_custom_type_id` (`custom_type_id`)");
            echo "[OK] Added index idx_ics_custom_type_id\n";
        } catch (Exception $e) {
            echo "[WARN] idx_ics_custom_type_id: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[OK] idx_ics_custom_type_id already exists\n";
    }
} else {
    echo "[WARN] ics_forms table not found -- run sql/run_ics_forms.php first\n";
}

// ── C. RBAC permissions + default grants ─────────────────────────────────
$permDefs = [
    ['action.manage_ics_form_types', 'Manage Custom ICS Form Types (install-wide)', 'action',
        'Author, edit, and archive agency-custom ICS form type definitions install-wide. Super Admin only.'],
    ['action.manage_ics_form_types_org', "Manage Own Org's Custom ICS Form Types", 'action',
        "Author, edit, and archive custom ICS form type definitions scoped to the caller's own organization."],
];

$permInserted = 0;
foreach ($permDefs as $p) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`) VALUES (?, ?, ?, ?)",
            $p
        );
        $permInserted++;
    } catch (Exception $e) {
        echo "[WARN] permission {$p[0]}: " . $e->getMessage() . "\n";
    }
}
echo "[OK] {$permInserted} permission(s) seeded (INSERT IGNORE -- already-present rows are a no-op)\n";

// Super Admin (1): both, via the broad "gets everything" grant elsewhere in
// the RBAC seed chain -- nothing to do here beyond the permission existing.
// Org Admin (2): gets action.manage_ics_form_types_org via the broad grant
// (NOT excluded); action.manage_ics_form_types (install-wide) is
// deliberately excluded -- see sql/rbac.sql's Org Admin NOT IN list, which
// this file's own broad Org Admin grant (in sql/run_00_rbac.php) must also
// exclude. Re-asserting a role-specific grant here (rather than relying
// solely on the broad sweep) makes this script self-healing even if the
// broad-grant exclusion list in the OTHER seed file is ever out of sync.
try {
    $orgTypeId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?",
        ['action.manage_ics_form_types_org']
    );
    if ($orgTypeId > 0) {
        foreach ([1, 2] as $roleId) {
            $roleExists = db_fetch_one("SELECT id FROM `{$prefix}roles` WHERE id = ?", [$roleId]);
            if (!$roleExists) continue;
            db_query(
                "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)",
                [$roleId, $orgTypeId]
            );
        }
        echo "[OK] action.manage_ics_form_types_org granted to Super Admin + Org Admin\n";
    }
    $globalTypeId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}permissions` WHERE `code` = ?",
        ['action.manage_ics_form_types']
    );
    if ($globalTypeId > 0) {
        $superRoleExists = db_fetch_one("SELECT id FROM `{$prefix}roles` WHERE id = 1");
        if ($superRoleExists) {
            db_query(
                "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (1, ?)",
                [$globalTypeId]
            );
        }
        echo "[OK] action.manage_ics_form_types granted to Super Admin only\n";
    }
} catch (Exception $e) {
    echo "[WARN] role grants: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
