<?php
/**
 * B19 (SPEC-STATUS.md, 2026-08-21) — the legacy `newui_facility_capacity`
 * table is dropped; the live `capacity_categories` + `facility_capacity`
 * pair is untouched. See sql/run_facility_capacity_legacy_table_drop.php's
 * own docblock for the full rationale (zero readers anywhere in the app;
 * the only writer was sql/facility_beds.sql's own auto-seed INSERT; every
 * row on every install checked matched that exact placeholder shape).
 *
 * Usage: php tests/test_facility_capacity_legacy_table_drop.php
 */
require __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

echo "=== B19 — newui_facility_capacity dropped, facility_capacity untouched ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function b19_table_exists(string $prefix, string $table): bool {
    return (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . $table]
    );
}

// ── 1. Migration is idempotent and drives the real script (not a copy of
//      its logic) — matches the established require-the-migration-inline
//      pattern (tests/test_gh96_requests_table_dropped.php et al).
ob_start();
try {
    $ranOk = true;
    require __DIR__ . '/../sql/run_facility_capacity_legacy_table_drop.php';
} catch (Throwable $e) {
    $ranOk = false;
}
$out1 = ob_get_clean();
t('run_facility_capacity_legacy_table_drop.php ran without throwing', $ranOk);

ob_start();
try {
    require __DIR__ . '/../sql/run_facility_capacity_legacy_table_drop.php';
} catch (Throwable $e) {
    $ranOk = false;
}
$out2 = ob_get_clean();
t('run_facility_capacity_legacy_table_drop.php is idempotent (second run is a clean SKIP)',
    strpos($out2, '[SKIP]') !== false);

// ── 2. The actual database state.
t('newui_facility_capacity table does NOT exist', !b19_table_exists($prefix, 'newui_facility_capacity'));
t('capacity_categories table STILL exists (the real, live capacity model)',
    b19_table_exists($prefix, 'capacity_categories'));
t('facility_capacity table STILL exists (the real, live capacity model)',
    b19_table_exists($prefix, 'facility_capacity'));

// ── 3. Source-level: sql/facility_beds.sql no longer CREATEs the dead
//      table, and sql/run_facility_capacity_tables.php's CREATE for the
//      real tables is untouched.
$facilityBeds = file_get_contents(__DIR__ . '/../sql/facility_beds.sql');
t('sql/facility_beds.sql no longer CREATEs `newui_facility_capacity`',
    preg_match('/CREATE TABLE\s+(IF NOT EXISTS\s+)?`newui_facility_capacity`/i', $facilityBeds) === 0);

$realTables = file_get_contents(__DIR__ . '/../sql/run_facility_capacity_tables.php');
t('sql/run_facility_capacity_tables.php still CREATEs capacity_categories (untouched)',
    strpos($realTables, 'CREATE TABLE IF NOT EXISTS `{$prefix}capacity_categories`') !== false);
t('sql/run_facility_capacity_tables.php still CREATEs facility_capacity (untouched)',
    strpos($realTables, 'CREATE TABLE IF NOT EXISTS `{$prefix}facility_capacity`') !== false);

// ── 4. No remaining PHP reference to the dropped table anywhere in api/,
//      inc/ (outside this test file and the migration script itself).
$scanDirs = ['api', 'inc'];
$offenders = [];
foreach ($scanDirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../' . $dir));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') continue;
        $src = file_get_contents($file->getPathname());
        if (strpos($src, 'newui_facility_capacity') !== false) {
            $offenders[] = str_replace('\\', '/', str_replace(__DIR__ . '/../', '', $file->getPathname()));
        }
    }
}
t('no api/ or inc/ file references newui_facility_capacity'
    . (empty($offenders) ? '' : ' (found in: ' . implode(', ', $offenders) . ')'),
    empty($offenders));

// ── 5. facility-board.php's existence gate checks the RIGHT table now —
//      the actual bug found alongside this cleanup (it used to check the
//      dead table as a proxy for whether the LIVE table's data was
//      available, which only ever worked by coincidence).
$facBoard = file_get_contents(__DIR__ . '/../facility-board.php');
t("facility-board.php's capacity existence check targets facility_capacity, not the dead table",
    strpos($facBoard, "'facility_capacity'") !== false
    && strpos($facBoard, "'newui_facility_capacity'") === false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
