<?php
/**
 * Phase 86 (2026-09-02) — Major Events extensions, schema.
 *
 * Extends the ALREADY-SHIPPED major-incident linking feature
 * (newui_major_incidents / newui_major_incident_links / api/major-incidents.php /
 * major-incidents.php) per the 5-persona design review recorded in
 * specs/phase-86-major-events/changes.md. Deliberately does NOT rename any
 * table (see that doc's own explanation of why the originally-proposed
 * rename was dropped) and does NOT add a JSON unified_command or
 * resource_summary column (replaced by the real junction table below and a
 * live-computed rollup respectively).
 *
 * Idempotent -- every step is guarded by an information_schema check or
 * INSERT IGNORE / a WHERE clause that only touches rows not already in the
 * target state. Auto-discovered by sql/run_migrations.php's run_*.php sweep.
 *
 * Spec: specs/phase-86-major-events/{spec.md,changes.md,tasks.md}
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 86 — Major Events extensions (schema)\n";
echo "=============================================\n\n";

// ── 1. New columns on the existing newui_major_incidents table ──────────
$newColumns = [
    'event_type'            => "VARCHAR(32) NULL DEFAULT NULL COMMENT 'structure-fire|mci|hazmat|severe-weather|planned-event|other'",
    'parent_incident_id'    => "INT NULL DEFAULT NULL COMMENT 'the originating ticket, if this event was escalated from one'",
    'boundary_id'           => "INT NULL DEFAULT NULL COMMENT 'FK-like reference to geofences.id, optional'",
    'mutual_aid_requested'  => "TINYINT(1) NOT NULL DEFAULT 0",
];
foreach ($newColumns as $col => $ddl) {
    $exists = db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . 'newui_major_incidents', $col]
    );
    if ($exists) {
        echo "[SKIP] newui_major_incidents.{$col} already exists\n";
        continue;
    }
    try {
        db_query("ALTER TABLE `{$prefix}newui_major_incidents` ADD COLUMN `{$col}` {$ddl}");
        echo "[OK] newui_major_incidents.{$col} added\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "[FAIL] newui_major_incidents.{$col}: " . $e->getMessage() . "\n");
        exit(1);
    }
}

$idxExists = db_fetch_value(
    "SELECT 1 FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_parent_incident'",
    [$prefix . 'newui_major_incidents']
);
if (!$idxExists) {
    db_query("ALTER TABLE `{$prefix}newui_major_incidents` ADD INDEX `idx_parent_incident` (`parent_incident_id`)");
    echo "[OK] newui_major_incidents.idx_parent_incident index added\n";
} else {
    echo "[SKIP] newui_major_incidents.idx_parent_incident already exists\n";
}

// ── 2. newui_major_incident_command — the unified-command roster ────────
// A real junction table, not a JSON blob: member_id nullable so a
// commander from an agency with no TicketsCAD account can still be
// recorded (external_name/agency), and joined_at/left_at capture the
// transfer-of-command history a bare array would lose (Marcus's review
// point — "who had command when" matters for after-action review).
$haveCommandTable = db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'newui_major_incident_command']
);
if ($haveCommandTable) {
    echo "[SKIP] newui_major_incident_command already exists\n";
} else {
    try {
        db_query(
            "CREATE TABLE `{$prefix}newui_major_incident_command` (
                `id`                 INT AUTO_INCREMENT PRIMARY KEY,
                `major_incident_id`  INT NOT NULL,
                `member_id`          INT NULL DEFAULT NULL,
                `external_name`      VARCHAR(100) NULL DEFAULT NULL,
                `agency`             VARCHAR(100) NOT NULL,
                `role`               VARCHAR(60) NOT NULL DEFAULT 'incident_commander',
                `joined_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `left_at`            DATETIME NULL DEFAULT NULL,
                KEY `idx_major_incident` (`major_incident_id`, `left_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        echo "[OK] newui_major_incident_command created\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "[FAIL] newui_major_incident_command: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// ── 3. RBAC — two new permissions for installs that already ran rbac.sql
//     / run_00_rbac.php before this commit. Fresh installs get these from
//     those seed files directly; this block is for EXISTING installs only,
//     and is a no-op there (INSERT IGNORE + idempotent classification).
$newPerms = [
    ['action.create_major_event',          'Create/Escalate Major Event',   'action'],
    ['action.manage_major_event_command',  'Manage Major Event Command',    'action'],
];
foreach ($newPerms as [$code, $name, $category]) {
    db_query(
        "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`) VALUES (?, ?, ?)",
        [$code, $name, $category]
    );
}
echo "[OK] action.create_major_event / action.manage_major_event_command seeded (or already present)\n";

// Classify both as admin_only=1 (Org Admin or above) -- supervisor-tier by
// default per Aisha's review point (a part-time dispatcher must not be able
// to unilaterally declare a major event). Mirrors sql/rbac.sql's own
// classification block exactly.
db_query(
    "UPDATE `{$prefix}permissions` SET `admin_only` = 1
      WHERE `code` IN ('action.create_major_event', 'action.manage_major_event_command')
        AND `admin_only` <> 1"
);
echo "[OK] both new permissions classified admin_only=1\n";

// Grant to Super Admin (role 1) and Org Admin (role 2) explicitly -- on an
// EXISTING install, rbac.sql's own unconditional "Super Admin gets
// EVERYTHING" and "Org Admin gets everything except ... AND admin_only <= 1"
// grant statements do NOT re-run automatically, so a brand-new permission
// row needs an explicit grant here or it silently grants to nobody.
foreach ([1, 2] as $roleId) {
    db_query(
        "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
         SELECT ?, `id` FROM `{$prefix}permissions`
          WHERE `code` IN ('action.create_major_event', 'action.manage_major_event_command')",
        [$roleId]
    );
}
echo "[OK] granted to Super Admin + Org Admin (roles 1-2)\n";

// Explicit safety check, not an assumption: confirm neither new permission
// landed on Dispatcher/Operator/Read-Only/Field Unit (roles 3-6) via any
// pre-existing broad grant that predates this migration.
$leaked = db_fetch_all(
    "SELECT rp.role_id, p.code FROM `{$prefix}role_permissions` rp
     JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
      WHERE p.code IN ('action.create_major_event', 'action.manage_major_event_command')
        AND rp.role_id NOT IN (1, 2)"
);
if (!empty($leaked)) {
    fwrite(STDERR, "[FAIL] unexpected grant(s) found on a non-supervisor role:\n");
    foreach ($leaked as $row) {
        fwrite(STDERR, "  role_id={$row['role_id']} code={$row['code']}\n");
    }
    exit(1);
}
echo "[OK] verified: neither permission is granted to Dispatcher/Operator/Read-Only/Field Unit\n";

echo "\nDone.\n";
