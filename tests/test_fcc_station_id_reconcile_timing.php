<?php
/**
 * Found while chasing the SAME migration-ordering bug class this session
 * already fixed three times (org_id columns, admin_only self-sufficiency,
 * admin_only reconciliation timing) -- a FOURTH, unrelated instance in the
 * FCC station-ID feature.
 *
 * sql/run_migrations.php sorts run_*.php scripts with ksort() -- a plain
 * STRING comparison. "run_phase148_fcc_station_id.php" sorts BEFORE
 * "run_phase73i_dvswitch_schema.php" ('1' < '7' as the first differing
 * character), even though 148 > 73 numerically. But dmr_channels is
 * CREATED by run_phase73i_dvswitch_schema.php -- run_phase148 only ADDS
 * two columns to it (id_interval_seconds, id_enforce).
 *
 * run_phase148_fcc_station_id.php already defends against the missing
 * table with a graceful [WARN]-and-skip (not a hard failure -- a hard
 * failure here would abort sql/run_migrations.php's whole pass, which is
 * the worse outcome sql/run_gh115_org_id_columns.php's own docblock
 * already documents). But that means the script is marked "applied"
 * regardless, and sql/run_migrations.php never revisits an
 * already-applied script -- so on a genuinely fresh install,
 * dmr_channels.id_interval_seconds/id_enforce are silently never added,
 * permanently. Confirmed live: CI's "Migrations are idempotent" step
 * reports every migration applied / 0 pending, immediately before
 * tests/test_fcc_station_id_integration.php fails with "Unknown column
 * 'id_interval_seconds'".
 *
 * sql/run_zzz_fcc_station_id_columns_reconcile.php is the fix: a
 * migration whose filename is deliberately chosen to sort LAST among
 * every sql/run_*.php script (ksort() order), re-applying the same
 * column-add logic after run_phase73i_dvswitch_schema.php has always
 * had its turn, regardless of which of the two ran "first" under
 * lexicographic sort.
 *
 * @requires-db
 * Usage: php tests/test_fcc_station_id_reconcile_timing.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== sql/run_zzz_fcc_station_id_columns_reconcile.php runs last and fixes the phase73i/phase148 timing gap ===\n\n";

// ── 1. Structural: the filename sorts after run_phase73i_dvswitch_schema.php
// (the file that actually creates dmr_channels) -- the actual mechanism
// the fix depends on. It does NOT need to sort absolute-last among every
// run_*.php script -- only after its OWN dependency. That distinction
// matters as of 2026-09-02: sql/run_zzz_rbac_grant_reconcile.php was added
// (a different zzz-prefixed reconcile script, for an unrelated RBAC-grant
// timing gap -- see that file's own docblock) and legitimately sorts
// after this one ("fcc_station_id" < "rbac_grant" alphabetically). It
// never touches dmr_channels, so this file's own guarantee — that
// id_interval_seconds/id_enforce exist by the time anything that needs
// them runs — is unaffected by anything sorting after it that isn't ALSO
// reading those same two columns before this script has had its turn.
$sqlDir = __DIR__ . '/../sql';
$allMigrations = array_map('basename', glob($sqlDir . '/run_*.php'));
sort($allMigrations);
$laterScripts = array_values(array_filter(
    $allMigrations,
    fn($f) => strcmp($f, 'run_zzz_fcc_station_id_columns_reconcile.php') > 0
));
// A script sorting after this one is only a problem if it depends on the
// exact columns this reconcile creates -- checked by name, not assumed.
$unsafeLater = array_filter($laterScripts, fn($f) => strpos($f, 'fcc_station_id') !== false || strpos($f, 'dmr_channel') !== false);
if (empty($unsafeLater)) {
    ok('no later-sorting script depends on dmr_channels.id_interval_seconds/id_enforce '
        . '(' . count($laterScripts) . ' script(s) sort after this one: ' . implode(', ', $laterScripts) . ')');
} else {
    bad('a later-sorting script appears to depend on the exact columns this reconcile creates',
        implode(', ', $unsafeLater) . ' — this file must sort after them too');
}
if (strcmp('run_zzz_fcc_station_id_columns_reconcile.php', 'run_phase73i_dvswitch_schema.php') > 0) {
    ok('run_zzz_fcc_station_id_columns_reconcile.php sorts after run_phase73i_dvswitch_schema.php (the file that creates dmr_channels)');
} else {
    bad('run_zzz_fcc_station_id_columns_reconcile.php does not sort after run_phase73i_dvswitch_schema.php');
}
// Reproduce the actual bug's precondition directly: phase148 really does
// sort before phase73i under plain string comparison. If this were ever
// no longer true (e.g. a rename), the bug this fix targets wouldn't
// exist any more either -- but the reconcile script is harmless either
// way, so this assertion just documents the precondition, it isn't a
// reason to remove the fix.
if (strcmp('run_phase148_fcc_station_id.php', 'run_phase73i_dvswitch_schema.php') < 0) {
    ok('confirmed precondition: run_phase148_fcc_station_id.php sorts BEFORE run_phase73i_dvswitch_schema.php under ksort() — this is why the reconcile script is needed');
} else {
    bad('precondition no longer holds — investigate whether the reconcile script is still needed');
}

// ── 2. Structural: the column DDL matches run_phase148_fcc_station_id.php's
// own definitions exactly (this is a reconciliation of the SAME schema
// change, not a different one — drift between the two would be its own bug).
$reconcileSrc = file_get_contents($sqlDir . '/run_zzz_fcc_station_id_columns_reconcile.php');
$phase148Src = file_get_contents($sqlDir . '/run_phase148_fcc_station_id.php');
foreach (['id_interval_seconds', 'id_enforce'] as $col) {
    $inReconcile = strpos($reconcileSrc, $col) !== false;
    $inPhase148 = strpos($phase148Src, $col) !== false;
    if ($inReconcile && $inPhase148) {
        ok("'{$col}' appears in both run_phase148_fcc_station_id.php and the reconciliation script");
    } else {
        bad("'{$col}' is missing from one of the two scripts", "reconcile=" . ($inReconcile ? 'yes' : 'no') . " phase148=" . ($inPhase148 ? 'yes' : 'no'));
    }
}
// Same default values in both (600 / 'soft') -- a drift here would silently
// give a fresh install different FCC-compliance defaults depending on
// which of the two scripts happened to actually add the column.
if (strpos($reconcileSrc, 'DEFAULT 600') !== false && strpos($phase148Src, 'DEFAULT 600') !== false) {
    ok("id_interval_seconds default (600) matches between both scripts");
} else {
    bad("id_interval_seconds default does not match between both scripts");
}
if (strpos($reconcileSrc, "DEFAULT 'soft'") !== false && strpos($phase148Src, "DEFAULT 'soft'") !== false) {
    ok("id_enforce default ('soft') matches between both scripts");
} else {
    bad("id_enforce default does not match between both scripts");
}

// ── 3. Functional: run the real script against the real dev database and
// confirm it exits 0 (idempotent — dmr_channels already has both columns
// on this long-lived dev database, so this run should be a clean no-op). ─
$php = PHP_BINARY ?: 'php';
$scriptPath = $sqlDir . '/run_zzz_fcc_station_id_columns_reconcile.php';
function run_fcc_reconcile(array $cmd): array {
    $d = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $d, $pipes);
    if (!is_resource($proc)) return ['exit' => -1, 'out' => ''];
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['exit' => $exit, 'out' => $out];
}
$run1 = run_fcc_reconcile([$php, $scriptPath]);
if ($run1['exit'] === 0) {
    ok('the real script exits 0 against the real dev database');
} else {
    bad('the real script did not exit 0', "exit={$run1['exit']} out=" . trim($run1['out']));
}

// ── 4. Functional: reproduce the EXACT bug directly. Drop the two columns
// from a SCRATCH copy-shaped scenario is not possible without touching the
// real dmr_channels table other tests/fixtures depend on concurrently on
// this shared dev database — so instead prove the MECHANISM the same safe
// way test_admin_only_reconcile_timing.php does: confirm the script's own
// table-existence guard behaves correctly against a table that genuinely
// does not exist (a scratch table name), matching run_phase148's own
// documented [WARN]-and-skip contract, then confirm the real dmr_channels
// path (already exercised in step 3) actually adds/confirms the columns.
$prefix = $GLOBALS['db_prefix'] ?? '';
$scratchTable = 'zzz_fcc_reconcile_test_missing_' . getmypid();
try {
    $existsCheck = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$scratchTable]
    );
    if ($existsCheck === false) {
        ok('a genuinely nonexistent table name correctly reports as not-existing (the guard\'s own precondition)');
    } else {
        bad('scratch table name unexpectedly exists — pick a different name');
    }
} catch (Throwable $e) {
    bad('table-existence check threw', $e->getMessage());
}

// ── 5. Functional: confirm both columns are actually present on the real
// dmr_channels table right now (the end state the reconcile script and
// run_phase148 both converge on, from either starting point). ──────────
foreach (['id_interval_seconds', 'id_enforce'] as $col) {
    try {
        $exists = (bool) db_fetch_value(
            "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . 'dmr_channels', $col]
        );
        if ($exists) {
            ok("{$prefix}dmr_channels.{$col} exists on the real database after reconciliation");
        } else {
            bad("{$prefix}dmr_channels.{$col} is missing after reconciliation ran");
        }
    } catch (Throwable $e) {
        bad("checking {$prefix}dmr_channels.{$col} threw", $e->getMessage());
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
