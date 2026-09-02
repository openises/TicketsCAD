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

// Live check: this used to just NOTE (not fail on) a pre-existing install
// still carrying the garbage value, since a schema-file fix cannot
// retroactively correct already-seeded data. rjonesbsink hit exactly that
// gap on 2026-09-02 -- confirmed live on THIS dev database too, which
// still carried def_st='10' == def_zoom=10 on region #1 until the
// sql/run_gh127_def_st_backfill.php migration below was written to close
// it. Now asserted for real, not merely noted.
try {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $haveTable = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$prefix . 'region']) > 0;
    if ($haveTable) {
        $row = db_fetch_one("SELECT def_st, def_zoom FROM `{$prefix}region` WHERE id = 1");
        if ($row !== null) {
            is_ok(
                !($row['def_st'] !== null && $row['def_st'] === (string) $row['def_zoom']),
                'live database region #1 does not carry the pre-fix garbage value (backfilled)'
            );
        }
    }
} catch (Throwable $e) { /* no database available — schema-file checks above still ran */ }

// ═══════════════════════════════════════════════════════════════════════
// GH#127 follow-up (rjonesbsink, 2026-09-02) — sql/run_gh127_def_st_backfill.php
// closes the gap the live check above used to only note: an install where
// the buggy seed already ran keeps def_st='10' forever, since
// `INSERT IGNORE INTO region VALUES (1, ...)` skips a row that already
// exists and a normal `git pull` + run_migrations.php never touches it.
// Drives the REAL migration script (not a re-implementation of its SQL)
// against a throwaway fixture row so this doesn't depend on -- or
// disturb -- region #1's own real state.
// ═══════════════════════════════════════════════════════════════════════
try {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $migrationPath = $base . '/sql/run_gh127_def_st_backfill.php';
    is_ok(file_exists($migrationPath), 'sql/run_gh127_def_st_backfill.php exists');

    if (file_exists($migrationPath)) {
        // A throwaway region row carrying the EXACT bug signature
        // (def_st == def_zoom, both '7') -- picked away from the real
        // def_zoom default (10) so it can't be confused with region #1.
        db_query(
            "INSERT INTO `{$prefix}region` (group_name, category, description, owner, def_st, def_zoom)
             VALUES ('gh127_backfill_fixture', 4, 'temporary test fixture', 1, '7', 7)"
        );
        $fixtureId = (int) db_fetch_value('SELECT LAST_INSERT_ID()');
        register_shutdown_function(static function () use ($prefix, $fixtureId) {
            try { db_query("DELETE FROM `{$prefix}region` WHERE id = ?", [$fixtureId]); } catch (Throwable $e) {}
        });

        $before = db_fetch_one("SELECT def_st, def_zoom FROM `{$prefix}region` WHERE id = ?", [$fixtureId]);
        is_ok($before['def_st'] === '7' && (int) $before['def_zoom'] === 7,
            'fixture row was created with the bug signature (def_st == def_zoom)');

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($migrationPath);
        $out = []; $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        $outStr = implode("\n", $out);
        is_ok($code === 0, "the migration exits 0 (exit $code; output: $outStr)");
        is_ok(strpos($outStr, "region.id={$fixtureId}:") !== false,
            "the migration reports the fixture row by id (output: $outStr)");

        $after = db_fetch_one("SELECT def_st FROM `{$prefix}region` WHERE id = ?", [$fixtureId]);
        is_ok($after['def_st'] === null, 'the fixture row\'s def_st is nulled by the migration');

        // Idempotency: a second run must find nothing left to fix.
        $out2 = []; $code2 = 0;
        exec($cmd . ' 2>&1', $out2, $code2);
        $out2Str = implode("\n", $out2);
        is_ok($code2 === 0, "a second run also exits 0 (exit $code2; output: $out2Str)");
        is_ok(strpos($out2Str, 'nothing to do') !== false,
            "a second run reports nothing left to fix (idempotent) (output: $out2Str)");
    }
} catch (Throwable $e) {
    bad('backfill migration test threw: ' . $e->getMessage());
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
