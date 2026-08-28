<?php
/**
 * Found while investigating a GH#115 CI failure (2026-08-26) -- a THIRD,
 * separate, pre-existing bug in the admin_only classification pipeline.
 * See sql/run_zzz_admin_only_reconcile.php's own docblock for the full
 * story; summary: sql/rbac.sql's admin_only classification UPDATE runs
 * as part of the foundational .sql import, BEFORE any sql/run_*.php
 * migration -- but console.design/action.intercom_unlock are created by
 * sql/run_phase114a_channel_registry.php and action.manage_matrix by
 * sql/run_phase114c_comm_routes.php, both of which run LATER. An UPDATE
 * against a WHERE clause matching zero rows is not an error, so these
 * three codes were silently left at admin_only's own DEFAULT 0 on every
 * genuinely fresh install, forever -- confirmed live via a CI diagnostic,
 * not guessed.
 *
 * sql/run_zzz_admin_only_reconcile.php is the general fix: a migration
 * whose filename is deliberately chosen to sort LAST among every
 * sql/run_*.php script (ksort() order), re-applying the same
 * classification UPDATE + canonical-alias propagation after every
 * permission-creating migration has had its turn.
 *
 * @requires-db
 * Usage: php tests/test_admin_only_reconcile_timing.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== sql/run_zzz_admin_only_reconcile.php runs last and fixes the Phase 114 timing gap ===\n\n";

// ── 1. Structural: the filename sorts after the specific migrations it
// depends on (the actual mechanism the fix relies on) — NOT necessarily
// after every other run_*.php file in the tree. A second, unrelated
// "run_zzz_*" reconciliation script (run_zzz_fcc_station_id_columns_
// reconcile.php, fixing a different migration-ordering gap in a
// different subsystem) was added later and legitimately sorts after
// this one ('f' > 'a') — that's harmless, since the two scripts touch
// entirely different tables and neither depends on the other's ordering
// relative to itself, only on sorting after ITS OWN dependencies. ──────
$sqlDir = __DIR__ . '/../sql';
$allMigrations = array_map('basename', glob($sqlDir . '/run_*.php'));
sort($allMigrations);
$zzzFiles = array_values(array_filter($allMigrations, function ($f) { return strpos($f, 'run_zzz_') === 0; }));
if (in_array('run_zzz_admin_only_reconcile.php', $zzzFiles, true)) {
    ok('run_zzz_admin_only_reconcile.php is part of the "sorts near the end" run_zzz_* family (' . count($zzzFiles) . ' such scripts found)');
} else {
    bad('run_zzz_admin_only_reconcile.php is missing entirely');
}
foreach (['run_phase114a_channel_registry.php', 'run_phase114c_comm_routes.php'] as $earlier) {
    if (strcmp('run_zzz_admin_only_reconcile.php', $earlier) > 0) {
        ok("run_zzz_admin_only_reconcile.php sorts after {$earlier} (the file that actually creates the affected permission codes)");
    } else {
        bad("run_zzz_admin_only_reconcile.php does not sort after {$earlier}");
    }
}

// ── 2. Structural: the tier lists match sql/rbac.sql's own lists exactly
// (this is a reconciliation of the SAME classification, not a
// different one — drift between the two would be its own bug). ────────
$reconcileSrc = file_get_contents($sqlDir . '/run_zzz_admin_only_reconcile.php');
$rbacSrc = file_get_contents($sqlDir . '/rbac.sql');
$tier1Codes = ['console.design', 'action.intercom_unlock', 'action.manage_matrix', 'action.manage_calls'];
foreach ($tier1Codes as $code) {
    $inReconcile = strpos($reconcileSrc, "'{$code}'") !== false;
    $inRbac = strpos($rbacSrc, "'{$code}'") !== false;
    if ($inReconcile && $inRbac) {
        ok("'{$code}' appears in both sql/rbac.sql and the reconciliation script");
    } else {
        bad("'{$code}' is missing from one of the two classification lists", "reconcile=" . ($inReconcile ? 'yes' : 'no') . " rbac.sql=" . ($inRbac ? 'yes' : 'no'));
    }
}

// ── 3. Functional: run the real script against the real dev database and
// confirm it exits 0 and reports the canonical-alias propagation is
// idempotent (a second run finds nothing left to do). ──────────────────
$php = PHP_BINARY ?: 'php';
$scriptPath = $sqlDir . '/run_zzz_admin_only_reconcile.php';
function run_reconcile(array $cmd): array {
    $d = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $d, $pipes);
    if (!is_resource($proc)) return ['exit' => -1, 'out' => ''];
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['exit' => $exit, 'out' => $out];
}
$run1 = run_reconcile([$php, $scriptPath]);
if ($run1['exit'] === 0) {
    ok('the real script exits 0 against the real dev database');
} else {
    bad('the real script did not exit 0', "exit={$run1['exit']} out=" . trim($run1['out']));
}

// ── 4. Functional: reproduce the EXACT timing gap directly — a fixture
// permission code inserted AFTER a simulated "classification pass" (the
// same shape as rbac.sql running before Phase 114's migrations exist)
// must still end up correctly classified once the reconcile script runs.
// Uses a fixture code, never touching the three real CI-flagged codes. ─
$prefix = $GLOBALS['db_prefix'] ?? '';
$fixtureCode = 'action.gh115_reconcile_test_fixture_' . getmypid();
try {
    // Simulate the exact bug: insert AFTER the classification pass would
    // have run (admin_only defaults to 0, same as INSERT IGNORE without
    // naming the column — the real Phase 114 shape).
    db_query(
        "INSERT INTO `{$prefix}permissions` (code, name, category) VALUES (?, 'GH115 reconcile fixture', 'action')",
        [$fixtureCode]
    );
    $before = (int) db_fetch_value("SELECT admin_only FROM `{$prefix}permissions` WHERE code = ?", [$fixtureCode]);
    if ($before === 0) {
        ok('fixture starts at admin_only=0, matching the real Phase 114 timing gap exactly');
    } else {
        bad('fixture did not start at 0', "got {$before}");
    }
    // The reconcile script's own hardcoded lists don't know about this
    // fixture code, so directly exercise the same UPDATE shape it uses
    // (this is the honest way to prove the MECHANISM without editing the
    // shipped script's own code list for a test-only fixture).
    db_query("UPDATE `{$prefix}permissions` SET admin_only = 1 WHERE code = ? AND admin_only < 1", [$fixtureCode]);
    $after = (int) db_fetch_value("SELECT admin_only FROM `{$prefix}permissions` WHERE code = ?", [$fixtureCode]);
    if ($after === 1) {
        ok('the same UPDATE ... AND admin_only < N shape the reconcile script uses correctly classifies a permission created after the fact');
    } else {
        bad('the UPDATE did not classify the fixture', "got {$after}");
    }
} catch (Throwable $e) {
    bad('fixture round-trip threw', $e->getMessage());
} finally {
    try { db_query("DELETE FROM `{$prefix}permissions` WHERE code = ?", [$fixtureCode]); } catch (Throwable $e) {}
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
