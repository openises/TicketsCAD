<?php
/**
 * GH#148 (Phase 155) -- Towing / roadside vendor dispatch with a rotation list.
 *
 * specs/phase-155-community-backlog/148-towing-rotation-list.md (section 6.1).
 *
 * WHAT THIS CREATES (all new; NO change to the `ticket` table):
 *   vendor_service_types      the editable pick list (Tow / Lockout / Jumpstart / Tire Change)
 *   vendor_providers          the outside companies (a vendor record, not a unit/facility/contact)
 *   vendor_rotation_lists     one ordered list per area / service type
 *   vendor_rotation_members   which provider sits on which list, and where
 *   vendor_dispatches         the per-incident header (a derived reference such as 26-0123-T1)
 *   vendor_dispatch_ledger    APPEND-ONLY record of every offer and outcome; "who is next" is DERIVED
 *                             from it, never kept as a stored last-dispatched timestamp
 *
 * Also: seeds the four service types, the five setting defaults (the feature is OFF on every
 * install, fresh or upgraded: vendor_dispatch_enabled='0'), and two RBAC permissions:
 *   action.dispatch_vendor  tier 0 (unrestricted), granted to Super Admin, Org Admin, Dispatcher
 *   action.manage_vendors   tier 1 (Org Admin or above), granted to Super Admin, Org Admin ONLY
 *
 * Self-sufficient in ANY order (sql/run_migrations.php runs files in lexicographic order, and on a
 * fresh install this runs before sql/run_rbac_v2.php): it depends only on `permissions`,
 * `role_permissions`, `roles` and `settings`, all of which base_schema.sql creates.
 *
 * Idempotent. Verifies its own OUTCOME (tables, the three real unique keys, seeds, settings,
 * permissions, tiers, grants, and that Dispatcher does not hold the tier-1 code directly or through
 * its canonical alias) and EXITS NON-ZERO if anything is missing -- a migration that catches its own
 * exception and exits 0 is a migration that never ran (CLAUDE.md, Phase 128 A9).
 *
 * Usage: php sql/run_gh148_vendor_dispatch.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$fail   = [];

echo "GH#148 -- Towing / roadside vendor dispatch\n";
echo "===========================================\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 1. Tables. No generated columns, no JSON type, no CHECK constraints
//    (self-hosted MariaDB versions vary; same reason Phase 144 declined a
//    generated column). Every unique key below ends in NOT NULL columns, so
//    it really constrains (a UNIQUE key ending in a NULLable column does not).
// ─────────────────────────────────────────────────────────────────────────
$tables = [
    'vendor_service_types' => "CREATE TABLE IF NOT EXISTS `{$prefix}vendor_service_types` (
        `id`                INT AUTO_INCREMENT PRIMARY KEY,
        `code`              VARCHAR(32) NOT NULL,
        `label`             VARCHAR(64) NOT NULL,
        `needs_destination` TINYINT(1) NOT NULL DEFAULT 0,
        `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
        `sort_order`        INT NOT NULL DEFAULT 0,
        UNIQUE KEY `uk_vendor_service_code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'vendor_providers' => "CREATE TABLE IF NOT EXISTS `{$prefix}vendor_providers` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `org_id`           INT NULL DEFAULT NULL,
        `name`             VARCHAR(120) NOT NULL,
        `contact_name`     VARCHAR(64) NULL DEFAULT NULL,
        `phone`            VARCHAR(32) NOT NULL,
        `phone_alt`        VARCHAR(32) NULL DEFAULT NULL,
        `service_area`     VARCHAR(160) NULL DEFAULT NULL,
        `hours_note`       VARCHAR(255) NULL DEFAULT NULL,
        `notes`            TEXT NULL,
        `yard_facility_id` INT NULL DEFAULT NULL,
        `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
        `suspended_until`  DATETIME NULL DEFAULT NULL,
        `suspend_reason`   VARCHAR(255) NULL DEFAULT NULL,
        `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_vendor_provider_org` (`org_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'vendor_rotation_lists' => "CREATE TABLE IF NOT EXISTS `{$prefix}vendor_rotation_lists` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `org_id`          INT NULL DEFAULT NULL,
        `name`            VARCHAR(120) NOT NULL,
        `service_type_id` INT NOT NULL,
        `description`     VARCHAR(255) NULL DEFAULT NULL,
        `mode`            VARCHAR(16) NULL DEFAULT NULL,
        `is_default`      TINYINT(1) NOT NULL DEFAULT 0,
        `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
        `sort_order`      INT NOT NULL DEFAULT 0,
        `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_vendor_list_org` (`org_id`),
        KEY `idx_vendor_list_service` (`service_type_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'vendor_rotation_members' => "CREATE TABLE IF NOT EXISTS `{$prefix}vendor_rotation_members` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `list_id`     INT NOT NULL,
        `provider_id` INT NOT NULL,
        `position`    INT NOT NULL DEFAULT 0,
        `added_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `removed_at`  DATETIME NULL DEFAULT NULL,
        UNIQUE KEY `uk_list_provider` (`list_id`, `provider_id`),
        KEY `idx_vendor_member_provider` (`provider_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'vendor_dispatches' => "CREATE TABLE IF NOT EXISTS `{$prefix}vendor_dispatches` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `ticket_id`       INT NOT NULL,
        `ticket_ref`      VARCHAR(64) NOT NULL,
        `ordinal`         INT NOT NULL,
        `org_id`          INT NULL DEFAULT NULL,
        `service_type_id` INT NOT NULL,
        `service_label`   VARCHAR(64) NOT NULL,
        `list_id`         INT NULL DEFAULT NULL,
        `status`          ENUM('open','assigned','on_scene','completed','cancelled','goa') NOT NULL DEFAULT 'open',
        `vehicle_desc`    VARCHAR(160) NULL DEFAULT NULL,
        `plate`           VARCHAR(16) NULL DEFAULT NULL,
        `plate_state`     VARCHAR(4) NULL DEFAULT NULL,
        `tow_reason`      VARCHAR(80) NULL DEFAULT NULL,
        `dest_facility_id` INT NULL DEFAULT NULL,
        `dest_text`       VARCHAR(255) NULL DEFAULT NULL,
        `provider_id`     INT NULL DEFAULT NULL,
        `provider_name`   VARCHAR(120) NULL DEFAULT NULL,
        `provider_phone`  VARCHAR(32) NULL DEFAULT NULL,
        `eta_minutes`     SMALLINT NULL DEFAULT NULL,
        `assigned_at`     DATETIME NULL DEFAULT NULL,
        `notes`           VARCHAR(255) NULL DEFAULT NULL,
        `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `created_by_name` VARCHAR(64) NOT NULL,
        `closed_at`       DATETIME NULL DEFAULT NULL,
        UNIQUE KEY `uk_ticket_ordinal` (`ticket_id`, `ordinal`),
        KEY `idx_vendor_dispatch_ticket` (`ticket_id`),
        KEY `idx_vendor_dispatch_list` (`list_id`),
        KEY `idx_vendor_dispatch_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'vendor_dispatch_ledger' => "CREATE TABLE IF NOT EXISTS `{$prefix}vendor_dispatch_ledger` (
        `id`                        BIGINT AUTO_INCREMENT PRIMARY KEY,
        `dispatch_id`               INT NULL DEFAULT NULL,
        `list_id`                   INT NULL DEFAULT NULL,
        `provider_id`               INT NULL DEFAULT NULL,
        `provider_name`             VARCHAR(120) NOT NULL,
        `provider_phone`            VARCHAR(32) NULL DEFAULT NULL,
        `event_type`                VARCHAR(24) NOT NULL,
        `selection_method`          VARCHAR(16) NULL DEFAULT NULL,
        `consumed_turn`             TINYINT(1) NOT NULL DEFAULT 0,
        `expected_head_provider_id` INT NULL DEFAULT NULL,
        `eta_minutes`               SMALLINT NULL DEFAULT NULL,
        `reason`                    VARCHAR(255) NULL DEFAULT NULL,
        `detail`                    VARCHAR(500) NULL DEFAULT NULL,
        `ref_event_id`              BIGINT NULL DEFAULT NULL,
        `event_at`                  DATETIME NOT NULL,
        `actor_user_id`             INT NULL DEFAULT NULL,
        `actor_name`                VARCHAR(64) NOT NULL,
        `actor_ip`                  VARCHAR(45) NULL DEFAULT NULL,
        KEY `idx_vendor_ledger_dispatch` (`dispatch_id`, `id`),
        KEY `idx_vendor_ledger_rot` (`list_id`, `provider_id`, `consumed_turn`, `event_at`),
        KEY `idx_vendor_ledger_ref` (`ref_event_id`),
        KEY `idx_vendor_ledger_event_at` (`event_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($tables as $name => $ddl) {
    try {
        $existed = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . $name]);
        db_query($ddl);
        echo ($existed ? "[OK] {$name} already present\n" : "[OK] {$name} created\n");
    } catch (Throwable $e) {
        $fail[] = "{$name}: " . $e->getMessage();
        echo "[FAIL] {$name}: " . $e->getMessage() . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 2. Seed the four service types (INSERT IGNORE by the unique `code`).
//    An admin may rename, deactivate or add types; a re-run never clobbers that.
// ─────────────────────────────────────────────────────────────────────────
$seedTypes = [
    // code, label, needs_destination, sort
    ['tow',         'Tow',         1, 10],
    ['lockout',     'Lockout',     0, 20],
    ['jumpstart',   'Jumpstart',   0, 30],
    ['tire_change', 'Tire Change', 0, 40],
];
foreach ($seedTypes as [$code, $label, $needsDest, $sort]) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}vendor_service_types` (`code`, `label`, `needs_destination`, `is_active`, `sort_order`)
             VALUES (?, ?, ?, 1, ?)",
            [$code, $label, $needsDest, $sort]);
    } catch (Throwable $e) {
        $fail[] = "seed service type {$code}: " . $e->getMessage();
        echo "[FAIL] seed service type {$code}: " . $e->getMessage() . "\n";
    }
}
echo "[OK] service types seeded (or already present)\n";

// ─────────────────────────────────────────────────────────────────────────
// 3. Setting defaults (the `settings` table: name/value, the store
//    get_variable() reads -- NOT the separate `config` table).
//    The feature is OFF by default on every install.
// ─────────────────────────────────────────────────────────────────────────
$settingDefaults = [
    'vendor_dispatch_enabled'         => '0',
    'vendor_rotation_mode'            => 'round_robin',
    'vendor_advance_rule'             => 'any_offer',
    'vendor_allow_override'           => '1',
    'vendor_override_requires_reason' => '1',
];
foreach ($settingDefaults as $name => $value) {
    try {
        db_query("INSERT IGNORE INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$name, $value]);
    } catch (Throwable $e) {
        $fail[] = "setting {$name}: " . $e->getMessage();
        echo "[FAIL] setting {$name}: " . $e->getMessage() . "\n";
    }
}
echo "[OK] setting defaults seeded (existing values are never overwritten)\n";

// ─────────────────────────────────────────────────────────────────────────
// 4. RBAC. action.dispatch_vendor = tier 0, roles 1/2/3.
//    action.manage_vendors = tier 1 (Org Admin or above), roles 1/2 ONLY.
//    The tier-1 code is subject to the whole six-site checklist: the seed
//    files (sql/rbac.sql, sql/run_00_rbac.php, sql/run_zzz_admin_only_reconcile.php)
//    carry the same rows/lists; this block is for installs that ran those
//    seeds before this change.
// ─────────────────────────────────────────────────────────────────────────
$perms = [
    // code, name, description, resource, verb, tier, roles
    ['action.dispatch_vendor', 'Dispatch Towing / Roadside Vendor',
     'Dispatch a towing / roadside-assistance company from an incident and record the outcome. '
     . 'Unrestricted (tier 0): an install that wants this limited can revoke it from Dispatcher in the Roles & Permissions UI.',
     'vendor', 'dispatch', 0, [1, 2, 3]],
    ['action.manage_vendors', 'Manage Towing / Roadside Vendors',
     'Manage the vendor providers, rotation lists, service types and settings, void a dispatch record at any time, '
     . 'and export the rotation history. Tier 1 (Org Admin or above): changing rotation order is ordinance-sensitive.',
     'vendor', 'manage', 1, [1, 2]],
];

try {
    $hasAdminOnly = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'admin_only'",
        [$prefix . 'permissions']) > 0;
    if (!$hasAdminOnly) {
        // Same DDL as sql/run_00_rbac.php: self-sufficient if this ever runs on a schema that predates the column.
        db_query("ALTER TABLE `{$prefix}permissions`
                  ADD COLUMN `admin_only` TINYINT UNSIGNED NOT NULL DEFAULT 0
                  COMMENT '0=unrestricted, 1=Org Admin or above, 2=Super Admin only. See inc/rbac_admin_only.php.'");
        echo "[OK] permissions.admin_only added\n";
    }
} catch (Throwable $e) {
    $fail[] = 'permissions.admin_only: ' . $e->getMessage();
    echo "[FAIL] permissions.admin_only: " . $e->getMessage() . "\n";
}

$permIds = [];
foreach ($perms as [$code, $name, $desc, $resource, $verb, $tier, $roles]) {
    try {
        $id = (int) db_fetch_value("SELECT `id` FROM `{$prefix}permissions` WHERE `code` = ? LIMIT 1", [$code]);
        if ($id === 0) {
            db_query(
                "INSERT INTO `{$prefix}permissions` (`code`, `name`, `description`, `category`, `resource`, `verb`)
                 VALUES (?, ?, ?, 'action', ?, ?)",
                [$code, $name, $desc, $resource, $verb]);
            $id = (int) db_insert_id();
            echo "[OK] permission inserted: {$code} (id={$id})\n";
        } else {
            echo "[OK] permission exists: {$code} (id={$id})\n";
        }
        $permIds[$code] = $id;

        // The seed files (sql/rbac.sql, sql/run_00_rbac.php) insert these rows WITHOUT resource/verb;
        // run_rbac_v2.php's A4 backfills them later on a fresh install. Setting them here (only where
        // still NULL) gives one consistent value on every install regardless of run order.
        db_query("UPDATE `{$prefix}permissions` SET `resource` = ?, `verb` = ?
                   WHERE `id` = ? AND (`resource` IS NULL OR `verb` IS NULL)",
            [$resource, $verb, $id]);

        // Classify the tier. Never LOWER an existing classification.
        db_query("UPDATE `{$prefix}permissions` SET `admin_only` = ? WHERE `id` = ? AND `admin_only` < ?",
            [$tier, $id, $tier]);
    } catch (Throwable $e) {
        $fail[] = "permission {$code}: " . $e->getMessage();
        echo "[FAIL] permission {$code}: " . $e->getMessage() . "\n";
    }
}

// Propagate the tier across a canonical alias in BOTH directions if run_rbac_v2.php already made one
// (identical to the block in sql/rbac.sql, so a lookup by either name agrees).
try {
    db_query("UPDATE `{$prefix}permissions` canon
                JOIN `{$prefix}permissions` old_p ON old_p.deprecated_alias_of = canon.code
                 SET canon.admin_only = old_p.admin_only
              WHERE old_p.admin_only > canon.admin_only");
    db_query("UPDATE `{$prefix}permissions` old_p
                JOIN `{$prefix}permissions` canon ON canon.code = old_p.deprecated_alias_of
                 SET old_p.admin_only = canon.admin_only
              WHERE canon.admin_only > old_p.admin_only");
} catch (Throwable $e) {
    $fail[] = 'alias tier propagation: ' . $e->getMessage();
    echo "[FAIL] alias tier propagation: " . $e->getMessage() . "\n";
}

foreach ($perms as [$code, $name, $desc, $resource, $verb, $tier, $roles]) {
    $id = $permIds[$code] ?? 0;
    if ($id === 0) continue;
    foreach ($roles as $roleId) {
        try {
            $has = db_fetch_value(
                "SELECT 1 FROM `{$prefix}role_permissions` WHERE `role_id` = ? AND `permission_id` = ? LIMIT 1",
                [$roleId, $id]);
            if (!$has) {
                db_query("INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)", [$roleId, $id]);
                echo "  [+] grant: role {$roleId} -> {$code}\n";
            }
        } catch (Throwable $e) {
            $fail[] = "grant role {$roleId} {$code}: " . $e->getMessage();
            echo "  [FAIL] grant role {$roleId} -> {$code}: " . $e->getMessage() . "\n";
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 5. Verify the OUTCOME -- re-ask the database, never trust a log line.
// ─────────────────────────────────────────────────────────────────────────
try {
    foreach (array_keys($tables) as $name) {
        $there = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . $name]);
        if ($there === 0) $fail[] = "verify: table {$name} does not exist";
    }

    // The three keys that MUST be real unique keys (non-unique here would silently allow duplicates).
    $uniqueKeys = [
        ['vendor_service_types',   'uk_vendor_service_code', 'code'],
        ['vendor_rotation_members', 'uk_list_provider',      'list_id,provider_id'],
        ['vendor_dispatches',      'uk_ticket_ordinal',      'ticket_id,ordinal'],
    ];
    foreach ($uniqueKeys as [$tbl, $idx, $cols]) {
        $rows = db_fetch_all(
            "SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              ORDER BY SEQ_IN_INDEX",
            [$prefix . $tbl, $idx]);
        if (!$rows) { $fail[] = "verify: unique key {$tbl}.{$idx} is missing"; continue; }
        $got = implode(',', array_column($rows, 'COLUMN_NAME'));
        if ($got !== $cols) $fail[] = "verify: {$tbl}.{$idx} covers ({$got}), expected ({$cols})";
        if ((int) $rows[0]['NON_UNIQUE'] !== 0) $fail[] = "verify: {$tbl}.{$idx} is not UNIQUE";
    }

    foreach (['tow', 'lockout', 'jumpstart', 'tire_change'] as $code) {
        $c = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}vendor_service_types` WHERE `code` = ?", [$code]);
        if ($c === 0) $fail[] = "verify: service type {$code} is not seeded";
    }

    foreach (array_keys($settingDefaults) as $name) {
        $c = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
        if ($c === 0) $fail[] = "verify: setting {$name} does not exist";
    }

    foreach ($perms as [$code, $name, $desc, $resource, $verb, $tier, $roles]) {
        $row = db_fetch_one("SELECT `id`, `admin_only` FROM `{$prefix}permissions` WHERE `code` = ?", [$code]);
        if (!$row) { $fail[] = "verify: permission {$code} does not exist"; continue; }
        if ((int) $row['admin_only'] < $tier) {
            $fail[] = "verify: {$code} admin_only=" . (int) $row['admin_only'] . ", expected at least {$tier}";
        }
        foreach ($roles as $roleId) {
            $g = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}role_permissions` WHERE `role_id` = ? AND `permission_id` = ?",
                [$roleId, (int) $row['id']]);
            if ($g === 0) $fail[] = "verify: role {$roleId} does not hold {$code}";
        }
    }

    // Leak check: the tier-1 code must NOT be held by Dispatcher (role 3), directly or via its canonical alias.
    $mgr = db_fetch_one("SELECT `id`, `deprecated_alias_of` FROM `{$prefix}permissions` WHERE `code` = 'action.manage_vendors'");
    if ($mgr) {
        $direct = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}role_permissions` WHERE `role_id` = 3 AND `permission_id` = ?", [(int) $mgr['id']]);
        if ($direct > 0) $fail[] = 'verify: Dispatcher (role 3) holds action.manage_vendors DIRECTLY';
        $aliasLeak = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}role_permissions` rp
               JOIN `{$prefix}permissions` canon ON canon.id = rp.permission_id
               JOIN `{$prefix}permissions` old_p ON old_p.deprecated_alias_of = canon.code
              WHERE rp.role_id = 3 AND old_p.code = 'action.manage_vendors'");
        if ($aliasLeak > 0) $fail[] = 'verify: Dispatcher (role 3) holds the canonical alias of action.manage_vendors';
    }
} catch (Throwable $e) {
    $fail[] = 'verify: ' . $e->getMessage();
}

if ($fail) {
    fwrite(STDERR, "\nFAILED:\n  - " . implode("\n  - ", $fail) . "\n");
    echo "\nFAILED:\n  - " . implode("\n  - ", $fail) . "\n";
    exit(1);
}

echo "\nDone. Towing / roadside vendor dispatch (GH#148) installed. The feature is OFF until an administrator turns on\n";
echo "Settings > Resources > Service Providers > Settings > 'Enable towing / roadside dispatch'.\n";
exit(0);
