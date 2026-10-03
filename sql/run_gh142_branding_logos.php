<?php
/**
 * GH#142 (Phase 155) - Agency logo and branding: schema, settings, RBAC.
 *
 * Spec: specs/phase-155-community-backlog/142-agency-logo-and-143-recheck.md
 * (Part B, section B5.1 and B5.2). Library: inc/branding.php.
 *
 * WHAT THIS CREATES
 *   - `branding_logos`: one row per (organization, variant), the image held as
 *     base64 text in a MEDIUMTEXT column (so it is part of every backup and
 *     restore with no filesystem involved). `org_id` is NOT NULL DEFAULT 0 where
 *     0 means install-wide: a UNIQUE key containing a NULLable column constrains
 *     nothing for the NULL rows (Phase 129), and a generated-column workaround
 *     was deliberately avoided on unknown self-hosted MariaDB (Phase 144). 0 is
 *     not a valid organization id, so the plain unique key binds. There is no
 *     foreign key to `organizations` (0 would violate it); cleanup is by code
 *     (branding_delete_for_org()).
 *   - The eleven `branding_*` settings, seeded INSERT IGNORE so a re-run never
 *     overwrites an administrator's value. The definitions live in ONE place,
 *     inc/branding.php, which is what this seeds from.
 *   - Two permissions split by blast radius (the Phase 138/140 template):
 *       action.manage_branding      tier 2 (Super Admin only)
 *       action.manage_branding_org  tier 1 (Org Admin and above)
 *
 * SELF-SUFFICIENT IN ANY ORDER. sql/run_migrations.php runs run_*.php files in
 * lexicographic order and this file sorts after run_00_rbac.php but before
 * run_rbac_v2.php and run_zzz_*, so the permissions, their tier and their grants
 * are all established here as well as in sql/rbac.sql and sql/run_00_rbac.php.
 *
 * IDEMPOTENT, and it VERIFIES ITS OWN OUTCOME (Phase 128: a migration that
 * catches its own exception and exits 0 is a migration that never ran): the
 * table, BOTH unique keys (by asking the database to refuse a duplicate, never
 * by reading the DDL), every setting, both permissions, their tiers and their
 * grants. Any miss exits non-zero.
 *
 * Usage: php sql/run_gh142_branding_logos.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/branding.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$fail   = [];

echo "GH#142 - Agency logo and branding\n";
echo "==================================\n\n";

// ── 1. The table ────────────────────────────────────────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}branding_logos` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `org_id`           INT NOT NULL DEFAULT 0 COMMENT '0 = install-wide; >0 = organizations.id (no FK on purpose)',
        `variant`          VARCHAR(8)  NOT NULL DEFAULT 'light' COMMENT 'light | dark',
        `asset_key`        CHAR(32)    NOT NULL COMMENT 'random, regenerated on every upload; the capability URL',
        `mime`             VARCHAR(32) NOT NULL,
        `width`            INT NOT NULL DEFAULT 0,
        `height`           INT NOT NULL DEFAULT 0,
        `byte_size`        INT NOT NULL DEFAULT 0,
        `sha256`           CHAR(64)    NOT NULL,
        `data_b64`         MEDIUMTEXT  NOT NULL COMMENT 'base64 of the normalised image; only the serving endpoint and the ICS data-URI path read it',
        `uploaded_by`      INT NOT NULL DEFAULT 0,
        `uploaded_by_name` VARCHAR(64) NOT NULL DEFAULT '',
        `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_branding_scope_variant` (`org_id`, `variant`),
        UNIQUE KEY `uk_branding_asset_key` (`asset_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] {$prefix}branding_logos present\n";
} catch (Throwable $e) {
    $fail[] = 'create table: ' . $e->getMessage();
    echo "[FAIL] create table: " . $e->getMessage() . "\n";
}

// ── 2. Settings (seeded from the single definitions array) ─────────────
foreach (branding_setting_definitions() as $name => $def) {
    try {
        db_query("INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$name, $def['default']]);
        echo "[OK] settings.{$name} ready (seeded or already present)\n";
    } catch (Throwable $e) {
        $fail[] = "setting {$name}: " . $e->getMessage();
        echo "[FAIL] setting {$name}: " . $e->getMessage() . "\n";
    }
}

// ── 3. Permissions, tiers and default grants ───────────────────────────
// resource/verb MUST equal what sql/run_rbac_v2.php's rrbv2_parse_code() derives from the code
// ('action.manage_branding_org' -> resource 'branding_org', verb 'manage'). An explicit
// ('branding', 'manage_org') here disagreed with that default: on an UPGRADE (a database where
// RBAC v2 already ran) the migration and the A8 canonicalization each created a canonical row for
// the same permission, leaving two canonical rows with the same display name
// (tests/test_rbac_alias_dedupe.php; the Roles editor listed "Manage Own Org's Logo" twice).
// A fresh install never showed it. Found by the Phase 155 integration run on an upgraded database.
$perms = [
    ['action.manage_branding', 'Manage Agency Branding (install-wide)', 'branding', 'manage', 2,
     'Upload or remove the install-wide agency logo, change any organization\'s logo, and change the branding settings. Super Admin only.'],
    ['action.manage_branding_org', "Manage Own Org's Logo", 'branding_org', 'manage', 1,
     "Upload or remove the caller's OWN organization's logo only. The organization is forced server-side, never taken from the request."],
];
$hasTier = false;
try {
    $hasTier = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'admin_only'",
        [$prefix . 'permissions']
    );
} catch (Throwable $e) {
    $hasTier = false;
}
// This script sorts BEFORE run_rbac_v2.php, so on a genuinely fresh install two
// columns it would like to use do not exist yet: roles.is_super and
// permissions.deprecated_alias_of. Both are probed, never assumed (found by running
// the real installer into an empty database: the first pass failed on
// "Unknown column r.is_super" and only the second pass, after run_rbac_v2.php had
// created it, recovered).
$colExists = function (string $table, string $col) use ($prefix): bool {
    try {
        return (bool) db_fetch_value(
            "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . $table, $col]
        );
    } catch (Throwable $e) {
        return false;
    }
};
$hasIsSuper = $colExists('roles', 'is_super');
$hasAlias   = $colExists('permissions', 'deprecated_alias_of');
$superOr    = $hasIsSuper ? 'r.is_super = 1 OR ' : '';
$notSuper   = $hasIsSuper ? 'r.is_super = 0 AND ' : '';

foreach ($perms as $p) {
    [$code, $name, $res, $verb, $tier, $desc] = $p;
    try {
        $id = db_fetch_value("SELECT `id` FROM `{$prefix}permissions` WHERE `code` = ? LIMIT 1", [$code]);
        if (!$id) {
            db_query(
                "INSERT INTO `{$prefix}permissions` (`code`, `name`, `category`, `resource`, `verb`, `description`)
                 VALUES (?, ?, 'action', ?, ?, ?)",
                [$code, $name, $res, $verb, $desc]
            );
            echo "[OK] added permission {$code}\n";
        } else {
            echo "[OK] permission {$code} already exists\n";
        }
        if ($hasTier) {
            db_query("UPDATE `{$prefix}permissions` SET `admin_only` = ? WHERE `code` = ? AND `admin_only` < ?", [$tier, $code, $tier]);
        }
    } catch (Throwable $e) {
        $fail[] = "permission {$code}: " . $e->getMessage();
        echo "[FAIL] permission {$code}: " . $e->getMessage() . "\n";
    }
}

// Propagate each tier onto the canonical-alias partner, if run_rbac_v2.php has
// already created one on this install (same step run_00_rbac.php performs).
if ($hasTier && $hasAlias) {
    try {
        db_query("UPDATE `{$prefix}permissions` canon
                    JOIN `{$prefix}permissions` old_p ON old_p.deprecated_alias_of = canon.code
                     SET canon.admin_only = old_p.admin_only
                  WHERE old_p.code IN ('action.manage_branding', 'action.manage_branding_org')
                    AND old_p.admin_only > canon.admin_only");
    } catch (Throwable $e) {
        echo "[WARN] alias tier propagation: " . $e->getMessage() . "\n";
    }
}

$grants = [
    'action.manage_branding'     => $superOr . "r.name = 'Super Admin'",
    'action.manage_branding_org' => $superOr . "r.name IN ('Super Admin', 'Org Admin')",
];
foreach ($grants as $code => $where) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
             SELECT r.id, (SELECT `id` FROM `{$prefix}permissions` WHERE `code` = ? LIMIT 1)
               FROM `{$prefix}roles` r WHERE {$where}",
            [$code]
        );
    } catch (Throwable $e) {
        $fail[] = "grant {$code}: " . $e->getMessage();
        echo "[FAIL] grant {$code}: " . $e->getMessage() . "\n";
    }
}

// Repair, same two mechanisms and rationale as the repair DELETEs in sql/rbac.sql:
// the install-wide code must not sit on Org Admin or Dispatcher, directly or
// through its canonical alias.
try {
    db_query("DELETE rp FROM `{$prefix}role_permissions` rp
                JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
                JOIN `{$prefix}roles` r ON r.id = rp.role_id
               WHERE p.code = 'action.manage_branding' AND {$notSuper}r.name <> 'Super Admin'");
    db_query("DELETE rp FROM `{$prefix}role_permissions` rp
                JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
                JOIN `{$prefix}roles` r ON r.id = rp.role_id
               WHERE p.code = 'action.manage_branding_org'
                 AND {$notSuper}r.name NOT IN ('Super Admin', 'Org Admin')");
    // The canonical-alias half only exists once run_rbac_v2.php has run (it creates
    // the column); run_rbac_v2.php's own guarded mirror covers a fresh install, and
    // the rbac.sql / run_00_rbac.php repairs cover every later re-import.
    if ($hasAlias) {
        db_query("DELETE rp FROM `{$prefix}role_permissions` rp
                    JOIN `{$prefix}permissions` canon ON canon.id = rp.permission_id
                    JOIN `{$prefix}permissions` old_p ON old_p.deprecated_alias_of = canon.code
                    JOIN `{$prefix}roles` r ON r.id = rp.role_id
                   WHERE old_p.code = 'action.manage_branding' AND {$notSuper}r.name <> 'Super Admin'");
        db_query("DELETE rp FROM `{$prefix}role_permissions` rp
                    JOIN `{$prefix}permissions` canon ON canon.id = rp.permission_id
                    JOIN `{$prefix}permissions` old_p ON old_p.deprecated_alias_of = canon.code
                    JOIN `{$prefix}roles` r ON r.id = rp.role_id
                   WHERE old_p.code = 'action.manage_branding_org'
                     AND {$notSuper}r.name NOT IN ('Super Admin', 'Org Admin')");
    }
    echo "[OK] default grants applied; any leaked grant repaired\n";
} catch (Throwable $e) {
    $fail[] = 'grant repair: ' . $e->getMessage();
    echo "[FAIL] grant repair: " . $e->getMessage() . "\n";
}

// ── 4. Verify the OUTCOME ──────────────────────────────────────────────
try {
    $tableThere = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'branding_logos']
    );
    if (!$tableThere) {
        $fail[] = 'verify: branding_logos does not exist';
    } else {
        // Ask the database to refuse a duplicate. A key that is declared but
        // does not bind (the NULL-in-unique-index trap) would pass a DDL read.
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ins = "INSERT INTO `{$prefix}branding_logos` (`org_id`, `variant`, `asset_key`, `mime`, `sha256`, `data_b64`)
                    VALUES (-1, ?, ?, 'image/png', ?, 'AA==')";
            $k1 = bin2hex(random_bytes(16));
            $k2 = bin2hex(random_bytes(16));
            $sha = str_repeat('0', 64);
            db_query($ins, ['light', $k1, $sha]);
            $refusedScope = false;
            try { db_query($ins, ['light', $k2, $sha]); } catch (Throwable $e) { $refusedScope = true; }
            if (!$refusedScope) $fail[] = 'verify: the (org_id, variant) unique key does not refuse a duplicate';
            $refusedKey = false;
            try { db_query($ins, ['dark', $k1, $sha]); } catch (Throwable $e) { $refusedKey = true; }
            if (!$refusedKey) $fail[] = 'verify: the asset_key unique key does not refuse a duplicate';
        } finally {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
        }
        $leftover = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}branding_logos` WHERE `org_id` = -1");
        if ($leftover !== 0) $fail[] = 'verify: probe rows were not rolled back';
    }

    foreach (branding_setting_definitions() as $name => $def) {
        $has = (bool) db_fetch_value("SELECT 1 FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
        if (!$has) $fail[] = "verify: setting {$name} does not exist";
    }

    foreach ($perms as $p) {
        [$code, , , , $tier] = $p;
        $row = db_fetch_one("SELECT `id`" . ($hasTier ? ", `admin_only`" : "") . " FROM `{$prefix}permissions` WHERE `code` = ?", [$code]);
        if (!$row) { $fail[] = "verify: permission {$code} does not exist"; continue; }
        if ($hasTier && (int) $row['admin_only'] !== $tier) {
            $fail[] = "verify: {$code} admin_only is " . (int) $row['admin_only'] . ", expected {$tier}";
        }
    }
    $held = function (string $roleName, string $code) use ($prefix): bool {
        return (bool) db_fetch_value(
            "SELECT 1 FROM `{$prefix}role_permissions` rp
               JOIN `{$prefix}roles` r ON r.id = rp.role_id
               JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
              WHERE r.name = ? AND p.code = ? LIMIT 1",
            [$roleName, $code]
        );
    };
    if (!$held('Super Admin', 'action.manage_branding'))     $fail[] = 'verify: Super Admin does not hold action.manage_branding';
    if (!$held('Super Admin', 'action.manage_branding_org')) $fail[] = 'verify: Super Admin does not hold action.manage_branding_org';
    if (!$held('Org Admin', 'action.manage_branding_org'))   $fail[] = 'verify: Org Admin does not hold action.manage_branding_org';
    if ($held('Org Admin', 'action.manage_branding'))        $fail[] = 'verify: Org Admin holds action.manage_branding (must be Super Admin only)';
    foreach (['Dispatcher', 'Operator', 'Read-Only', 'Field Unit'] as $roleName) {
        foreach (['action.manage_branding', 'action.manage_branding_org'] as $code) {
            if ($held($roleName, $code)) $fail[] = "verify: {$roleName} holds {$code}";
        }
    }
} catch (Throwable $e) {
    $fail[] = 'verify: ' . $e->getMessage();
}

if ($fail) {
    echo "\nFAILED:\n  - " . implode("\n  - ", $fail) . "\n";
    exit(1);
}

echo "\nDone. Agency logo and branding (GH#142) installed.\n";
exit(0);
