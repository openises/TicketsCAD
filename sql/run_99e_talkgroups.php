<?php
/**
 * Phase 99e talkgroups (2026-06-28) — admin-configurable DMR talkgroup
 * registry. Used by:
 *   - Compose form (DMR channel — future: 'Send to → Talkgroup' picker)
 *   - DMR radio widget (future: selectable talkgroup before PTT)
 *   - Routing rules (target a talkgroup as a forward destination)
 *
 * Per-install configurable. Eric (2026-06-28): "3127 for example is
 * Minnesota State, and 31272 we call 'MN Metro 2'. Each system should
 * be able to configure its own list of talk-groups."
 *
 * Idempotent — safe to re-run. Seeds Eric's two known Minnesota
 * talkgroups on first run; does NOT modify existing rows on rerun.
 *
 *   php sql/run_99e_talkgroups.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    db_query(
        "CREATE TABLE IF NOT EXISTS `{$prefix}talkgroups` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `name`        VARCHAR(64)  NOT NULL,
            `dmr_id`      INT UNSIGNED NOT NULL,
            `description` VARCHAR(255) DEFAULT NULL,
            `sort_order`  INT NOT NULL DEFAULT 0,
            `enabled`     TINYINT(1)   NOT NULL DEFAULT 1,
            `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_dmr_id` (`dmr_id`),
            KEY `idx_sort` (`sort_order`, `name`),
            KEY `idx_enabled` (`enabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "✓ talkgroups table ready\n";
} catch (Exception $e) {
    fwrite(STDERR, "ERROR creating talkgroups table: " . $e->getMessage() . "\n");
    exit(1);
}

// Seed Eric's two known Minnesota talkgroups on first run.
// INSERT IGNORE — existing rows untouched.
$seed = [
    [3127,  'MN State',     'Minnesota Statewide DMR talkgroup',          10],
    [31272, 'MN Metro 2',   'Minnesota Twin Cities Metro 2 — local SAR',  20],
];
foreach ($seed as [$id, $name, $desc, $sort]) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}talkgroups` (dmr_id, name, description, sort_order, enabled)
             VALUES (?, ?, ?, ?, 1)",
            [$id, $name, $desc, $sort]
        );
    } catch (Exception $e) {
        fwrite(STDERR, "  WARN seeding tg {$id}: " . $e->getMessage() . "\n");
    }
}
$count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}talkgroups`");
echo "✓ seeded talkgroups (table now has {$count} row(s); existing rows untouched)\n";

// Seed RBAC permission for managing talkgroups.
try {
    $existing = db_fetch_value(
        "SELECT id FROM `{$prefix}permissions` WHERE code = 'action.manage_talkgroups' LIMIT 1"
    );
    if (!$existing) {
        db_query(
            "INSERT INTO `{$prefix}permissions` (code, category, name, description)
             VALUES ('action.manage_talkgroups', 'action',
                     'Manage DMR talkgroups',
                     'Create / edit / delete talkgroup registry rows used by Compose + radio widget')"
        );
        // Grant to Super Admin (role 1) by default.
        $permId = (int) db_insert_id();
        db_query(
            "INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id)
             VALUES (1, ?)",
            [$permId]
        );
        echo "✓ added action.manage_talkgroups permission + Super Admin grant\n";
    } else {
        echo "✓ permission already present\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "  WARN seeding permission: " . $e->getMessage() . "\n");
}

echo "Done.\n";
