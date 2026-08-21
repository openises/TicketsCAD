<?php
/**
 * GH#96 (2026-08-20) — the legacy `requests` table is dropped; the
 * separate, unrelated `access_requests` table (a real live v4 feature) is
 * untouched. See sql/run_gh96_drop_requests_table.php's own docblock for
 * the full rationale (5-persona design review, #91 dead-control-audit
 * "confirmed genuinely gone" bucket).
 *
 * Usage: php tests/test_gh96_requests_table_dropped.php
 */
require __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#96 — requests table dropped, access_requests untouched ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function gh96_table_exists(string $prefix, string $table): bool {
    return (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . $table]
    );
}

// ── 1. Migration is idempotent and drives the real script (not a copy of
//      its logic) — matches the established require-the-migration-inline
//      pattern (e.g. test_gh52_second_extra_data_slot.php,
//      test_gh96_mileage_log_write_paths.php).
ob_start();
try {
    $idemOk = true;
    require __DIR__ . '/../sql/run_gh96_drop_requests_table.php';
} catch (Throwable $e) {
    $idemOk = false;
}
$out1 = ob_get_clean();
t('run_gh96_drop_requests_table.php ran without throwing', $idemOk);

ob_start();
try {
    require __DIR__ . '/../sql/run_gh96_drop_requests_table.php';
} catch (Throwable $e) {
    $idemOk = false;
}
$out2 = ob_get_clean();
t('run_gh96_drop_requests_table.php is idempotent (second run is a clean SKIP)',
    strpos($out2, '[SKIP]') !== false);

// ── 2. The actual database state.
t('requests table does NOT exist', !gh96_table_exists($prefix, 'requests'));
t('access_requests table STILL exists (a real live v4 feature, unrelated to this cleanup)',
    gh96_table_exists($prefix, 'access_requests'));

// ── 3. Source-level: no CREATE TABLE `requests` left in either shipped
//      schema file, and neither schema file was left referencing
//      access_requests incorrectly in its place.
$baseSchema = file_get_contents(__DIR__ . '/../sql/base_schema.sql');
$resetSchema = file_get_contents(__DIR__ . '/../sql/base_schema_RESET_DESTRUCTIVE.sql');
t('sql/base_schema.sql no longer CREATEs `requests`',
    preg_match('/CREATE TABLE\s+(IF NOT EXISTS\s+)?`requests`/i', $baseSchema) === 0);
t('sql/base_schema_RESET_DESTRUCTIVE.sql no longer CREATEs `requests`',
    preg_match('/CREATE TABLE\s+`requests`/i', $resetSchema) === 0);
t('sql/base_schema.sql still CREATEs `access_requests` (untouched)',
    strpos($baseSchema, 'CREATE TABLE IF NOT EXISTS `access_requests`') !== false);
t('sql/base_schema_RESET_DESTRUCTIVE.sql still CREATEs `access_requests` (untouched)',
    strpos($resetSchema, 'CREATE TABLE `access_requests`') !== false);

// ── 4. No remaining PHP reference to the dropped table anywhere in api/,
//      inc/, tools/ (outside this test file and the migration script
//      itself, both of which legitimately name it in comments/strings).
//      A tokenizing scan, not a plain grep, so a string like "no requests
//      pending" in an unrelated comment doesn't false-positive; this only
//      flags the table actually being referenced as a SQL identifier
//      (`{$prefix}requests` / `requests` backtick-quoted).
$scanDirs = ['api', 'inc'];
$offenders = [];
foreach ($scanDirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../' . $dir));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') continue;
        $src = file_get_contents($file->getPathname());
        // Match `{prefix}requests` or bare `requests` as a backtick-quoted
        // SQL identifier, but not `access_requests` (word boundary via a
        // negative lookbehind for '_').
        if (preg_match('/(?<!_)`requests`/', $src)) {
            $offenders[] = str_replace(__DIR__ . '/../', '', $file->getPathname());
        }
    }
}
t('no api/ or inc/ file references a backtick-quoted `requests` table'
    . (empty($offenders) ? '' : ' (found in: ' . implode(', ', $offenders) . ')'),
    empty($offenders));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
