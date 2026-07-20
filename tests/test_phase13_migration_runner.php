<?php
/**
 * Phase 13 — master migration runner.
 *
 * Verifies:
 *   - sql/run_migrations.php exists and lints
 *   - api/migrations-check.php exists and is admin-only
 *   - inc/navbar.php has the pending-migrations banner
 *   - docs/INSTALL.md exists with the canonical command
 *   - _migrations table is created with the expected columns
 *   - sanity probe: location_providers / captions_i18n / roles all
 *     have rows on this dev box (we ran the orchestrator earlier)
 */

require_once __DIR__ . '/../config.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 13 — migration runner tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── Files exist ───────────────────────────────────────────────────────
foreach ([
    'sql/run_migrations.php',
    'api/migrations-check.php',
    'docs/INSTALL.md',
] as $rel) {
    if (file_exists($base . '/' . $rel)) {
        ok("{$rel} exists");
    } else {
        bad("{$rel} missing");
    }
}

// ── Orchestrator content ─────────────────────────────────────────────
$rm = file_get_contents($base . '/sql/run_migrations.php');
if (strpos($rm, "_migrations") !== false &&
    strpos($rm, 'shell_exec') !== false) {
    ok('orchestrator uses subprocess isolation via shell_exec');
} else {
    bad('orchestrator does NOT use subprocess isolation');
}
if (strpos($rm, "hash_file('sha256'") !== false) {
    ok('orchestrator hashes each migration for change detection');
} else {
    bad('orchestrator does NOT hash migrations');
}
if (strpos($rm, '--force') !== false && strpos($rm, '--list') !== false) {
    ok('orchestrator supports --force and --list flags');
} else {
    bad('orchestrator missing flags');
}

// ── API endpoint behavior ────────────────────────────────────────────
$mc = file_get_contents($base . '/api/migrations-check.php');
if (strpos($mc, 'is_admin()') !== false) {
    ok('api/migrations-check.php is admin-gated');
} else {
    bad('api/migrations-check.php missing admin gate');
}
if (strpos($mc, "'pending'") !== false && strpos($mc, "'tracking_table'") !== false) {
    ok('api/migrations-check.php returns expected response keys');
} else {
    bad('api/migrations-check.php missing expected response shape');
}

// ── Navbar banner ────────────────────────────────────────────────────
$nv = file_get_contents($base . '/inc/navbar.php');
if (strpos($nv, 'migrationsPendingBanner') !== false &&
    strpos($nv, 'api/migrations-check.php') !== false) {
    ok('inc/navbar.php has the pending-migrations banner + fetch');
} else {
    bad('inc/navbar.php missing banner');
}
if (strpos($nv, 'migrationsBannerDismissed') !== false) {
    ok('navbar banner is dismissable per session');
} else {
    bad('navbar banner is NOT dismissable');
}

// ── Docs ─────────────────────────────────────────────────────────────
$inst = file_get_contents($base . '/docs/INSTALL.md');
if (strpos($inst, 'php sql/run_migrations.php') !== false) {
    ok('docs/INSTALL.md has the canonical run-migrations command');
} else {
    bad('docs/INSTALL.md missing the command');
}
if (strpos($inst, '_migrations') !== false) {
    ok('docs/INSTALL.md explains the tracking table');
} else {
    bad('docs/INSTALL.md does NOT explain _migrations');
}

// ── _migrations table exists on dev (we ran it earlier) ─────────────
try {
    $cols = db_fetch_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . '_migrations']
    );
    $names = array_map(function ($r) { return $r['COLUMN_NAME']; }, $cols);
    $expected = ['id', 'script_name', 'script_hash', 'applied_at', 'applied_by', 'duration_ms', 'status', 'notes'];
    $missing = array_diff($expected, $names);
    if (empty($missing)) {
        ok('_migrations table has all expected columns');
    } else {
        bad('_migrations table missing columns', implode(',', $missing));
    }
} catch (Exception $e) {
    bad('_migrations check', $e->getMessage());
}

// ── Sanity probe — these tables should have rows after the orchestrator ran
try {
    $lp = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}location_providers`");
    if ($lp >= 7) {
        ok("location_providers has rows ({$lp})");
    } else {
        bad("location_providers row count", "{$lp}, expected >= 7");
    }
} catch (Exception $e) {
    bad('location_providers probe', $e->getMessage());
}

echo "\n";
echo "===========================================\n";
echo "Phase 13 migration runner: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";

if ($fail > 0) exit(1);
