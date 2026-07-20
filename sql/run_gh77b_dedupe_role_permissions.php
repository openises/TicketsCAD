<?php
/**
 * GH #77 (a beta tester) — dedupe role_permissions + enforce UNIQUE(role_id, permission_id)
 *
 * The first #77 fix deduped the `permissions` table and added UNIQUE(code).
 * But a second tester still saw "two of every permission" in the role editor
 * with a CLEAN permissions table (no duplicate codes). Root cause: duplicate
 * rows in `role_permissions` (older installs accumulate them from re-runnable
 * seed imports that INSERT grants without an ON DUPLICATE / UNIQUE guard). The
 * per-role editor LEFT JOINs role_permissions, so each duplicated grant
 * doubled the permission in the display, and inflated the per-role perm_count
 * badge.
 *
 * api/rbac.php now GROUP BY p.id in that query (fixes the display everywhere
 * immediately). This migration cleans the underlying data and prevents
 * recurrence. Idempotent — safe to re-run; picked up by run_migrations.php.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';
$table = $prefix . 'role_permissions';

echo "GH #77b — dedupe role_permissions\n";
echo "=================================\n\n";

$have = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?", [$table]);
if (!$have) {
    echo "role_permissions table absent — nothing to do.\n";
    return;
}

// 1. Remove duplicate (role_id, permission_id) rows, keeping the lowest id.
try {
    $dupRows = (int) db_fetch_value(
        "SELECT COALESCE(SUM(c - 1), 0) FROM (
            SELECT COUNT(*) c FROM `{$table}`
             GROUP BY role_id, permission_id HAVING c > 1) x");
    if ($dupRows > 0) {
        // Self-join delete: drop every row that has a lower-id twin with the
        // same (role_id, permission_id).
        db_query(
            "DELETE rp1 FROM `{$table}` rp1
             JOIN `{$table}` rp2
               ON rp1.role_id = rp2.role_id
              AND rp1.permission_id = rp2.permission_id
              AND rp1.id > rp2.id");
        echo "Removed {$dupRows} duplicate grant row(s).\n";
    } else {
        echo "No duplicate grants found.\n";
    }
} catch (Exception $e) {
    echo "dedupe note: " . $e->getMessage() . "\n";
}

// 2. Add UNIQUE(role_id, permission_id) if not already present.
try {
    $idx = db_fetch_all("SHOW INDEX FROM `{$table}`");
    $hasUnique = false;
    // Group index columns by key name; a unique key on exactly
    // (role_id, permission_id) is what we want.
    $byKey = [];
    foreach ($idx as $i) {
        if ((int) $i['Non_unique'] === 0) {
            $byKey[$i['Key_name']][(int) $i['Seq_in_index']] = $i['Column_name'];
        }
    }
    foreach ($byKey as $cols) {
        ksort($cols);
        $c = array_values($cols);
        if ($c === ['role_id', 'permission_id'] || $c === ['permission_id', 'role_id']) {
            $hasUnique = true;
            break;
        }
    }
    if ($hasUnique) {
        echo "UNIQUE(role_id, permission_id) already present.\n";
    } else {
        db_query("ALTER TABLE `{$table}`
                  ADD UNIQUE KEY `uniq_role_perm` (`role_id`, `permission_id`)");
        echo "Added UNIQUE(role_id, permission_id).\n";
    }
} catch (Exception $e) {
    echo "unique-key note: " . $e->getMessage() . "\n";
}

echo "\nDone. role_permissions rows now: "
   . (int) db_fetch_value("SELECT COUNT(*) FROM `{$table}`") . "\n";
