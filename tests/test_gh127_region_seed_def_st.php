<?php
/**
 * GH#127 (reported 2026-08-29, rjonesbsink) — region seed data had a
 * garbage def_st value.
 *
 * sql/base_schema.sql's default 'General' region INSERT used positional
 * VALUES with 12 columns (id, group_name, category, description, owner,
 * def_area_code, def_city, def_lat, def_lng, def_st, def_zoom, boundary).
 * The literal was (1,'General',4,'General - group 0',1,'','',NULL,NULL,
 * '10',10,0) -- the SAME value '10' landed in BOTH def_st (position 10)
 * and def_zoom (position 11), an apparent copy-paste slip. Settings ->
 * Locations -> Regions renders def_st straight into a free-text "State"
 * field, so a fresh install's default region showed State: 10.
 *
 * Fixed to NULL (matching the column's own nullable default and its
 * NULL siblings def_lat/def_lng in the same row) in both
 * sql/base_schema.sql and sql/base_schema_RESET_DESTRUCTIVE.sql.
 *
 * This is deliberately narrow: it fixes ONLY the seed-data typo. The
 * SEPARATE question the same report raised -- whether region-based
 * dispatcher filtering (docs/USER-GUIDE.md §10.5) is a real, missing
 * feature or documentation describing something that was never built --
 * is a scope decision left for Eric, not addressed here.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/inc/db.php';

$pass = 0; $fail = 0;
function ok(string $m): void { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "  FAIL: $m\n"; }
function is_ok($cond, string $m): void { $cond ? ok($m) : bad($m); }

echo "\n=== GH#127 — region seed def_st garbage value ===\n";

foreach (['sql/base_schema.sql', 'sql/base_schema_RESET_DESTRUCTIVE.sql'] as $rel) {
    $path = $base . '/' . $rel;
    $src = @file_get_contents($path);
    if ($src === false) { bad("{$rel} — could not read file"); continue; }

    // Find the region INSERT's VALUES tuple specifically (not region_type,
    // which has its own separate INSERT right below it in both files).
    if (!preg_match('/INSERT (?:IGNORE )?INTO `region` VALUES\s*\n\(([^)]*)\)/', $src, $m)) {
        bad("{$rel} — could not locate the region seed INSERT");
        continue;
    }
    $values = array_map('trim', explode(',', $m[1]));
    // Columns: id, group_name, category, description, owner, def_area_code,
    // def_city, def_lat, def_lng, def_st, def_zoom, boundary (12 total).
    is_ok(count($values) === 12, "{$rel} — region seed row has 12 columns (got " . count($values) . ")");
    if (count($values) === 12) {
        $defSt   = $values[9];
        $defZoom = $values[10];
        is_ok($defSt === 'NULL', "{$rel} — def_st is NULL, not a stray copy of def_zoom (got {$defSt})");
        is_ok($defZoom === '10', "{$rel} — def_zoom is still correctly 10 (got {$defZoom})");
    }
}

// Live check: if this dev database's region table still carries the old
// garbage value (seeded before this fix), that's a pre-existing-install
// migration gap worth knowing about, not something this narrow fix
// silently glosses over — report it, don't fail the suite on it, since a
// live install's existing data is out of scope for a schema-file fix.
try {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $haveTable = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$prefix . 'region']) > 0;
    if ($haveTable) {
        $row = db_fetch_one("SELECT def_st, def_zoom FROM `{$prefix}region` WHERE id = 1");
        if ($row !== null) {
            if ($row['def_st'] === $row['def_zoom'] && $row['def_st'] !== null) {
                echo "  NOTE: this live database's region #1 still carries the pre-fix value (def_st='{$row['def_st']}') -- schema-file fixes don't retroactively correct already-seeded installs; not treated as a test failure.\n";
            } else {
                ok('live database region #1 does not carry the pre-fix garbage value');
            }
        }
    }
} catch (Throwable $e) { /* no database available — schema-file checks above still ran */ }

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
