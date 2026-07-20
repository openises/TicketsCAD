<?php
/**
 * Phase 99h (2026-06-29) — APRS watchlist table.
 *
 * Eric beta: a system-wide list of "interesting" APRS callsigns
 * curated by admins. Distinct from APRS targets linked to
 * personnel/units — this is the catch-all "watch this random
 * station" list (Bike MS event lead vehicle, a Skywarn spotter
 * during severe weather, etc.).
 *
 * Single shared list — NOT per-user, per-incident, or per-org.
 * Individual viewers toggle layer visibility client-side. Admins
 * curate the contents via the APRS map List view's Watch toggle.
 *
 * Run: php sql/run_99h_aprs_watchlist.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    db_query(
        "CREATE TABLE IF NOT EXISTS `{$prefix}aprs_watchlist` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `callsign`    VARCHAR(16) NOT NULL,
            `note`        VARCHAR(255) NULL,
            `added_by`    INT NULL,
            `added_by_name` VARCHAR(64) NULL,
            `added_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_callsign` (`callsign`),
            KEY `idx_added_at` (`added_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "✓ aprs_watchlist table ready\n";

    // RBAC permission for admin curate. Read access stays open
    // (any logged-in user who can see the map sees the watchlist).
    // Schema: permissions.code is the unique stable ID, .name is
    // the human label, .category is the grouping (action/screen/etc).
    // role_permissions is the M2M with role_id + permission_id PK.
    db_query(
        "INSERT IGNORE INTO `{$prefix}permissions` (code, name, description, category)
         VALUES ('action.manage_aprs_watchlist',
                 'Manage APRS watchlist',
                 'Add/remove callsigns to the system-wide APRS watchlist',
                 'action')"
    );
    $permId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}permissions` WHERE code = ? LIMIT 1",
        ['action.manage_aprs_watchlist']
    );
    if ($permId > 0) {
        db_query(
            "INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id)
             VALUES (1, ?)",
            [$permId]
        );
    }
    echo "✓ action.manage_aprs_watchlist permission (id={$permId}) seeded — Super Admin granted\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

$count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}aprs_watchlist`");
echo "Done. {$count} callsign(s) currently watched.\n";
