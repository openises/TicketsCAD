<?php
/**
 * GH #77 — de-duplicate the permissions table and enforce UNIQUE(code)
 *
 * Symptom (a beta tester): "two of every permission" on settings.php#roles-levels.
 *
 * Root cause: the RBAC seed (run_00_rbac.php) inserts permissions with
 * INSERT IGNORE, which relies on a UNIQUE key on permissions.code to suppress
 * re-inserts. But the table is created with CREATE TABLE IF NOT EXISTS. An
 * install whose permissions table was first created by an OLDER schema that
 * lacked UNIQUE(code) never gained the key (IF NOT EXISTS won't alter an
 * existing table), so INSERT IGNORE had nothing to ignore against — every
 * re-run of the seed appended a fresh duplicate row for every code, and the
 * roles UI's LEFT JOIN then listed each permission twice (or more).
 *
 * This migration heals such an install:
 *   1. Collapse duplicate permission rows by code — keep the lowest id,
 *      re-point role_permissions grants onto it (UPDATE IGNORE + cleanup so
 *      the (role_id, permission_id) PK is respected), delete the dup rows.
 *   2. Add the UNIQUE key on code if it's missing, so re-seeds can never
 *      duplicate again.
 *
 * Idempotent — on a healthy install it finds no dups and confirms the key.
 * Picked up automatically by run_migrations.php.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH #77 — de-duplicate permissions + enforce UNIQUE(code)\n";
echo "=======================================================\n\n";

// Guard: no permissions table on this install (RBAC not installed yet) → skip.
$have = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?", [$prefix . 'permissions']);
if (!$have) {
    echo "permissions table absent — nothing to do (RBAC seed will create it\n";
    echo "with UNIQUE(code)).\n";
    return;
}

$hasRolePerms = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?", [$prefix . 'role_permissions']);

// ── 1. Collapse duplicate codes ──────────────────────────────────────
$dupCodes = db_fetch_all(
    "SELECT code, MIN(id) AS keep_id, COUNT(*) AS n
       FROM `{$prefix}permissions`
      GROUP BY code HAVING COUNT(*) > 1");

if (!$dupCodes) {
    echo "No duplicate permission codes found.\n";
} else {
    echo count($dupCodes) . " duplicated code(s) found — collapsing:\n";
    $removed = 0;
    foreach ($dupCodes as $d) {
        $keep = (int) $d['keep_id'];
        $dups = db_fetch_all(
            "SELECT id FROM `{$prefix}permissions` WHERE code = ? AND id <> ?",
            [$d['code'], $keep]);
        $dupIds = array_map(function ($r) { return (int) $r['id']; }, $dups);
        if (!$dupIds) continue;
        $in = implode(',', array_fill(0, count($dupIds), '?'));

        if ($hasRolePerms) {
            // Re-point grants to the kept permission id. UPDATE IGNORE skips
            // rows that would collide with an existing (role_id, keep_id) PK;
            // the DELETE then removes those now-redundant dup grants.
            db_query(
                "UPDATE IGNORE `{$prefix}role_permissions`
                    SET permission_id = ?
                  WHERE permission_id IN ($in)",
                array_merge([$keep], $dupIds));
            db_query(
                "DELETE FROM `{$prefix}role_permissions` WHERE permission_id IN ($in)",
                $dupIds);
        }

        db_query("DELETE FROM `{$prefix}permissions` WHERE id IN ($in)", $dupIds);
        $removed += count($dupIds);
        echo "  {$d['code']}: kept id {$keep}, removed " . count($dupIds)
           . " dup row(s)\n";
    }
    echo "Removed {$removed} duplicate permission row(s).\n";
}

// ── 2. Ensure UNIQUE(code) so it can never recur ─────────────────────
$hasUnique = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = ?
        AND column_name = 'code' AND non_unique = 0", [$prefix . 'permissions']);

if ($hasUnique) {
    echo "UNIQUE(code) already present.\n";
} else {
    try {
        db_query("ALTER TABLE `{$prefix}permissions`
                  ADD UNIQUE KEY `uniq_permission_code` (`code`)");
        echo "Added UNIQUE(code) to permissions.\n";
    } catch (Exception $e) {
        echo "ERR adding UNIQUE(code): " . $e->getMessage() . "\n";
        echo "(If this failed on remaining duplicates, re-run after review.)\n";
    }
}

echo "\nDone. permissions rows now: "
   . db_fetch_value("SELECT COUNT(*) FROM `{$prefix}permissions`") . "\n";
