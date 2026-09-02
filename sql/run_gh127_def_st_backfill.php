<?php
/**
 * GH #127 follow-up (rjonesbsink) — backfill region.def_st on installs
 * where the original buggy seed already ran.
 *
 * The base_schema.sql seed used to copy region.def_zoom's value into
 * def_st (a US-state-abbreviation field, not a zoom level) -- fixed to
 * seed def_st = NULL. But `INSERT IGNORE INTO region VALUES (1, ...)`
 * only runs once per install (id=1 already exists on every upgraded
 * install), so the fresh-install fix never reaches a database that
 * already has the bad row. `git pull` + run_migrations.php alone does
 * NOT fix this -- confirmed live by the reporter re-checking their own
 * install after pulling.
 *
 * The bug's exact signature is unambiguous: def_st holding the SAME
 * value as that row's own def_zoom (both stored as the literal zoom
 * integer). No legitimate two-letter state abbreviation can equal a
 * zoom-level integer, so `def_st = def_zoom` is safe to null without
 * risking a real, admin-entered state value. Idempotent -- a clean
 * re-run finds nothing left to fix.
 *
 * Compares def_st = def_zoom directly (an implicit numeric coercion of
 * the varchar side), NOT `def_st = CAST(def_zoom AS CHAR)` -- region is
 * latin1_swedish_ci (base_schema.sql's table default) while this
 * connection's session default collation is a newer utf8mb4 variant,
 * and CAST(... AS CHAR) produces a string in the CONNECTION's default
 * collation, not the column's -- MySQL/MariaDB then refuses the `=`
 * with "Illegal mix of collations". Caught live on this exact dev
 * database while first testing this script (which also, not
 * coincidentally, turned out to still be carrying the live bug this
 * migration exists to fix).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH #127 follow-up — region.def_st backfill\n";
echo "===========================================\n\n";

$have = db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?", [$prefix . 'region']);
if (!$have) {
    echo "region table absent — nothing to do.\n";
    return;
}

$affected = db_fetch_all(
    "SELECT `id`, `def_st`, `def_zoom` FROM `{$prefix}region`
      WHERE `def_st` IS NOT NULL AND `def_st` <> '' AND `def_st` = `def_zoom`"
);

if (empty($affected)) {
    echo "No region row has the bug's signature (def_st = def_zoom) — nothing to do.\n";
} else {
    foreach ($affected as $row) {
        echo "region.id={$row['id']}: def_st='{$row['def_st']}' matches def_zoom={$row['def_zoom']} — nulling.\n";
    }
    db_query(
        "UPDATE `{$prefix}region` SET `def_st` = NULL
          WHERE `def_st` IS NOT NULL AND `def_st` <> '' AND `def_st` = `def_zoom`"
    );
    echo "\nBackfilled " . count($affected) . " row(s).\n";
}

echo "\nDone.\n";
